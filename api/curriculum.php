<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');
requireLogin();

$userId = currentUserId();
$db = getDb();
$method = $_SERVER['REQUEST_METHOD'];

function curriculumJsonError(string $message, int $status = 400): never {
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => $message]);
    exit;
}

try {
    switch ($method) {
        case 'GET':
            session_write_close();
            if (!empty($_GET['context'])) {
                $context = getSemesterContext($db, $userId);
                echo json_encode(['ok' => true, 'context' => $context]);
                break;
            }

            if (!empty($_GET['list'])) {
                $stmt = $db->prepare(
                    'SELECT s.*, 
                            (SELECT COUNT(*) FROM curriculum_weeks cw WHERE cw.semester_id = s.id AND cw.user_id = s.user_id) AS weeks_count
                     FROM semesters s
                     WHERE s.user_id = ?
                     ORDER BY s.is_current DESC, s.start_date DESC'
                );
                $stmt->execute([$userId]);
                $semesters = $stmt->fetchAll();
                echo json_encode(['ok' => true, 'semesters' => $semesters]);
                break;
            }

            // Default: Current semester with full weeks list and context
            $semesterId = isset($_GET['semester_id']) ? (int) $_GET['semester_id'] : null;
            if ($semesterId) {
                $stmt = $db->prepare('SELECT * FROM semesters WHERE id = ? AND user_id = ? LIMIT 1');
                $stmt->execute([$semesterId, $userId]);
            } else {
                $stmt = $db->prepare('SELECT * FROM semesters WHERE user_id = ? AND is_current = 1 ORDER BY id DESC LIMIT 1');
                $stmt->execute([$userId]);
            }
            $semester = $stmt->fetch();

            if (!$semester) {
                echo json_encode([
                    'ok'       => true,
                    'semester' => null,
                    'weeks'    => [],
                    'events'   => [],
                    'context'  => getSemesterContext($db, $userId),
                ]);
                break;
            }

            $wStmt = $db->prepare('SELECT * FROM curriculum_weeks WHERE semester_id = ? AND user_id = ? ORDER BY week_number ASC');
            $wStmt->execute([$semester['id'], $userId]);
            $weeks = $wStmt->fetchAll();

            $eStmt = $db->prepare('SELECT * FROM academic_events WHERE semester_id = ? AND user_id = ? ORDER BY event_date ASC');
            $eStmt->execute([$semester['id'], $userId]);
            $events = $eStmt->fetchAll();

            echo json_encode([
                'ok'       => true,
                'semester' => $semester,
                'weeks'    => $weeks,
                'events'   => $events,
                'context'  => getSemesterContext($db, $userId),
            ]);
            break;

        case 'POST':
            $isJson = (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false);
            $body = $isJson ? (json_decode(file_get_contents('php://input'), true) ?? []) : $_POST;

            $csrf = $body['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
            verifyCsrf($csrf);

            $action = $body['action'] ?? '';

            // ----------------------------------------------------------------
            // ACTION 1: Extract Curriculum from Document (Review Screen step)
            // ----------------------------------------------------------------
            if ($action === 'extract_document') {
                $extractedText = '';
                $diagnostics = [];

                if (!empty($_FILES['document']['tmp_name'])) {
                    $file = $_FILES['document'];
                    if ($file['error'] !== UPLOAD_ERR_OK) {
                        curriculumJsonError('File upload failed. Please try again.', 422);
                    }
                    if ($file['size'] > 15 * 1024 * 1024) {
                        curriculumJsonError('File size is too large (maximum 15MB).', 422);
                    }

                    $rawContent = file_get_contents($file['tmp_name']);
                    $procResult = DocumentProcessor::extract($rawContent, $file['name'] ?? 'calendar.pdf');
                    $extractedText = $procResult['text'] ?? '';
                    $diagnostics = $procResult['diagnostics'] ?? [];

                    if (!empty($procResult['is_scanned'])) {
                        echo json_encode([
                            'ok'           => false,
                            'error_code'   => 'SCANNED_PDF_NO_OCR',
                            'error'        => "This document appears to be a scanned PDF. We couldn't read its text automatically. You can try another document or enter the calendar manually.",
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

                if (trim($extractedText) === '' || strlen(trim($extractedText)) < 15) {
                    echo json_encode([
                        'ok'           => false,
                        'error_code'   => 'EMPTY_EXTRACTION',
                        'error'        => "We couldn't read this file. Try another document or enter the information manually.",
                        'manual_entry' => true,
                        'diagnostics'  => $diagnostics,
                    ]);
                    exit;
                }

                $parsed = DocumentProcessor::parseCurriculum($extractedText);
                echo json_encode([
                    'ok'          => true,
                    'extracted'   => $parsed,
                    'preview'     => substr(trim($extractedText), 0, 200),
                    'diagnostics' => $diagnostics,
                ]);
                break;
            }

            // ----------------------------------------------------------------
            // ACTION 2: Save Confirmed Curriculum (Manual or After Review)
            // ----------------------------------------------------------------
            if ($action === 'save_curriculum') {
                $name = trim((string) ($body['semester_name'] ?? ''));
                $startDate = trim((string) ($body['start_date'] ?? ''));
                $endDate = trim((string) ($body['end_date'] ?? ''));
                $isCurrent = !empty($body['is_current']) ? 1 : 1;
                $weeks = $body['weeks'] ?? [];

                if ($name === '') {
                    curriculumJsonError('Semester name is required.', 422);
                }
                if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
                    curriculumJsonError('Valid start and end dates (YYYY-MM-DD) are required.', 422);
                }
                if ($endDate <= $startDate) {
                    curriculumJsonError('Semester end date must be after the start date.', 422);
                }
                if (!is_array($weeks) || empty($weeks)) {
                    curriculumJsonError('At least one academic week is required in the curriculum.', 422);
                }

                $validTypes = ['teaching', 'student_week', 'break', 'revision', 'exam', 'orientation', 'other'];

                $db->beginTransaction();
                try {
                    // Mark previous semesters not current if this one is current
                    if ($isCurrent) {
                        $db->prepare('UPDATE semesters SET is_current = 0 WHERE user_id = ?')->execute([$userId]);
                    }

                    // Check if semester with same name already exists for user
                    $sStmt = $db->prepare('SELECT id FROM semesters WHERE user_id = ? AND name = ? LIMIT 1');
                    $sStmt->execute([$userId, $name]);
                    $existingId = $sStmt->fetchColumn();

                    if ($existingId) {
                        $semesterId = (int) $existingId;
                        $uStmt = $db->prepare(
                            'UPDATE semesters SET start_date = ?, end_date = ?, is_current = ? WHERE id = ? AND user_id = ?'
                        );
                        $uStmt->execute([$startDate, $endDate, $isCurrent, $semesterId, $userId]);
                        $db->prepare('DELETE FROM curriculum_weeks WHERE semester_id = ? AND user_id = ?')->execute([$semesterId, $userId]);
                    } else {
                        $iStmt = $db->prepare(
                            'INSERT INTO semesters (user_id, name, start_date, end_date, is_current) VALUES (?, ?, ?, ?, ?)'
                        );
                        $iStmt->execute([$userId, $name, $startDate, $endDate, $isCurrent]);
                        $semesterId = (int) $db->lastInsertId();
                    }

                    // Insert curriculum weeks
                    $wInsert = $db->prepare(
                        'INSERT INTO curriculum_weeks (semester_id, user_id, week_number, label, week_type, start_date, end_date, notes)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                    );

                    foreach ($weeks as $idx => $w) {
                        $wNum = isset($w['week_number']) ? (int) $w['week_number'] : ($idx + 1);
                        $label = trim((string) ($w['label'] ?? "Week {$wNum}"));
                        $type = in_array($w['week_type'] ?? '', $validTypes, true) ? $w['week_type'] : 'teaching';
                        $wStart = !empty($w['start_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $w['start_date']) ? $w['start_date'] : $startDate;
                        $wEnd = !empty($w['end_date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $w['end_date']) ? $w['end_date'] : $endDate;
                        $notes = !empty($w['notes']) ? trim((string) $w['notes']) : null;

                        $wInsert->execute([
                            $semesterId,
                            $userId,
                            $wNum,
                            $label,
                            $type,
                            $wStart,
                            $wEnd,
                            $notes,
                        ]);
                    }

                    // Insert optional events if supplied
                    if (!empty($body['events']) && is_array($body['events'])) {
                        $db->prepare('DELETE FROM academic_events WHERE semester_id = ? AND user_id = ?')->execute([$semesterId, $userId]);
                        $eInsert = $db->prepare(
                            'INSERT INTO academic_events (semester_id, user_id, title, event_date, event_type, notes)
                             VALUES (?, ?, ?, ?, ?, ?)'
                        );
                        foreach ($body['events'] as $ev) {
                            $eTitle = trim((string) ($ev['title'] ?? ''));
                            $eDate = trim((string) ($ev['event_date'] ?? ''));
                            if ($eTitle !== '' && preg_match('/^\d{4}-\d{2}-\d{2}$/', $eDate)) {
                                $eType = in_array($ev['event_type'] ?? '', ['exam','holiday','deadline','ceremony','break','other'], true) ? $ev['event_type'] : 'other';
                                $eNotes = !empty($ev['notes']) ? trim((string) $ev['notes']) : null;
                                $eInsert->execute([$semesterId, $userId, $eTitle, $eDate, $eType, $eNotes]);
                            }
                        }
                    }

                    $db->commit();
                    logActivity($userId, "Semester calendar added: {$name}", 'success');

                    echo json_encode([
                        'ok'          => true,
                        'semester_id' => $semesterId,
                        'message'     => 'Semester calendar saved successfully.',
                        'context'     => getSemesterContext($db, $userId),
                    ]);
                    break;
                } catch (Throwable $e) {
                    $db->rollBack();
                    throw $e;
                }
            }

            curriculumJsonError('Invalid action specified.', 422);
            break;

        case 'DELETE':
            parse_str(file_get_contents('php://input'), $body);
            verifyCsrf($body['csrf_token'] ?? null);
            $id = filter_var($body['id'] ?? null, FILTER_VALIDATE_INT);
            if (!$id || $id < 1) {
                curriculumJsonError('A valid semester ID is required.', 422);
            }

            $stmt = $db->prepare('SELECT id, name FROM semesters WHERE id = ? AND user_id = ? LIMIT 1');
            $stmt->execute([$id, $userId]);
            $sem = $stmt->fetch();
            if (!$sem) {
                curriculumJsonError('Semester not found.', 404);
            }

            $db->prepare('DELETE FROM semesters WHERE id = ? AND user_id = ?')->execute([$id, $userId]);
            logActivity($userId, "Semester calendar deleted: {$sem['name']}", 'info');
            echo json_encode(['ok' => true, 'message' => 'Semester deleted successfully.']);
            break;

        default:
            header('Allow: GET, POST, DELETE');
            curriculumJsonError('Method not allowed.', 405);
    }
} catch (PDOException $e) {
    error_log('Curriculum API database error: ' . $e->getMessage());
    curriculumJsonError('A database error occurred while managing the semester curriculum.', 500);
} catch (Throwable $e) {
    error_log('Curriculum API error: ' . $e->getMessage());
    curriculumJsonError('An error occurred while processing your curriculum request.', 500);
}
