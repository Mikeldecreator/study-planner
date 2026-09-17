<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
header('Content-Type: application/json');
requireLogin();

$userId = currentUserId();
$db = getDb();
$method = $_SERVER['REQUEST_METHOD'];

function ownedTaskIdOrNull(PDO $db, $taskId, int $userId): ?int {
    if ($taskId === null || $taskId === '' || !is_numeric($taskId)) {
        return null;
    }
    $stmt = $db->prepare('SELECT id FROM tasks WHERE id = ? AND user_id = ? LIMIT 1');
    $stmt->execute([(int) $taskId, $userId]);
    $id = $stmt->fetchColumn();
    return $id === false ? null : (int) $id;
}

/**
 * DESIGN NOTE: schedule_events is a RECURRING WEEKLY template (day_of_week
 * 0–6), not date-specific occurrences. That means "this week" and "next
 * week" show the same pattern — there's no separate row per calendar week.
 * This keeps the model simple (matches a real semester timetable, which
 * repeats) at the cost of the mockup's per-date navigation. See README.
 */

switch ($method) {

    case 'GET':
        $stmt = $db->prepare(
            'SELECT
                se.*,
                c.code AS course_code,
                c.name AS course_name,
                c.color AS course_color,
                c.icon AS course_icon,
                t.title AS task_title,
                t.due_at AS task_due_at,
                t.status AS task_status
             FROM schedule_events se
             LEFT JOIN courses c
                ON c.id = se.course_id
                AND c.user_id = se.user_id
             LEFT JOIN tasks t
                ON t.id = se.task_id
                AND t.user_id = se.user_id
             WHERE se.user_id = ?
             ORDER BY se.day_of_week, se.start_time'
        );
        $stmt->execute([$userId]);
        $events = $stmt->fetchAll();

        // Tasks are date-specific, unlike recurring schedule_events.
        $taskStmt = $db->prepare(
            'SELECT
                t.id, t.course_id, t.title, t.type, t.priority, t.status,
                t.progress_percent, t.duration_hours, t.due_at,
                c.code AS course_code, c.name AS course_name,
                c.color AS course_color, c.icon AS course_icon
             FROM tasks t
             LEFT JOIN courses c
                ON c.id = t.course_id
                AND c.user_id = t.user_id
             WHERE t.user_id = ?
             ORDER BY t.due_at ASC'
        );
        $taskStmt->execute([$userId]);
        $tasks = $taskStmt->fetchAll();

        $courseStmt = $db->prepare(
            'SELECT id, code, name, color, icon
             FROM courses
             WHERE user_id = ?
             ORDER BY code, name'
        );
        $courseStmt->execute([$userId]);
        $courses = $courseStmt->fetchAll();

        $totalSessions = count($events);
        $scheduledHours = 0;
        $completed = 0;
        $daysWithCompletion = [];
        $byType = [];

        foreach ($events as $ev) {
            $hours = (strtotime($ev['end_time']) - strtotime($ev['start_time'])) / 3600;
            $scheduledHours += $hours;
            $byType[$ev['event_type']] = ($byType[$ev['event_type']] ?? 0) + $hours;
            if ($ev['is_completed']) {
                $completed++;
                $daysWithCompletion[$ev['day_of_week']] = true;
            }
        }

        $weeklyGoal = (float) (getUserProfileRow($userId)['weekly_goal_hours'] ?? 0);

        echo json_encode([
            'events' => $events,
            'tasks' => $tasks,
            'courses' => $courses,
            'stats' => [
                'total_sessions' => $totalSessions,
                'scheduled_hours' => round($scheduledHours, 1),
                'completed' => $completed,
                'weekly_utilization' => $weeklyGoal > 0 ? min(100, round($scheduledHours / $weeklyGoal * 100)) : 0,
                'daily_goal_met' => count($daysWithCompletion),
                'weekly_goal_hours' => $weeklyGoal,
                'time_distribution' => $byType,
            ],
        ]);
        break;

    case 'POST':
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        verifyCsrf($body['csrf_token'] ?? null);

        $title = trim($body['title'] ?? '');
        if ($title === '' || !isset($body['day_of_week'], $body['start_time'], $body['end_time'])) {
            http_response_code(422);
            echo json_encode(['error' => 'Title, day, and start/end time are required.']);
            exit;
        }
        if ($body['end_time'] <= $body['start_time']) {
            http_response_code(422);
            echo json_encode(['error' => 'End time must be after start time.']);
            exit;
        }

        $stmt = $db->prepare(
            'INSERT INTO schedule_events (user_id, course_id, task_id, title, event_type, day_of_week, start_time, end_time)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
        );
        $stmt->execute([
            $userId,
            ownedCourseIdOrNull($db, $body['course_id'] ?? null, $userId),
            ownedTaskIdOrNull($db, $body['task_id'] ?? null, $userId),
            $title,
            $body['event_type'] ?? 'study',
            (int) $body['day_of_week'],
            $body['start_time'],
            $body['end_time'],
        ]);
        echo json_encode(['ok' => true, 'id' => $db->lastInsertId()]);
        break;

    case 'PUT':
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        verifyCsrf($body['csrf_token'] ?? null);
        $id = (int) ($body['id'] ?? 0);

        $stmt = $db->prepare('SELECT * FROM schedule_events WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $userId]);
        $existing = $stmt->fetch();
        if (!$existing) {
            http_response_code(404);
            echo json_encode(['error' => 'Session not found.']);
            exit;
        }

        // Quick toggle-complete calls only send { id, is_completed } — everything
        // else falls back to its current column via COALESCE-style defaults handled below.
        if (array_key_exists('is_completed', $body) && count($body) <= 3) {
            $done = !empty($body['is_completed']) ? 1 : 0;
            $stmt = $db->prepare('UPDATE schedule_events SET is_completed=?, progress_percent=?, completed_at=? WHERE id=? AND user_id=?');
            $stmt->execute([$done, $done ? 100 : 0, $done ? date('Y-m-d H:i:s') : null, $id, $userId]);
            echo json_encode(['ok' => true]);
            exit;
        }

        // The full-update path below used to trust $body directly for
        // title/day_of_week/start_time/end_time with no fallback, despite the
        // comment above claiming otherwise: a request missing any of those
        // (day_of_week especially — an absent key silently casts to 0/Sunday)
        // would either corrupt the row or throw on the NOT NULL time columns.
        // The normal Edit Session form always sends every field, so this
        // wasn't reachable through the UI — but the endpoint itself wasn't
        // actually safe against a partial request. Now it genuinely falls
        // back to the existing row, matching what the comment always claimed.
        $title     = trim($body['title'] ?? $existing['title']);
        $eventType = $body['event_type'] ?? $existing['event_type'];
        $dayOfWeek = array_key_exists('day_of_week', $body) ? (int) $body['day_of_week'] : (int) $existing['day_of_week'];
        $startTime = $body['start_time'] ?: $existing['start_time'];
        $endTime   = $body['end_time'] ?: $existing['end_time'];

        if ($title === '' || $endTime <= $startTime) {
            http_response_code(422);
            echo json_encode(['error' => 'Title is required and end time must be after start time.']);
            exit;
        }

        $stmt = $db->prepare(
            'UPDATE schedule_events SET title=?, event_type=?, course_id=?, task_id=?, day_of_week=?, start_time=?, end_time=?, is_completed=?, progress_percent=?, completed_at=?
             WHERE id=? AND user_id=?'
        );
        $stmt->execute([
            $title,
            $eventType,
            ownedCourseIdOrNull($db, $body['course_id'] ?? null, $userId),
            array_key_exists('task_id', $body)
                ? ownedTaskIdOrNull($db, $body['task_id'], $userId)
                : ($existing['task_id'] ?? null),
            $dayOfWeek,
            $startTime,
            $endTime,
            !empty($body['is_completed']) ? 1 : 0,
            !empty($body['is_completed']) ? 100 : (int)($existing['progress_percent'] ?? 0),
            !empty($body['is_completed']) ? ($existing['completed_at'] ?: date('Y-m-d H:i:s')) : null,
            $id,
            $userId,
        ]);
        echo json_encode(['ok' => true]);
        break;

    case 'DELETE':
        parse_str(file_get_contents('php://input'), $body);
        verifyCsrf($body['csrf_token'] ?? null);
        $id = (int) ($body['id'] ?? 0);

        $stmt = $db->prepare('DELETE FROM schedule_events WHERE id = ? AND user_id = ?');
        $stmt->execute([$id, $userId]);
        echo json_encode(['ok' => true]);
        break;

    default:
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
}
