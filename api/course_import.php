<?php
ob_start();
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');
requireLogin();

$userId = currentUserId();
$db = getDb();
$method = $_SERVER['REQUEST_METHOD'];

function courseImportJson(array $data, int $status = 200): never {
    if (ob_get_length()) {
        ob_clean();
    }
    http_response_code($status);
    echo json_encode($data);
    exit;
}

function courseImportJsonError(string $message, int $status = 400, array $extra = []): never {
    courseImportJson(array_merge(['ok' => false, 'error' => $message], $extra), $status);
}

if ($method !== 'POST') {
    header('Allow: POST');
    courseImportJsonError('Method not allowed.', 405);
}

try {
    $isJson = (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false);
    $body = $isJson ? (json_decode(file_get_contents('php://input'), true) ?? []) : $_POST;

    $csrf = $body['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
    verifyCsrf($csrf);

    $action = $body['action'] ?? '';

    // ----------------------------------------------------------------
    // ACTION 1: Extract Courses from Course Registration Form (Review Step)
    // ----------------------------------------------------------------
    if ($action === 'extract_form') {
        $extractedText = '';
        $diagnostics = [];

        if (!empty($_FILES['document']['tmp_name'])) {
            $file = $_FILES['document'];
            if ($file['error'] !== UPLOAD_ERR_OK) {
                $uploadErrMessage = match ($file['error']) {
                    UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The uploaded file exceeds the maximum allowed file size. Please choose a smaller file or paste the text.',
                    UPLOAD_ERR_PARTIAL => 'The file was only partially uploaded. Please try uploading again.',
                    UPLOAD_ERR_NO_FILE => 'No file was selected for upload.',
                    default => 'File upload failed. Please try again or paste the course text directly.'
                };
                courseImportJsonError($uploadErrMessage, 422);
            }
            if ($file['size'] > 15 * 1024 * 1024) {
                courseImportJsonError('File size is too large (maximum 15MB).', 422);
            }

            $rawContent = file_get_contents($file['tmp_name']);
            $procResult = DocumentProcessor::extract($rawContent, $file['name'] ?? 'document.pdf');
            $extractedText = $procResult['text'] ?? '';
            $diagnostics = $procResult['diagnostics'] ?? [];

            if (!empty($procResult['is_scanned'])) {
                courseImportJson([
                    'ok'           => false,
                    'error_code'   => 'SCANNED_PDF_NO_OCR',
                    'error'        => "This document appears to be a scanned PDF. We couldn't read its text automatically. You can try another document or enter your courses manually.",
                    'is_scanned'   => true,
                    'manual_entry' => true,
                    'diagnostics'  => $diagnostics,
                ]);
            }
        } elseif (!empty($body['text'])) {
            $procResult = DocumentProcessor::extract((string) $body['text'], 'pasted_text.txt');
            $extractedText = $procResult['text'] ?? '';
            $diagnostics = $procResult['diagnostics'] ?? [];
        }

        if (trim($extractedText) === '' || strlen(trim($extractedText)) < 10) {
            courseImportJson([
                'ok'           => false,
                'error_code'   => 'EMPTY_EXTRACTION',
                'error'        => "We couldn't read any text from this file. Try another document or add your courses manually.",
                'manual_entry' => true,
                'diagnostics'  => $diagnostics,
            ]);
        }

        $courses = DocumentProcessor::parseCourses($extractedText, $db, $userId);
        if (empty($courses)) {
            courseImportJson([
                'ok'           => false,
                'error_code'   => 'NO_ITEMS_FOUND',
                'error'        => "We couldn't identify course codes in this document. Please check the file or add courses manually.",
                'preview'      => substr(trim($extractedText), 0, 150),
                'manual_entry' => true,
                'diagnostics'  => $diagnostics,
            ]);
        }

        // Check which courses the student already has
        $cStmt = $db->prepare('SELECT UPPER(code) FROM courses WHERE user_id = ?');
        $cStmt->execute([$userId]);
        $existingCodes = array_flip($cStmt->fetchAll(PDO::FETCH_COLUMN));

        foreach ($courses as &$c) {
            $c['already_exists'] = isset($existingCodes[strtoupper($c['code'])]);
        }
        unset($c);

        courseImportJson([
            'ok'          => true,
            'courses'     => $courses,
            'items'       => $courses,
            'found_count' => count($courses),
            'diagnostics' => $diagnostics,
        ]);
    }

    // ----------------------------------------------------------------
    // ACTION 2: Confirm and Save Reviewed Courses
    // ----------------------------------------------------------------
    if ($action === 'confirm_import') {
        $courses = $body['courses'] ?? $body['items'] ?? [];
        if (!is_array($courses) || empty($courses)) {
            courseImportJsonError('No courses provided for import.', 422);
        }

        $cStmt = $db->prepare('SELECT UPPER(code) FROM courses WHERE user_id = ?');
        $cStmt->execute([$userId]);
        $existingCodes = array_flip($cStmt->fetchAll(PDO::FETCH_COLUMN));

        $defaultIcons = ['📘', '💻', '🗄️', '📊', '⚛️', '📖', '🔬', '📐'];
        $defaultColors = ['#059669', '#166534', '#0284c7', '#7c3aed', '#d97706', '#db2777', '#0d9488'];

        $insertStmt = $db->prepare(
            'INSERT INTO courses (user_id, code, name, credits, semester, icon, color)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        );

        $defaultSemester = trim((string) ($body['semester'] ?? ''));
        if ($defaultSemester === '') {
            $semStmt = $db->prepare('SELECT name FROM semesters WHERE user_id = ? AND is_current = 1 ORDER BY id DESC LIMIT 1');
            $semStmt->execute([$userId]);
            $defaultSemester = (string) ($semStmt->fetchColumn() ?: '');
        }

        // Fetch known user semesters for canonical association
        $knownSemStmt = $db->prepare('SELECT name FROM semesters WHERE user_id = ?');
        $knownSemStmt->execute([$userId]);
        $knownSemesters = $knownSemStmt->fetchAll(PDO::FETCH_COLUMN) ?: [];

        $imported = 0;
        $skipped = 0;
        $addedNames = [];

        foreach ($courses as $idx => $course) {
            $code = strtoupper(trim((string) ($course['code'] ?? '')));
            $name = trim((string) ($course['name'] ?? ''));
            $credits = max(1, min(6, (int) ($course['credits'] ?? 3)));
            $semester = trim((string) ($course['semester'] ?? ''));
            if ($semester === '') {
                $semester = $defaultSemester;
            }

            // Canonical semester normalization: preserve relationship and fit VARCHAR(30)
            if ($semester !== '') {
                foreach ($knownSemesters as $kSem) {
                    if (stripos($semester, $kSem) !== false || stripos($kSem, $semester) !== false) {
                        $semester = $kSem;
                        break;
                    }
                }
                if (mb_strlen($semester, 'UTF-8') > 30) {
                    if (stripos($semester, 'Second') !== false) {
                        $semester = preg_match('/\b(\d{4}\/\d{4}|\d{2}\/\d{2})\b/', $semester, $sm)
                            ? 'Second Sem ' . $sm[1]
                            : 'Second Semester';
                    } elseif (stripos($semester, 'First') !== false) {
                        $semester = preg_match('/\b(\d{4}\/\d{4}|\d{2}\/\d{2})\b/', $semester, $sm)
                            ? 'First Sem ' . $sm[1]
                            : 'First Semester';
                    } else {
                        $semester = mb_substr($semester, 0, 30, 'UTF-8');
                    }
                }
            }

            if ($code === '' || $name === '') {
                continue;
            }

            // Skip duplicates
            if (isset($existingCodes[$code])) {
                $skipped++;
                continue;
            }

            $icon = $defaultIcons[$idx % count($defaultIcons)];
            $color = $defaultColors[$idx % count($defaultColors)];

            $insertStmt->execute([
                $userId,
                $code,
                $name,
                $credits,
                $semester ?: null,
                $icon,
                $color,
            ]);

            $existingCodes[$code] = true;
            $imported++;
            $addedNames[] = $code;
        }

        if ($imported > 0) {
            logActivity($userId, "Added {$imported} courses from course registration form: " . implode(', ', array_slice($addedNames, 0, 4)), 'success');
        }

        courseImportJson([
            'ok'             => true,
            'imported_count' => $imported,
            'skipped_count'  => $skipped,
            'total'          => count($courses),
            'message'        => "{$imported} course(s) successfully added to your courses.",
        ]);
    }

    courseImportJsonError('Invalid action specified.', 422);
} catch (PDOException $e) {
    error_log('Course Import database error: ' . $e->getMessage());
    courseImportJsonError('A database error occurred while saving courses.', 500);
} catch (Throwable $e) {
    error_log('Course Import error: ' . $e->getMessage());
    courseImportJsonError('An error occurred during course import.', 500);
}
