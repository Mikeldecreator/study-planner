<?php
/**
 * Universal Academic Document Import API Endpoint
 * 
 * Supports all 4 academic features:
 * 1. Courses (Course Registration Form)
 * 2. Curriculum (Semester Academic Calendar & Weeks)
 * 3. Timetable (Class Timetable & Schedules)
 * 4. Work (Assignments, Projects, Tests, Deadlines)
 * 
 * Flow:
 * - action=extract: Validates file/text, extracts text, detects scanned PDF, normalizes text, runs domain parser, flags duplicates, returns review items.
 * - action=confirm: Saves reviewed items into the database with duplicate protection under current user ID.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/document_processor.php';

header('Content-Type: application/json; charset=utf-8');
requireLogin();

$userId = currentUserId();
$db = getDb();
$method = $_SERVER['REQUEST_METHOD'];

function docImportJson(array $payload, int $status = 200): never {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function docImportError(string $message, string $errorCode = 'ERROR', int $status = 400, array $extra = []): never {
    http_response_code($status);
    echo json_encode(array_merge([
        'ok'         => false,
        'error_code' => $errorCode,
        'error'      => $message,
    ], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($method !== 'POST') {
    header('Allow: POST');
    docImportError('Method not allowed.', 'METHOD_NOT_ALLOWED', 405);
}

try {
    $isJson = (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false);
    $body = $isJson ? (json_decode(file_get_contents('php://input'), true) ?? []) : $_POST;

    $csrf = $body['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
    verifyCsrf($csrf);

    $action = $body['action'] ?? '';
    $domain = strtolower(trim((string) ($body['domain'] ?? '')));

    $validDomains = ['courses', 'curriculum', 'timetable', 'work'];
    if (!in_array($domain, $validDomains, true)) {
        docImportError('Invalid or unspecified academic domain.', 'INVALID_DOMAIN', 422);
    }

    // =========================================================================
    // ACTION 1: EXTRACT DOCUMENT OR TEXT (Pre-Review Step)
    // =========================================================================
    if ($action === 'extract') {
        $content = '';
        $filename = 'document.txt';

        if (!empty($_FILES['document']['tmp_name'])) {
            $file = $_FILES['document'];
            if ($file['error'] !== UPLOAD_ERR_OK) {
                docImportError('File upload failed. Please try again.', 'UPLOAD_FAILED', 422);
            }
            if ($file['size'] > 15 * 1024 * 1024) {
                docImportError('File size is too large (maximum 15MB).', 'FILE_TOO_LARGE', 422);
            }
            $filename = $file['name'] ?? 'document.pdf';
            $content = (string) file_get_contents($file['tmp_name']);
        } elseif (!empty($body['text'])) {
            $content = (string) $body['text'];
            $filename = 'pasted_text.txt';
        } else {
            docImportError('Please upload a document (.pdf, .docx, .txt) or enter text.', 'NO_CONTENT', 422);
        }

        // Run extraction and normalization
        $extracted = DocumentProcessor::extract($content, $filename);
        $diagnostics = $extracted['diagnostics'] ?? [];

        // Check if Scanned PDF without OCR
        if (!empty($extracted['is_scanned'])) {
            docImportError(
                "This document appears to be a scanned PDF. We couldn't read its text automatically. You can try another document or enter the information manually.",
                'SCANNED_PDF_NO_OCR',
                200, // Return 200 with ok:false so frontend displays student guidance
                [
                    'is_scanned'   => true,
                    'diagnostics'  => $diagnostics,
                    'manual_entry' => true,
                ]
            );
        }

        $extractedText = trim((string) ($extracted['text'] ?? ''));
        if (mb_strlen($extractedText, 'UTF-8') < 10) {
            docImportError(
                "We couldn't read any text from this file. Try another document or enter your information manually.",
                'EMPTY_EXTRACTION',
                200,
                [
                    'diagnostics'  => $diagnostics,
                    'manual_entry' => true,
                ]
            );
        }

        // Run specific domain parser
        switch ($domain) {
            case 'courses':
                $items = DocumentProcessor::parseCourses($extractedText, $db, $userId);
                if (empty($items)) {
                    docImportError(
                        "We couldn't identify course codes in this document. Please check the file or add courses manually.",
                        'NO_ITEMS_FOUND',
                        200,
                        [
                            'preview'      => mb_substr($extractedText, 0, 200, 'UTF-8'),
                            'diagnostics'  => $diagnostics,
                            'manual_entry' => true,
                        ]
                    );
                }
                docImportJson([
                    'ok'          => true,
                    'domain'      => 'courses',
                    'items'       => $items,
                    'found_count' => count($items),
                    'diagnostics' => $diagnostics,
                ]);
                break;

            case 'curriculum':
                $parsed = DocumentProcessor::parseCurriculum($extractedText);
                docImportJson([
                    'ok'          => true,
                    'domain'      => 'curriculum',
                    'extracted'   => $parsed,
                    'found_count' => count($parsed['weeks'] ?? []),
                    'diagnostics' => $diagnostics,
                ]);
                break;

            case 'timetable':
                $items = DocumentProcessor::parseTimetable($extractedText, $db, $userId);
                if (empty($items)) {
                    docImportError(
                        "We couldn't identify class timetable slots in this document. Please check the file or add classes manually.",
                        'NO_ITEMS_FOUND',
                        200,
                        [
                            'preview'      => mb_substr($extractedText, 0, 200, 'UTF-8'),
                            'diagnostics'  => $diagnostics,
                            'manual_entry' => true,
                        ]
                    );
                }
                docImportJson([
                    'ok'          => true,
                    'domain'      => 'timetable',
                    'items'       => $items,
                    'found_count' => count($items),
                    'diagnostics' => $diagnostics,
                ]);
                break;

            case 'work':
                $items = DocumentProcessor::parseWork($extractedText, $db, $userId);
                if (empty($items)) {
                    docImportError(
                        "We couldn't identify assignments, tests, or academic work in this document. Please check the file or add work manually.",
                        'NO_ITEMS_FOUND',
                        200,
                        [
                            'preview'      => mb_substr($extractedText, 0, 200, 'UTF-8'),
                            'diagnostics'  => $diagnostics,
                            'manual_entry' => true,
                        ]
                    );
                }
                docImportJson([
                    'ok'          => true,
                    'domain'      => 'work',
                    'items'       => $items,
                    'found_count' => count($items),
                    'diagnostics' => $diagnostics,
                ]);
                break;
        }
    }

    // =========================================================================
    // ACTION 2: CONFIRM AND COMMIT REVIEWED ITEMS TO DATABASE
    // =========================================================================
    if ($action === 'confirm') {
        switch ($domain) {
            // -----------------------------------------------------------------
            // DOMAIN: COURSES
            // -----------------------------------------------------------------
            case 'courses':
                $courses = $body['items'] ?? $body['courses'] ?? [];
                if (!is_array($courses) || empty($courses)) {
                    docImportError('No courses provided for import.', 'EMPTY_ITEMS', 422);
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
                    logActivity($userId, "Imported {$imported} courses: " . implode(', ', array_slice($addedNames, 0, 4)), 'success');
                }

                docImportJson([
                    'ok'             => true,
                    'imported_count' => $imported,
                    'skipped_count'  => $skipped,
                    'total'          => count($courses),
                    'message'        => "{$imported} course(s) successfully added to your courses." . ($skipped > 0 ? " ({$skipped} duplicates skipped)" : ""),
                ]);
                break;

            // -----------------------------------------------------------------
            // DOMAIN: CURRICULUM
            // -----------------------------------------------------------------
            case 'curriculum':
                $semName = trim((string) ($body['semester_name'] ?? 'First Semester'));
                $startDate = trim((string) ($body['start_date'] ?? ''));
                $endDate = trim((string) ($body['end_date'] ?? ''));
                $weeks = $body['weeks'] ?? $body['items'] ?? [];

                if ($semName === '') {
                    docImportError('Semester name is required.', 'MISSING_NAME', 422);
                }
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
                    docImportError('Valid start and end dates (YYYY-MM-DD) are required.', 'INVALID_DATES', 422);
                }
                if ($endDate <= $startDate) {
                    docImportError('Semester end date must be after the start date.', 'INVALID_DATE_RANGE', 422);
                }
                if (!is_array($weeks) || empty($weeks)) {
                    docImportError('At least one academic week is required in the curriculum.', 'NO_WEEKS', 422);
                }

                $db->beginTransaction();

                // Find or insert active semester
                $semStmt = $db->prepare('SELECT id FROM semesters WHERE user_id = ? AND is_current = 1 LIMIT 1');
                $semStmt->execute([$userId]);
                $existingSemId = $semStmt->fetchColumn();

                if ($existingSemId) {
                    $semesterId = (int) $existingSemId;
                    $upSem = $db->prepare('UPDATE semesters SET name = ?, start_date = ?, end_date = ? WHERE id = ? AND user_id = ?');
                    $upSem->execute([$semName, $startDate, $endDate, $semesterId, $userId]);
                } else {
                    $db->prepare('UPDATE semesters SET is_current = 0 WHERE user_id = ?')->execute([$userId]);
                    $insSem = $db->prepare('INSERT INTO semesters (user_id, name, start_date, end_date, is_current) VALUES (?, ?, ?, ?, 1)');
                    $insSem->execute([$userId, $semName, $startDate, $endDate]);
                    $semesterId = (int) $db->lastInsertId();
                }

                // Replace weeks
                $db->prepare('DELETE FROM curriculum_weeks WHERE semester_id = ? AND user_id = ?')->execute([$semesterId, $userId]);

                $insWeek = $db->prepare(
                    'INSERT INTO curriculum_weeks (semester_id, user_id, week_number, label, week_type, start_date, end_date, notes)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                );

                $validTypes = ['teaching', 'student_week', 'break', 'revision', 'exam', 'orientation', 'other'];
                $savedWeeksCount = 0;

                foreach ($weeks as $idx => $w) {
                    $wNum = max(1, min(52, (int) ($w['week_number'] ?? ($idx + 1))));
                    $label = trim((string) ($w['label'] ?? "Teaching Week {$wNum}"));
                    $wType = strtolower(trim((string) ($w['week_type'] ?? 'teaching')));
                    if (!in_array($wType, $validTypes, true)) $wType = 'teaching';

                    $wStart = trim((string) ($w['start_date'] ?? ''));
                    $wEnd = trim((string) ($w['end_date'] ?? ''));
                    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $wStart)) {
                        $wStart = date('Y-m-d', strtotime("+{$idx} weeks", strtotime($startDate)));
                    }
                    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $wEnd)) {
                        $wEnd = date('Y-m-d', strtotime('+6 days', strtotime($wStart)));
                    }
                    $notes = trim((string) ($w['notes'] ?? ''));

                    $insWeek->execute([
                        $semesterId,
                        $userId,
                        $wNum,
                        $label,
                        $wType,
                        $wStart,
                        $wEnd,
                        $notes ?: null,
                    ]);
                    $savedWeeksCount++;
                }

                $db->commit();
                logActivity($userId, "Updated semester calendar: {$semName} ({$savedWeeksCount} weeks)", 'success');

                docImportJson([
                    'ok'          => true,
                    'semester_id' => $semesterId,
                    'weeks_count' => $savedWeeksCount,
                    'message'     => "Academic calendar successfully saved with {$savedWeeksCount} weeks.",
                ]);
                break;

            // -----------------------------------------------------------------
            // DOMAIN: TIMETABLE
            // -----------------------------------------------------------------
            case 'timetable':
                $classes = $body['items'] ?? $body['classes'] ?? [];
                if (!is_array($classes) || empty($classes)) {
                    docImportError('No timetable classes provided for import.', 'EMPTY_ITEMS', 422);
                }

                // Map courses
                $cStmt = $db->prepare('SELECT UPPER(code) as code, id FROM courses WHERE user_id = ?');
                $cStmt->execute([$userId]);
                $courseMap = $cStmt->fetchAll(PDO::FETCH_KEY_PAIR);

                // Existing slots to prevent duplicate insertion
                $existStmt = $db->prepare('SELECT day_of_week, start_time, end_time, course_id, title FROM schedule_events WHERE user_id = ?');
                $existStmt->execute([$userId]);
                $existingSlots = [];
                foreach ($existStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $key = "{$row['day_of_week']}_{$row['start_time']}_{$row['end_time']}_{$row['course_id']}";
                    $existingSlots[$key] = true;
                }

                $dayMap = [
                    'sunday' => 0, 'sun' => 0, '0' => 0,
                    'monday' => 1, 'mon' => 1, '1' => 1,
                    'tuesday' => 2, 'tue' => 2, '2' => 2,
                    'wednesday' => 3, 'wed' => 3, '3' => 3,
                    'thursday' => 4, 'thu' => 4, '4' => 4,
                    'friday' => 5, 'fri' => 5, '5' => 5,
                    'saturday' => 6, 'sat' => 6, '6' => 6,
                ];

                $insertStmt = $db->prepare(
                    'INSERT INTO schedule_events (user_id, course_id, title, event_type, day_of_week, start_time, end_time, is_completed)
                     VALUES (?, ?, ?, ?, ?, ?, ?, 0)'
                );

                $imported = 0;
                $skipped = 0;

                foreach ($classes as $item) {
                    $rawDay = strtolower(trim((string) ($item['day_of_week'] ?? '1')));
                    $dow = $dayMap[$rawDay] ?? 1;

                    $cCode = strtoupper(trim((string) ($item['course_code'] ?? '')));
                    $courseId = !empty($item['course_id']) ? (int) $item['course_id'] : ($courseMap[$cCode] ?? null);

                    $start = trim((string) ($item['start_time'] ?? '09:00:00'));
                    $end = trim((string) ($item['end_time'] ?? '11:00:00'));
                    $startTime = date('H:i:s', strtotime($start));
                    $endTime = date('H:i:s', strtotime($end));
                    if ($endTime <= $startTime) {
                        $endTime = date('H:i:s', strtotime('+1 hour', strtotime($startTime)));
                    }

                    $eventType = strtolower(trim((string) ($item['event_type'] ?? 'lecture')));
                    if (!in_array($eventType, ['lecture', 'study', 'exam', 'other'], true)) {
                        $eventType = 'lecture';
                    }

                    $title = trim((string) ($item['title'] ?? ''));
                    if (!$title && $cCode) {
                        $title = $cCode . ' ' . ucfirst($eventType);
                    }
                    if (!$title) {
                        $title = 'Class Lecture';
                    }

                    // Append location if provided and not already in title
                    $location = trim((string) ($item['location'] ?? ''));
                    if ($location && $location !== 'Campus' && !str_contains($title, $location)) {
                        $title .= " ({$location})";
                    }

                    // Duplicate check
                    $slotKey = "{$dow}_{$startTime}_{$endTime}_{$courseId}";
                    if (isset($existingSlots[$slotKey])) {
                        $skipped++;
                        continue;
                    }

                    $insertStmt->execute([
                        $userId,
                        $courseId,
                        $title,
                        $eventType,
                        $dow,
                        $startTime,
                        $endTime,
                    ]);

                    $existingSlots[$slotKey] = true;
                    $imported++;
                }

                if ($imported > 0) {
                    logActivity($userId, "Imported {$imported} timetable classes to weekly schedule", 'success');
                }

                docImportJson([
                    'ok'             => true,
                    'imported_count' => $imported,
                    'skipped_count'  => $skipped,
                    'total'          => count($classes),
                    'message'        => "{$imported} class(es) added to your timetable." . ($skipped > 0 ? " ({$skipped} duplicates skipped)" : ""),
                ]);
                break;

            // -----------------------------------------------------------------
            // DOMAIN: WORK (ASSIGNMENTS / TESTS / PROJECTS)
            // -----------------------------------------------------------------
            case 'work':
                $tasks = $body['items'] ?? $body['tasks'] ?? [];
                if (!is_array($tasks) || empty($tasks)) {
                    docImportError('No work items provided for import.', 'EMPTY_ITEMS', 422);
                }

                // Map courses (both exact and normalized without spaces/hyphens)
                $cStmt = $db->prepare('SELECT UPPER(code) as code, id FROM courses WHERE user_id = ?');
                $cStmt->execute([$userId]);
                $courseMap = $cStmt->fetchAll(PDO::FETCH_KEY_PAIR);
                $courseMapNorm = [];
                $validCourseIds = [];
                foreach ($courseMap as $cCode => $cId) {
                    $clean = preg_replace('/[^A-Za-z0-9]/', '', $cCode);
                    $courseMapNorm[$clean] = (int)$cId;
                    $validCourseIds[(int)$cId] = true;
                }

                // Fetch existing task titles to prevent duplicates
                $tStmt = $db->prepare('SELECT LOWER(TRIM(title)), course_id FROM tasks WHERE user_id = ?');
                $tStmt->execute([$userId]);
                $existingTasks = [];
                foreach ($tStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $key = strtolower(trim($row['LOWER(TRIM(title))'])) . '_' . ($row['course_id'] ?? 0);
                    $existingTasks[$key] = true;
                }

                $validTypes = ['assignment', 'project', 'test', 'exam', 'research', 'study_session', 'lab_report', 'other'];
                $validPriorities = ['low', 'medium', 'high'];

                $insertStmt = $db->prepare(
                    'INSERT INTO tasks (user_id, course_id, title, description, type, priority, status, progress_percent, duration_hours, due_at)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
                );

                $imported = 0;
                $skipped = 0;

                foreach ($tasks as $task) {
                    $title = trim((string) ($task['title'] ?? ''));
                    if ($title === '') continue;

                    $cCode = strtoupper(trim((string) ($task['course_code'] ?? '')));
                    $cleanCode = preg_replace('/[^A-Za-z0-9]/', '', $cCode);
                    $courseId = null;
                    if (!empty($task['course_id']) && isset($validCourseIds[(int)$task['course_id']])) {
                        $courseId = (int)$task['course_id'];
                    } elseif (isset($courseMap[$cCode])) {
                        $courseId = (int)$courseMap[$cCode];
                    } elseif (isset($courseMapNorm[$cleanCode])) {
                        $courseId = (int)$courseMapNorm[$cleanCode];
                    }

                    // Check duplicate
                    $tKey = strtolower($title) . '_' . ($courseId ?? 0);
                    if (isset($existingTasks[$tKey])) {
                        $skipped++;
                        continue;
                    }

                    $type = strtolower(trim((string) ($task['type'] ?? 'assignment')));
                    if (!in_array($type, $validTypes, true)) $type = 'assignment';

                    $priority = strtolower(trim((string) ($task['priority'] ?? 'medium')));
                    if (!in_array($priority, $validPriorities, true)) $priority = 'medium';

                    // Due At (datetime)
                    $dueDate = trim((string) ($task['due_date'] ?? ''));
                    $dueTime = trim((string) ($task['due_time'] ?? '23:59:00'));
                    $dueAt = null;
                    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dueDate)) {
                        $cleanTime = date('H:i:s', strtotime($dueTime ?: '23:59:00'));
                        $dueAt = "{$dueDate} {$cleanTime}";
                    }

                    $desc = trim((string) ($task['description'] ?? ''));
                    $duration = max(0.5, min(100.0, (float) ($task['duration_hours'] ?? (in_array($type, ['project', 'exam'], true) ? 4.0 : 2.0))));

                    $insertStmt->execute([
                        $userId,
                        $courseId,
                        $title,
                        $desc ?: null,
                        $type,
                        $priority,
                        'pending',
                        0,
                        $duration,
                        $dueAt,
                    ]);

                    $existingTasks[$tKey] = true;
                    $imported++;
                }

                if ($imported > 0) {
                    logActivity($userId, "Imported {$imported} academic work items / assignments", 'success');
                }

                docImportJson([
                    'ok'             => true,
                    'imported_count' => $imported,
                    'skipped_count'  => $skipped,
                    'total'          => count($tasks),
                    'message'        => "{$imported} work item(s) successfully added." . ($skipped > 0 ? " ({$skipped} duplicates skipped)" : ""),
                ]);
                break;
        }
    }

    docImportError('Invalid action specified.', 'INVALID_ACTION', 422);

} catch (PDOException $e) {
    error_log('Document Import database error: ' . $e->getMessage());
    docImportError('A database error occurred while processing document import.', 'DB_ERROR', 500);
} catch (Throwable $e) {
    error_log('Document Import error: ' . $e->getMessage());
    docImportError('An error occurred during document import: ' . $e->getMessage(), 'SERVER_ERROR', 500);
}
