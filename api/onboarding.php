<?php
declare(strict_types=1);

ob_start();

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/document_processor.php';

header('Content-Type: application/json; charset=utf-8');
requireLogin();

$userId = currentUserId();
$db = getDb();
$method = $_SERVER['REQUEST_METHOD'];

function onboardingJson(array $data, int $status = 200): never {
    if (ob_get_length()) {
        ob_clean();
    }
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function onboardingError(string $message, int $status = 400, array $extra = []): never {
    onboardingJson(array_merge([
        'ok'    => false,
        'error' => $message,
    ], $extra), $status);
}

// =========================================================================
// GET: Fetch Onboarding State, Current User, and Committed Records
// =========================================================================
if ($method === 'GET') {
    $csrf = csrfToken();
    session_write_close();

    $profile = getUserProfileRow($userId, $db);

    // Fetch existing courses
    $cStmt = $db->prepare(
        'SELECT id, code, name, credits, semester, icon, color
         FROM courses
         WHERE user_id = ?
         ORDER BY code ASC'
    );
    $cStmt->execute([$userId]);
    $courses = $cStmt->fetchAll();

    // Fetch existing timetable / schedule events
    $tStmt = $db->prepare(
        'SELECT se.id, se.course_id, se.title, se.event_type, se.day_of_week, se.start_time, se.end_time,
                c.code AS course_code, c.name AS course_name
         FROM schedule_events se
         LEFT JOIN courses c ON c.id = se.course_id AND c.user_id = se.user_id
         WHERE se.user_id = ?
         ORDER BY se.day_of_week, se.start_time'
    );
    $tStmt->execute([$userId]);
    $timetable = $tStmt->fetchAll();

    // Fetch active curriculum semester & weeks
    $semStmt = $db->prepare(
        'SELECT * FROM semesters WHERE user_id = ? AND is_current = 1 ORDER BY id DESC LIMIT 1'
    );
    $semStmt->execute([$userId]);
    $semester = $semStmt->fetch();

    $weeks = [];
    if ($semester) {
        $wStmt = $db->prepare(
            'SELECT * FROM curriculum_weeks WHERE semester_id = ? AND user_id = ? ORDER BY week_number ASC'
        );
        $wStmt->execute([$semester['id'], $userId]);
        $weeks = $wStmt->fetchAll();
    }

    onboardingJson([
        'ok'         => true,
        'csrf_token' => $csrf,
        'user'       => [
            'id'                   => $userId,
            'full_name'            => (string) ($profile['full_name'] ?? ''),
            'email'                => (string) ($profile['email'] ?? ''),
            'level'                => (string) ($profile['level'] ?? ''),
            'program'              => (string) ($profile['program'] ?? ''),
            'academic_session'     => (string) ($profile['academic_session'] ?? ''),
            'current_semester'     => (string) ($profile['current_semester'] ?? ''),
            'weekly_goal_hours'    => (float) ($profile['weekly_goal_hours'] ?? 15.0),
            'preferred_study_days' => (string) ($profile['preferred_study_days'] ?? '1,2,3,4,5'),
            'preferred_study_time' => (string) ($profile['preferred_study_time'] ?? 'morning'),
            'onboarding_completed' => !empty($profile['onboarding_completed']),
            'onboarding_step'      => max(1, (int) ($profile['onboarding_step'] ?? 1)),
        ],
        'courses'    => $courses,
        'timetable'  => $timetable,
        'curriculum' => [
            'semester' => $semester ?: null,
            'weeks'    => $weeks,
        ],
    ]);
}

// =========================================================================
// POST: Actions & State Transitions
// =========================================================================
if ($method !== 'POST') {
    header('Allow: GET, POST');
    onboardingError('Method not allowed.', 405);
}

try {
    $isJson = (stripos($_SERVER['CONTENT_TYPE'] ?? '', 'application/json') !== false);
    $body = $isJson ? (json_decode(file_get_contents('php://input'), true) ?? []) : $_POST;

    $csrf = $body['csrf_token'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
    verifyCsrf($csrf);

    $action = trim((string) ($body['action'] ?? ''));

    switch ($action) {

        // -----------------------------------------------------------------
        // 1. SAVE PROFILE & STUDIES (Step 1 & 2)
        // -----------------------------------------------------------------
        case 'save_profile':
            $fullName = trim((string) ($body['full_name'] ?? ''));
            $program  = trim((string) ($body['program'] ?? ''));
            $level    = trim((string) ($body['level'] ?? ''));
            $semester = trim((string) ($body['current_semester'] ?? $body['semester'] ?? ''));
            $session  = trim((string) ($body['academic_session'] ?? $body['session'] ?? ''));

            if ($fullName === '') {
                onboardingError('Please enter your full name.', 422);
            }
            if ($program === '') {
                onboardingError('Please specify your programme or department of study.', 422);
            }
            if ($level === '') {
                onboardingError('Please select or specify your current level.', 422);
            }
            if ($semester === '') {
                onboardingError('Please select your current semester.', 422);
            }

            if (strlen($fullName) > 100 || strlen($program) > 100 || strlen($level) > 50 || strlen($semester) > 50 || strlen($session) > 50) {
                onboardingError('One or more fields exceed maximum character limits.', 422);
            }

            try {
                $upStmt = $db->prepare(
                    'UPDATE users
                     SET full_name = ?, program = ?, level = ?, current_semester = ?, academic_session = ?,
                         onboarding_step = GREATEST(COALESCE(onboarding_step, 1), 3)
                     WHERE id = ?'
                );
                $upStmt->execute([$fullName, $program, $level, $semester, $session ?: null, $userId]);
            } catch (PDOException $pe) {
                // If new columns not yet in users table
                $upStmt = $db->prepare(
                    'UPDATE users SET full_name = ?, program = ?, level = ? WHERE id = ?'
                );
                $upStmt->execute([$fullName, $program, $level, $userId]);
            }

            $_SESSION['user_name'] = $fullName;

            // Also check/create active semester record if start/end dates known or default
            if ($semester !== '') {
                $semCheck = $db->prepare('SELECT id FROM semesters WHERE user_id = ? AND is_current = 1 LIMIT 1');
                $semCheck->execute([$userId]);
                $curSemId = $semCheck->fetchColumn();

                $semFullName = $session !== '' ? "{$session} {$semester}" : $semester;
                if ($curSemId) {
                    $db->prepare('UPDATE semesters SET name = ? WHERE id = ? AND user_id = ?')
                       ->execute([$semFullName, $curSemId, $userId]);
                } else {
                    $curYear = (int) date('Y');
                    $sStart = "{$curYear}-09-01";
                    $sEnd   = date('Y-m-d', strtotime('+16 weeks', strtotime($sStart)));
                    $db->prepare('INSERT INTO semesters (user_id, name, start_date, end_date, is_current) VALUES (?, ?, ?, ?, 1)')
                       ->execute([$userId, $semFullName, $sStart, $sEnd]);
                }
            }

            logActivity($userId, "Updated academic profile: {$program}, {$level} ({$semester})", 'info');

            onboardingJson([
                'ok'      => true,
                'step'    => 3,
                'message' => 'Academic profile saved successfully.',
                'user'    => [
                    'full_name'        => $fullName,
                    'program'          => $program,
                    'level'            => $level,
                    'current_semester' => $semester,
                    'academic_session' => $session,
                ],
            ]);
            break;

        // -----------------------------------------------------------------
        // 2. PARSE DOCUMENT OR TEXT (Course Form, Timetable, Curriculum)
        // -----------------------------------------------------------------
        case 'parse_document':
            $domain = strtolower(trim((string) ($body['domain'] ?? 'courses')));
            if (!in_array($domain, ['courses', 'timetable', 'curriculum'], true)) {
                onboardingError('Invalid academic domain for parsing.', 422);
            }

            $content = '';
            $filename = 'document.txt';

            if (!empty($_FILES['document']['tmp_name'])) {
                $file = $_FILES['document'];
                if ($file['error'] !== UPLOAD_ERR_OK) {
                    onboardingError('File upload failed. Please try again.', 422);
                }
                if ($file['size'] > 15 * 1024 * 1024) {
                    onboardingError('File size is too large (maximum 15MB).', 422);
                }
                $filename = $file['name'] ?? 'document.pdf';
                $content = (string) file_get_contents($file['tmp_name']);
            } elseif (!empty($body['text'])) {
                $content = (string) $body['text'];
                $filename = 'pasted_text.txt';
            } else {
                onboardingError('Please upload a document (.pdf, .docx, .txt) or enter text.', 422);
            }

            $extracted = DocumentProcessor::extract($content, $filename);
            $diagnostics = $extracted['diagnostics'] ?? [];
            $extractedText = trim((string) ($extracted['text'] ?? ''));

            if (!empty($extracted['is_scanned'])) {
                onboardingJson([
                    'ok'          => false,
                    'error'       => "This document appears to be a scanned image. We couldn't read its text automatically. Please try another document or add your entries directly.",
                    'is_scanned'  => true,
                    'diagnostics' => $diagnostics,
                ]);
            }

            if (mb_strlen($extractedText, 'UTF-8') < 10) {
                onboardingJson([
                    'ok'          => false,
                    'error'       => "We couldn't read any text from this file. Please check the document format or paste the text directly.",
                    'diagnostics' => $diagnostics,
                ]);
            }

            switch ($domain) {
                case 'courses':
                    $parsed = DocumentProcessor::parseCourses($extractedText, $db, $userId);
                    // Check against already saved courses in DB
                    $existStmt = $db->prepare('SELECT UPPER(code) FROM courses WHERE user_id = ?');
                    $existStmt->execute([$userId]);
                    $existing = array_flip($existStmt->fetchAll(PDO::FETCH_COLUMN));

                    foreach ($parsed as &$p) {
                        $p['already_saved'] = isset($existing[strtoupper($p['code'] ?? '')]);
                    }
                    unset($p);

                    onboardingJson([
                        'ok'          => true,
                        'domain'      => 'courses',
                        'items'       => $parsed,
                        'found_count' => count($parsed),
                        'diagnostics' => $diagnostics,
                    ]);
                    break;

                case 'timetable':
                    $parsed = DocumentProcessor::parseTimetable($extractedText, $db, $userId);
                    onboardingJson([
                        'ok'          => true,
                        'domain'      => 'timetable',
                        'items'       => $parsed,
                        'found_count' => count($parsed),
                        'diagnostics' => $diagnostics,
                    ]);
                    break;

                case 'curriculum':
                    $parsed = DocumentProcessor::parseCurriculum($extractedText);
                    onboardingJson([
                        'ok'          => true,
                        'domain'      => 'curriculum',
                        'extracted'   => $parsed,
                        'found_count' => count($parsed['weeks'] ?? []),
                        'diagnostics' => $diagnostics,
                    ]);
                    break;
            }
            break;

        // -----------------------------------------------------------------
        // 3. COMMIT COURSES (Step 3)
        // -----------------------------------------------------------------
        case 'save_courses':
            $courses = $body['courses'] ?? $body['items'] ?? [];
            if (!is_array($courses) || empty($courses)) {
                onboardingError('Please provide at least one valid course to save.', 422);
            }

            // Fetch existing courses to avoid duplicate codes
            $cStmt = $db->prepare('SELECT UPPER(code) FROM courses WHERE user_id = ?');
            $cStmt->execute([$userId]);
            $existingCodes = array_flip($cStmt->fetchAll(PDO::FETCH_COLUMN));

            // Default semester
            $userProf = getUserProfileRow($userId, $db);
            $defaultSemester = trim((string) ($userProf['current_semester'] ?? 'First Semester'));

            $defaultIcons = ['📘', '💻', '🗄️', '📊', '⚛️', '📖', '🔬', '📐', '🧠', '🌐'];
            $defaultColors = ['#059669', '#166534', '#0284c7', '#7c3aed', '#d97706', '#db2777', '#0d9488', '#2563eb'];

            $insertStmt = $db->prepare(
                'INSERT INTO courses (user_id, code, name, lecturer, credits, semester, icon, color)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );

            $imported = 0;
            $skipped = 0;
            $addedCodes = [];

            foreach ($courses as $idx => $c) {
                $code = strtoupper(trim((string) ($c['code'] ?? '')));
                $name = trim((string) ($c['name'] ?? ''));
                $credits = max(1, min(6, (int) ($c['credits'] ?? 3)));
                $lecturer = trim((string) ($c['lecturer'] ?? ''));
                $sem = trim((string) ($c['semester'] ?? '')) ?: $defaultSemester;

                if ($code === '' || $name === '') {
                    continue;
                }

                if (isset($existingCodes[$code])) {
                    $skipped++;
                    continue;
                }

                $icon = $c['icon'] ?? $defaultIcons[$idx % count($defaultIcons)];
                $color = $c['color'] ?? $defaultColors[$idx % count($defaultColors)];

                $insertStmt->execute([
                    $userId,
                    $code,
                    $name,
                    $lecturer ?: null,
                    $credits,
                    $sem ?: null,
                    $icon,
                    $color,
                ]);

                $existingCodes[$code] = true;
                $imported++;
                $addedCodes[] = $code;
            }

            // Advance onboarding step to 4
            try {
                $db->prepare('UPDATE users SET onboarding_step = GREATEST(COALESCE(onboarding_step, 1), 4) WHERE id = ?')
                   ->execute([$userId]);
            } catch (Throwable $e) {}

            if ($imported > 0) {
                logActivity($userId, "Imported {$imported} course(s): " . implode(', ', array_slice($addedCodes, 0, 4)), 'success');
            }

            // Return current list of courses
            $cStmt = $db->prepare('SELECT id, code, name, credits, semester, icon, color FROM courses WHERE user_id = ? ORDER BY code ASC');
            $cStmt->execute([$userId]);
            $allCourses = $cStmt->fetchAll();

            onboardingJson([
                'ok'             => true,
                'step'           => 4,
                'imported_count' => $imported,
                'skipped_count'  => $skipped,
                'courses'        => $allCourses,
                'message'        => "{$imported} course(s) saved to your study planner." . ($skipped > 0 ? " ({$skipped} duplicates skipped)" : ""),
            ]);
            break;

        // -----------------------------------------------------------------
        // 4. COMMIT TIMETABLE / CLASSES (Step 4 - Optional)
        // -----------------------------------------------------------------
        case 'save_timetable':
            $classes = $body['classes'] ?? $body['items'] ?? [];
            if (!is_array($classes) || empty($classes)) {
                // If user submits empty timetable, advance step to 5 cleanly
                try {
                    $db->prepare('UPDATE users SET onboarding_step = GREATEST(COALESCE(onboarding_step, 1), 5) WHERE id = ?')
                       ->execute([$userId]);
                } catch (Throwable $e) {}

                onboardingJson([
                    'ok'             => true,
                    'step'           => 5,
                    'imported_count' => 0,
                    'message'        => 'Timetable skipped.',
                ]);
            }

            // Build course map for matching
            $cStmt = $db->prepare('SELECT UPPER(code) as code, id FROM courses WHERE user_id = ?');
            $cStmt->execute([$userId]);
            $courseMap = $cStmt->fetchAll(PDO::FETCH_KEY_PAIR);
            $courseMapClean = [];
            $validCourseIds = [];
            foreach ($courseMap as $cCode => $cId) {
                $clean = preg_replace('/[^A-Za-z0-9]/', '', (string)$cCode);
                $courseMapClean[$clean] = (int) $cId;
                $validCourseIds[(int) $cId] = true;
            }

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
                $rawDay = strtolower(trim((string) ($item['day_of_week'] ?? $item['day'] ?? '1')));
                $dow = $dayMap[$rawDay] ?? 1;

                $cCode = strtoupper(trim((string) ($item['course_code'] ?? '')));
                $cleanCode = preg_replace('/[^A-Za-z0-9]/', '', $cCode);

                $courseId = null;
                if (!empty($item['course_id']) && isset($validCourseIds[(int)$item['course_id']])) {
                    $courseId = (int) $item['course_id'];
                } elseif (isset($courseMap[$cCode])) {
                    $courseId = (int) $courseMap[$cCode];
                } elseif (isset($courseMapClean[$cleanCode])) {
                    $courseId = (int) $courseMapClean[$cleanCode];
                }

                $start = trim((string) ($item['start_time'] ?? '09:00:00'));
                $end   = trim((string) ($item['end_time'] ?? '11:00:00'));
                $startTime = DocumentProcessor::standardizeTime($start);
                $endTime   = DocumentProcessor::standardizeTime($end);
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

                $location = trim((string) ($item['location'] ?? ''));
                if ($location && $location !== 'Campus' && !str_contains($title, $location)) {
                    $title .= " ({$location})";
                }

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

            try {
                $db->prepare('UPDATE users SET onboarding_step = GREATEST(COALESCE(onboarding_step, 1), 5) WHERE id = ?')
                   ->execute([$userId]);
            } catch (Throwable $e) {}

            if ($imported > 0) {
                logActivity($userId, "Added {$imported} class schedule slots during onboarding", 'success');
            }

            onboardingJson([
                'ok'             => true,
                'step'           => 5,
                'imported_count' => $imported,
                'skipped_count'  => $skipped,
                'message'        => "{$imported} class(es) added to your weekly schedule.",
            ]);
            break;

        // -----------------------------------------------------------------
        // 5. COMMIT CURRICULUM / ACADEMIC CALENDAR (Step 5 - Optional)
        // -----------------------------------------------------------------
        case 'save_curriculum':
            $semName   = trim((string) ($body['semester_name'] ?? ''));
            $startDate = trim((string) ($body['start_date'] ?? ''));
            $endDate   = trim((string) ($body['end_date'] ?? ''));
            $weeks     = $body['weeks'] ?? $body['items'] ?? [];

            if ($semName === '') {
                $userProf = getUserProfileRow($userId, $db);
                $semName = trim((string) ($userProf['current_semester'] ?? 'First Semester'));
            }

            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $endDate)) {
                onboardingError('Valid start and end dates (YYYY-MM-DD) are required for the semester.', 422);
            }
            if ($endDate <= $startDate) {
                onboardingError('Semester end date must be after the start date.', 422);
            }

            $db->beginTransaction();

            // Find or insert active semester
            $semStmt = $db->prepare('SELECT id FROM semesters WHERE user_id = ? AND is_current = 1 LIMIT 1');
            $semStmt->execute([$userId]);
            $existingSemId = $semStmt->fetchColumn();

            if ($existingSemId) {
                $semesterId = (int) $existingSemId;
                $db->prepare('UPDATE semesters SET name = ?, start_date = ?, end_date = ? WHERE id = ? AND user_id = ?')
                   ->execute([$semName, $startDate, $endDate, $semesterId, $userId]);
            } else {
                $db->prepare('UPDATE semesters SET is_current = 0 WHERE user_id = ?')->execute([$userId]);
                $db->prepare('INSERT INTO semesters (user_id, name, start_date, end_date, is_current) VALUES (?, ?, ?, ?, 1)')
                   ->execute([$userId, $semName, $startDate, $endDate]);
                $semesterId = (int) $db->lastInsertId();
            }

            // Replace weeks if provided
            if (is_array($weeks) && !empty($weeks)) {
                $db->prepare('DELETE FROM curriculum_weeks WHERE semester_id = ? AND user_id = ?')->execute([$semesterId, $userId]);

                $insWeek = $db->prepare(
                    'INSERT INTO curriculum_weeks (semester_id, user_id, week_number, label, week_type, start_date, end_date, notes)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
                );

                $dbValidTypes = ['teaching', 'student_week', 'revision', 'exam', 'break', 'other'];
                $savedWeeks = 0;

                foreach ($weeks as $idx => $w) {
                    $wNum  = max(1, min(52, (int) ($w['week_number'] ?? ($idx + 1))));
                    $label = trim((string) ($w['label'] ?? "Teaching Week {$wNum}"));
                    $wType = strtolower(trim((string) ($w['week_type'] ?? 'teaching')));
                    if ($wType === 'orientation') $wType = 'other';
                    if (!in_array($wType, $dbValidTypes, true)) $wType = 'teaching';

                    $wStart = trim((string) ($w['start_date'] ?? ''));
                    $wEnd   = trim((string) ($w['end_date'] ?? ''));
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
                    $savedWeeks++;
                }
            }

            $db->commit();

            try {
                $db->prepare('UPDATE users SET onboarding_step = GREATEST(COALESCE(onboarding_step, 1), 6) WHERE id = ?')
                   ->execute([$userId]);
            } catch (Throwable $e) {}

            logActivity($userId, "Configured semester calendar: {$semName}", 'success');

            onboardingJson([
                'ok'          => true,
                'step'        => 6,
                'semester_id' => $semesterId,
                'message'     => 'Academic calendar saved successfully.',
            ]);
            break;

        // -----------------------------------------------------------------
        // 6. SKIP STEP (Optional Timetable or Curriculum)
        // -----------------------------------------------------------------
        case 'skip_step':
            $currentStep = (int) ($body['step'] ?? 4);
            $nextStep = $currentStep + 1;

            try {
                $db->prepare('UPDATE users SET onboarding_step = GREATEST(COALESCE(onboarding_step, 1), ?) WHERE id = ?')
                   ->execute([$nextStep, $userId]);
            } catch (Throwable $e) {}

            onboardingJson([
                'ok'   => true,
                'step' => $nextStep,
            ]);
            break;

        // -----------------------------------------------------------------
        // 7. COMMIT STUDY GOALS & PREFERENCES (Step 7 - Required)
        // -----------------------------------------------------------------
        case 'save_goals':
            $goalHours = max(1.0, min(100.0, (float) ($body['weekly_goal_hours'] ?? 15.0)));
            $time      = strtolower(trim((string) ($body['preferred_study_time'] ?? 'morning')));
            if (!in_array($time, ['morning', 'afternoon', 'evening', 'flexible'], true)) {
                $time = 'morning';
            }

            $rawDays = $body['preferred_study_days'] ?? '1,2,3,4,5';
            $days = is_array($rawDays)
                ? implode(',', array_filter(array_map('intval', $rawDays), fn($d) => $d >= 0 && $d <= 6))
                : preg_replace('/[^0-6,]/', '', (string) $rawDays);
            if ($days === '') {
                $days = '1,2,3,4,5';
            }

            try {
                $gStmt = $db->prepare(
                    'UPDATE users
                     SET weekly_goal_hours = ?, preferred_study_time = ?, preferred_study_days = ?,
                         onboarding_step = GREATEST(COALESCE(onboarding_step, 1), 7)
                     WHERE id = ?'
                );
                $gStmt->execute([$goalHours, $time, $days, $userId]);
            } catch (Throwable $e) {
                onboardingError('Failed to save study goals.', 500);
            }

            onboardingJson([
                'ok'                   => true,
                'step'                 => 7,
                'weekly_goal_hours'    => $goalHours,
                'preferred_study_time' => $time,
                'preferred_study_days' => $days,
                'message'              => 'Study goals saved successfully.',
            ]);
            break;

        // -----------------------------------------------------------------
        // 8. FINAL COMPLETE ONBOARDING TRANSACTION
        // -----------------------------------------------------------------
        case 'complete':
            // Verify profile is present
            $userProf = getUserProfileRow($userId, $db);
            if (empty($userProf['full_name']) || empty($userProf['program'])) {
                onboardingError('Please complete your academic profile before finishing onboarding.', 422);
            }

            // Persist completion state
            try {
                $cStmt = $db->prepare(
                    'UPDATE users
                     SET onboarding_completed = 1, onboarding_step = 7
                     WHERE id = ?'
                );
                $cStmt->execute([$userId]);
            } catch (PDOException $e) {
                // If column missing in legacy DB
                $cStmt = $db->prepare('UPDATE users SET tour_completed = 1 WHERE id = ?');
                $cStmt->execute([$userId]);
            }

            logActivity($userId, 'Completed academic onboarding and configured study goals 🎉', 'success');

            // Emit welcome notification
            try {
                $nStmt = $db->prepare(
                    "INSERT INTO notifications (user_id, channel, message, send_at)
                     VALUES (?, 'in_app', 'Welcome to Study Planner! Your semester profile, courses, and study goals are configured.', NOW())"
                );
                $nStmt->execute([$userId]);
            } catch (Throwable $e) {}

            onboardingJson([
                'ok'        => true,
                'completed' => true,
                'redirect'  => 'dashboard.php',
                'message'   => 'Welcome aboard! Your study planner is ready.',
            ]);
            break;

        default:
            onboardingError("Unrecognized action '{$action}'.", 422);
    }

} catch (Throwable $e) {
    error_log("[ONBOARDING ERROR] " . $e->getMessage());
    onboardingError('An error occurred while processing your request: ' . $e->getMessage(), 500);
}
