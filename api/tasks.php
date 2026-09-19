<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

header(
    'Content-Type: application/json; charset=utf-8'
);

requireLogin();

$userId = currentUserId();
$db = getDb();

$method = $_SERVER['REQUEST_METHOD'];

function taskApiJson(
    array $payload,
    int $status = 200
): never {
    http_response_code($status);

    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE
    );

    exit;
}

function normalizeTaskStatus(
    string $status
): string {
    $allowed = [
        'not_started',
        'pending',
        'in_progress',
        'completed'
    ];

    return in_array(
        $status,
        $allowed,
        true
    )
        ? $status
        : 'not_started';
}

function normalizeTaskType(
    string $type
): string {
    $allowed = [
        'assignment',
        'project',
        'test',
        'exam',
        'research',
        'study_session',
        'lab_report',
        'other'
    ];

    return in_array(
        $type,
        $allowed,
        true
    )
        ? $type
        : 'assignment';
}

function normalizeTaskPriority(
    string $priority
): string {
    $allowed = [
        'low',
        'medium',
        'high'
    ];

    return in_array(
        $priority,
        $allowed,
        true
    )
        ? $priority
        : 'medium';
}

if (!function_exists('decorateTask')) {
function decorateTask(
    array $task
): array {
    $task['urgency'] =
        classifyTaskUrgency(
            (string)($task['due_at'] ?? ''),
            (string)($task['status'] ?? '')
        );

    $task =
        array_merge(
            $task,
            buildTaskIntelligence($task)
        );

    $task['priority_label'] =
        priorityLabel(
            (string)($task['priority'] ?? 'medium')
        );

    $task['course_credits'] =
        isset($task['course_credits'])
            ? (int)$task['course_credits']
            : 0;

    $task['course_grade_point'] =
        isset($task['course_grade_point']) &&
        $task['course_grade_point'] !== null
            ? (float)$task['course_grade_point']
            : null;

    return $task;
}
}

switch ($method) {

    case 'GET':
        session_write_close();

        // Authoritatively check and persist completion for active tasks that reached their estimated workload
        if (function_exists('checkAndPersistTaskTimeCompletion')) {
            $autoCheckStmt = $db->prepare("
                SELECT t.id, t.duration_hours,
                       (SELECT COALESCE(SUM(CASE WHEN status = 'running' THEN duration_seconds + GREATEST(0, TIMESTAMPDIFF(SECOND, started_at, NOW())) ELSE duration_seconds END), 0)
                        FROM task_work_sessions WHERE task_id = t.id AND user_id = t.user_id) AS total_focused
                FROM tasks t
                WHERE t.user_id = ? AND t.status != 'completed' AND t.duration_hours > 0
            ");
            $autoCheckStmt->execute([$userId]);
            foreach ($autoCheckStmt->fetchAll() as $cand) {
                $estSec = (int) round(((float)$cand['duration_hours']) * 3600);
                if ($estSec > 0 && (int)$cand['total_focused'] >= $estSec) {
                    checkAndPersistTaskTimeCompletion($db, $userId, (int)$cand['id']);
                }
            }
        }

        $sql = '
            SELECT
                t.*,
                c.code AS course_code,
                c.name AS course_name,
                c.color AS course_color,
                c.credits AS course_credits,
                c.grade_point AS course_grade_point,
                (SELECT COALESCE(SUM(CASE WHEN status = \'running\' THEN duration_seconds + GREATEST(0, TIMESTAMPDIFF(SECOND, started_at, NOW())) ELSE duration_seconds END), 0) FROM task_work_sessions WHERE task_id = t.id AND user_id = t.user_id) AS focused_seconds
            FROM tasks t
            LEFT JOIN courses c
                ON c.id = t.course_id
                AND c.user_id = t.user_id
            WHERE t.user_id = ?
        ';

        $params = [
            $userId
        ];

        if (
            !empty($_GET['status']) &&
            $_GET['status'] !== 'all'
        ) {
            $filterStatus = strtolower(trim((string)$_GET['status']));
            if ($filterStatus === 'overdue') {
                $sql .= "
                    AND t.status != 'completed'
                    AND t.due_at < NOW()
                ";
            } elseif ($filterStatus === 'due_soon') {
                $days = defined('DUE_SOON_WINDOW_DAYS') ? (int)DUE_SOON_WINDOW_DAYS : 3;
                $sql .= "
                    AND t.status != 'completed'
                    AND t.due_at >= NOW()
                    AND t.due_at <= DATE_ADD(NOW(), INTERVAL {$days} DAY)
                ";
            } elseif ($filterStatus === 'pending') {
                $sql .= "
                    AND t.status != 'completed'
                    AND t.status IN ('pending', 'not_started')
                ";
            } else {
                $sql .= '
                    AND t.status = ?
                ';

                $params[] =
                    normalizeTaskStatus(
                        $filterStatus
                    );
            }
        }

        if (!empty($_GET['course_id'])) {
            $sql .= '
                AND t.course_id = ?
            ';

            $params[] =
                (int)$_GET['course_id'];
        }

        if (!empty($_GET['type'])) {
            $sql .= '
                AND t.type = ?
            ';

            $params[] =
                normalizeTaskType(
                    (string)$_GET['type']
                );
        }

        if (!empty($_GET['priority'])) {
            $sql .= '
                AND t.priority = ?
            ';

            $params[] =
                normalizeTaskPriority(
                    (string)$_GET['priority']
                );
        }

        if (!empty($_GET['id'])) {
            $sql .= '
                AND t.id = ?
            ';

            $params[] =
                (int)$_GET['id'];
        }

        if (!empty($_GET['q'])) {
            $sql .= '
                AND (
                    t.title LIKE ?
                    OR t.description LIKE ?
                    OR c.code LIKE ?
                    OR c.name LIKE ?
                )
            ';

            $q =
                '%' .
                trim(
                    (string)$_GET['q']
                ) .
                '%';

            $params[] = $q;
            $params[] = $q;
            $params[] = $q;
            $params[] = $q;
        }

        $sql .= "
            ORDER BY
                t.status = 'completed' ASC,
                t.due_at ASC
        ";

        $stmt =
            $db->prepare($sql);

        $stmt->execute(
            $params
        );

        $tasks =
            array_map(
                'decorateTask',
                $stmt->fetchAll()
            );

        $sortBy = strtolower(trim((string)($_GET['sort'] ?? 'smart')));

        usort(
            $tasks,
            static function (
                array $a,
                array $b
            ) use ($sortBy): int {
                if ($sortBy === 'due_asc') {
                    $dueA = strtotime((string)($a['due_at'] ?? '')) ?: PHP_INT_MAX;
                    $dueB = strtotime((string)($b['due_at'] ?? '')) ?: PHP_INT_MAX;
                    if ($dueA !== $dueB) return $dueA <=> $dueB;
                } elseif ($sortBy === 'due_desc') {
                    $dueA = strtotime((string)($a['due_at'] ?? '')) ?: 0;
                    $dueB = strtotime((string)($b['due_at'] ?? '')) ?: 0;
                    if ($dueA !== $dueB) return $dueB <=> $dueA;
                } elseif ($sortBy === 'priority_desc') {
                    $pOrder = ['high' => 3, 'medium' => 2, 'low' => 1];
                    $pA = $pOrder[$a['priority'] ?? 'medium'] ?? 2;
                    $pB = $pOrder[$b['priority'] ?? 'medium'] ?? 2;
                    if ($pA !== $pB) return $pB <=> $pA;
                } elseif ($sortBy === 'progress_asc') {
                    $prA = (float)($a['system_progress'] ?? $a['time_progress_percent'] ?? $a['progress_percent'] ?? 0);
                    $prB = (float)($b['system_progress'] ?? $b['time_progress_percent'] ?? $b['progress_percent'] ?? 0);
                    if ($prA != $prB) return $prA <=> $prB;
                } elseif ($sortBy === 'progress_desc') {
                    $prA = (float)($a['system_progress'] ?? $a['time_progress_percent'] ?? $a['progress_percent'] ?? 0);
                    $prB = (float)($b['system_progress'] ?? $b['time_progress_percent'] ?? $b['progress_percent'] ?? 0);
                    if ($prA != $prB) return $prB <=> $prA;
                } elseif ($sortBy === 'title_asc') {
                    $res = strcasecmp((string)($a['title'] ?? ''), (string)($b['title'] ?? ''));
                    if ($res !== 0) return $res;
                }

                // Default: Smart priority score DESC, risk DESC, progress ASC, due ASC, remaining DESC
                $scoreA = (int)($a['smart_priority_score'] ?? 0);
                $scoreB = (int)($b['smart_priority_score'] ?? 0);
                if ($scoreA !== $scoreB) return $scoreB <=> $scoreA;

                $riskA = (int)($a['task_risk_score'] ?? 0);
                $riskB = (int)($b['task_risk_score'] ?? 0);
                if ($riskA !== $riskB) return $riskB <=> $riskA;

                $progressA = (float)($a['system_progress'] ?? $a['time_progress_percent'] ?? $a['progress_percent'] ?? 0);
                $progressB = (float)($b['system_progress'] ?? $b['time_progress_percent'] ?? $b['progress_percent'] ?? 0);
                if ($progressA != $progressB) return $progressA <=> $progressB;

                $dueA = strtotime((string)($a['due_at'] ?? '')) ?: PHP_INT_MAX;
                $dueB = strtotime((string)($b['due_at'] ?? '')) ?: PHP_INT_MAX;
                if ($dueA !== $dueB) return $dueA <=> $dueB;

                $remainingA = (float)($a['remaining_hours'] ?? 0);
                $remainingB = (float)($b['remaining_hours'] ?? 0);
                return $remainingB <=> $remainingA;
            }
        );

        $stmt =
            $db->prepare(
                '
                SELECT
                    t.*,
                    c.code AS course_code,
                    c.name AS course_name,
                    c.color AS course_color,
                    c.credits AS course_credits,
                    c.grade_point AS course_grade_point,
                    (SELECT COALESCE(SUM(CASE WHEN status = \'running\' THEN duration_seconds + GREATEST(0, TIMESTAMPDIFF(SECOND, started_at, NOW())) ELSE duration_seconds END), 0) FROM task_work_sessions WHERE task_id = t.id AND user_id = t.user_id) AS focused_seconds
                FROM tasks t
                LEFT JOIN courses c
                    ON c.id = t.course_id
                    AND c.user_id = t.user_id
                WHERE t.user_id = ?
                '
            );

        $stmt->execute(
            [$userId]
        );

        $allTasks =
            array_map(
                'decorateTask',
                $stmt->fetchAll()
            );

        $summary = [
            'total' => count($allTasks),
            'due_soon' => 0,
            'overdue' => 0,
            'in_progress' => 0,
            'completed' => 0,
            'pending' => 0,
            'remaining_hours' => 0,
            'at_risk' => 0
        ];

        foreach ($allTasks as $task) {
            $bucket =
                classifyTaskUrgency(
                    (string)(
                        $task['due_at'] ?? ''
                    ),
                    (string)(
                        $task['status'] ?? ''
                    )
                );

            if ($bucket === 'due_soon') {
                $summary['due_soon']++;
            }

            if ($bucket === 'overdue') {
                $summary['overdue']++;
            }

            if (
                $task['status'] ===
                'in_progress'
            ) {
                $summary['in_progress']++;
            }

            if (
                $task['status'] ===
                'completed'
            ) {
                $summary['completed']++;
            }

            if (
                $task['status'] !== 'completed' &&
                in_array(
                    $task['status'],
                    [
                        'pending',
                        'not_started'
                    ],
                    true
                )
            ) {
                $summary['pending']++;
            }

            $summary[
                'remaining_hours'
            ] +=
                (float)(
                    $task[
                        'remaining_hours'
                    ] ?? 0
                );

            if (
                $task['status'] !== 'completed' &&
                (
                    (int)(
                        $task[
                            'task_risk_score'
                        ] ?? 0
                    )
                ) >= 60
            ) {
                $summary['at_risk']++;
            }
        }

        $summary[
            'remaining_hours'
        ] =
            round(
                $summary[
                    'remaining_hours'
                ],
                2
            );

        $activeTasks =
            array_values(
                array_filter(
                    $allTasks,
                    static function (
                        array $task
                    ): bool {
                        return
                            $task['status'] !==
                            'completed';
                    }
                )
            );

        usort(
            $activeTasks,
            static function (
                array $a,
                array $b
            ): int {
                $scoreA =
                    (int)(
                        $a[
                            'smart_priority_score'
                        ] ?? 0
                    );

                $scoreB =
                    (int)(
                        $b[
                            'smart_priority_score'
                        ] ?? 0
                    );

                if ($scoreA !== $scoreB) {
                    return $scoreB <=> $scoreA;
                }

                $riskA =
                    (int)(
                        $a[
                            'task_risk_score'
                        ] ?? 0
                    );

                $riskB =
                    (int)(
                        $b[
                            'task_risk_score'
                        ] ?? 0
                    );

                if ($riskA !== $riskB) {
                    return $riskB <=> $riskA;
                }

                $progressA =
                    (int)(
                        $a[
                            'progress_percent'
                        ] ?? 0
                    );

                $progressB =
                    (int)(
                        $b[
                            'progress_percent'
                        ] ?? 0
                    );

                if ($progressA !== $progressB) {
                    return $progressA <=> $progressB;
                }

                $dueA =
                    strtotime(
                        (string)(
                            $a['due_at'] ?? ''
                        )
                    ) ?: PHP_INT_MAX;

                $dueB =
                    strtotime(
                        (string)(
                            $b['due_at'] ?? ''
                        )
                    ) ?: PHP_INT_MAX;

                if ($dueA !== $dueB) {
                    return $dueA <=> $dueB;
                }

                $remainingA =
                    (float)(
                        $a[
                            'remaining_hours'
                        ] ?? 0
                    );

                $remainingB =
                    (float)(
                        $b[
                            'remaining_hours'
                        ] ?? 0
                    );

                return $remainingB <=> $remainingA;
            }
        );

        $recommendedTask =
            $activeTasks[0] ?? null;

        taskApiJson([
            'tasks' =>
                $tasks,

            'task' =>
                !empty($_GET['id']) ? ($tasks[0] ?? null) : null,

            'summary' =>
                $summary,

            'recommended_task' =>
                $recommendedTask,

            'server_time' =>
                date('c')
        ]);

        break;

    case 'POST':

        $body =
            json_decode(
                file_get_contents(
                    'php://input'
                ),
                true
            ) ?? [];

        verifyCsrf(
            $body['csrf_token'] ?? null
        );

        $title =
            trim(
                (string)(
                    $body['title'] ?? ''
                )
            );

        $dueAt =
            trim(
                (string)(
                    $body['due_at'] ?? $body['due_date'] ?? ''
                )
            );

        if (
            $title === '' ||
            $dueAt === ''
        ) {
            taskApiJson(
                [
                    'error' =>
                        'Title and due date are required.'
                ],
                422
            );
        }

        $cleanDueAt = str_replace('T', ' ', $dueAt);
        $dueDate =
            DateTime::createFromFormat(
                'Y-m-d H:i:s',
                $cleanDueAt
            )
            ?:
            DateTime::createFromFormat(
                'Y-m-d\TH:i',
                $dueAt
            )
            ?:
            DateTime::createFromFormat(
                'Y-m-d H:i',
                $cleanDueAt
            )
            ?:
            DateTime::createFromFormat(
                'Y-m-d',
                $dueAt
            );

        if (!$dueDate) {
            taskApiJson(
                [
                    'error' =>
                        'Invalid deadline format.'
                ],
                422
            );
        }

        $status =
            normalizeTaskStatus(
                (string)(
                    $body['status']
                    ?? 'pending'
                )
            );

        $progress =
            max(
                0,
                min(
                    100,
                    (int)(
                        $body[
                            'progress_percent'
                        ] ?? 0
                    )
                )
            );

        if (
            $status ===
            'completed'
        ) {
            $progress = 100;
        }

        $duration =
            max(
                0,
                min(
                    999,
                    (float)(
                        $body[
                            'duration_hours'
                        ] ?? 0
                    )
                )
            );

        $stmt =
            $db->prepare(
                '
                INSERT INTO tasks
                (
                    user_id,
                    course_id,
                    title,
                    description,
                    type,
                    priority,
                    status,
                    progress_percent,
                    duration_hours,
                    due_at
                )
                VALUES
                (
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?,
                    ?
                )
                '
            );

        $rawCourseId = $body['course_id'] ?? null;
        $courseId = null;
        if ($rawCourseId !== null && $rawCourseId !== '') {
            $courseId = ownedCourseIdOrNull($db, $rawCourseId, $userId);
            if ($courseId === null) {
                taskApiJson(
                    [
                        'error' => 'Selected course does not exist or does not belong to your account.'
                    ],
                    422
                );
            }
        }

        $stmt->execute([
            $userId,

            $courseId,

            $title,

            trim(
                (string)(
                    $body['description'] ?? ''
                )
            ) ?: null,

            normalizeTaskType(
                (string)(
                    $body['type']
                    ?? 'assignment'
                )
            ),

            normalizeTaskPriority(
                (string)(
                    $body['priority']
                    ?? 'medium'
                )
            ),

            $status,

            $progress,

            $duration,

            $dueDate->format(
                'Y-m-d H:i:s'
            )
        ]);

        $taskId =
            $db->lastInsertId();

        logActivity(
            $userId,
            "New task added: {$title}",
            'info'
        );

        $reminderTime =
            (clone $dueDate)
                ->modify(
                    '-' .
                    REMINDER_LEAD_HOURS .
                    ' hours'
                )
                ->format(
                    'Y-m-d H:i:s'
                );

        $insertNotif =
            $db->prepare(
                '
                INSERT INTO notifications
                (
                    user_id,
                    task_id,
                    channel,
                    message,
                    send_at
                )
                VALUES (?, ?, ?, ?, ?)
                '
            );

        $insertNotif->execute([
            $userId,
            $taskId,
            'in_app',
            "\"{$title}\" is due soon",
            $reminderTime
        ]);

        if (EMAIL_ENABLED) {
            $insertNotif->execute([
                $userId,
                $taskId,
                'email',
                "\"{$title}\" is due soon",
                $reminderTime
            ]);
        }

        taskApiJson([
            'ok' => true,
            'id' => $taskId,
            'course_id' => $courseId,
            'task' => [
                'id' => $taskId,
                'course_id' => $courseId,
                'title' => $title,
                'status' => $status,
                'due_at' => $dueDate->format('Y-m-d H:i:s')
            ]
        ], 201);

        break;

    case 'PUT':

        $body =
            json_decode(
                file_get_contents(
                    'php://input'
                ),
                true
            ) ?? [];

        verifyCsrf(
            $body['csrf_token'] ?? null
        );

        $id =
            (int)(
                $body['id'] ?? 0
            );

        $stmt =
            $db->prepare(
                '
                SELECT
                    id,
                    title,
                    description,
                    type,
                    priority,
                    status,
                    progress_percent,
                    duration_hours,
                    completed_at,
                    due_at,
                    course_id
                FROM tasks
                WHERE id = ?
                AND user_id = ?
                '
            );

        $stmt->execute([
            $id,
            $userId
        ]);

        $existing =
            $stmt->fetch();

        if (!$existing) {
            taskApiJson(
                [
                    'error' =>
                        'Task not found.'
                ],
                404
            );
        }

        $wasAlreadyCompleted = ($existing['status'] === 'completed');
        $isReopenAction = (!empty($body['action']) && $body['action'] === 'reopen')
            || (!empty($body['reopen']))
            || (isset($body['status']) && $body['status'] !== 'completed' && $wasAlreadyCompleted && !empty($body['is_reopen']));

        if ($wasAlreadyCompleted && !$isReopenAction) {
            // Case 5: Completed tasks must remain completed when edited
            $newStatus = 'completed';
            $completedAt = $existing['completed_at'] ?: date('Y-m-d H:i:s');
            $progress = 100;
        } elseif ($isReopenAction) {
            // Case 6: Deliberate Undo / Reopen
            // Preserves actual study-session history and factual focused time
            $totalFocused = getTaskTotalFocusedSeconds($db, $userId, $id);
            $newStatus = ($totalFocused > 0) ? 'in_progress' : 'pending';
            $completedAt = null;
            $rawDuration = (float)($body['duration_hours'] ?? $existing['duration_hours'] ?? 0);
            if ($rawDuration > 0 && $totalFocused > 0) {
                $progress = min(99, (int)floor(($totalFocused / ($rawDuration * 3600)) * 100));
            } else {
                $progress = 0;
            }
        } else {
            // Standard status update (e.g. Deliberate Manual Completion or normal edit)
            $newStatus = normalizeTaskStatus(
                (string)($body['status'] ?? $existing['status'] ?? 'pending')
            );
            $completedAt = ($newStatus === 'completed') ? date('Y-m-d H:i:s') : null;
            $progress = ($newStatus === 'completed')
                ? 100
                : max(0, min(100, (int)($body['progress_percent'] ?? $existing['progress_percent'] ?? 0)));
        }

        $dueAt =
            trim(
                (string)(
                    $body['due_at']
                    ?? $body['due_date']
                    ?? ''
                )
            );

        $cleanDueAt = str_replace('T', ' ', $dueAt);
        $dueDate =
            $dueAt !== ''
                ? (
                    DateTime::createFromFormat(
                        'Y-m-d H:i:s',
                        $cleanDueAt
                    )
                    ?:
                    DateTime::createFromFormat(
                        'Y-m-d\TH:i',
                        $dueAt
                    )
                    ?:
                    DateTime::createFromFormat(
                        'Y-m-d H:i',
                        $cleanDueAt
                    )
                    ?:
                    DateTime::createFromFormat(
                        'Y-m-d',
                        $dueAt
                    )
                )
                : new DateTime(
                    $existing['due_at']
                );

        if (!$dueDate) {
            taskApiJson(
                [
                    'error' =>
                        'Invalid deadline format.'
                ],
                422
            );
        }

        $stmt =
            $db->prepare(
                '
                UPDATE tasks SET
                    title=?,
                    description=?,
                    type=?,
                    priority=?,
                    status=?,
                    progress_percent=?,
                    duration_hours=?,
                    due_at=?,
                    completed_at=?,
                    course_id=?
                WHERE id=?
                AND user_id=?
                '
            );

        $rawCourseId = array_key_exists('course_id', $body) ? $body['course_id'] : ($existing['course_id'] ?? null);
        $courseId = null;
        if ($rawCourseId !== null && $rawCourseId !== '') {
            $courseId = ownedCourseIdOrNull($db, $rawCourseId, $userId);
            if ($courseId === null) {
                taskApiJson(
                    [
                        'error' => 'Selected course does not exist or does not belong to your account.'
                    ],
                    422
                );
            }
        }

        $stmt->execute([
            trim(
                (string)(
                    $body['title']
                    ?? $existing['title']
                )
            ) ?: $existing['title'],

            trim(
                (string)(
                    $body['description']
                    ?? ''
                )
            ) ?: null,

            normalizeTaskType(
                (string)(
                    $body['type']
                    ?? 'assignment'
                )
            ),

            normalizeTaskPriority(
                (string)(
                    $body['priority']
                    ?? 'medium'
                )
            ),

            $newStatus,

            $progress,

            max(
                0,
                min(
                    999,
                    (float)(
                        $body[
                            'duration_hours'
                        ] ?? 0
                    )
                )
            ),

            $dueDate->format(
                'Y-m-d H:i:s'
            ),

            $completedAt,

            $courseId,

            $id,

            $userId
        ]);

        if (
            $newStatus ===
            'completed' &&
            !$wasAlreadyCompleted
        ) {
            logActivity(
                $userId,
                "You completed {$existing['title']}",
                'success'
            );

            $stmtNotif = $db->prepare(
                'UPDATE notifications SET read_at = NOW() WHERE task_id = ? AND user_id = ? AND read_at IS NULL'
            );
            $stmtNotif->execute([$id, $userId]);

            // Resolve course code for notification
            $notifCourseCode = '';
            $effectiveCid = ownedCourseIdOrNull($db, $body['course_id'] ?? $existing['course_id'], $userId);
            if ($effectiveCid) {
                $cStmt = $db->prepare('SELECT code FROM courses WHERE id = ? AND user_id = ?');
                $cStmt->execute([$effectiveCid, $userId]);
                $cRow = $cStmt->fetch();
                if (!empty($cRow['code'])) {
                    $notifCourseCode = $cRow['code'];
                }
            }

            $taskLabel = $notifCourseCode !== ''
                ? "{$notifCourseCode}: '{$existing['title']}'"
                : "'{$existing['title']}'";

            $completedTs = strtotime($completedAt ?: 'now');
            $eventKey = "task_completed_{$id}_{$completedTs}";
            $notifMsg = "Your {$taskLabel} has been marked as completed.";

            $stmtCheck = $db->prepare('SELECT id FROM notifications WHERE user_id = ? AND event_key = ? LIMIT 1');
            $stmtCheck->execute([$userId, $eventKey]);
            if (!$stmtCheck->fetch()) {
                $stmtIns = $db->prepare(
                    "INSERT INTO notifications (user_id, task_id, channel, event_key, message, send_at)
                     VALUES (?, ?, 'in_app', ?, ?, NOW())"
                );
                $stmtIns->execute([$userId, $id, $eventKey, $notifMsg]);
            }
        } elseif ($newStatus !== 'completed' && $wasAlreadyCompleted) {
            logActivity(
                $userId,
                "Task reopened: {$existing['title']}",
                'info'
            );

            // Resolve course code for reopened notification
            $notifCourseCode = '';
            $effectiveCid = ownedCourseIdOrNull($db, $body['course_id'] ?? $existing['course_id'], $userId);
            if ($effectiveCid) {
                $cStmt = $db->prepare('SELECT code FROM courses WHERE id = ? AND user_id = ?');
                $cStmt->execute([$effectiveCid, $userId]);
                $cRow = $cStmt->fetch();
                if (!empty($cRow['code'])) {
                    $notifCourseCode = $cRow['code'];
                }
            }

            $taskLabel = $notifCourseCode !== ''
                ? "{$notifCourseCode}: '{$existing['title']}'"
                : "'{$existing['title']}'";

            $reopenTs = time();
            $eventKey = "task_reopened_{$id}_{$reopenTs}";
            $notifMsg = "Your {$taskLabel} has been moved back to active work.";

            $stmtIns = $db->prepare(
                "INSERT INTO notifications (user_id, task_id, channel, event_key, message, send_at)
                 VALUES (?, ?, 'in_app', ?, ?, NOW())"
            );
            $stmtIns->execute([$userId, $id, $eventKey, $notifMsg]);
        }

        taskApiJson([
            'ok' => true,
            'id' => $id,
            'course_id' => $courseId,
            'task' => [
                'id' => $id,
                'course_id' => $courseId,
                'title' => trim((string)($body['title'] ?? $existing['title'])),
                'status' => $newStatus,
                'progress_percent' => $progress
            ]
        ], 200);

        break;

    case 'DELETE':

        parse_str(
            file_get_contents(
                'php://input'
            ),
            $body
        );

        verifyCsrf(
            $body['csrf_token'] ?? null
        );

        $id =
            (int)(
                $body['id'] ?? 0
            );

        $stmt =
            $db->prepare(
                '
                DELETE FROM tasks
                WHERE id = ?
                AND user_id = ?
                '
            );

        $stmt->execute([
            $id,
            $userId
        ]);

        taskApiJson([
            'ok' => true
        ]);

        break;

    default:

        taskApiJson(
            [
                'error' =>
                    'Method not allowed'
            ],
            405
        );
}
