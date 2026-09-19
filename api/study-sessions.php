<?php
/**
 * ============================================================================
 * STUDY PLANNER — STUDY SESSIONS & TIME TRACKING API (FOUNDATION 8A)
 *
 * Backend support for focus timer sessions, duration calculation, and
 * time-based task progress metrics.
 *
 * Strictly non-destructive: Completing a session does NOT complete the task
 * or modify tasks.progress_percent.
 * ============================================================================
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

// Require authenticated session (fails with HTTP 401 if not logged in)
requireLogin();

$userId = currentUserId();
$db = getDb();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

/**
 * Output JSON payload and exit
 */
function sessionApiJson(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

switch ($method) {

    // ------------------------------------------------------------------------
    // GET: Query study sessions and focus time metrics
    // ------------------------------------------------------------------------
    case 'GET':
        session_write_close();

        // 1. Current active session (?active=1 or ?action=active)
        if (isset($_GET['active']) || (isset($_GET['action']) && $_GET['action'] === 'active')) {
            $active = getUserActiveStudySession($db, $userId);
            sessionApiJson([
                'ok'             => true,
                'active_session' => $active,
                'session'        => $active,
            ]);
        }

        // 2. Focused time and session history for a specific task (?task_id=X)
        if (isset($_GET['task_id'])) {
            $taskId = (int) $_GET['task_id'];
            if ($taskId <= 0) {
                sessionApiJson(['error' => 'Invalid task ID.'], 422);
            }

            // Verify task ownership
            $stmt = $db->prepare(
                'SELECT t.*, c.code AS course_code, c.name AS course_name, c.color AS course_color
                 FROM tasks t
                 LEFT JOIN courses c ON c.id = t.course_id AND c.user_id = t.user_id
                 WHERE t.id = ? AND t.user_id = ?'
            );
            $stmt->execute([$taskId, $userId]);
            $task = $stmt->fetch();

            if (!$task) {
                sessionApiJson(['error' => 'Task not found or access denied.'], 404);
            }

            // Query task sessions
            $stmt = $db->prepare(
                'SELECT * FROM task_work_sessions
                 WHERE user_id = ? AND task_id = ?
                 ORDER BY started_at DESC'
            );
            $stmt->execute([$userId, $taskId]);
            $sessions = $stmt->fetchAll();

            $totalSeconds = getTaskTotalFocusedSeconds($db, $userId, $taskId);
            $timeProgress = calculateTaskTimeProgress($task, $totalSeconds);

            sessionApiJson([
                'ok'                    => true,
                'task_id'               => $taskId,
                'task_title'            => $task['title'],
                'total_focused_seconds' => $totalSeconds,
                'total_focused_minutes' => $timeProgress['focused_minutes'],
                'total_focused_hours'   => $timeProgress['focused_hours'],
                'time_progress'         => $timeProgress,
                'sessions'              => $sessions,
            ]);
        }

        // 3. Today's focus time (?today=1)
        if (isset($_GET['today'])) {
            $stmt = $db->prepare(
                'SELECT s.*, 
                        t.title AS task_title, 
                        c.code AS course_code, 
                        c.name AS course_name, 
                        c.color AS course_color
                 FROM task_work_sessions s
                 INNER JOIN tasks t ON t.id = s.task_id AND t.user_id = s.user_id
                 LEFT JOIN courses c ON c.id = t.course_id AND c.user_id = s.user_id
                 WHERE s.user_id = ? AND DATE(s.started_at) = CURDATE()
                 ORDER BY s.started_at DESC'
            );
            $stmt->execute([$userId]);
            $todaySessions = $stmt->fetchAll();

            $todaySeconds = getUserTodayFocusedSeconds($db, $userId);

            sessionApiJson([
                'ok'                    => true,
                'today_focused_seconds' => $todaySeconds,
                'today_focused_minutes' => round($todaySeconds / 60, 1),
                'today_focused_hours'   => round($todaySeconds / 3600, 2),
                'sessions'              => $todaySessions,
            ]);
        }

        // 4. Session history for authenticated user (default or ?history=1)
        $limit = min(100, max(1, (int) ($_GET['limit'] ?? 50)));

        $stmt = $db->prepare(
            'SELECT s.*, 
                    t.title AS task_title, 
                    t.status AS task_status, 
                    t.duration_hours AS task_duration_hours,
                    c.code AS course_code, 
                    c.name AS course_name, 
                    c.color AS course_color
             FROM task_work_sessions s
             INNER JOIN tasks t ON t.id = s.task_id AND t.user_id = s.user_id
             LEFT JOIN courses c ON c.id = t.course_id AND c.user_id = s.user_id
             WHERE s.user_id = ?
             ORDER BY s.started_at DESC
             LIMIT ?'
        );
        $stmt->bindValue(1, $userId, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();
        $history = $stmt->fetchAll();

        $activeSession = getUserActiveStudySession($db, $userId);
        $todaySeconds = getUserTodayFocusedSeconds($db, $userId);

        sessionApiJson([
            'ok'                    => true,
            'sessions'              => $history,
            'today_focused_seconds' => $todaySeconds,
            'today_focused_minutes' => round($todaySeconds / 60, 1),
            'today_focused_hours'   => round($todaySeconds / 3600, 2),
            'active_session'        => $activeSession,
        ]);
        break;

    // ------------------------------------------------------------------------
    // POST: Start, pause, resume, stop, complete study session
    // ------------------------------------------------------------------------
    case 'POST':

        $body = json_decode(file_get_contents('php://input'), true) ?? [];

        // Enforce CSRF protection on all state-modifying requests
        verifyCsrf($body['csrf_token'] ?? null);

        $action = strtolower(trim((string) ($body['action'] ?? '')));

        if ($action === '') {
            sessionApiJson(['error' => 'Action parameter is required.'], 422);
        }

        // ====================================================================
        // ACTION: active
        // ====================================================================
        if ($action === 'active') {
            $active = getUserActiveStudySession($db, $userId);
            sessionApiJson([
                'ok'             => true,
                'active_session' => $active,
                'session'        => $active,
            ]);
        }

        // ====================================================================
        // ACTION: start
        // ====================================================================
        if ($action === 'start') {
            $taskId = (int) ($body['task_id'] ?? 0);
            if ($taskId <= 0) {
                sessionApiJson(['error' => 'Valid task ID is required to start a study session.'], 422);
            }

            // Verify task ownership
            $stmt = $db->prepare('SELECT id, user_id, title, duration_hours, progress_percent, status FROM tasks WHERE id = ? AND user_id = ?');
            $stmt->execute([$taskId, $userId]);
            $task = $stmt->fetch();

            if (!$task) {
                sessionApiJson(['error' => 'Task not found or access denied.'], 404);
            }

            // Prevent multiple active sessions for the same user
            $stmt = $db->prepare("SELECT id, task_id, status, started_at FROM task_work_sessions WHERE user_id = ? AND status IN ('running', 'paused') LIMIT 1");
            $stmt->execute([$userId]);
            $existingActive = $stmt->fetch();

            if ($existingActive) {
                sessionApiJson([
                    'error'             => 'An active study session is already in progress. Please pause, stop, or complete it before starting a new session.',
                    'active_session_id' => (int) $existingActive['id'],
                    'active_task_id'    => (int) $existingActive['task_id'],
                ], 409);
            }

            // Insert new session (server timestamps only)
            $stmt = $db->prepare(
                "INSERT INTO task_work_sessions (user_id, task_id, started_at, duration_seconds, status)
                 VALUES (?, ?, NOW(), 0, 'running')"
            );
            try {
                $stmt->execute([$userId, $taskId]);
            } catch (PDOException $e) {
                if (str_contains($e->getMessage(), '1265') || str_contains($e->getMessage(), 'Data truncated') || str_contains($e->getMessage(), 'status')) {
                    $db->exec("ALTER TABLE `task_work_sessions` MODIFY COLUMN `status` ENUM('running','paused','completed','stopped','active','cancelled') NOT NULL DEFAULT 'running'");
                    $stmt->execute([$userId, $taskId]);
                } else {
                    throw $e;
                }
            }
            $newId = (int) $db->lastInsertId();

            $newSession = getUserActiveStudySession($db, $userId);

            sessionApiJson([
                'ok'      => true,
                'session' => $newSession,
                'message' => 'Study session started.',
            ], 201);
        }

        // ====================================================================
        // ACTION: pause
        // ====================================================================
        if ($action === 'pause') {
            $sessionId = (int) ($body['session_id'] ?? 0);

            if ($sessionId > 0) {
                $stmt = $db->prepare('SELECT * FROM task_work_sessions WHERE id = ? AND user_id = ?');
                $stmt->execute([$sessionId, $userId]);
            } else {
                $stmt = $db->prepare("SELECT * FROM task_work_sessions WHERE user_id = ? AND status = 'running' ORDER BY id DESC LIMIT 1");
                $stmt->execute([$userId]);
            }
            $session = $stmt->fetch();

            if (!$session) {
                sessionApiJson(['error' => 'No active running study session found to pause.'], 404);
            }

            if ($session['status'] !== 'running') {
                sessionApiJson(['error' => 'Session is not currently running.'], 422);
            }

            // Calculate elapsed seconds since started_at (server-side only)
            $startedTs = strtotime((string) $session['started_at']);
            $elapsed = ($startedTs !== false) ? max(0, time() - $startedTs) : 0;
            $newDuration = max(0, (int) $session['duration_seconds'] + $elapsed);

            $stmt = $db->prepare(
                "UPDATE task_work_sessions
                 SET duration_seconds = ?, status = 'paused'
                 WHERE id = ? AND user_id = ?"
            );
            $stmt->execute([$newDuration, $session['id'], $userId]);

            $stmt = $db->prepare('SELECT * FROM task_work_sessions WHERE id = ? AND user_id = ?');
            $stmt->execute([$session['id'], $userId]);
            $updated = $stmt->fetch();

            sessionApiJson([
                'ok'      => true,
                'session' => $updated,
                'message' => 'Study session paused.',
            ]);
        }

        // ====================================================================
        // ACTION: resume
        // ====================================================================
        if ($action === 'resume') {
            $sessionId = (int) ($body['session_id'] ?? 0);

            if ($sessionId > 0) {
                $stmt = $db->prepare('SELECT * FROM task_work_sessions WHERE id = ? AND user_id = ?');
                $stmt->execute([$sessionId, $userId]);
            } else {
                $stmt = $db->prepare("SELECT * FROM task_work_sessions WHERE user_id = ? AND status = 'paused' ORDER BY id DESC LIMIT 1");
                $stmt->execute([$userId]);
            }
            $session = $stmt->fetch();

            if (!$session) {
                sessionApiJson(['error' => 'No paused study session found to resume.'], 404);
            }

            if ($session['status'] !== 'paused') {
                sessionApiJson(['error' => 'Session is not currently paused.'], 422);
            }

            // Resume by updating started_at to NOW() and status to running
            $stmt = $db->prepare(
                "UPDATE task_work_sessions
                 SET started_at = NOW(), status = 'running'
                 WHERE id = ? AND user_id = ?"
            );
            $stmt->execute([$session['id'], $userId]);

            $updated = getUserActiveStudySession($db, $userId);

            sessionApiJson([
                'ok'      => true,
                'session' => $updated,
                'message' => 'Study session resumed.',
            ]);
        }

        // ====================================================================
        // ACTION: stop
        // ====================================================================
        if ($action === 'stop') {
            $sessionId = (int) ($body['session_id'] ?? 0);

            if ($sessionId > 0) {
                $stmt = $db->prepare('SELECT * FROM task_work_sessions WHERE id = ? AND user_id = ?');
                $stmt->execute([$sessionId, $userId]);
            } else {
                $stmt = $db->prepare("SELECT * FROM task_work_sessions WHERE user_id = ? AND status IN ('running', 'paused') ORDER BY id DESC LIMIT 1");
                $stmt->execute([$userId]);
            }
            $session = $stmt->fetch();

            if (!$session) {
                sessionApiJson(['error' => 'No active study session found to stop.'], 404);
            }

            if (in_array($session['status'], ['completed', 'stopped'], true)) {
                sessionApiJson(['error' => 'Session is already closed.'], 422);
            }

            // Calculate final duration
            $finalDuration = (int) $session['duration_seconds'];
            if ($session['status'] === 'running') {
                $startedTs = strtotime((string) $session['started_at']);
                $elapsed = ($startedTs !== false) ? max(0, time() - $startedTs) : 0;
                $finalDuration = max(0, $finalDuration + $elapsed);
            }

            $stmt = $db->prepare(
                "UPDATE task_work_sessions
                 SET ended_at = NOW(), duration_seconds = ?, status = 'stopped'
                 WHERE id = ? AND user_id = ?"
            );
            $stmt->execute([$finalDuration, $session['id'], $userId]);

            $stmt = $db->prepare('SELECT * FROM task_work_sessions WHERE id = ? AND user_id = ?');
            $stmt->execute([$session['id'], $userId]);
            $updated = $stmt->fetch();

            // Trigger completion notification if meaningful focus time (>= 60s)
            if ($finalDuration >= 60) {
                $eventKey = "session_completed_{$session['id']}";
                $stmtCheck = $db->prepare('SELECT id FROM notifications WHERE user_id = ? AND event_key = ? LIMIT 1');
                $stmtCheck->execute([$userId, $eventKey]);
                if (!$stmtCheck->fetch()) {
                    $stmtTask = $db->prepare(
                        'SELECT t.title AS task_title, c.code AS course_code
                         FROM tasks t
                         LEFT JOIN courses c ON c.id = t.course_id AND c.user_id = t.user_id
                         WHERE t.id = ? AND t.user_id = ?'
                    );
                    $stmtTask->execute([(int) $session['task_id'], $userId]);
                    $taskMeta = $stmtTask->fetch();

                    $focusMins = max(1, round($finalDuration / 60));
                    $unit = ($focusMins === 1) ? 'minute' : 'minutes';
                    $targetName = !empty($taskMeta['course_code'])
                        ? "{$taskMeta['course_code']}: '{$taskMeta['task_title']}'"
                        : (!empty($taskMeta['task_title']) ? "'{$taskMeta['task_title']}'" : 'your studies');

                    $notifMsg = "Study session completed: You focused for {$focusMins} {$unit} on {$targetName}.";
                    $stmtIns = $db->prepare(
                        "INSERT INTO notifications (user_id, task_id, channel, event_key, message, send_at)
                         VALUES (?, ?, 'in_app', ?, ?, NOW())"
                    );
                    $stmtIns->execute([$userId, (int) $session['task_id'], $eventKey, $notifMsg]);
                }
            }

            sessionApiJson([
                'ok'      => true,
                'session' => $updated,
                'message' => 'Study session stopped.',
            ]);
        }

        // ====================================================================
        // ACTION: complete
        // ====================================================================
        if ($action === 'complete') {
            $sessionId = (int) ($body['session_id'] ?? 0);

            if ($sessionId > 0) {
                $stmt = $db->prepare('SELECT * FROM task_work_sessions WHERE id = ? AND user_id = ?');
                $stmt->execute([$sessionId, $userId]);
            } else {
                $stmt = $db->prepare("SELECT * FROM task_work_sessions WHERE user_id = ? AND status IN ('running', 'paused') ORDER BY id DESC LIMIT 1");
                $stmt->execute([$userId]);
            }
            $session = $stmt->fetch();

            if (!$session) {
                sessionApiJson(['error' => 'No active study session found to complete.'], 404);
            }

            if ($session['status'] === 'completed') {
                sessionApiJson(['error' => 'Session is already completed.'], 422);
            }

            // Calculate final duration
            $finalDuration = (int) $session['duration_seconds'];
            if ($session['status'] === 'running') {
                $startedTs = strtotime((string) $session['started_at']);
                $elapsed = ($startedTs !== false) ? max(0, time() - $startedTs) : 0;
                $finalDuration = max(0, $finalDuration + $elapsed);
            }

            $stmt = $db->prepare(
                "UPDATE task_work_sessions
                 SET ended_at = NOW(), duration_seconds = ?, status = 'completed'
                 WHERE id = ? AND user_id = ?"
            );
            $stmt->execute([$finalDuration, $session['id'], $userId]);

            $stmt = $db->prepare('SELECT * FROM task_work_sessions WHERE id = ? AND user_id = ?');
            $stmt->execute([$session['id'], $userId]);
            $updated = $stmt->fetch();

            // Trigger completion notification if meaningful focus time (>= 60s)
            if ($finalDuration >= 60) {
                $eventKey = "session_completed_{$session['id']}";
                $stmtCheck = $db->prepare('SELECT id FROM notifications WHERE user_id = ? AND event_key = ? LIMIT 1');
                $stmtCheck->execute([$userId, $eventKey]);
                if (!$stmtCheck->fetch()) {
                    $stmtTask = $db->prepare(
                        'SELECT t.title AS task_title, c.code AS course_code
                         FROM tasks t
                         LEFT JOIN courses c ON c.id = t.course_id AND c.user_id = t.user_id
                         WHERE t.id = ? AND t.user_id = ?'
                    );
                    $stmtTask->execute([(int) $session['task_id'], $userId]);
                    $taskMeta = $stmtTask->fetch();

                    $focusMins = max(1, round($finalDuration / 60));
                    $unit = ($focusMins === 1) ? 'minute' : 'minutes';
                    $targetName = !empty($taskMeta['course_code'])
                        ? "{$taskMeta['course_code']}: '{$taskMeta['task_title']}'"
                        : (!empty($taskMeta['task_title']) ? "'{$taskMeta['task_title']}'" : 'your studies');

                    $notifMsg = "Study session completed: You focused for {$focusMins} {$unit} on {$targetName}.";
                    $stmtIns = $db->prepare(
                        "INSERT INTO notifications (user_id, task_id, channel, event_key, message, send_at)
                         VALUES (?, ?, 'in_app', ?, ?, NOW())"
                    );
                    $stmtIns->execute([$userId, (int) $session['task_id'], $eventKey, $notifMsg]);
                }
            }

            // Fetch task to compute updated time progress (without modifying task status or manual progress)
            $stmt = $db->prepare('SELECT * FROM tasks WHERE id = ? AND user_id = ?');
            $stmt->execute([$session['task_id'], $userId]);
            $task = $stmt->fetch();

            $totalFocused = getTaskTotalFocusedSeconds($db, $userId, (int) $session['task_id']);
            $timeProgress = $task ? calculateTaskTimeProgress($task, $totalFocused) : null;

            sessionApiJson([
                'ok'                 => true,
                'session'            => $updated,
                'task_time_progress' => $timeProgress,
                'message'            => 'Study session completed.',
            ]);
        }

        sessionApiJson(['error' => "Unsupported action '{$action}'. Supported actions: start, pause, resume, stop, complete."], 422);
        break;

    default:
        sessionApiJson(['error' => 'Method not allowed.'], 405);
}
