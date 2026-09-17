<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');
requireLogin();

$userId = currentUserId();
$db = getDb();
$method = $_SERVER['REQUEST_METHOD'];

function courseImportJsonError(string $message, int $status = 400): never {
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => $message]);
    exit;
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
                courseImportJsonError('File upload failed. Please try again.', 422);
            }
            if ($file['size'] > 15 * 1024 * 1024) {
                courseImportJsonError('File size is too large (maximum 15MB).', 422);
            }

            $rawContent = file_get_contents($file['tmp_name']);
            $procResult = DocumentProcessor::extract($rawContent, $file['name'] ?? 'document.pdf');
            $extractedText = $procResult['text'] ?? '';
            $diagnostics = $procResult['diagnostics'] ?? [];

            if (!empty($procResult['is_scanned'])) {
                echo json_encode([
                    'ok'           => false,
                    'error_code'   => 'SCANNED_PDF_NO_OCR',
                    'error'        => "This document appears to be a scanned PDF. We couldn't read its text automatically. You can try another document or enter your courses manually.",
                    'is_scanned'   => true,
                    'manual_entry' => true,
                    'diagnostics'  => $diagnostics,
                ]);
                exit;
            }
        } elseif (!empty($body['text'])) {
            $procResult = DocumentProcessor::extract((string) $body['text'], 'pasted_text.txt');
            $extractedText = $procResult['text'] ?? '';
            $diagnostics = $procResult['diagnostics'] ?? [];
        }

        if (trim($extractedText) === '' || strlen(trim($extractedText)) < 10) {
            echo json_encode([
                'ok'           => false,
                'error_code'   => 'EMPTY_EXTRACTION',
                'error'        => "We couldn't read any text from this file. Try another document or add your courses manually.",
                'manual_entry' => true,
                'diagnostics'  => $diagnostics,
            ]);
            exit;
        }

        $courses = DocumentProcessor::parseCourses($extractedText, $db, $userId);
        if (empty($courses)) {
            echo json_encode([
                'ok'           => false,
                'error_code'   => 'NO_ITEMS_FOUND',
                'error'        => "We couldn't identify course codes in this document. Please check the file or add courses manually.",
                'preview'      => substr(trim($extractedText), 0, 150),
                'manual_entry' => true,
                'diagnostics'  => $diagnostics,
            ]);
            exit;
        }

        // Check which courses the student already has
        $cStmt = $db->prepare('SELECT UPPER(code) FROM courses WHERE user_id = ?');
        $cStmt->execute([$userId]);
        $existingCodes = array_flip($cStmt->fetchAll(PDO::FETCH_COLUMN));

        foreach ($courses as &$c) {
            $c['already_exists'] = isset($existingCodes[strtoupper($c['code'])]);
        }
        unset($c);

        echo json_encode([
            'ok'          => true,
            'courses'     => $courses,
            'found_count' => count($courses),
        ]);
        exit;
    }

    // ----------------------------------------------------------------
    // ACTION 2: Confirm and Save Reviewed Courses
    // ----------------------------------------------------------------
    if ($action === 'confirm_import') {
        $courses = $body['courses'] ?? [];
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

        $imported = 0;
        $skipped = 0;
        $addedNames = [];

        foreach ($courses as $idx => $course) {
            $code = strtoupper(trim((string) ($course['code'] ?? '')));
            $name = trim((string) ($course['name'] ?? ''));
            $credits = max(1, min(6, (int) ($course['credits'] ?? 3)));
            $semester = trim((string) ($course['semester'] ?? ''));

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

        echo json_encode([
            'ok'             => true,
            'imported_count' => $imported,
            'skipped_count'  => $skipped,
            'total'          => count($courses),
            'message'        => "{$imported} course(s) successfully added to your courses.",
        ]);
        exit;
    }

    courseImportJsonError('Invalid action specified.', 422);
} catch (PDOException $e) {
    error_log('Course Import database error: ' . $e->getMessage());
    courseImportJsonError('A database error occurred while saving courses.', 500);
} catch (Throwable $e) {
    error_log('Course Import error: ' . $e->getMessage());
    courseImportJsonError('An error occurred during course import.', 500);
}
