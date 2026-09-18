<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');
requireLogin();

$userId = currentUserId();
$method = $_SERVER['REQUEST_METHOD'];

function courseJsonError(string $message, int $status = 400): never
{
    http_response_code($status);
    echo json_encode(['error' => $message]);
    exit;
}

function parseCourseBody(): array
{
    $raw = file_get_contents('php://input');
    $body = json_decode($raw ?: '', true);
    if (!is_array($body)) courseJsonError('Invalid JSON request body.', 400);
    return $body;
}

function validateCourseFields(array $fields, bool $requireRequired = true): array
{
    $out = [];
    if (array_key_exists('code', $fields)) {
        $out['code'] = trim((string) $fields['code']);
        if ($requireRequired && $out['code'] === '') courseJsonError('Course code is required.', 422);
        if ($out['code'] !== '' && strlen($out['code']) > 20) courseJsonError('Course code must be 20 characters or fewer.', 422);
    }
    if (array_key_exists('name', $fields)) {
        $out['name'] = trim((string) $fields['name']);
        if ($requireRequired && $out['name'] === '') courseJsonError('Course title is required.', 422);
        if ($out['name'] !== '' && strlen($out['name']) > 150) courseJsonError('Course title must be 150 characters or fewer.', 422);
    }
    if (array_key_exists('lecturer', $fields)) {
        $out['lecturer'] = trim((string) $fields['lecturer']);
        if (strlen($out['lecturer']) > 100) courseJsonError('Lecturer name is too long.', 422);
    }
    if (array_key_exists('credits', $fields)) {
        if ($fields['credits'] === '' || !filter_var($fields['credits'], FILTER_VALIDATE_INT)) courseJsonError('Credits must be a whole number.', 422);
        $out['credits'] = (int) $fields['credits'];
        if ($out['credits'] < 1 || $out['credits'] > 6) courseJsonError('Credits must be between 1 and 6.', 422);
    }
    if (array_key_exists('semester', $fields)) {
        $out['semester'] = trim((string) $fields['semester']);
        if (strlen($out['semester']) > 30) courseJsonError('Semester is too long.', 422);
    }
    if (array_key_exists('icon', $fields)) {
        $out['icon'] = trim((string) $fields['icon']);
        if ($out['icon'] === '') $out['icon'] = '📘';
        if (strlen($out['icon']) > 10) courseJsonError('Course icon is too long.', 422);
    }
    if (array_key_exists('color', $fields)) {
        $out['color'] = trim((string) $fields['color']);
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $out['color'])) courseJsonError('Accent color must be a valid hex color.', 422);
    }
    if (array_key_exists('estimated_hours', $fields)) {
        if ($fields['estimated_hours'] === '' || $fields['estimated_hours'] === null) $fields['estimated_hours'] = 0;
        if (!is_numeric($fields['estimated_hours'])) courseJsonError('Estimated hours must be numeric.', 422);
        $out['estimated_hours'] = max(0, min(9999, (float)$fields['estimated_hours']));
    }
    if (array_key_exists('progress_percent', $fields)) {
        $out['progress_percent'] = max(0, min(100, (int)$fields['progress_percent']));
    }
    if (array_key_exists('status', $fields)) {
        $status = (string)$fields['status'];
        if (!in_array($status, ['pending','in_progress','completed'], true)) courseJsonError('Invalid course status.', 422);
        $out['status'] = $status;
    }
    if (array_key_exists('grade_point', $fields)) {
        if ($fields['grade_point'] === '' || $fields['grade_point'] === null) {
            $out['grade_point'] = null;
        } else {
            if (!is_numeric($fields['grade_point'])) courseJsonError('Grade point must be numeric.', 422);
            $out['grade_point'] = round((float) $fields['grade_point'], 2);
            if ($out['grade_point'] < 0 || $out['grade_point'] > 5) courseJsonError('Grade point must be between 0 and 5.', 422);
        }
    }
    return $out;
}

try {
    $db = getDb();
    switch ($method) {
        case 'GET':
            session_write_close();
            $stmt = $db->prepare(
                "SELECT c.*,
                    COUNT(t.id) AS task_count,
                    COALESCE(SUM(t.status = 'completed'), 0) AS completed_count,
                    COALESCE(SUM(t.status != 'completed' AND t.due_at >= NOW() AND t.due_at <= DATE_ADD(NOW(), INTERVAL " . DUE_SOON_WINDOW_DAYS . " DAY)), 0) AS due_soon_count
                 FROM courses c
                 LEFT JOIN tasks t ON t.course_id = c.id AND t.user_id = c.user_id
                 WHERE c.user_id = ?
                 GROUP BY c.id
                 ORDER BY c.code ASC"
            );
            $stmt->execute([$userId]);
            $courses = $stmt->fetchAll();

            $totalTasks = 0;
            $progressSum = 0;
            $totalCredits = 0;
            $gpaWeighted = 0;
            $gpaCredits = 0;
            $progressBreakdown = ['completed' => 0, 'in_progress' => 0, 'not_started' => 0];

            foreach ($courses as &$course) {
                $taskCount = (int) $course['task_count'];
                $completed = (int) $course['completed_count'];
                $course['task_count'] = $taskCount;
                $course['completed_count'] = $completed;
                $course['due_soon_count'] = (int) $course['due_soon_count'];
                $course['credits'] = (int) $course['credits'];
                $taskProgressStmt = $db->prepare('SELECT COALESCE(AVG(progress_percent),0) FROM tasks WHERE course_id=? AND user_id=?');
                $taskProgressStmt->execute([$course['id'], $userId]);
                $taskProgress = (int)round((float)$taskProgressStmt->fetchColumn());
                $storedProgress = (int)($course['progress_percent'] ?? 0);
                $course['progress'] = max($storedProgress, $taskProgress, ($taskCount > 0 && $completed === $taskCount) ? 100 : 0);
                if ($course['progress'] >= 100 && ($course['status'] ?? 'pending') !== 'completed') {
                    $sync = $db->prepare('UPDATE courses SET progress_percent=100,status="completed",completed_at=COALESCE(completed_at,NOW()) WHERE id=? AND user_id=?');
                    $sync->execute([$course['id'],$userId]);
                    $course['status']='completed'; $course['progress_percent']=100;
                } elseif ($course['progress'] > 0 && ($course['status'] ?? 'pending') === 'pending') {
                    $sync = $db->prepare('UPDATE courses SET status="in_progress" WHERE id=? AND user_id=?');
                    $sync->execute([$course['id'],$userId]);
                    $course['status']='in_progress';
                }
                $totalTasks += $taskCount;
                $progressSum += $course['progress'];
                $totalCredits += $course['credits'];
                if ($course['grade_point'] !== null) {
                    $gpaWeighted += (float) $course['grade_point'] * $course['credits'];
                    $gpaCredits += $course['credits'];
                }
                if ($course['progress'] >= 100) $progressBreakdown['completed']++;
                elseif ($course['progress'] > 0) $progressBreakdown['in_progress']++;
                else $progressBreakdown['not_started']++;
            }
            unset($course);

            $courseCount = count($courses);
            $averageProgress = $courseCount ? (int) round($progressSum / $courseCount) : 0;

            $stmt = $db->prepare("SELECT COUNT(*) FROM tasks WHERE user_id = ? AND status != 'completed' AND due_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 7 DAY)");
            $stmt->execute([$userId]);
            $dueThisWeek = (int) $stmt->fetchColumn();

            $stmt = $db->prepare(
                "SELECT t.id, t.title, t.priority, t.due_at, c.code AS course_code
                 FROM tasks t LEFT JOIN courses c ON c.id = t.course_id AND c.user_id = t.user_id
                 WHERE t.user_id = ? AND t.status != 'completed'
                 ORDER BY t.due_at ASC LIMIT 4"
            );
            $stmt->execute([$userId]);
            $upcoming = $stmt->fetchAll();
            foreach ($upcoming as &$task) {
                $task['due_label'] = dueRelativeLabel($task['due_at']);
            }
            unset($task);

            echo json_encode([
                'courses' => $courses,
                'summary' => [
                    'total_courses' => $courseCount,
                    'total_tasks' => $totalTasks,
                    'total_credits' => $totalCredits,
                    'due_this_week' => $dueThisWeek,
                    'average_progress' => $averageProgress,
                    'gpa' => $gpaCredits > 0 ? round($gpaWeighted / $gpaCredits, 2) : null,
                    'progress_breakdown' => $progressBreakdown,
                ],
                'upcoming_deadlines' => $upcoming,
            ]);
            break;

        case 'POST':
            $body = parseCourseBody();
            verifyCsrf($body['csrf_token'] ?? null);
            $fields = validateCourseFields($body, true);
            $fields += ['lecturer' => '', 'credits' => 3, 'semester' => '', 'icon' => '📘', 'color' => '#059669', 'grade_point' => null, 'status' => 'pending', 'progress_percent' => 0, 'estimated_hours' => 0];
            if ($fields['status'] === 'completed' || $fields['progress_percent'] >= 100) { $fields['status'] = 'completed'; $fields['progress_percent'] = 100; } elseif ($fields['progress_percent'] > 0 && $fields['status'] === 'pending') { $fields['status'] = 'in_progress'; }

            $stmt = $db->prepare('SELECT id FROM courses WHERE user_id = ? AND code = ? LIMIT 1');
            $stmt->execute([$userId, $fields['code']]);
            if ($stmt->fetch()) courseJsonError('You already have a course with that code.', 409);

            $stmt = $db->prepare(
                'INSERT INTO courses (user_id, code, name, lecturer, credits, semester, icon, color, grade_point, status, progress_percent, estimated_hours)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([$userId, $fields['code'], $fields['name'], $fields['lecturer'] ?: null, $fields['credits'], $fields['semester'] ?: null, $fields['icon'], $fields['color'], $fields['grade_point'], $fields['status'], $fields['progress_percent'], $fields['estimated_hours']]);
            logActivity($userId, "New course added: {$fields['code']} — {$fields['name']}", 'success');
            echo json_encode(['ok' => true, 'id' => $db->lastInsertId()]);
            break;

        case 'PUT':
            $body = parseCourseBody();
            verifyCsrf($body['csrf_token'] ?? null);
            $id = filter_var($body['id'] ?? null, FILTER_VALIDATE_INT);
            if (!$id || $id < 1) courseJsonError('A valid course ID is required.', 422);

            $stmt = $db->prepare('SELECT * FROM courses WHERE id = ? AND user_id = ? LIMIT 1');
            $stmt->execute([$id, $userId]);
            $existing = $stmt->fetch();
            if (!$existing) courseJsonError('Course not found.', 404);

            $allowed = ['code','name','lecturer','credits','semester','icon','color','grade_point','status','progress_percent','estimated_hours'];
            $incoming = array_intersect_key($body, array_flip($allowed));
            $fields = validateCourseFields($incoming, false);
            $merged = array_merge($existing, $fields);
            if (($merged['status'] ?? 'pending') === 'completed' || (int)($merged['progress_percent'] ?? 0) >= 100) { $merged['status'] = 'completed'; $merged['progress_percent'] = 100; } elseif ((int)($merged['progress_percent'] ?? 0) > 0 && ($merged['status'] ?? 'pending') === 'pending') { $merged['status'] = 'in_progress'; }
            if (trim((string) $merged['code']) === '' || trim((string) $merged['name']) === '') courseJsonError('Course code and title are required.', 422);

            $stmt = $db->prepare('SELECT id FROM courses WHERE user_id = ? AND code = ? AND id <> ? LIMIT 1');
            $stmt->execute([$userId, $merged['code'], $id]);
            if ($stmt->fetch()) courseJsonError('You already have a course with that code.', 409);

            $stmt = $db->prepare(
                'UPDATE courses SET code=?, name=?, lecturer=?, credits=?, semester=?, icon=?, color=?, grade_point=?, status=?, progress_percent=?, estimated_hours=?, completed_at=?
                 WHERE id=? AND user_id=?'
            );
            $stmt->execute([$merged['code'], $merged['name'], $merged['lecturer'] ?: null, (int) $merged['credits'], $merged['semester'] ?: null, $merged['icon'] ?: '📘', $merged['color'] ?: '#059669', $merged['grade_point'] === '' ? null : $merged['grade_point'], $merged['status'] ?? $existing['status'], (int)($merged['progress_percent'] ?? $existing['progress_percent']), (float)($merged['estimated_hours'] ?? $existing['estimated_hours']), (($merged['status'] ?? $existing['status']) === 'completed' ? ($existing['completed_at'] ?: date('Y-m-d H:i:s')) : null), $id, $userId]);
            echo json_encode(['ok' => true]);
            break;

        case 'DELETE':
            parse_str(file_get_contents('php://input'), $body);
            verifyCsrf($body['csrf_token'] ?? null);
            $id = filter_var($body['id'] ?? null, FILTER_VALIDATE_INT);
            if (!$id || $id < 1) courseJsonError('A valid course ID is required.', 422);
            $stmt = $db->prepare('SELECT id FROM courses WHERE id = ? AND user_id = ? LIMIT 1');
            $stmt->execute([$id, $userId]);
            if (!$stmt->fetch()) courseJsonError('Course not found.', 404);
            $stmt = $db->prepare('DELETE FROM courses WHERE id = ? AND user_id = ?');
            $stmt->execute([$id, $userId]);
            echo json_encode(['ok' => true]);
            break;

        default:
            header('Allow: GET, POST, PUT, DELETE');
            courseJsonError('Method not allowed.', 405);
    }
} catch (PDOException $e) {
    error_log('Courses API database error: ' . $e->getMessage());
    $message = 'A database error prevented the course request from completing.';
    if (defined('APP_DEBUG') && APP_DEBUG) {
        $message .= ' [DEBUG: ' . $e->getMessage() . ']';
    }
    courseJsonError($message, 500);
} catch (Throwable $e) {
    error_log('Courses API error: ' . $e->getMessage());
    $message = 'The course request could not be completed.';
    if (defined('APP_DEBUG') && APP_DEBUG) {
        $message .= ' [DEBUG: ' . $e->getMessage() . ']';
    }
    courseJsonError($message, 500);
}
