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

switch ($method) {

    case 'GET':

        $sql = '
            SELECT
                t.*,
                c.code AS course_code,
                c.name AS course_name,
                c.color AS course_color,
                c.credits AS course_credits,
                c.grade_point AS course_grade_point
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
            if ($_GET['status'] === 'overdue') {
                $sql .= "
                    AND t.status != 'completed'
                    AND t.due_at < NOW()
                ";
            } else {
                $sql .= '
                    AND t.status = ?
                ';

                $params[] =
                    normalizeTaskStatus(
                        (string)$_GET['status']
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

        $sql .= '
            ORDER BY
                t.status = "completed" ASC,
                t.due_at ASC
        ';

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

        usort(
            $tasks,
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

        $stmt =
            $db->prepare(
                '
                SELECT
                    t.*,
                    c.code AS course_code,
                    c.name AS course_name,
                    c.credits AS course_credits,
                    c.grade_point AS course_grade_point
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
                    $body['due_at'] ?? ''
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

        $dueDate =
            DateTime::createFromFormat(
                'Y-m-d H:i:s',
                str_replace(
                    'T',
                    ' ',
                    $dueAt
                )
            )
            ?:
            DateTime::createFromFormat(
                'Y-m-d\TH:i',
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

        $stmt->execute([
            $userId,

            ownedCourseIdOrNull(
                $db,
                $body['course_id'] ?? null,
                $userId
            ),

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
            'id' => $taskId
        ]);

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
                    status,
                    completed_at,
                    due_at
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

        $newStatus =
            normalizeTaskStatus(
                (string)(
                    $body['status']
                    ?? 'pending'
                )
            );

        $wasAlreadyCompleted =
            $existing['status'] ===
            'completed';

        $completedAt = null;

        if (
            $newStatus ===
            'completed'
        ) {
            $completedAt =
                $wasAlreadyCompleted
                    ? $existing['completed_at']
                    : date(
                        'Y-m-d H:i:s'
                    );
        }

        $progress =
            $newStatus ===
            'completed'
                ? 100
                : max(
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

        $dueAt =
            trim(
                (string)(
                    $body['due_at']
                    ?? ''
                )
            );

        $dueDate =
            $dueAt !== ''
                ? (
                    DateTime::createFromFormat(
                        'Y-m-d H:i:s',
                        str_replace(
                            'T',
                            ' ',
                            $dueAt
                        )
                    )
                    ?:
                    DateTime::createFromFormat(
                        'Y-m-d\TH:i',
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

            ownedCourseIdOrNull(
                $db,
                $body['course_id'] ?? null,
                $userId
            ),

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
        }

        taskApiJson([
            'ok' => true
        ]);

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
