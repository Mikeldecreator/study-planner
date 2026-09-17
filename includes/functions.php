<?php
/**
 * This file exists to solve one specific design risk: the dashboard shows
 * "due soon" / "overdue" counts in FOUR different widgets (stat cards,
 * upcoming deadlines list, task breakdown donut, and notification triggers).
 * If each of those computed "is this overdue?" independently, they could
 * drift out of sync. Every one of them calls the functions below instead.
 */

const DUE_SOON_WINDOW_DAYS = 3;

/**
 * Classify a single task's urgency bucket, given its due datetime and status.
 * Returns one of: 'overdue' | 'due_soon' | 'upcoming' | 'completed'
 */
function classifyTaskUrgency(string $dueAt, string $status): string
{
    if ($status === 'completed') {
        return 'completed';
    }

    $due = new DateTime($dueAt);
    $now = new DateTime();

    if ($due < $now) {
        return 'overdue';
    }

    $diffDays = (int) $now->diff($due)->format('%a');
    if ($diffDays <= DUE_SOON_WINDOW_DAYS) {
        return 'due_soon';
    }

    return 'upcoming';
}

/** Human label for the priority pill, e.g. "HIGH PRIORITY" — matches the mockup. */
function priorityLabel(string $priority): string
{
    return strtoupper($priority) . ' PRIORITY';
}

/** Tailwind color classes per priority, so every page styles pills identically. */
function priorityColorClasses(string $priority): string
{
    return match ($priority) {
        'high'   => 'bg-red-50 text-red-600',
        'medium' => 'bg-amber-50 text-amber-600',
        'low'    => 'bg-green-50 text-green-700',
        default  => 'bg-gray-50 text-gray-600',
    };
}

/** Friendly "Due Tomorrow" / "Due in 3 days" / "Overdue" strings for the UI. */
function dueRelativeLabel(string $dueAt): string
{
    $due = new DateTime($dueAt);
    $now = new DateTime();
    $now->setTime(0, 0);
    $dueDay = (clone $due)->setTime(0, 0);

    $diffDays = (int) $now->diff($dueDay)->format('%r%a');

    if ($diffDays < 0)  return 'Overdue';
    if ($diffDays === 0) return 'Due Today';
    if ($diffDays === 1) return 'Due Tomorrow';
    return "Due in {$diffDays} days";
}

/**
 * Fetch the current user's profile/preferences row.
 *
 * The `dark_mode`, `level`, `program`, `weekly_goal_hours`,
 * `notifications_enabled` and `week_start_day` columns were added to
 * `users` after the Settings page shipped (see database/schema.sql).
 * A database that was imported before that migration ran is missing
 * those columns, which would otherwise throw an uncaught PDOException
 * here, return a 500/HTML response instead of JSON, and make every
 * protected page's auth check (which calls this via api/me.php) look
 * like the session is invalid — bouncing a logged-in user straight
 * back to the login page. Falling back to safe defaults keeps the
 * app usable (and the real fix — running the migration in
 * database/schema.sql — visible) instead of hard-crashing.
 */
function getUserProfileRow(int $userId): array
{
    $defaults = [
        'full_name'             => null,
        'email'                 => null,
        'dark_mode'             => 0,
        'level'                 => '',
        'program'               => '',
        'tagline'               => 'Better plans. Bigger goals.',
        'weekly_goal_hours'     => 0,
        'notifications_enabled' => 1,
        'week_start_day'        => 1,
    ];

    try {
        $stmt = getDb()->prepare(
            'SELECT
                full_name,
                email,
                dark_mode,
                level,
                program,
                tagline,
                weekly_goal_hours,
                notifications_enabled,
                week_start_day
             FROM users
             WHERE id = ?'
        );

        $stmt->execute([$userId]);

        $row = $stmt->fetch();

        return $row
            ? array_merge($defaults, $row)
            : $defaults;

    } catch (PDOException $e) {

        /*
         * Allows the application to continue working
         * when the database migration has not yet been run.
         */
        if ($e->getCode() !== '42S22') {
            throw $e;
        }

        $stmt = getDb()->prepare(
            'SELECT full_name, email
             FROM users
             WHERE id = ?'
        );

        $stmt->execute([$userId]);

        $row = $stmt->fetch();

        return $row
            ? array_merge($defaults, $row)
            : $defaults;
    }
}         

/**
 * Resolve a client-supplied course_id to a value that's safe to store on a
 * task or schedule_events row: null if absent/empty, or the id itself only
 * if it actually belongs to this user. Without this check, a hand-crafted
 * request (course dropdowns in the UI only ever list the current user's own
 * courses, so this isn't reachable through normal use) could attach a task
 * or session to a course_id borrowed from someone else's account — the FK
 * constraint only requires the row to exist, not that it belongs to the
 * caller.
 */
function ownedCourseIdOrNull(PDO $db, $courseId, int $userId): ?int
{
    if (empty($courseId)) {
        return null;
    }
    $stmt = $db->prepare('SELECT id FROM courses WHERE id = ? AND user_id = ?');
    $stmt->execute([(int) $courseId, $userId]);
    return $stmt->fetch() ? (int) $courseId : null;
}


/**
 * Seed/repair the current user's academic data from the bundled demo account.
 *
 * Why this exists:
 * Older copies of this project stored all seed rows under user_id = 1.
 * A real account created later gets another user_id, so every protected API
 * correctly filters those rows out. We copy only the bundled demo account's
 * rows, only when the target account is completely empty, and remap every
 * foreign key so ownership remains correct.
 */
function ensureUserDataSeeded(int $userId): void
{
    if ($userId <= 0) {
        return;
    }

    $db = getDb();

    // The bundled demo account is intentionally identified by email, not ID.
    // That makes this safe even if another row was inserted before it.
    $demoStmt = $db->prepare("SELECT id FROM users WHERE email = 'michael@example.com' LIMIT 1");
    $demoStmt->execute();
    $demoUserId = (int) ($demoStmt->fetchColumn() ?: 0);
    if ($demoUserId <= 0 || $demoUserId === $userId) {
        return;
    }

    // Do not overwrite or merge into an account that already has data.
    $checks = [
        'courses', 'tasks', 'schedule_events', 'notifications', 'activity_log'
    ];
    foreach ($checks as $table) {
        $stmt = $db->prepare("SELECT 1 FROM {$table} WHERE user_id = ? LIMIT 1");
        $stmt->execute([$userId]);
        if ($stmt->fetchColumn()) {
            return;
        }
    }

    $db->beginTransaction();
    try {
        $courseMap = [];
        $stmt = $db->prepare('SELECT id, code, name, lecturer, credits, semester, icon, color, grade_point FROM courses WHERE user_id = ? ORDER BY id');
        $stmt->execute([$demoUserId]);
        $demoCourses = $stmt->fetchAll();

        foreach ($demoCourses as $course) {
            $insert = $db->prepare(
                'INSERT INTO courses (user_id, code, name, lecturer, credits, semester, icon, color, grade_point)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $insert->execute([
                $userId,
                $course['code'],
                $course['name'],
                $course['lecturer'],
                $course['credits'],
                $course['semester'],
                $course['icon'],
                $course['color'],
                $course['grade_point'],
            ]);
            $courseMap[(int) $course['id']] = (int) $db->lastInsertId();
        }

        $taskMap = [];
        $stmt = $db->prepare(
            'SELECT id, course_id, title, description, type, priority, status, progress_percent,
                    duration_hours, due_at, completed_at, created_at, updated_at
             FROM tasks WHERE user_id = ? ORDER BY id'
        );
        $stmt->execute([$demoUserId]);
        $demoTasks = $stmt->fetchAll();

        foreach ($demoTasks as $task) {
            $newCourseId = $task['course_id'] === null ? null : ($courseMap[(int) $task['course_id']] ?? null);
            $insert = $db->prepare(
                'INSERT INTO tasks (user_id, course_id, title, description, type, priority, status,
                                    progress_percent, duration_hours, due_at, completed_at, created_at, updated_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $insert->execute([
                $userId,
                $newCourseId,
                $task['title'],
                $task['description'],
                $task['type'],
                $task['priority'],
                $task['status'],
                $task['progress_percent'],
                $task['duration_hours'],
                $task['due_at'],
                $task['completed_at'],
                $task['created_at'],
                $task['updated_at'],
            ]);
            $taskMap[(int) $task['id']] = (int) $db->lastInsertId();
        }

        $stmt = $db->prepare(
            'SELECT id, course_id, task_id, title, event_type, day_of_week, start_time, end_time, is_completed, created_at
             FROM schedule_events WHERE user_id = ? ORDER BY id'
        );
        $stmt->execute([$demoUserId]);
        foreach ($stmt->fetchAll() as $event) {
            $newCourseId = $event['course_id'] === null ? null : ($courseMap[(int) $event['course_id']] ?? null);
            $newTaskId = $event['task_id'] === null ? null : ($taskMap[(int) $event['task_id']] ?? null);
            $insert = $db->prepare(
                'INSERT INTO schedule_events (user_id, course_id, task_id, title, event_type, day_of_week,
                                              start_time, end_time, is_completed, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $insert->execute([
                $userId,
                $newCourseId,
                $newTaskId,
                $event['title'],
                $event['event_type'],
                $event['day_of_week'],
                $event['start_time'],
                $event['end_time'],
                $event['is_completed'],
                $event['created_at'],
            ]);
        }

        $stmt = $db->prepare(
            'SELECT task_id, channel, message, send_at, sent_at, read_at, created_at
             FROM notifications WHERE user_id = ? ORDER BY id'
        );
        $stmt->execute([$demoUserId]);
        foreach ($stmt->fetchAll() as $notification) {
            $newTaskId = $notification['task_id'] === null ? null : ($taskMap[(int) $notification['task_id']] ?? null);
            $insert = $db->prepare(
                'INSERT INTO notifications (user_id, task_id, channel, message, send_at, sent_at, read_at, created_at)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $insert->execute([
                $userId,
                $newTaskId,
                $notification['channel'],
                $notification['message'],
                $notification['send_at'],
                $notification['sent_at'],
                $notification['read_at'],
                $notification['created_at'],
            ]);
        }

        $stmt = $db->prepare(
            'SELECT message, icon, created_at FROM activity_log WHERE user_id = ? ORDER BY id'
        );
        $stmt->execute([$demoUserId]);
        foreach ($stmt->fetchAll() as $activity) {
            $insert = $db->prepare(
                'INSERT INTO activity_log (user_id, message, icon, created_at) VALUES (?, ?, ?, ?)'
            );
            $insert->execute([$userId, $activity['message'], $activity['icon'], $activity['created_at']]);
        }

        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        // A seed-data repair must never prevent the user from signing in.
        // Real API/database errors are still handled normally elsewhere.
    }
}

function h(?string $str): string
{
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}

/** "2 hours ago" style relative time for the Recent Activity feed. */
function timeAgo(string $datetime): string
{
    $diff = time() - strtotime($datetime);
    if ($diff < 60)    return 'just now';
    if ($diff < 3600)  return floor($diff / 60) . ' min ago';
    if ($diff < 86400) return floor($diff / 3600) . ' hour' . (floor($diff / 3600) == 1 ? '' : 's') . ' ago';
    return floor($diff / 86400) . ' day' . (floor($diff / 86400) == 1 ? '' : 's') . ' ago';
}

/* ============================================================
   TASK INTELLIGENCE ENGINE
   Server-side source of truth for academic task priority.
============================================================ */

/**
 * Estimate total workload when the student did not provide hours.
 *
 * If duration_hours exists, that student-provided estimate remains
 * authoritative. Otherwise we use the academic task type and the
 * amount of written information as a fallback estimate.
 */
function estimateTaskWorkloadHours(array $task): float
{
    $stored = (float) ($task['duration_hours'] ?? 0);

    if ($stored > 0) {
        return round(max(0, min(999, $stored)), 2);
    }

    $type = strtolower((string) ($task['type'] ?? 'assignment'));

    $baseHours = [
        'assignment'    => 2.0,
        'project'       => 8.0,
        'test'          => 3.0,
        'exam'          => 4.0,
        'research'      => 6.0,
        'lab_report'    => 3.0,
        'study_session' => 2.0,
        'other'         => 2.0,
    ];

    $base = $baseHours[$type] ?? 2.0;

    $text = trim(
        (string) ($task['title'] ?? '') . ' ' .
        (string) ($task['description'] ?? '')
    );

    /*
     * Longer task descriptions usually indicate a more substantial
     * piece of academic work. Keep the adjustment conservative.
     */
    $lengthBonus = min(
        3.0,
        floor(strlen($text) / 180) * 0.5
    );

    return round($base + $lengthBonus, 2);
}


/**
 * Academic assessment type risk.
 */
function taskTypeRiskScore(string $type): int
{
    $scores = [
        'exam'          => 100,
        'test'          => 90,
        'project'       => 82,
        'research'      => 75,
        'lab_report'    => 72,
        'assignment'    => 70,
        'other'         => 55,
        'study_session' => 45,
    ];

    return $scores[strtolower($type)] ?? 55;
}


/**
 * Student-entered priority.
 *
 * This is intentionally only one part of the final smart score.
 * A task marked "low" can still become critical when overdue.
 */
function manualPriorityScore(string $priority): int
{
    $scores = [
        'high'   => 100,
        'medium' => 55,
        'low'    => 20,
    ];

    return $scores[strtolower($priority)] ?? 55;
}


/**
 * Course academic risk.
 *
 * More credits = more academic weight.
 * Lower GPA in a course = higher risk.
 * Courses without a grade yet receive a neutral score.
 */
function courseRiskScore($gradePoint, $credits): int
{
    $credits = max(1, (int) $credits);

    if ($gradePoint === null) {
        $gradeRisk = 50;
    } elseif ((float) $gradePoint <= 2.0) {
        $gradeRisk = 100;
    } elseif ((float) $gradePoint <= 2.5) {
        $gradeRisk = 82;
    } elseif ((float) $gradePoint <= 3.0) {
        $gradeRisk = 62;
    } elseif ((float) $gradePoint <= 3.5) {
        $gradeRisk = 38;
    } else {
        $gradeRisk = 18;
    }

    $creditWeight = min(
        100,
        50 + (($credits - 1) * 12)
    );

    return (int) round(
        ($gradeRisk * 0.65) +
        ($creditWeight * 0.35)
    );
}


/**
 * Build the complete academic intelligence profile for one task.
 */
function buildTaskIntelligence(array $task): array
{
    $now = new DateTime();
    $due = new DateTime((string) $task['due_at']);

    $hoursRemaining =
        ($due->getTimestamp() - $now->getTimestamp()) / 3600;

    $workload =
        estimateTaskWorkloadHours($task);

    $progress = max(
        0,
        min(
            100,
            (int) ($task['progress_percent'] ?? 0)
        )
    );

    if (($task['status'] ?? '') === 'completed') {
        $progress = 100;
    }

    /*
     * Remaining academic work.
     */
    $remainingHours = round(
        $workload * (1 - ($progress / 100)),
        2
    );

    $remainingMinutes = max(
        0,
        (int) round($remainingHours * 60)
    );


    /* --------------------------------------------------------
       DEADLINE URGENCY
    -------------------------------------------------------- */

    if (($task['status'] ?? '') === 'completed') {

        $deadlineScore = 0;
        $deadlineUrgency = 'completed';

    } elseif ($hoursRemaining < 0) {

        $deadlineScore = 100;
        $deadlineUrgency = 'overdue';

    } elseif ($hoursRemaining <= 6) {

        $deadlineScore = 98;
        $deadlineUrgency = 'critical';

    } elseif ($hoursRemaining <= 24) {

        $deadlineScore = 90;
        $deadlineUrgency = 'urgent';

    } elseif ($hoursRemaining <= 48) {

        $deadlineScore = 78;
        $deadlineUrgency = 'soon';

    } elseif ($hoursRemaining <= 72) {

        $deadlineScore = 65;
        $deadlineUrgency = 'approaching';

    } elseif ($hoursRemaining <= 168) {

        $deadlineScore = 45;
        $deadlineUrgency = 'this_week';

    } else {

        $deadlineScore = 20;
        $deadlineUrgency = 'upcoming';
    }


    /* --------------------------------------------------------
       REMAINING WORK
    -------------------------------------------------------- */

    $remainingScore =
        $progress >= 100
            ? 0
            : 100 - $progress;


    /* --------------------------------------------------------
       WORKLOAD PRESSURE

       Example:
       8 hours remaining
       4 hours until deadline

       = very high pressure.
    -------------------------------------------------------- */

    $workloadPressure = 0;

    if (($task['status'] ?? '') !== 'completed') {

        if ($hoursRemaining <= 0) {

            $workloadPressure =
                $remainingHours > 0
                    ? 100
                    : 80;

        } else {

            $ratio =
                $remainingHours /
                max(1, $hoursRemaining);

            $workloadPressure =
                (int) round(
                    min(
                        100,
                        $ratio * 100
                    )
                );
        }
    }


    /* --------------------------------------------------------
       COURSE RELATIONSHIP
    -------------------------------------------------------- */

    $courseRisk =
        courseRiskScore(
            array_key_exists(
                'course_grade_point',
                $task
            )
                ? $task['course_grade_point']
                : null,
            $task['course_credits'] ?? 0
        );


    /* --------------------------------------------------------
       TASK TYPE RISK
    -------------------------------------------------------- */

    $typeScore =
        taskTypeRiskScore(
            (string) (
                $task['type'] ?? 'other'
            )
        );


    /* --------------------------------------------------------
       OVERALL TASK RISK
    -------------------------------------------------------- */

    $riskScore = (int) round(
        ($deadlineScore * 0.40) +
        ($workloadPressure * 0.30) +
        ($remainingScore * 0.15) +
        ($typeScore * 0.10) +
        ($courseRisk * 0.05)
    );


    /* --------------------------------------------------------
       SMART PRIORITY

       This is the number that determines:
       "What should the student work on next?"
    -------------------------------------------------------- */

    $smartScore = (int) round(
        ($deadlineScore * 0.35) +
        ($riskScore * 0.25) +
        ($remainingScore * 0.15) +
        ($workloadPressure * 0.15) +
        (
            manualPriorityScore(
                (string) (
                    $task['priority'] ?? 'medium'
                )
            ) * 0.07
        ) +
        ($courseRisk * 0.03)
    );


    if (($task['status'] ?? '') === 'completed') {

        $smartScore = 0;
        $riskScore = 0;
    }


    $smartScore =
        max(
            0,
            min(
                100,
                $smartScore
            )
        );


    /* --------------------------------------------------------
       LABELS
    -------------------------------------------------------- */

    $smartLabel =
        $smartScore >= 85
            ? 'Critical'
            : (
                $smartScore >= 70
                    ? 'High'
                    : (
                        $smartScore >= 50
                            ? 'Medium'
                            : 'Low'
                    )
            );


    $riskLabel =
        $riskScore >= 80
            ? 'Critical Risk'
            : (
                $riskScore >= 60
                    ? 'High Risk'
                    : (
                        $riskScore >= 35
                            ? 'Moderate Risk'
                            : 'Low Risk'
                    )
            );


    /* --------------------------------------------------------
       REASON
    -------------------------------------------------------- */

    if (
        $hoursRemaining < 0 &&
        $remainingHours > 0
    ) {

        $reason =
            'Overdue with unfinished work';

    } elseif ($workloadPressure >= 80) {

        $reason =
            'Heavy remaining workload for the time available';

    } elseif ($deadlineScore >= 90) {

        $reason =
            'Deadline is very close';

    } elseif (
        $remainingScore >= 80 &&
        $courseRisk >= 65
    ) {

        $reason =
            'Large amount of work in a high-risk course';

    } elseif ($typeScore >= 90) {

        $reason =
            'Assessment type has high academic impact';

    } elseif ($remainingScore >= 60) {

        $reason =
            'Most of the task is still unfinished';

    } else {

        $reason =
            'Best balance of urgency, workload and academic impact';
    }


    /* --------------------------------------------------------
       HUMAN DEADLINE DISPLAY
    -------------------------------------------------------- */

    $dueLabel =
        dueRelativeLabel(
            (string) $task['due_at']
        );


    if ($hoursRemaining < 0) {

        if (abs($hoursRemaining) >= 24) {

            $deadlineDisplay =
                'Overdue by ' .
                floor(
                    abs($hoursRemaining) / 24
                ) .
                'd ' .
                (
                    (int) abs($hoursRemaining) % 24
                ) .
                'h';

        } else {

            $deadlineDisplay =
                'Overdue by ' .
                max(
                    1,
                    (int) floor(
                        abs($hoursRemaining)
                    )
                ) .
                'h';
        }

    } elseif ($hoursRemaining < 1) {

        $deadlineDisplay =
            'Due in ' .
            max(
                1,
                (int) round(
                    $hoursRemaining * 60
                )
            ) .
            'm';

    } elseif ($hoursRemaining >= 24) {

        $deadlineDisplay =
            'Due in ' .
            floor(
                $hoursRemaining / 24
            ) .
            'd ' .
            (
                (int) floor(
                    $hoursRemaining
                ) % 24
            ) .
            'h';

    } else {

        $deadlineDisplay =
            'Due in ' .
            floor(
                $hoursRemaining
            ) .
            'h';
    }


    return [

        'estimated_workload_hours' =>
            $workload,

        'remaining_hours' =>
            $remainingHours,

        'remaining_minutes' =>
            $remainingMinutes,

        'hours_remaining' =>
            round(
                $hoursRemaining,
                2
            ),

        'deadline_urgency' =>
            $deadlineUrgency,

        'deadline_urgency_label' =>
            ucfirst(
                str_replace(
                    '_',
                    ' ',
                    $deadlineUrgency
                )
            ),

        'deadline_display' =>
            $deadlineDisplay,

        'workload_pressure' =>
            $workloadPressure,

        'course_risk_score' =>
            $courseRisk,

        'task_risk_score' =>
            $riskScore,

        'task_risk' =>
            $riskLabel,

        'smart_priority_score' =>
            $smartScore,

        'smart_priority_label' =>
            $smartLabel,

        'priority_reason' =>
            $reason,

        'due_label' =>
            $dueLabel,
    ];
}