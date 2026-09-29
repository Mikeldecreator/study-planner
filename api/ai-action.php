<?php

declare(strict_types=1);

/**
 * FOUNDATION 7B / STAGE B: SAFE AI ACTION EXECUTION ENDPOINT
 *
 * Controlled execution endpoint for user-confirmed AI planner actions.
 *
 * Security & Mandates:
 * 1. Authenticated: Student must be logged in via session (requireLogin).
 * 2. User Isolation: $userId is derived exclusively from currentUserId(). Never accepted from client.
 * 3. CSRF Protection: Validates CSRF token on every request via verifyCsrf().
 * 4. Whitelisted Actions: Only permits strictly defined actions:
 *    - create_task
 *    - update_task
 *    - complete_task
 *    - create_schedule_item
 *    - update_schedule_item
 *    - update_study_goal
 * 5. Ownership Verification: Confirms tasks, courses, and schedules belong to the active user.
 * 6. Business Logic Reuse: Integrates with existing notifications, intelligence, and activity logging.
 * 7. Zero Arbitrary Code: Strictly no arbitrary SQL, PHP, or command execution.
 */

ob_start();

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/ai.php';

header('Content-Type: application/json; charset=utf-8');

function aiActionJson(array $data, int $status = 200): never {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// 1. Enforce active authentication
requireLogin();

// 2. Enforce POST method
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'POST') {
    aiActionJson([
        'ok'    => false,
        'error' => 'Method Not Allowed. POST is required.'
    ], 405);
}

$userId = currentUserId();
$db = getDb();

// 3. Parse JSON request body
$rawBody = file_get_contents('php://input');
$body = json_decode($rawBody ?: '', true);
if (!is_array($body)) {
    $body = $_POST;
}

// 4. Verify CSRF token
$csrfToken = $body['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
verifyCsrf($csrfToken);

// 5. Validate action type against strict whitelist
$action = trim((string)($body['action'] ?? ($body['type'] ?? '')));
$allowedActions = [
    'create_task',
    'update_task',
    'complete_task',
    'create_schedule_item',
    'update_schedule_item',
    'update_study_goal'
];

if (!in_array($action, $allowedActions, true)) {
    aiActionJson([
        'ok'    => false,
        'error' => "Action '{$action}' is not supported or permitted.",
        'code'  => 'UNSUPPORTED_ACTION'
    ], 422);
}

$payload = is_array($body['payload'] ?? null) ? $body['payload'] : [];

// 6. Execute whitelisted action
switch ($action) {

    // -------------------------------------------------------------------------
    // ACTION: CREATE TASK (Add Work)
    // -------------------------------------------------------------------------
    case 'create_task':
        $title = trim((string)($payload['title'] ?? ''));
        $dueAtRaw = trim((string)($payload['due_at'] ?? $payload['due_date'] ?? ''));

        if ($title === '' || $dueAtRaw === '') {
            aiActionJson([
                'ok'    => false,
                'error' => 'Task title and due date are required.',
                'code'  => 'INVALID_PAYLOAD'
            ], 422);
        }

        // Validate and normalize due date
        $cleanDueAt = str_replace('T', ' ', $dueAtRaw);
        $dueDate = DateTime::createFromFormat('Y-m-d H:i:s', $cleanDueAt)
            ?: DateTime::createFromFormat('Y-m-d H:i', $cleanDueAt)
            ?: DateTime::createFromFormat('Y-m-d', $dueAtRaw);

        if (!$dueDate) {
            $ts = strtotime($dueAtRaw);
            if ($ts !== false) {
                $dueDate = (new DateTime())->setTimestamp($ts);
            }
        }

        if (!$dueDate) {
            aiActionJson([
                'ok'    => false,
                'error' => 'Invalid deadline format. Please specify a valid date and time.',
                'code'  => 'INVALID_DATE'
            ], 422);
        }

        // Validate course ownership
        $courseId = null;
        if (!empty($payload['course_id'])) {
            $courseId = ownedCourseIdOrNull($db, $payload['course_id'], $userId);
            if ($courseId === null) {
                aiActionJson([
                    'ok'    => false,
                    'error' => 'Selected course does not exist or does not belong to your account.',
                    'code'  => 'COURSE_NOT_OWNED'
                ], 422);
            }
        } elseif (!empty($payload['course_code'])) {
            $cCode = trim((string)$payload['course_code']);
            $stmtC = $db->prepare('SELECT id FROM courses WHERE user_id = ? AND LOWER(code) = LOWER(?) LIMIT 1');
            $stmtC->execute([$userId, $cCode]);
            $foundCid = $stmtC->fetchColumn();
            if ($foundCid) {
                $courseId = (int)$foundCid;
            }
        }

        $type = normalizeTaskType((string)($payload['type'] ?? 'assignment'));
        $priority = normalizeTaskPriority((string)($payload['priority'] ?? 'medium'));
        $description = trim((string)($payload['description'] ?? '')) ?: null;
        $dueAtFormatted = $dueDate->format('Y-m-d H:i:s');

        $stmt = $db->prepare('
            INSERT INTO tasks (
                user_id, course_id, title, description, type, priority,
                status, progress_percent, duration_hours, due_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, 0, 0, ?)
        ');
        $stmt->execute([
            $userId,
            $courseId,
            $title,
            $description,
            $type,
            $priority,
            'pending',
            $dueAtFormatted
        ]);

        $taskId = (int)$db->lastInsertId();

        // Application activity log
        logActivity($userId, "New task added: {$title}", 'info');

        // Application reminder notification (lead hours before deadline)
        $reminderLead = defined('REMINDER_LEAD_HOURS') ? (int)REMINDER_LEAD_HOURS : 24;
        $reminderTime = (clone $dueDate)->modify("-{$reminderLead} hours")->format('Y-m-d H:i:s');

        try {
            $stmtNotif = $db->prepare('
                INSERT INTO notifications (user_id, task_id, channel, message, send_at)
                VALUES (?, ?, "in_app", ?, ?)
            ');
            $stmtNotif->execute([
                $userId,
                $taskId,
                "\"{$title}\" is due soon",
                $reminderTime
            ]);

            if (defined('EMAIL_ENABLED') && EMAIL_ENABLED) {
                $stmtNotif->execute([
                    $userId,
                    $taskId,
                    'email',
                    "\"{$title}\" is due soon",
                    $reminderTime
                ]);
            }
        } catch (\Throwable $e) {
            // Non-critical notification failure
            error_log('Notification insertion notice: ' . $e->getMessage());
        }

        // Fetch inserted task row with course details
        $stmtTask = $db->prepare('
            SELECT t.*, c.code AS course_code, c.name AS course_name, c.color AS course_color
            FROM tasks t
            LEFT JOIN courses c ON c.id = t.course_id AND c.user_id = t.user_id
            WHERE t.id = ? AND t.user_id = ?
        ');
        $stmtTask->execute([$taskId, $userId]);
        $createdTask = $stmtTask->fetch(PDO::FETCH_ASSOC);

        aiActionJson([
            'ok'      => true,
            'action'  => 'create_task',
            'id'      => $taskId,
            'message' => "Successfully added '{$title}' to your work.",
            'task'    => $createdTask ?: [
                'id'       => $taskId,
                'title'    => $title,
                'due_at'   => $dueAtFormatted,
                'status'   => 'pending',
                'priority' => $priority
            ]
        ], 201);
        break;

    // -------------------------------------------------------------------------
    // ACTION: UPDATE TASK (Move / Reschedule / Edit Work)
    // -------------------------------------------------------------------------
    case 'update_task':
        $taskId = (int)($payload['task_id'] ?? $payload['id'] ?? 0);
        if ($taskId <= 0) {
            aiActionJson([
                'ok'    => false,
                'error' => 'Task ID is required for updating work.',
                'code'  => 'MISSING_TASK_ID'
            ], 422);
        }

        // Verify task ownership
        $stmt = $db->prepare('SELECT * FROM tasks WHERE id = ? AND user_id = ?');
        $stmt->execute([$taskId, $userId]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$existing) {
            aiActionJson([
                'ok'    => false,
                'error' => 'Task not found or does not belong to your account.',
                'code'  => 'TASK_NOT_FOUND'
            ], 404);
        }

        $newTitle = trim((string)($payload['title'] ?? $existing['title']));
        if ($newTitle === '') {
            $newTitle = $existing['title'];
        }

        $newDueAt = $existing['due_at'];
        if (!empty($payload['due_at'])) {
            $dueAtRaw = trim((string)$payload['due_at']);
            $cleanDueAt = str_replace('T', ' ', $dueAtRaw);
            $parsedDate = DateTime::createFromFormat('Y-m-d H:i:s', $cleanDueAt)
                ?: DateTime::createFromFormat('Y-m-d H:i', $cleanDueAt)
                ?: DateTime::createFromFormat('Y-m-d', $dueAtRaw);

            if (!$parsedDate) {
                $ts = strtotime($dueAtRaw);
                if ($ts !== false) {
                    $parsedDate = (new DateTime())->setTimestamp($ts);
                }
            }

            if ($parsedDate) {
                $newDueAt = $parsedDate->format('Y-m-d H:i:s');
            }
        }

        $newPriority = !empty($payload['priority'])
            ? normalizeTaskPriority((string)$payload['priority'])
            : $existing['priority'];

        $newCourseId = $existing['course_id'];
        if (array_key_exists('course_id', $payload)) {
            $newCourseId = ownedCourseIdOrNull($db, $payload['course_id'], $userId);
        }

        $stmtUpdate = $db->prepare('
            UPDATE tasks SET
                title = ?,
                due_at = ?,
                priority = ?,
                course_id = ?
            WHERE id = ? AND user_id = ?
        ');
        $stmtUpdate->execute([
            $newTitle,
            $newDueAt,
            $newPriority,
            $newCourseId,
            $taskId,
            $userId
        ]);

        logActivity($userId, "Task updated: {$newTitle}", 'info');

        aiActionJson([
            'ok'      => true,
            'action'  => 'update_task',
            'id'      => $taskId,
            'message' => "Successfully updated '{$newTitle}'.",
            'task'    => [
                'id'       => $taskId,
                'title'    => $newTitle,
                'due_at'   => $newDueAt,
                'priority' => $newPriority
            ]
        ], 200);
        break;

    // -------------------------------------------------------------------------
    // ACTION: COMPLETE TASK (Mark Work As Completed)
    // -------------------------------------------------------------------------
    case 'complete_task':
        $taskId = (int)($payload['task_id'] ?? $payload['id'] ?? 0);
        if ($taskId <= 0) {
            aiActionJson([
                'ok'    => false,
                'error' => 'Task ID is required to complete work.',
                'code'  => 'MISSING_TASK_ID'
            ], 422);
        }

        // Verify task ownership
        $stmt = $db->prepare('SELECT * FROM tasks WHERE id = ? AND user_id = ?');
        $stmt->execute([$taskId, $userId]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$existing) {
            aiActionJson([
                'ok'    => false,
                'error' => 'Task not found or does not belong to your account.',
                'code'  => 'TASK_NOT_FOUND'
            ], 404);
        }

        $wasAlreadyCompleted = ($existing['status'] === 'completed');
        $completedAt = date('Y-m-d H:i:s');

        $stmtComp = $db->prepare("
            UPDATE tasks SET
                status = 'completed',
                progress_percent = 100,
                completed_at = ?
            WHERE id = ? AND user_id = ?
        ");
        $stmtComp->execute([$completedAt, $taskId, $userId]);

        if (!$wasAlreadyCompleted) {
            logActivity($userId, "You completed {$existing['title']}", 'success');

            // Mark pending reminders for this task as read
            try {
                $stmtNotif = $db->prepare('
                    UPDATE notifications SET read_at = NOW()
                    WHERE task_id = ? AND user_id = ? AND read_at IS NULL
                ');
                $stmtNotif->execute([$taskId, $userId]);

                // Emit task completed notification
                $completedTs = strtotime($completedAt);
                $eventKey = "task_completed_{$taskId}_{$completedTs}";
                $notifMsg = "Your work '{$existing['title']}' has been marked as completed.";

                $stmtCheck = $db->prepare('SELECT id FROM notifications WHERE user_id = ? AND event_key = ? LIMIT 1');
                $stmtCheck->execute([$userId, $eventKey]);
                if (!$stmtCheck->fetch()) {
                    $stmtIns = $db->prepare("
                        INSERT INTO notifications (user_id, task_id, channel, event_key, message, send_at)
                        VALUES (?, ?, 'in_app', ?, ?, NOW())
                    ");
                    $stmtIns->execute([$userId, $taskId, $eventKey, $notifMsg]);
                }
            } catch (\Throwable $e) {
                error_log('Task completion notification notice: ' . $e->getMessage());
            }
        }

        aiActionJson([
            'ok'      => true,
            'action'  => 'complete_task',
            'id'      => $taskId,
            'message' => "Marked '{$existing['title']}' as completed.",
            'task'    => [
                'id'           => $taskId,
                'title'        => $existing['title'],
                'status'       => 'completed',
                'completed_at' => $completedAt
            ]
        ], 200);
        break;

    // -------------------------------------------------------------------------
    // ACTION: CREATE SCHEDULE ITEM (Add Class / Timetable Session)
    // -------------------------------------------------------------------------
    case 'create_schedule_item':
        $title = trim((string)($payload['title'] ?? ''));
        $dayOfWeek = (int)($payload['day_of_week'] ?? -1);
        $startTime = trim((string)($payload['start_time'] ?? ''));
        $endTime = trim((string)($payload['end_time'] ?? ''));

        if ($title === '' || $dayOfWeek < 0 || $dayOfWeek > 6 || $startTime === '' || $endTime === '') {
            aiActionJson([
                'ok'    => false,
                'error' => 'Title, day of week (0–6), start time, and end time are required.',
                'code'  => 'INVALID_SCHEDULE_PAYLOAD'
            ], 422);
        }

        // Format times to HH:MM:SS
        if (strlen($startTime) === 5) $startTime .= ':00';
        if (strlen($endTime) === 5) $endTime .= ':00';

        if ($endTime <= $startTime) {
            aiActionJson([
                'ok'    => false,
                'error' => 'End time must be after start time.',
                'code'  => 'INVALID_TIME_RANGE'
            ], 422);
        }

        // Conflict check against existing schedule events for this user
        $stmtConflict = $db->prepare('
            SELECT id, title, start_time, end_time
            FROM schedule_events
            WHERE user_id = ? AND day_of_week = ?
              AND NOT (end_time <= ? OR start_time >= ?)
            LIMIT 1
        ');
        $stmtConflict->execute([$userId, $dayOfWeek, $startTime, $endTime]);
        $conflict = $stmtConflict->fetch(PDO::FETCH_ASSOC);

        if ($conflict) {
            $confStart = date('g:i A', strtotime($conflict['start_time']));
            $confEnd = date('g:i A', strtotime($conflict['end_time']));
            aiActionJson([
                'ok'    => false,
                'error' => "This session conflicts with an existing class: '{$conflict['title']}' ({$confStart} – {$confEnd}).",
                'code'  => 'SCHEDULE_CONFLICT'
            ], 409);
        }

        // Validate course ownership
        $courseId = null;
        if (!empty($payload['course_id'])) {
            $courseId = ownedCourseIdOrNull($db, $payload['course_id'], $userId);
        } elseif (!empty($payload['course_code'])) {
            $cCode = trim((string)$payload['course_code']);
            $stmtC = $db->prepare('SELECT id FROM courses WHERE user_id = ? AND LOWER(code) = LOWER(?) LIMIT 1');
            $stmtC->execute([$userId, $cCode]);
            $foundCid = $stmtC->fetchColumn();
            if ($foundCid) {
                $courseId = (int)$foundCid;
            }
        }

        $eventType = trim((string)($payload['event_type'] ?? 'class'));
        if (!in_array($eventType, ['class', 'study', 'exam', 'other'], true)) {
            $eventType = 'class';
        }

        $stmtIns = $db->prepare('
            INSERT INTO schedule_events (
                user_id, course_id, task_id, title, event_type, day_of_week, start_time, end_time
            ) VALUES (?, ?, NULL, ?, ?, ?, ?, ?)
        ');
        $stmtIns->execute([
            $userId,
            $courseId,
            $title,
            $eventType,
            $dayOfWeek,
            $startTime,
            $endTime
        ]);

        $schedId = (int)$db->lastInsertId();
        logActivity($userId, "Added class to schedule: {$title}", 'info');

        aiActionJson([
            'ok'      => true,
            'action'  => 'create_schedule_item',
            'id'      => $schedId,
            'message' => "Added '{$title}' to your schedule.",
            'event'   => [
                'id'          => $schedId,
                'title'       => $title,
                'day_of_week' => $dayOfWeek,
                'start_time'  => $startTime,
                'end_time'    => $endTime,
                'event_type'  => $eventType
            ]
        ], 201);
        break;

    // -------------------------------------------------------------------------
    // ACTION: UPDATE SCHEDULE ITEM (Move / Edit Timetable Class)
    // -------------------------------------------------------------------------
    case 'update_schedule_item':
        $schedId = (int)($payload['schedule_id'] ?? $payload['id'] ?? 0);
        if ($schedId <= 0) {
            aiActionJson([
                'ok'    => false,
                'error' => 'Schedule ID is required.',
                'code'  => 'MISSING_SCHEDULE_ID'
            ], 422);
        }

        $stmt = $db->prepare('SELECT * FROM schedule_events WHERE id = ? AND user_id = ?');
        $stmt->execute([$schedId, $userId]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$existing) {
            aiActionJson([
                'ok'    => false,
                'error' => 'Schedule item not found or does not belong to your account.',
                'code'  => 'SCHEDULE_NOT_FOUND'
            ], 404);
        }

        $newTitle = trim((string)($payload['title'] ?? $existing['title']));
        $newDow = array_key_exists('day_of_week', $payload) ? (int)$payload['day_of_week'] : (int)$existing['day_of_week'];
        $newStart = !empty($payload['start_time']) ? trim((string)$payload['start_time']) : $existing['start_time'];
        $newEnd = !empty($payload['end_time']) ? trim((string)$payload['end_time']) : $existing['end_time'];

        if (strlen($newStart) === 5) $newStart .= ':00';
        if (strlen($newEnd) === 5) $newEnd .= ':00';

        if ($newEnd <= $newStart) {
            aiActionJson([
                'ok'    => false,
                'error' => 'End time must be after start time.',
                'code'  => 'INVALID_TIME_RANGE'
            ], 422);
        }

        // Check conflicts against OTHER events
        $stmtConflict = $db->prepare('
            SELECT id, title, start_time, end_time
            FROM schedule_events
            WHERE user_id = ? AND day_of_week = ? AND id != ?
              AND NOT (end_time <= ? OR start_time >= ?)
            LIMIT 1
        ');
        $stmtConflict->execute([$userId, $newDow, $schedId, $newStart, $newEnd]);
        $conflict = $stmtConflict->fetch(PDO::FETCH_ASSOC);

        if ($conflict) {
            $confStart = date('g:i A', strtotime($conflict['start_time']));
            $confEnd = date('g:i A', strtotime($conflict['end_time']));
            aiActionJson([
                'ok'    => false,
                'error' => "This session conflicts with an existing class: '{$conflict['title']}' ({$confStart} – {$confEnd}).",
                'code'  => 'SCHEDULE_CONFLICT'
            ], 409);
        }

        $stmtUp = $db->prepare('
            UPDATE schedule_events SET
                title = ?,
                day_of_week = ?,
                start_time = ?,
                end_time = ?
            WHERE id = ? AND user_id = ?
        ');
        $stmtUp->execute([
            $newTitle,
            $newDow,
            $newStart,
            $newEnd,
            $schedId,
            $userId
        ]);

        logActivity($userId, "Updated schedule session: {$newTitle}", 'info');

        aiActionJson([
            'ok'      => true,
            'action'  => 'update_schedule_item',
            'id'      => $schedId,
            'message' => "Updated '{$newTitle}' in your schedule.",
            'event'   => [
                'id'          => $schedId,
                'title'       => $newTitle,
                'day_of_week' => $newDow,
                'start_time'  => $newStart,
                'end_time'    => $newEnd
            ]
        ], 200);
        break;

    // -------------------------------------------------------------------------
    // ACTION: UPDATE STUDY GOAL (Set Weekly Study Goal Hours)
    // -------------------------------------------------------------------------
    case 'update_study_goal':
        $goalHours = (float)($payload['weekly_goal_hours'] ?? $payload['goal_hours'] ?? 0);
        if ($goalHours <= 0) {
            aiActionJson([
                'ok'    => false,
                'error' => 'Weekly study goal must be a positive number of hours.',
                'code'  => 'INVALID_GOAL'
            ], 422);
        }

        // Clamp to realistic range (1.0 to 100.0 hours per week)
        $clampedGoal = max(1.0, min(100.0, round($goalHours, 1)));

        $stmtGoal = $db->prepare('UPDATE users SET weekly_goal_hours = ? WHERE id = ?');
        $stmtGoal->execute([$clampedGoal, $userId]);

        logActivity($userId, "Updated weekly study goal to {$clampedGoal} hours", 'info');

        aiActionJson([
            'ok'                => true,
            'action'            => 'update_study_goal',
            'weekly_goal_hours' => $clampedGoal,
            'message'           => "Updated your weekly study goal to {$clampedGoal} hours per week."
        ], 200);
        break;

    default:
        aiActionJson([
            'ok'    => false,
            'error' => 'Unsupported action.',
            'code'  => 'UNSUPPORTED_ACTION'
        ], 422);
}
