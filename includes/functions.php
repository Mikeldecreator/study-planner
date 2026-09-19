<?php
/**
 * This file exists to solve one specific design risk: the dashboard shows
 * "due soon" / "overdue" counts in FOUR different widgets (stat cards,
 * upcoming deadlines list, task breakdown donut, and notification triggers).
 * If each of those computed "is this overdue?" independently, they could
 * drift out of sync. Every one of them calls the functions below instead.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/document_processor.php';

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
        'weekly_goal_hours'     => 15.0,
        'notifications_enabled' => 1,
        'week_start_day'        => 1,
        'preferred_study_time'  => 'flexible',
        'preferred_study_days'  => '1,2,3,4,5',
        'tour_completed'        => 0,
        'dismissed_tips'        => '[]',
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
                week_start_day,
                preferred_study_time,
                preferred_study_days,
                tour_completed,
                dismissed_tips
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

        $result = $row
            ? array_merge($defaults, $row)
            : $defaults;
        $cache[$userId] = $result;
        return $result;
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
    // Multi-User Isolation Invariant:
    // Newly registered or empty accounts must NEVER be automatically populated with
    // records from other accounts. Every student has an isolated, private academic workspace.
    return;
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
 * Foundation 8D: Centralized System Task State Engine.
 * Determines authoritative task status from objective activity and evidence:
 * - system_progress >= 100% -> 'completed'
 * - focused_seconds > 0 and system_progress < 100% -> 'in_progress'
 * - rawStatus === 'completed' && userProgress >= 100 && focused_seconds === 0 -> 'completed' (historical/mock task)
 * - rawStatus === 'in_progress' -> 'in_progress'
 * - default -> 'pending'
 */
function determineSystemTaskStatus(array $task, array $timeMetrics): string
{
    $rawStatus = (string)($task['status'] ?? 'pending');
    $userProgress = max(0, min(100, (int)($task['progress_percent'] ?? 0)));
    $focusedSeconds = (int)($timeMetrics['focused_seconds'] ?? 0);
    $systemProgress = (float)($timeMetrics['time_progress'] ?? 0.0);

    // 1. Manual Completion is Authoritative:
    // When a student explicitly marks a task completed (or userProgress reached 100%),
    // manual completion is the authoritative completion status.
    if ($rawStatus === 'completed' || $userProgress >= 100) {
        return 'completed';
    }

    // 2. Objective completion: system progress >= 100%
    if ($systemProgress >= 100.0) {
        return 'completed';
    }

    // 3. Active study work: focused time > 0 and system progress < 100%
    if ($focusedSeconds > 0) {
        return 'in_progress';
    }

    // 4. User marked in_progress manually with 0 focus time
    if ($rawStatus === 'in_progress') {
        return 'in_progress';
    }

    if ($rawStatus === 'not_started') {
        return 'not_started';
    }

    // Default for newly created or unstarted tasks
    return 'pending';
}

/**
 * Build the complete academic intelligence profile for one task.
 */
function buildTaskIntelligence(array $task): array
{
    $now = new DateTime();
    $due = new DateTime((string) ($task['due_at'] ?? 'now + 7 days'));

    $hoursRemaining =
        ($due->getTimestamp() - $now->getTimestamp()) / 3600;

    $workload =
        estimateTaskWorkloadHours($task);

    $userProgress = max(
        0,
        min(
            100,
            (int) ($task['progress_percent'] ?? 0)
        )
    );

    $userStatus = (string) ($task['status'] ?? 'pending');

    // Foundation 8C: Resolve focused study time for the task
    if (isset($task['total_focused_seconds'])) {
        $focusedSeconds = max(0, (int) $task['total_focused_seconds']);
    } elseif (isset($task['focused_seconds'])) {
        $focusedSeconds = max(0, (int) $task['focused_seconds']);
    } elseif (isset($task['focused_hours'])) {
        $focusedSeconds = max(0, (int) round((float) $task['focused_hours'] * 3600));
    } elseif (!empty($task['id']) && !empty($task['user_id'])) {
        try {
            $focusedSeconds = getTaskTotalFocusedSeconds(getDb(), (int) $task['user_id'], (int) $task['id']);
        } catch (\Throwable $e) {
            $focusedSeconds = 0;
        }
    } else {
        $focusedSeconds = 0;
    }

    $timeMetrics = calculateTaskTimeProgress($task, $focusedSeconds);
    $rawDuration = isset($task['duration_hours']) ? (float) $task['duration_hours'] : 0.0;
    $systemProgress = (float) ($timeMetrics['time_progress'] ?? 0.0);

    // Foundation 8D: System Task State Engine determines authoritative status
    $systemStatus = determineSystemTaskStatus($task, $timeMetrics);
    $isSystemCompleted = ($systemStatus === 'completed');

    // User completion request and discrepancy detection (Informational Warning)
    $userCompletionRequest = ($userStatus === 'completed' || $userProgress >= 100);
    $progressDiscrepancy = false;
    $discrepancyNote = null;

    if ($userCompletionRequest) {
        // If user marked complete, but recorded study time is lower than estimated workload
        if ($timeMetrics['has_estimate'] && $systemProgress < 100.0) {
            $progressDiscrepancy = true;
            if ($focusedSeconds === 0) {
                $discrepancyNote = 'Task marked complete with no recorded focus time.';
            } else {
                $discrepancyNote = "Task marked complete with limited recorded study time ({$timeMetrics['focused_hours']}h of {$timeMetrics['estimated_duration_hours']}h estimate).";
            }
        }
    } elseif (abs($userProgress - $systemProgress) >= 20.0) {
        $progressDiscrepancy = true;
        $discrepancyNote = "User reported progress ({$userProgress}%) differs from system progress ({$systemProgress}%).";
    }

    /*
     * Remaining academic work.
     * Foundation 8D: Calculated strictly from objective system evidence without allowing
     * manual progress to override actual study sessions or duration.
     */
    if ($isSystemCompleted) {
        $remainingHours = 0.0;
        $remainingMinutes = 0;
    } elseif ($rawDuration > 0) {
        $remainingHours = (float) $timeMetrics['remaining_hours'];
        $remainingMinutes = max(0, (int) round($remainingHours * 60));
    } else {
        $remainingHours = max(0.0, round($workload - $timeMetrics['focused_hours'], 2));
        $remainingMinutes = max(0, (int) round($remainingHours * 60));
    }


    /* --------------------------------------------------------
       DEADLINE URGENCY
    -------------------------------------------------------- */

    if ($isSystemCompleted) {

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
       Foundation 8D: Objective system progress determines remaining work score.
    -------------------------------------------------------- */

    $remainingScore =
        ($isSystemCompleted || $systemProgress >= 100.0)
            ? 0
            : max(0, min(100, (int) round(100.0 - $systemProgress)));


    /* --------------------------------------------------------
       WORKLOAD PRESSURE

       Example:
       8 hours remaining
       4 hours until deadline

       = very high pressure.
    -------------------------------------------------------- */

    $workloadPressure = 0;

    if (!$isSystemCompleted) {

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


    if ($isSystemCompleted) {

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
            (string) ($task['due_at'] ?? '')
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


    /* --------------------------------------------------------
       RECOMMENDED ACTION
       Actionable, explainable recommendation in plain language.
    -------------------------------------------------------- */

    if ($isSystemCompleted) {
        $action = 'No action required — task is completed.';
    } elseif ($hoursRemaining < 0 && $remainingHours > 0) {
        $action = 'Complete and submit this overdue task immediately.';
    } elseif ($deadlineUrgency === 'critical') {
        $action = 'Focus exclusively on finishing and submitting this task before the deadline.';
    } elseif ($workloadPressure >= 80) {
        $action = 'Allocate dedicated study blocks now; remaining work exceeds comfortable completion time.';
    } elseif ($deadlineUrgency === 'urgent') {
        $action = 'Prioritize this task today to ensure on-time submission.';
    } elseif ($typeScore >= 90) {
        $action = 'Schedule targeted revision sessions for this high-impact assessment.';
    } elseif ($courseRisk >= 65) {
        $action = 'Spend extra study time on this task to protect your standing in this high-risk course.';
    } elseif ($remainingHours > 4) {
        $action = 'Break this substantial task into smaller 1-2 hour study sessions.';
    } else {
        $action = 'Maintain steady progress according to your study plan.';
    }

    $temporalUrgency = classifyTaskUrgency(
        (string) ($task['due_at'] ?? ''),
        $systemStatus
    );

    $riskLevel = strtolower(str_replace(' Risk', '', $riskLabel));

    return [
        'status' =>
            $systemStatus,

        'system_status' =>
            $systemStatus,

        'user_status' =>
            $userStatus,

        'user_progress' =>
            $userProgress,

        'system_progress' =>
            $systemProgress,

        'user_completion_request' =>
            $userCompletionRequest,

        'progress_discrepancy' =>
            $progressDiscrepancy,

        'discrepancy_note' =>
            $discrepancyNote,

        'is_system_completed' =>
            $isSystemCompleted,

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

        'temporal_urgency' =>
            $temporalUrgency,

        'urgency' =>
            $temporalUrgency,

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

        'deadline_pressure' =>
            $deadlineScore,

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

        'risk_label' =>
            $riskLabel,

        'risk_level' =>
            $riskLevel,

        'smart_priority_score' =>
            $smartScore,

        'smart_priority_label' =>
            $smartLabel,

        'priority_reason' =>
            $reason,

        'recommended_action' =>
            $action,

        'due_label' =>
            $dueLabel,

        // Foundation 8C: Time-based metrics and progress
        'has_estimate' =>
            $timeMetrics['has_estimate'],

        'focused_seconds' =>
            $timeMetrics['focused_seconds'],

        'focused_minutes' =>
            $timeMetrics['focused_minutes'],

        'focused_hours' =>
            $timeMetrics['focused_hours'],

        'estimated_seconds' =>
            $timeMetrics['estimated_seconds'],

        'time_progress' =>
            $timeMetrics['time_progress'],

        'time_progress_percent' =>
            $timeMetrics['time_progress_percent'],

        'time_remaining_seconds' =>
            $timeMetrics['remaining_seconds'],

        'time_remaining_hours' =>
            $timeMetrics['remaining_hours'],

        'time_tracking' =>
            $timeMetrics,
    ];
}

/**
 * Decorate a task array with full academic intelligence, course metadata, and priority styling.
 * Centralized decorator used across tasks, deadlines, and dashboard APIs.
 */
function decorateTask(array $task): array
{
    $task['user_status'] = (string)($task['status'] ?? 'pending');
    $task['user_progress'] = max(0, min(100, (int)($task['progress_percent'] ?? 0)));

    $intelligence = buildTaskIntelligence($task);
    $task = array_merge($task, $intelligence);

    // Foundation 8D: Set authoritative system status
    $task['status'] = $intelligence['system_status'];

    $task['priority_label'] = priorityLabel((string)($task['priority'] ?? 'medium'));
    $task['course_credits'] = isset($task['course_credits']) ? (int)$task['course_credits'] : 0;
    $task['course_grade_point'] = (isset($task['course_grade_point']) && $task['course_grade_point'] !== null)
        ? (float)$task['course_grade_point']
        : null;

    if (isset($intelligence['time_tracking'])) {
        $tt = $intelligence['time_tracking'];
        $task['has_estimate']          = $tt['has_estimate'];
        $task['focused_seconds']       = $tt['focused_seconds'];
        $task['focused_minutes']       = $tt['focused_minutes'];
        $task['focused_hours']         = $tt['focused_hours'];
        $task['estimated_seconds']     = $tt['estimated_seconds'];
        $task['time_progress']         = $tt['time_progress'];
        $task['time_progress_percent'] = $tt['time_progress_percent'];
        $task['remaining_seconds']     = !empty($intelligence['is_system_completed']) ? 0 : $tt['remaining_seconds'];
        $task['remaining_minutes']     = !empty($intelligence['is_system_completed']) ? 0 : $tt['remaining_minutes'];
        $task['time_tracking']         = $tt;
    }

    return $task;
}

/**
 * Compute total estimated remaining workload hours for unfinished tasks across all courses for a user.
 * Single batched query prevents N+1 query amplification.
 */
function getCoursesWorkloadMap(PDO $db, int $userId): array
{
    $stmt = $db->prepare(
        "SELECT id, user_id, course_id, duration_hours, type, title, description, progress_percent, status, due_at 
         FROM tasks 
         WHERE user_id = ? AND status != 'completed' AND course_id IS NOT NULL"
    );
    $stmt->execute([$userId]);
    $tasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $courseWorkload = [];
    foreach ($tasks as $t) {
        $cId = (int)$t['course_id'];
        $intel = buildTaskIntelligence($t);
        $hrs = (float)($intel['remaining_hours'] ?? 0);
        $courseWorkload[$cId] = ($courseWorkload[$cId] ?? 0.0) + $hrs;
    }

    foreach ($courseWorkload as $cId => $hrs) {
        $courseWorkload[$cId] = round($hrs, 1);
    }

    return $courseWorkload;
}

/**
 * Compute total estimated remaining workload hours for unfinished tasks in a course.
 */
function calculateCourseWorkload(PDO $db, int $userId, int $courseId): float
{
    $stmt = $db->prepare(
        "SELECT duration_hours, type, title, description, progress_percent, status, due_at FROM tasks 
         WHERE user_id = ? AND course_id = ? AND status != 'completed'"
    );
    $stmt->execute([$userId, $courseId]);
    $tasks = $stmt->fetchAll();

    $workload = 0.0;
    foreach ($tasks as $task) {
        $intel = buildTaskIntelligence($task);
        $workload += (float) ($intel['remaining_hours'] ?? 0);
    }

    return round($workload, 1);
}

/**
 * Determine academic pressure tier for a course based on risk score, remaining hours, and overdue tasks.
 */
function determineCoursePressure(int $courseRiskScore, float $remainingHours, int $overdueCount, int $pendingCount): string
{
    if ($pendingCount === 0 && $overdueCount === 0) {
        return 'Low';
    }
    if ($overdueCount > 0 && ($courseRiskScore >= 60 || $remainingHours >= 8.0)) {
        return 'Critical';
    }
    if ($overdueCount > 0 || $courseRiskScore >= 75 || $remainingHours >= 12.0) {
        return 'High';
    }
    if ($courseRiskScore >= 50 || $remainingHours >= 5.0 || $pendingCount >= 3) {
        return 'Moderate';
    }
    return 'Low';
}

/**
 * Compute compact academic insights for the Progress section.
 */
function computeProgressInsights(PDO $db, int $userId, array $courses, array $tasksSummary, string $range): array
{
    // 1. Strongest Area
    $strongest = null;
    foreach ($courses as $c) {
        if (($c['task_count'] ?? 0) > 0 && ($c['completed_count'] ?? 0) > 0) {
            if ($strongest === null || ($c['progress'] ?? 0) > ($strongest['progress'] ?? 0)) {
                $strongest = $c;
            } elseif (($c['progress'] ?? 0) === ($strongest['progress'] ?? 0) && ($c['course_risk_score'] ?? 100) < ($strongest['course_risk_score'] ?? 100)) {
                $strongest = $c;
            }
        }
    }

    $strongestArea = $strongest !== null
        ? [
            'course_code' => $strongest['code'],
            'course_name' => $strongest['name'],
            'progress'    => $strongest['progress'] ?? $strongest['progress_percent'] ?? 0,
            'label'       => "{$strongest['code']} (" . ($strongest['progress'] ?? $strongest['progress_percent'] ?? 0) . "%)",
            'description' => "Leading with {$strongest['completed_count']} of {$strongest['task_count']} tasks completed.",
        ]
        : [
            'course_code' => null,
            'course_name' => null,
            'progress'    => 0,
            'label'       => 'None yet',
            'description' => 'Complete tasks in a course to identify your strongest academic area.',
        ];

    // 2. Needs Attention
    $attention = null;
    foreach ($courses as $c) {
        $pending = (int)($c['pending_tasks_count'] ?? $c['pending_count'] ?? 0);
        $overdue = (int)($c['overdue_tasks_count'] ?? $c['overdue_count'] ?? 0);
        if ($pending > 0 || $overdue > 0) {
            if ($attention === null) {
                $attention = $c;
            } else {
                // Priority: overdue tasks > critical/high pressure > risk score > remaining hours
                $currentScore = ($overdue * 50) + (($c['course_pressure'] ?? 'Low') === 'Critical' ? 40 : (($c['course_pressure'] ?? 'Low') === 'High' ? 25 : 10)) + (($c['course_risk_score'] ?? 0) * 0.2);
                $attentionOverdue = (int)($attention['overdue_tasks_count'] ?? $attention['overdue_count'] ?? 0);
                $bestScore    = ($attentionOverdue * 50) + (($attention['course_pressure'] ?? 'Low') === 'Critical' ? 40 : (($attention['course_pressure'] ?? 'Low') === 'High' ? 25 : 10)) + (($attention['course_risk_score'] ?? 0) * 0.2);
                if ($currentScore > $bestScore) {
                    $attention = $c;
                }
            }
        }
    }

    $needsAttention = $attention !== null
        ? [
            'course_code' => $attention['code'],
            'course_name' => $attention['name'],
            'pressure'    => $attention['course_pressure'] ?? 'Moderate',
            'label'       => "{$attention['code']} (" . ($attention['course_pressure'] ?? 'Moderate') . ')',
            'description' => (($attention['overdue_tasks_count'] ?? $attention['overdue_count'] ?? 0) > 0)
                ? (($attention['overdue_tasks_count'] ?? $attention['overdue_count']) . " overdue task(s) and " . ($attention['remaining_workload_hours'] ?? 0) . "h pending workload.")
                : (($attention['pending_tasks_count'] ?? $attention['pending_count'] ?? 0) . " pending task(s) with " . ($attention['remaining_workload_hours'] ?? 0) . "h workload."),
        ]
        : [
            'course_code' => null,
            'course_name' => null,
            'pressure'    => 'Low',
            'label'       => 'All caught up',
            'description' => 'No active courses currently require urgent attention.',
        ];

    // 3. Remaining Workload
    $totalRemainingHours = 0.0;
    foreach ($courses as $c) {
        $totalRemainingHours += (float)($c['remaining_workload_hours'] ?? 0);
    }
    $totalRemainingHours = round($totalRemainingHours, 1);

    $remainingWorkload = [
        'hours'       => $totalRemainingHours,
        'label'       => "{$totalRemainingHours} hrs",
        'description' => "Across {$tasksSummary['pending']} pending and {$tasksSummary['overdue']} overdue tasks.",
    ];

    // 4. Completion Trend (honest comparison vs previous period)
    $now = new DateTime();
    $days = ($range === 'week') ? 7 : (($range === 'month') ? 30 : 30);
    $currentStart = (clone $now)->modify("-{$days} days")->format('Y-m-d H:i:s');
    $prevStart    = (clone $now)->modify('-' . ($days * 2) . ' days')->format('Y-m-d H:i:s');

    $stmt = $db->prepare(
        "SELECT COUNT(*) AS n FROM tasks 
         WHERE user_id = ? AND status = 'completed' AND completed_at BETWEEN ? AND NOW()"
    );
    $stmt->execute([$userId, $currentStart]);
    $currentCompleted = (int)$stmt->fetch()['n'];

    $stmt = $db->prepare(
        "SELECT COUNT(*) AS n FROM tasks 
         WHERE user_id = ? AND status = 'completed' AND completed_at BETWEEN ? AND ?"
    );
    $stmt->execute([$userId, $prevStart, $currentStart]);
    $prevCompleted = (int)$stmt->fetch()['n'];

    $stmt = $db->prepare(
        "SELECT COUNT(*) AS n FROM tasks 
         WHERE user_id = ? AND created_at BETWEEN ? AND ?"
    );
    $stmt->execute([$userId, $prevStart, $currentStart]);
    $prevCreated = (int)$stmt->fetch()['n'];

    if ($prevCreated === 0 && $prevCompleted === 0) {
        $completionTrend = [
            'has_trend'   => false,
            'delta'       => 0,
            'label'       => 'Steady baseline',
            'description' => 'Not enough historical data to determine a trend yet.',
        ];
    } else {
        $delta = $currentCompleted - $prevCompleted;
        $label = $delta > 0 ? "+{$delta} completed" : ($delta < 0 ? "{$delta} completed" : 'Even pace');
        $desc = $delta > 0
            ? "Task completions increased by +{$delta} compared to the previous {$days} days."
            : ($delta < 0
                ? "Task completions dropped by " . abs($delta) . " compared to the previous {$days} days."
                : "Completion pace matches the previous {$days}-day period.");
        $completionTrend = [
            'has_trend'   => true,
            'delta'       => $delta,
            'label'       => $label,
            'description' => $desc,
        ];
    }

    // 5. Study Consistency (from recurring schedule events and weekly study goal)
    $stmt = $db->prepare(
        'SELECT COUNT(*) AS total, SUM(is_completed = 1) AS completed 
         FROM schedule_events WHERE user_id = ?'
    );
    $stmt->execute([$userId]);
    $sessionStats = $stmt->fetch();
    $totalSessions = (int)($sessionStats['total'] ?? 0);
    $compSessions  = (int)($sessionStats['completed'] ?? 0);

    $planningContext = getPersonalPlanningContext($db, $userId);
    $weeklyGoal   = (float)($planningContext['weekly_goal_hours'] ?? 0);
    $loggedHours  = (float)($planningContext['logged_study_hours'] ?? 0);
    $goalProgress = (int)($planningContext['goal_progress_percent'] ?? 0);

    if ($totalSessions === 0) {
        $studyConsistency = [
            'rate'                  => $weeklyGoal > 0 ? min(100, $goalProgress) : 0,
            'label'                 => $weeklyGoal > 0 ? "{$goalProgress}% of goal" : 'No sessions',
            'description'           => $weeklyGoal > 0
                ? "Logged {$loggedHours}h of your {$weeklyGoal}h weekly goal."
                : 'Add recurring study blocks in Schedule to track consistency.',
            'weekly_goal_hours'     => $weeklyGoal,
            'logged_study_hours'    => $loggedHours,
            'goal_progress_percent' => $goalProgress,
        ];
    } else {
        $rate = (int)round(($compSessions / $totalSessions) * 100);
        $studyConsistency = [
            'rate'                  => $rate,
            'label'                 => "{$rate}% consistency",
            'description'           => $weeklyGoal > 0
                ? "{$compSessions} of {$totalSessions} sessions completed • Logged {$loggedHours}h / {$weeklyGoal}h goal ({$goalProgress}%)."
                : "{$compSessions} of {$totalSessions} weekly scheduled sessions completed.",
            'weekly_goal_hours'     => $weeklyGoal,
            'logged_study_hours'    => $loggedHours,
            'goal_progress_percent' => $goalProgress,
        ];
    }

    return [
        'strongest_area'     => $strongestArea,
        'needs_attention'    => $needsAttention,
        'remaining_workload' => $remainingWorkload,
        'completion_trend'   => $completionTrend,
        'study_consistency'  => $studyConsistency,
    ];
}

/**
 * Compute period comparison trends and course attention analysis for Reports.
 */
function computeReportComparisons(
    PDO $db,
    int $userId,
    string $range,
    DateTime $currentStart,
    DateTime $currentEnd,
    int $currentTasksCreated,
    int $currentTasksCompleted,
    int $currentOverdueTasks
): array {
    if ($range === 'week') {
        $prevStart = (clone $currentStart)->modify('-7 days');
        $prevEnd   = (clone $currentStart)->modify('-1 second');
    } elseif ($range === 'month') {
        $prevStart = (clone $currentStart)->modify('-1 month');
        $prevEnd   = (clone $currentStart)->modify('-1 second');
    } else {
        $prevStart = (clone $currentStart)->modify('-90 days');
        $prevEnd   = (clone $currentStart)->modify('-1 second');
    }

    $prevStartStr = $prevStart->format('Y-m-d H:i:s');
    $prevEndStr   = $prevEnd->format('Y-m-d H:i:s');

    $stmt = $db->prepare(
        "SELECT COUNT(*) AS n FROM tasks 
         WHERE user_id = ? AND status = 'completed' AND completed_at BETWEEN ? AND ?"
    );
    $stmt->execute([$userId, $prevStartStr, $prevEndStr]);
    $prevCompleted = (int)$stmt->fetch()['n'];

    $stmt = $db->prepare(
        "SELECT COUNT(*) AS n FROM tasks 
         WHERE user_id = ? AND created_at BETWEEN ? AND ?"
    );
    $stmt->execute([$userId, $prevStartStr, $prevEndStr]);
    $prevCreated = (int)$stmt->fetch()['n'];

    $hasPrevData = ($prevCreated > 0 || $prevCompleted > 0);

    $completionTrend = '';
    $workloadTrend = '';
    $rateDelta = 0;

    $currentRate = $currentTasksCreated > 0 ? round($currentTasksCompleted / $currentTasksCreated * 100) : 0;
    $prevRate    = $prevCreated > 0 ? round($prevCompleted / $prevCreated * 100) : 0;

    if (!$hasPrevData) {
        $completionTrend = 'Not enough historical data to determine a reliable trend.';
        $workloadTrend   = 'Not enough historical data to compare workload between periods.';
    } else {
        $rateDelta = (int)($currentRate - $prevRate);
        if ($rateDelta > 0) {
            $completionTrend = "Task completion improved by +{$rateDelta}% compared to the previous period.";
        } elseif ($rateDelta < 0) {
            $completionTrend = "Task completion dropped by " . abs($rateDelta) . "% compared to the previous period.";
        } else {
            $completionTrend = "Task completion rate remained steady at {$currentRate}% compared to the previous period.";
        }

        $workloadDelta = $currentTasksCreated - $prevCreated;
        if ($workloadDelta > 0) {
            $workloadTrend = "New task creation increased (+{$workloadDelta}) compared to the previous period.";
        } elseif ($workloadDelta < 0) {
            $workloadTrend = "New task creation decreased (" . abs($workloadDelta) . " fewer) compared to the previous period.";
        } else {
            $workloadTrend = "New task creation remained identical to the previous period.";
        }
    }

    // Course comparison and explainable attention rationale
    $stmt = $db->prepare(
        "SELECT c.id, c.code, c.name, c.credits, c.grade_point,
                COUNT(t.id) AS total_tasks,
                SUM(t.status = 'completed') AS completed_tasks,
                SUM(t.status != 'completed' AND t.due_at < NOW()) AS overdue_tasks,
                SUM(t.status != 'completed' AND t.due_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL 2 DAY)) AS due_soon_tasks
         FROM courses c
         LEFT JOIN tasks t ON t.course_id = c.id AND t.user_id = c.user_id
         WHERE c.user_id = ?
         GROUP BY c.id
         ORDER BY c.code ASC"
    );
    $stmt->execute([$userId]);
    $courseRows = $stmt->fetchAll();

    $highestProgressCourse = null;
    $attentionCourse = null;

    foreach ($courseRows as $cr) {
        $total = (int)$cr['total_tasks'];
        $comp  = (int)$cr['completed_tasks'];
        $overdue = (int)$cr['overdue_tasks'];
        $dueSoon = (int)$cr['due_soon_tasks'];
        $pct = $total > 0 ? round($comp / $total * 100) : 0;
        $workloadHours = calculateCourseWorkload($db, $userId, (int)$cr['id']);
        $riskScore = courseRiskScore($cr['grade_point'] !== null ? (float)$cr['grade_point'] : null, (int)$cr['credits']);

        if ($comp > 0) {
            if ($highestProgressCourse === null || $pct > ($highestProgressCourse['progress'] ?? 0)) {
                $highestProgressCourse = [
                    'code'     => $cr['code'],
                    'name'     => $cr['name'],
                    'progress' => $pct,
                ];
            }
        }

        $pending = $total - $comp;
        if ($pending > 0 || $overdue > 0) {
            $severity = ($overdue * 50) + ($dueSoon * 25) + ($workloadHours * 2) + ($riskScore * 0.3);
            if ($attentionCourse === null || $severity > $attentionCourse['severity']) {
                $reasons = [];
                if ($overdue > 0) {
                    $reasons[] = "{$overdue} overdue task" . ($overdue === 1 ? '' : 's');
                }
                if ($dueSoon > 0) {
                    $reasons[] = "{$dueSoon} deadline approaching within 48h";
                }
                if ($workloadHours > 0) {
                    $reasons[] = "{$workloadHours}h estimated workload remaining";
                }
                if ($riskScore >= 60) {
                    $reasons[] = "high academic course risk (score {$riskScore}/100)";
                }

                $explanation = $cr['code'] . ' needs attention: ' . (empty($reasons) ? 'Pending assignments require action.' : implode(', ', $reasons) . '.');

                $attentionCourse = [
                    'code'           => $cr['code'],
                    'name'           => $cr['name'],
                    'severity'       => $severity,
                    'reasons'        => $reasons,
                    'explanation'    => $explanation,
                    'workload_hours' => $workloadHours,
                ];
            }
        }
    }

    return [
        'has_previous_data'        => $hasPrevData,
        'completion_trend'         => $completionTrend,
        'workload_trend'           => $workloadTrend,
        'rate_delta'               => $rateDelta,
        'previous_tasks_completed' => $prevCompleted,
        'previous_tasks_created'   => $prevCreated,
        'previous_completion_rate' => $prevRate,
        'highest_progress_course'  => $highestProgressCourse,
        'attention_course'         => $attentionCourse,
    ];
}

/**
 * Ensure notification schema columns (event_key and notification_preferences) exist.
 */
function ensureNotificationSchema(PDO $db): void
{
    static $ensured = false;
    if ($ensured) {
        return;
    }
    try {
        $cols = $db->query("SHOW COLUMNS FROM notifications LIKE 'event_key'")->fetchAll(PDO::FETCH_ASSOC);
        if (empty($cols)) {
            $db->exec("ALTER TABLE notifications ADD COLUMN event_key VARCHAR(100) NULL AFTER channel");
            $db->exec("ALTER TABLE notifications ADD INDEX idx_user_event_key (user_id, event_key)");
        }
        $userCols = $db->query("SHOW COLUMNS FROM users LIKE 'notification_preferences'")->fetchAll(PDO::FETCH_ASSOC);
        if (empty($userCols)) {
            $db->exec("ALTER TABLE users ADD COLUMN notification_preferences TEXT NULL AFTER notifications_enabled");
        }
        $ensured = true;
    } catch (Throwable $e) {
        $ensured = true;
    }
}

/**
 * Retrieve user notification preferences with robust defaults.
 */
function getUserNotificationPreferences(PDO $db, int $userId): array
{
    $defaults = [
        'class_1h'              => true,
        'class_30m'             => true,
        'class_10m'             => true,
        'deadline_24h'          => true,
        'deadline_2h'           => true,
        'deadline_overdue'      => true,
        'curriculum_alerts'     => true,
        'study_gap_suggestions' => true,
    ];

    try {
        $stmt = $db->prepare('SELECT notification_preferences, notifications_enabled FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return $defaults;
        }

        if (empty($row['notifications_enabled'])) {
            foreach ($defaults as $k => $v) {
                $defaults[$k] = false;
            }
            return $defaults;
        }

        if (!empty($row['notification_preferences'])) {
            $saved = json_decode((string)$row['notification_preferences'], true);
            if (is_array($saved)) {
                foreach ($defaults as $k => $v) {
                    if (array_key_exists($k, $saved)) {
                        $defaults[$k] = (bool)$saved[$k];
                    }
                }
            }
        }
    } catch (Throwable $e) {
        // Fallback to defaults
    }

    return $defaults;
}

/**
 * Deduplication helper: Check if matching notification exists by event_key or message pattern.
 */
function academicNotificationExists(PDO $db, int $userId, string $eventKey, ?string $messagePattern = null, int $withinHours = 48): bool
{
    try {
        $stmt = $db->prepare('SELECT id FROM notifications WHERE user_id = ? AND event_key = ? LIMIT 1');
        $stmt->execute([$userId, $eventKey]);
        if ($stmt->fetchColumn()) {
            return true;
        }
    } catch (Throwable $e) {
        // Ignore if column missing during edge case
    }

    if ($messagePattern !== null) {
        try {
            $stmt2 = $db->prepare(
                "SELECT id FROM notifications 
                 WHERE user_id = ? 
                   AND message LIKE ? 
                   AND (read_at IS NULL OR send_at >= DATE_SUB(NOW(), INTERVAL {$withinHours} HOUR)) 
                 LIMIT 1"
            );
            $stmt2->execute([$userId, $messagePattern]);
            if ($stmt2->fetchColumn()) {
                return true;
            }
        } catch (Throwable $e) {
            // Ignore
        }
    }

    return false;
}

/**
 * Decorate a notification record with appropriate icon category, visual tone, human-readable title, and action_url.
 */
function decorateNotification(array $notification): array
{
    $msg = (string)($notification['message'] ?? '');
    $category = 'info';
    $tone = 'info';
    $title = 'Notification';
    $actionUrl = 'notifications.php';

    if (stripos($msg, 'marked as completed') !== false || stripos($msg, 'work completed') !== false) {
        $category = 'completion';
        $tone = 'success';
        $title = 'Work completed';
        $actionUrl = !empty($notification['task_id']) ? "tasks.php?id=" . (int)$notification['task_id'] : "tasks.php?tab=completed";
    } elseif (stripos($msg, 'moved back to active work') !== false || stripos($msg, 'work reopened') !== false) {
        $category = 'completion';
        $tone = 'info';
        $title = 'Work reopened';
        $actionUrl = !empty($notification['task_id']) ? "tasks.php?id=" . (int)$notification['task_id'] : "tasks.php";
    } elseif (stripos($msg, 'study session completed') !== false) {
        $category = 'completion';
        $tone = 'success';
        $title = 'Study session completed';
        $actionUrl = !empty($notification['task_id']) ? "tasks.php?id=" . (int)$notification['task_id'] : "tasks.php";
    } elseif (stripos($msg, 'overdue') !== false) {
        $category = 'overdue';
        $tone = 'urgent';
        $title = 'Overdue Task';
        $actionUrl = !empty($notification['task_id']) ? "tasks.php?id=" . (int)$notification['task_id'] : "tasks.php";
    } elseif (stripos($msg, 'urgent deadline') !== false || stripos($msg, '(2h)') !== false) {
        $category = 'deadline';
        $tone = 'urgent';
        $title = 'Urgent Deadline';
        $actionUrl = !empty($notification['task_id']) ? "tasks.php?id=" . (int)$notification['task_id'] : "tasks.php";
    } elseif (stripos($msg, 'deadline') !== false || stripos($msg, 'due') !== false) {
        $category = 'deadline';
        $tone = 'warning';
        $title = 'Approaching Deadline';
        $actionUrl = !empty($notification['task_id']) ? "tasks.php?id=" . (int)$notification['task_id'] : "tasks.php";
    } elseif (stripos($msg, 'class reminder') !== false) {
        $category = 'class_reminder';
        $tone = stripos($msg, '(10m)') !== false ? 'urgent' : (stripos($msg, '(30m)') !== false ? 'warning' : 'info');
        $title = stripos($msg, '(10m)') !== false ? 'Class Starting Soon' : 'Class Reminder';
        $actionUrl = 'schedule.php';
    } elseif (stripos($msg, 'examination alert') !== false || stripos($msg, 'exam week') !== false) {
        $category = 'curriculum';
        $tone = 'urgent';
        $title = 'Examination Alert';
        $actionUrl = 'courses.php';
    } elseif (stripos($msg, 'revision week') !== false || stripos($msg, 'academic calendar') !== false) {
        $category = 'curriculum';
        $tone = 'info';
        $title = 'Academic Calendar';
        $actionUrl = 'courses.php';
    } elseif (stripos($msg, 'study opportunity') !== false || stripos($msg, 'free block') !== false) {
        $category = 'smart_study';
        $tone = 'suggestion';
        $title = 'Study Opportunity';
        $actionUrl = 'tasks.php';
    } elseif (stripos($msg, 'academic alert') !== false || stripos($msg, 'risk') !== false) {
        $category = 'course';
        $tone = 'warning';
        $title = 'Academic Risk Alert';
        $actionUrl = 'courses.php';
    } elseif (stripos($msg, 'study session') !== false || stripos($msg, 'missed') !== false) {
        $category = 'schedule';
        $tone = 'warning';
        $title = 'Missed Study Session';
        $actionUrl = 'schedule.php';
    } elseif (stripos($msg, 'goal') !== false) {
        $category = 'goal';
        $tone = stripos($msg, 'achieved') !== false ? 'success' : 'info';
        $title = stripos($msg, 'achieved') !== false ? 'Study Goal Achieved' : 'Study Goal Update';
        $actionUrl = 'dashboard.php';
    }

    return array_merge($notification, [
        'category'   => $category,
        'type'       => $tone,
        'tone'       => $tone,
        'title'      => $title,
        'action_url' => $actionUrl,
        'is_read'    => !empty($notification['read_at']),
    ]);
}

/**
 * Deduplication helper: Check if a matching unread or recent notification already exists.
 */
function notificationExists(PDO $db, int $userId, ?int $taskId, string $pattern, int $withinHours = 24): bool
{
    $query = "SELECT id FROM notifications 
              WHERE user_id = ? 
                AND message LIKE ? 
                AND (read_at IS NULL OR send_at >= DATE_SUB(NOW(), INTERVAL {$withinHours} HOUR))";
    $params = [$userId, $pattern];
    if ($taskId !== null) {
        $query .= " AND task_id = ?";
        $params[] = $taskId;
    }
    $query .= " LIMIT 1";

    $stmt = $db->prepare($query);
    $stmt->execute($params);
    return (bool)$stmt->fetchColumn();
}

/**
 * Generate academic timetable, deadline, curriculum, and smart study reminders.
 * Strictly deduplicates using event_key to ensure repeat runs never create duplicates.
 */
function generateAcademicReminders(PDO $db, ?int $targetUserId = null): array
{
    ensureNotificationSchema($db);

    $now = time();
    $todayStr = date('Y-m-d');
    $todayDow = (int)date('w'); // 0=Sun..6=Sat
    $totalGenerated = 0;

    $usersToProcess = [];
    if ($targetUserId !== null) {
        $stmt = $db->prepare('SELECT id, notifications_enabled, notification_preferences FROM users WHERE id = ?');
        $stmt->execute([$targetUserId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $usersToProcess[] = $row;
        }
    } else {
        $stmt = $db->query('SELECT id, notifications_enabled, notification_preferences FROM users WHERE notifications_enabled = 1');
        $usersToProcess = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    $insertStmt = $db->prepare(
        "INSERT INTO notifications (user_id, task_id, channel, event_key, message, send_at)
         VALUES (?, ?, 'in_app', ?, ?, NOW())"
    );

    foreach ($usersToProcess as $userRow) {
        $userId = (int)$userRow['id'];
        if (empty($userRow['notifications_enabled'])) {
            continue;
        }

        $prefs = getUserNotificationPreferences($db, $userId);

        // 1. TIMETABLE CLASS REMINDERS (1h, 30m, 10m before class start)
        $stmt = $db->prepare(
            "SELECT se.id, se.course_id, se.title, se.start_time, se.end_time,
                    c.code AS course_code, c.name AS course_name
             FROM schedule_events se
             LEFT JOIN courses c ON c.id = se.course_id
             WHERE se.user_id = ? 
               AND se.event_type = 'lecture'
               AND se.day_of_week = ?
             ORDER BY se.start_time ASC"
        );
        $stmt->execute([$userId, $todayDow]);
        $todaysClasses = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($todaysClasses as $cls) {
            $classStartTime = $cls['start_time']; // e.g. 09:00:00
            $classStartTs = strtotime("{$todayStr} {$classStartTime}");
            $diffMins = ($classStartTs - $now) / 60.0;
            $courseLabel = !empty($cls['course_code']) ? $cls['course_code'] : $cls['title'];
            $fmtTime = date('g:i A', $classStartTs);

            // 1 Hour Before: within 31 to 60 minutes of start
            if ($prefs['class_1h'] && $diffMins <= 60 && $diffMins >= 31) {
                $key = "class_{$cls['id']}_{$todayStr}_1h";
                if (!academicNotificationExists($db, $userId, $key, "%Class Reminder (1h): '{$courseLabel}'%")) {
                    $msg = "Class Reminder (1h): '{$courseLabel}' starts in 1 hour at {$fmtTime}. Check your lecture materials.";
                    $insertStmt->execute([$userId, null, $key, $msg]);
                    $totalGenerated++;
                }
            }

            // 30 Minutes Before: within 11 to 30 minutes of start
            if ($prefs['class_30m'] && $diffMins <= 30 && $diffMins >= 11) {
                $key = "class_{$cls['id']}_{$todayStr}_30m";
                if (!academicNotificationExists($db, $userId, $key, "%Class Reminder (30m): '{$courseLabel}'%")) {
                    $msg = "Class Reminder (30m): '{$courseLabel}' starts in 30 minutes at {$fmtTime}.";
                    $insertStmt->execute([$userId, null, $key, $msg]);
                    $totalGenerated++;
                }
            }

            // 10 Minutes Before: within 0 to 10 minutes of start
            if ($prefs['class_10m'] && $diffMins <= 10 && $diffMins >= 0) {
                $key = "class_{$cls['id']}_{$todayStr}_10m";
                if (!academicNotificationExists($db, $userId, $key, "%Class Reminder (10m): '{$courseLabel}'%")) {
                    $msg = "Class Reminder (10m): '{$courseLabel}' starts in 10 minutes at {$fmtTime}! Head to class now.";
                    $insertStmt->execute([$userId, null, $key, $msg]);
                    $totalGenerated++;
                }
            }
        }

        // 2. DEADLINE REMINDERS (24h, 2h, and Overdue notices)
        $stmt = $db->prepare(
            "SELECT t.id, t.title, t.due_at, t.status, c.code AS course_code
             FROM tasks t
             LEFT JOIN courses c ON c.id = t.course_id
             WHERE t.user_id = ? 
               AND t.status != 'completed'
               AND t.due_at IS NOT NULL"
        );
        $stmt->execute([$userId]);
        $uncompletedTasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($uncompletedTasks as $task) {
            $taskId = (int)$task['id'];
            $dueTs = strtotime($task['due_at']);
            $diffHours = ($dueTs - $now) / 3600.0;
            $taskTitle = $task['title'];
            $dueFormatted = date('M j, g:i A', $dueTs);

            // 24h Before (between 2 and 24 hours away)
            if ($prefs['deadline_24h'] && $diffHours <= 24 && $diffHours > 2) {
                $key = "task_{$taskId}_24h";
                if (!academicNotificationExists($db, $userId, $key, "%Deadline Reminder (24h): '{$taskTitle}'%")) {
                    $msg = "Deadline Reminder (24h): '{$taskTitle}' is due tomorrow on {$dueFormatted}.";
                    $insertStmt->execute([$userId, $taskId, $key, $msg]);
                    $totalGenerated++;
                }
            }

            // 2h Before (between 0 and 2 hours away)
            if ($prefs['deadline_2h'] && $diffHours <= 2 && $diffHours > 0) {
                $key = "task_{$taskId}_2h";
                if (!academicNotificationExists($db, $userId, $key, "%Urgent Deadline (2h): '{$taskTitle}'%")) {
                    $msg = "Urgent Deadline (2h): '{$taskTitle}' is due in 2 hours ({$dueFormatted})! Finalize and submit.";
                    $insertStmt->execute([$userId, $taskId, $key, $msg]);
                    $totalGenerated++;
                }
            }

            // Overdue Notice (due_at in past, within last 7 days)
            if ($prefs['deadline_overdue'] && $diffHours < 0 && $diffHours >= -168) {
                $key = "task_{$taskId}_overdue";
                if (!academicNotificationExists($db, $userId, $key, "%Overdue Task: '{$taskTitle}'%")) {
                    $msg = "Overdue Task: '{$taskTitle}' was due on {$dueFormatted}. Prioritize or update its status.";
                    $insertStmt->execute([$userId, $taskId, $key, $msg]);
                    $totalGenerated++;
                }
            }
        }

        // 3. CURRICULUM & ACADEMIC CALENDAR NOTIFICATIONS
        if ($prefs['curriculum_alerts']) {
            $stmt = $db->prepare(
                "SELECT cw.id, cw.week_number, cw.label, cw.week_type, cw.start_date, cw.end_date, s.name AS semester_name
                 FROM curriculum_weeks cw
                 INNER JOIN semesters s ON s.id = cw.semester_id
                 WHERE cw.user_id = ? AND s.is_current = 1
                 ORDER BY cw.start_date ASC"
            );
            $stmt->execute([$userId]);
            $curriculumWeeks = $stmt->fetchAll(PDO::FETCH_ASSOC);

            foreach ($curriculumWeeks as $week) {
                $weekId = (int)$week['id'];
                $startDate = $week['start_date'];
                $startTs = strtotime($startDate);
                $daysUntilStart = (int)round(($startTs - strtotime($todayStr)) / 86400);

                // Week starts today
                if ($daysUntilStart === 0) {
                    $key = "curr_start_{$weekId}_{$todayStr}";
                    if (!academicNotificationExists($db, $userId, $key)) {
                        $weekTypeLabel = ucwords(str_replace('_', ' ', $week['week_type']));
                        $msg = "Academic Calendar: Week {$week['week_number']} ({$week['label']} - {$weekTypeLabel}) starts today.";
                        $insertStmt->execute([$userId, null, $key, $msg]);
                        $totalGenerated++;
                    }
                }

                // Examination Week upcoming in 1 to 7 days
                if ($week['week_type'] === 'exam' && $daysUntilStart > 0 && $daysUntilStart <= 7) {
                    $key = "curr_exam_upcoming_{$weekId}";
                    if (!academicNotificationExists($db, $userId, $key)) {
                        $fmtStartDate = date('l, M j', $startTs);
                        $msg = "Examination Alert: Exam Week begins in {$daysUntilStart} day(s) on {$fmtStartDate}. Review your study timetable.";
                        $insertStmt->execute([$userId, null, $key, $msg]);
                        $totalGenerated++;
                    }
                }

                // Revision Week upcoming in 1 to 7 days
                if ($week['week_type'] === 'revision' && $daysUntilStart > 0 && $daysUntilStart <= 7) {
                    $key = "curr_rev_upcoming_{$weekId}";
                    if (!academicNotificationExists($db, $userId, $key)) {
                        $fmtStartDate = date('l, M j', $startTs);
                        $msg = "Revision Week Alert: Revision Week starts in {$daysUntilStart} day(s) on {$fmtStartDate}. Consolidate coursework and practice problems.";
                        $insertStmt->execute([$userId, null, $key, $msg]);
                        $totalGenerated++;
                    }
                }
            }
        }

        // 4. SMART STUDY PLANNING: FREE GAPS BETWEEN CLASSES
        if ($prefs['study_gap_suggestions'] && count($todaysClasses) >= 2) {
            for ($i = 0; $i < count($todaysClasses) - 1; $i++) {
                $c1 = $todaysClasses[$i];
                $c2 = $todaysClasses[$i + 1];
                $end1Ts = strtotime("{$todayStr} {$c1['end_time']}");
                $start2Ts = strtotime("{$todayStr} {$c2['start_time']}");
                $gapHours = ($start2Ts - $end1Ts) / 3600.0;

                // If gap is >= 2 hours and hasn't passed yet
                if ($gapHours >= 2.0 && $now < $start2Ts) {
                    $gapKey = "gap_{$c1['id']}_{$c2['id']}_{$todayStr}";
                    if (!academicNotificationExists($db, $userId, $gapKey)) {
                        $code1 = !empty($c1['course_code']) ? $c1['course_code'] : $c1['title'];
                        $code2 = !empty($c2['course_code']) ? $c2['course_code'] : $c2['title'];
                        $roundedGap = round($gapHours, 1);
                        $msg = "Study Opportunity: You have a {$roundedGap}h free block between {$code1} and {$code2}. Good window for focused study.";
                        $insertStmt->execute([$userId, null, $gapKey, $msg]);
                        $totalGenerated++;
                    }
                }
            }
        }
    }

    return [
        'status'    => 'ok',
        'generated' => $totalGenerated,
    ];
}

/**
 * Generate smart contextual notifications while enforcing strict duplicate prevention
 * and respecting user notification preferences.
 */
function syncContextualNotifications(PDO $db, int $userId): array
{
    $user = getUserProfileRow($userId);
    if (empty($user['notifications_enabled'])) {
        return ['status' => 'disabled', 'generated' => 0];
    }

    $generated = 0;

    // Generate academic timetable, deadline, curriculum, and smart study reminders
    $academicRes = generateAcademicReminders($db, $userId);
    $generated += (int)($academicRes['generated'] ?? 0);

    $insertStmt = $db->prepare(
        "INSERT INTO notifications (user_id, task_id, channel, message, send_at) 
         VALUES (?, ?, 'in_app', ?, NOW())"
    );

    // 1. High academic risk alerts
    $stmt = $db->prepare(
        "SELECT id, code, name, credits, grade_point FROM courses WHERE user_id = ?"
    );
    $stmt->execute([$userId]);
    $courses = $stmt->fetchAll();

    foreach ($courses as $c) {
        $risk = courseRiskScore($c['grade_point'] !== null ? (float)$c['grade_point'] : null, (int)$c['credits']);
        if ($risk >= 60) {
            // Check if course has unfinished tasks
            $stmtPending = $db->prepare(
                "SELECT COUNT(*) FROM tasks WHERE user_id = ? AND course_id = ? AND status != 'completed'"
            );
            $stmtPending->execute([$userId, $c['id']]);
            $pendingCount = (int)$stmtPending->fetchColumn();

            if ($pendingCount > 0) {
                $riskLabel = $risk >= 80 ? 'Critical Risk' : 'High Risk';
                $code = $c['code'];
                if (!notificationExists($db, $userId, null, "%Academic Alert: {$code}%", 48)) {
                    $insertStmt->execute([
                        $userId,
                        null,
                        "Academic Alert: {$code} is at {$riskLabel} ({$risk}/100) with {$pendingCount} pending task(s).",
                    ]);
                    $generated++;
                }
            }
        }
    }

    // 4. Missed study sessions (weekly recurring sessions in schedule_events)
    $todayDow = (int)date('w'); // 0=Sun..6=Sat
    $currentTime = date('H:i:s');

    $stmt = $db->prepare(
        "SELECT id, title, day_of_week, end_time FROM schedule_events 
         WHERE user_id = ? AND event_type = 'study' AND is_completed = 0"
    );
    $stmt->execute([$userId]);
    $studySessions = $stmt->fetchAll();

    foreach ($studySessions as $ss) {
        $sessionDow = (int)$ss['day_of_week'];
        $isPast = ($sessionDow < $todayDow) || ($sessionDow === $todayDow && $ss['end_time'] < $currentTime);
        if ($isPast) {
            $sessionTitle = $ss['title'];
            if (!notificationExists($db, $userId, null, "%Missed Study Session: '{$sessionTitle}'%", 48)) {
                $insertStmt->execute([
                    $userId,
                    null,
                    "Missed Study Session: '{$sessionTitle}' was scheduled earlier this week and remains uncompleted.",
                ]);
                $generated++;
            }
        }
    }

    // 5. Study goal progress
    $weeklyGoal = (float)($user['weekly_goal_hours'] ?? 0);
    if ($weeklyGoal > 0) {
        $loggedHours = getUserCompletedStudyHours($db, $userId);

        if ($loggedHours >= $weeklyGoal) {
            if (!notificationExists($db, $userId, null, '%Goal Achieved: You reached your weekly study goal%', 72)) {
                $insertStmt->execute([
                    $userId,
                    null,
                    "Goal Achieved: You reached your weekly study goal of {$weeklyGoal}h ({$loggedHours}h completed)!",
                ]);
                $generated++;
            }
        } elseif ($todayDow >= 4 && $loggedHours < ($weeklyGoal * 0.5) && (!empty($courses) || !empty($studySessions))) {
            if (!notificationExists($db, $userId, null, '%Study Goal Reminder: You have logged%', 72)) {
                $insertStmt->execute([
                    $userId,
                    null,
                    "Study Goal Reminder: You have logged {$loggedHours}h of your {$weeklyGoal}h weekly study goal.",
                ]);
                $generated++;
            }
        }
    }

    return ['status' => 'ok', 'generated' => $generated];
}

/**
 * Compute study hours completed by the user with request-level memoization.
 */
function getUserCompletedStudyHours(PDO $db, int $userId): float
{
    $stmt = $db->prepare(
        'SELECT SUM(TIME_TO_SEC(TIMEDIFF(end_time, start_time))) / 3600 AS hrs 
         FROM schedule_events WHERE user_id = ? AND is_completed = 1'
    );
    $stmt->execute([$userId]);
    return round((float)($stmt->fetch()['hrs'] ?? 0), 1);
}

/**
 * Retrieve the full personal planning context for a user.
 * Integrates weekly goal, logged hours, preferred study times, preferred days, and timetable alignment.
 */
function getPersonalPlanningContext(PDO $db, int $userId): array
{
    $user = getUserProfileRow($userId);
    $weeklyGoal = (float)($user['weekly_goal_hours'] ?? 15.0);

    // Compute study hours completed this week (from schedule_events)
    $loggedHours = getUserCompletedStudyHours($db, $userId);
    $goalPct = $weeklyGoal > 0 ? (int)min(100, round(($loggedHours / $weeklyGoal) * 100)) : 0;

    $preferredTime = (string)($user['preferred_study_time'] ?? 'flexible');
    $timeLabels = [
        'morning'   => 'Morning (8:00 AM – 12:00 PM)',
        'afternoon' => 'Afternoon (12:00 PM – 5:00 PM)',
        'evening'   => 'Evening (5:00 PM – 10:00 PM)',
        'flexible'  => 'Flexible (Any time)',
    ];
    $preferredTimeLabel = $timeLabels[$preferredTime] ?? 'Flexible (Any time)';

    $daysRaw = (string)($user['preferred_study_days'] ?? '1,2,3,4,5');
    $dayIndices = array_values(array_filter(
        array_unique(array_map('intval', explode(',', $daysRaw))),
        fn($d) => $d >= 0 && $d <= 6
    ));
    sort($dayIndices);
    if (empty($dayIndices)) {
        $dayIndices = [1, 2, 3, 4, 5];
    }

    $dayNames = [0 => 'Sun', 1 => 'Mon', 2 => 'Tue', 3 => 'Wed', 4 => 'Thu', 5 => 'Fri', 6 => 'Sat'];
    $dayLabels = array_map(fn($d) => $dayNames[$d] ?? '', $dayIndices);
    $preferredDaysLabel = implode(', ', $dayLabels);

    $todayDow = (int)date('w');
    $isPreferredToday = in_array($todayDow, $dayIndices, true);

    return [
        'weekly_goal_hours'      => $weeklyGoal,
        'logged_study_hours'     => $loggedHours,
        'goal_progress_percent'  => $goalPct,
        'preferred_study_time'   => $preferredTime,
        'preferred_time_label'   => $preferredTimeLabel,
        'preferred_study_days'   => $dayIndices,
        'preferred_days_label'   => $preferredDaysLabel,
        'week_start_day'         => (int)($user['week_start_day'] ?? 1),
        'notifications_enabled'  => (bool)($user['notifications_enabled'] ?? true),
        'is_preferred_day_today' => $isPreferredToday,
    ];
}

/**
 * FOUNDATION 7A: CENTRALIZED READ-ONLY AI ACADEMIC CONTEXT
 *
 * Compiles a comprehensive, machine-readable, grounded context of the student's
 * real academic situation across:
 * 1. user & personal_planning
 * 2. today (academic status, today's focus, priority actions, today's sessions)
 * 3. tasks (active tasks, summary, intelligence metrics)
 * 4. deadlines (upcoming & overdue backlogs)
 * 5. courses (course workload, pressure, risk)
 * 6. schedule (weekly timetable, sessions today, utilization)
 * 7. progress (completion rate, insights)
 * 8. reports (historical comparisons, suggested actions)
 * 9. notifications (active contextual alerts)
 *
 * Grounded in actual database data — no fake/mock trends.
 * Read-only — never modifies database records.
 * Secure — excludes passwords, hashes, CSRF tokens, and secrets.
 */
function getAIAcademicContext(PDO $db, int $userId): array
{
    // --- 1. USER & PERSONAL PLANNING CONTEXT ---
    $userRow = getUserProfileRow($userId);
    $planningContext = getPersonalPlanningContext($db, $userId);

    $userContext = [
        'id'        => $userId,
        'full_name' => $userRow['full_name'] ?? 'Student',
        'email'     => $userRow['email'] ?? '',
        'program'   => $userRow['program'] ?? '',
        'level'     => $userRow['level'] ?? '',
        'tagline'   => $userRow['tagline'] ?? '',
    ];

    // --- 2. COURSES ---
    $stmt = $db->prepare(
        "SELECT c.id, c.code, c.name, c.credits, c.grade_point, c.color, c.icon,
                COUNT(t.id) AS task_count,
                SUM(t.status = 'completed') AS completed_count
         FROM courses c
         LEFT JOIN tasks t ON t.course_id = c.id AND t.user_id = c.user_id
         WHERE c.user_id = ?
         GROUP BY c.id
         ORDER BY c.code ASC"
    );
    $stmt->execute([$userId]);
    $rawCourses = $stmt->fetchAll();

    // Batch overdue task counts across all courses in a single query to eliminate N+1 amplification
    $stmtOverdue = $db->prepare(
        "SELECT course_id, COUNT(*) AS overdue_count
         FROM tasks
         WHERE user_id = ? AND status != 'completed' AND due_at < NOW() AND course_id IS NOT NULL
         GROUP BY course_id"
    );
    $stmtOverdue->execute([$userId]);
    $overdueMap = [];
    while ($row = $stmtOverdue->fetch(PDO::FETCH_ASSOC)) {
        $overdueMap[(int)$row['course_id']] = (int)$row['overdue_count'];
    }
    $workloadMap = getCoursesWorkloadMap($db, $userId);

    $courses = [];
    foreach ($rawCourses as $c) {
        $courseId = (int)$c['id'];
        $count = (int)$c['task_count'];
        $completed = (int)$c['completed_count'];
        $pct = $count > 0 ? (int)round(($completed / $count) * 100) : 0;
        $credits = (int)($c['credits'] ?? 3);
        $gradePoint = $c['grade_point'] !== null ? (float)$c['grade_point'] : null;

        $riskScore = courseRiskScore($gradePoint, $credits);
        $riskLabel = $riskScore >= 80 ? 'Critical Risk' : ($riskScore >= 60 ? 'High Risk' : ($riskScore >= 35 ? 'Moderate Risk' : 'Low Risk'));

        $workloadHours = (float)($workloadMap[$courseId] ?? 0.0);
        $overdueCount = $overdueMap[$courseId] ?? 0;

        $pendingCount = max(0, $count - $completed);
        $pressure = determineCoursePressure($riskScore, $workloadHours, $overdueCount, $pendingCount);

        if ($overdueCount > 0) {
            $msg = "{$overdueCount} overdue • {$workloadHours}h remaining • {$riskLabel}";
        } elseif ($pendingCount > 0) {
            $msg = "{$pendingCount} pending task" . ($pendingCount === 1 ? '' : 's') . " • {$workloadHours}h remaining";
        } else {
            $msg = 'All tasks completed • On track';
        }

        $courses[] = [
            'id'                       => $courseId,
            'code'                     => $c['code'],
            'name'                     => $c['name'],
            'credits'                  => $credits,
            'grade_point'              => $gradePoint,
            'task_count'               => $count,
            'completed_count'          => $completed,
            'pending_count'            => $pendingCount,
            'pending_tasks_count'      => $pendingCount,
            'overdue_count'            => $overdueCount,
            'overdue_tasks_count'      => $overdueCount,
            'progress_percent'         => $pct,
            'progress'                 => $pct,
            'remaining_workload_hours' => $workloadHours,
            'course_risk_score'        => $riskScore,
            'course_risk_label'        => $riskLabel,
            'course_pressure'          => $pressure,
            'context_message'          => $msg,
        ];
    }

    // --- 3. ALL TASKS & INTELLIGENCE ---
    $stmt = $db->prepare(
        "SELECT t.*, c.code AS course_code, c.name AS course_name, c.color AS course_color,
                c.credits AS course_credits, c.grade_point AS course_grade_point
         FROM tasks t
         LEFT JOIN courses c ON c.id = t.course_id AND c.user_id = t.user_id
         WHERE t.user_id = ?
         ORDER BY t.status = 'completed' ASC, t.due_at ASC"
    );
    $stmt->execute([$userId]);
    $allRawTasks = $stmt->fetchAll();

    $allTasks = [];
    $activeTasks = [];
    $completedTasks = [];
    $overdueTasks = [];
    $dueSoonTasks = [];
    $todayTasks = [];

    $totalTasksCount = count($allRawTasks);
    $totalRemainingWorkload = 0.0;
    $todayDateStr = date('Y-m-d');

    foreach ($allRawTasks as $rawT) {
        $decorated = decorateTask($rawT);
        $isCompleted = ($decorated['status'] === 'completed');
        $urgency = $decorated['urgency'] ?? 'upcoming';

        $taskSummaryItem = [
            'id'                    => (int)$decorated['id'],
            'course_id'             => $decorated['course_id'] ? (int)$decorated['course_id'] : null,
            'course_code'           => $decorated['course_code'] ?? null,
            'course_name'           => $decorated['course_name'] ?? null,
            'title'                 => $decorated['title'],
            'description'           => $decorated['description'] ?? '',
            'type'                  => $decorated['type'],
            'priority'              => $decorated['priority'],
            'priority_label'        => $decorated['priority_label'] ?? strtoupper($decorated['priority']) . ' PRIORITY',
            'status'                => $decorated['status'],
            'progress_percent'      => (int)($decorated['progress_percent'] ?? 0),
            'duration_hours'        => (float)($decorated['duration_hours'] ?? 0),
            'remaining_hours'       => (float)($decorated['remaining_hours'] ?? 0),
            'due_at'                => $decorated['due_at'],
            'due_at_display'        => date('g:i A · M j, Y', strtotime($decorated['due_at'])),
            'urgency'               => $urgency,
            'due_label'             => $decorated['due_label'] ?? dueRelativeLabel($decorated['due_at']),
            'smart_priority_score'  => (int)($decorated['smart_priority_score'] ?? 0),
            'smart_priority_label'  => $decorated['smart_priority_label'] ?? 'Normal',
            'task_risk_score'       => (int)($decorated['task_risk_score'] ?? 0),
            'task_risk'             => $decorated['task_risk'] ?? 'Normal',
            'workload_pressure'     => (int)($decorated['workload_pressure'] ?? 0),
            'deadline_pressure'     => $decorated['deadline_pressure'] ?? 'normal',
            'recommended_action'    => $decorated['recommended_action'] ?? '',
            'priority_reason'       => $decorated['priority_reason'] ?? '',
        ];

        $allTasks[] = $taskSummaryItem;

        if ($isCompleted) {
            $completedTasks[] = $taskSummaryItem;
        } else {
            $activeTasks[] = $taskSummaryItem;
            $totalRemainingWorkload += (float)$taskSummaryItem['remaining_hours'];

            if ($urgency === 'overdue') {
                $overdueTasks[] = $taskSummaryItem;
            } elseif ($urgency === 'due_soon') {
                $dueSoonTasks[] = $taskSummaryItem;
            }

            if (substr($decorated['due_at'], 0, 10) === $todayDateStr) {
                $todayTasks[] = $taskSummaryItem;
            }
        }
    }

    // Sort active tasks by urgency, smart priority, due date
    usort($activeTasks, function ($a, $b) {
        $aOverdue = ($a['urgency'] === 'overdue') ? 1 : 0;
        $bOverdue = ($b['urgency'] === 'overdue') ? 1 : 0;
        if ($bOverdue !== $aOverdue) return $bOverdue - $aOverdue;

        if ($b['smart_priority_score'] !== $a['smart_priority_score']) {
            return $b['smart_priority_score'] <=> $a['smart_priority_score'];
        }

        $dueA = strtotime($a['due_at']);
        $dueB = strtotime($b['due_at']);
        if ($dueA !== $dueB) return $dueA <=> $dueB;

        return $a['id'] <=> $b['id'];
    });

    // --- 4. TODAY & ACADEMIC STATE ---
    $todayDow = (int)date('w');
    $nowTime = date('H:i:s');

    // Today's schedule sessions
    $stmt = $db->prepare(
        'SELECT se.*, c.code AS course_code, c.name AS course_name, c.color AS course_color 
         FROM schedule_events se
         LEFT JOIN courses c ON c.id = se.course_id AND c.user_id = se.user_id
         WHERE se.user_id = ? AND se.day_of_week = ? ORDER BY se.start_time'
    );
    $stmt->execute([$userId, $todayDow]);
    $rawTodaySchedule = $stmt->fetchAll();

    $todaySessions = [];
    $completedSessionsToday = 0;
    $upcomingSessionsToday = 0;
    $missedSessionsToday = 0;
    $inProgressSessionsToday = 0;
    $nextSessionToday = null;

    foreach ($rawTodaySchedule as $ev) {
        $isComp = !empty($ev['is_completed']);
        $startTime = $ev['start_time'];
        $endTime = $ev['end_time'];

        if ($isComp) {
            $timelineStatus = 'completed';
            $timelineLabel = 'Completed';
            $completedSessionsToday++;
        } elseif ($endTime < $nowTime) {
            $timelineStatus = 'missed';
            $timelineLabel = 'Missed';
            $missedSessionsToday++;
        } elseif ($startTime <= $nowTime && $nowTime <= $endTime) {
            $timelineStatus = 'in_progress';
            $timelineLabel = 'In Progress';
            $inProgressSessionsToday++;
            if ($nextSessionToday === null) $nextSessionToday = $ev;
        } else {
            $timelineStatus = 'upcoming';
            $timelineLabel = 'Upcoming';
            $upcomingSessionsToday++;
            if ($nextSessionToday === null) $nextSessionToday = $ev;
        }

        $durationHrs = round((strtotime($endTime) - strtotime($startTime)) / 3600, 2);

        $todaySessions[] = [
            'id'               => (int)$ev['id'],
            'course_id'        => $ev['course_id'] ? (int)$ev['course_id'] : null,
            'course_code'      => $ev['course_code'] ?? null,
            'course_name'      => $ev['course_name'] ?? null,
            'title'            => $ev['title'],
            'event_type'       => $ev['event_type'],
            'start_time'       => $startTime,
            'end_time'         => $endTime,
            'start_label'      => date('g:i A', strtotime($startTime)),
            'end_label'        => date('g:i A', strtotime($endTime)),
            'duration_hours'   => $durationHrs,
            'timeline_status'  => $timelineStatus,
            'timeline_label'   => $timelineLabel,
            'is_completed'     => $isComp,
            'is_study_session' => ($ev['event_type'] === 'study'),
        ];
    }

    // Determine Academic State & Context (reusing Dashboard rules)
    $todaysFocus = null;
    $priorityActions = [];
    $academicState = 'on_track';
    $headline = "You're On Track";
    $contextMessage = 'Your study progress is steady. Focus on your scheduled sessions today.';

    $overdueCount = count($overdueTasks);

    if ($totalTasksCount === 0) {
        $academicState = 'empty';
        $headline = 'Welcome to Your Study Planner';
        $contextMessage = 'Add your course tasks and timetable to activate smart academic planning.';
    } elseif (empty($activeTasks)) {
        $academicState = 'all_completed';
        $headline = "You're All Caught Up!";
        $contextMessage = 'All your tasks are completed. Use today to review or get ahead on upcoming topics.';
    } else {
        $todaysFocus = $activeTasks[0];
        if (count($activeTasks) > 1) {
            $priorityActions = array_slice($activeTasks, 1, 3);
        }

        if ($overdueCount > 0) {
            $academicState = 'overdue';
            $headline = 'Attention Needed';
            $contextMessage = $overdueCount === 1
                ? 'You have 1 overdue task that requires immediate attention.'
                : "You have {$overdueCount} overdue tasks that require immediate attention.";
        } elseif (
            ($todaysFocus['task_risk'] ?? '') === 'Critical Risk' ||
            ($todaysFocus['task_risk'] ?? '') === 'High Risk' ||
            ($todaysFocus['deadline_pressure'] ?? '') === 'urgent' ||
            ($todaysFocus['deadline_pressure'] ?? '') === 'critical'
        ) {
            $academicState = 'urgent';
            $headline = 'High Priority Items Today';
            $contextMessage = 'Important deadlines are approaching soon. Prioritize your top focus task today.';
        } else {
            $academicState = 'on_track';
            $headline = "You're On Track";
            $contextMessage = 'Maintain your study rhythm and complete tasks ahead of deadlines.';
        }
    }

    $dayNamesFull = [0 => 'Sunday', 1 => 'Monday', 2 => 'Tuesday', 3 => 'Wednesday', 4 => 'Thursday', 5 => 'Friday', 6 => 'Saturday'];

    $todayContext = [
        'date'                     => $todayDateStr,
        'formatted_date'           => date('l, F j, Y'),
        'day_of_week'              => $todayDow,
        'day_name'                 => $dayNamesFull[$todayDow] ?? '',
        'academic_state'           => $academicState,
        'headline'                 => $headline,
        'message'                  => $contextMessage,
        'todays_focus'             => $todaysFocus,
        'priority_actions'         => $priorityActions,
        'tasks_due_today'          => $todayTasks,
        'sessions_today'           => $todaySessions,
        'sessions_completed_count' => $completedSessionsToday,
        'sessions_upcoming_count'  => $upcomingSessionsToday,
        'sessions_missed_count'    => $missedSessionsToday,
        'active_tasks_count'       => count($activeTasks),
        'overdue_tasks_count'      => $overdueCount,
        'remaining_workload_hours' => round($totalRemainingWorkload, 1),
    ];

    // --- 5. DEADLINES BACKLOG ---
    // Overdue: incomplete tasks where due_at < NOW() ($overdueTasks)
    // Upcoming: strictly future active tasks (urgency !== 'overdue', due_at >= NOW()), sorted chronologically
    $deadlinesUpcoming = [];
    foreach ($activeTasks as $t) {
        if (($t['urgency'] ?? '') !== 'overdue') {
            $deadlinesUpcoming[] = $t;
        }
    }
    usort($deadlinesUpcoming, function ($a, $b) {
        $dueA = strtotime($a['due_at']);
        $dueB = strtotime($b['due_at']);
        if ($dueA !== $dueB) {
            return $dueA <=> $dueB;
        }
        return $a['id'] <=> $b['id'];
    });

    // --- 6. SCHEDULE (WEEKLY TIMETABLE) ---
    $stmt = $db->prepare(
        'SELECT se.*, c.code AS course_code, c.name AS course_name, c.color AS course_color
         FROM schedule_events se
         LEFT JOIN courses c ON c.id = se.course_id AND c.user_id = se.user_id
         WHERE se.user_id = ?
         ORDER BY se.day_of_week, se.start_time'
    );
    $stmt->execute([$userId]);
    $allEvents = $stmt->fetchAll();

    $scheduledWeeklyHours = 0.0;
    $completedWeeklySessions = 0;
    $hoursByType = [];

    foreach ($allEvents as $ev) {
        $hrs = (strtotime($ev['end_time']) - strtotime($ev['start_time'])) / 3600;
        $scheduledWeeklyHours += $hrs;
        $type = $ev['event_type'] ?? 'other';
        $hoursByType[$type] = ($hoursByType[$type] ?? 0) + $hrs;
        if (!empty($ev['is_completed'])) {
            $completedWeeklySessions++;
        }
    }

    $weeklyGoal = (float)$planningContext['weekly_goal_hours'];

    $scheduleContext = [
        'total_weekly_sessions'    => count($allEvents),
        'scheduled_weekly_hours'   => round($scheduledWeeklyHours, 1),
        'completed_weekly_sessions'=> $completedWeeklySessions,
        'weekly_goal_hours'        => $weeklyGoal,
        'weekly_utilization_pct'   => $weeklyGoal > 0 ? (int)min(100, round(($scheduledWeeklyHours / $weeklyGoal) * 100)) : 0,
        'time_distribution_hours'  => array_map(fn($h) => round($h, 1), $hoursByType),
        'today_sessions'           => $todaySessions,
    ];

    // --- 7. PROGRESS & INSIGHTS ---
    $taskSummaryForInsights = [
        'total'     => $totalTasksCount,
        'completed' => count($completedTasks),
        'pending'   => count($activeTasks) - $overdueCount,
        'overdue'   => $overdueCount,
    ];
    $overallTaskProgress = $totalTasksCount > 0 ? (int)round((count($completedTasks) / $totalTasksCount) * 100) : 0;
    $progressInsights = computeProgressInsights($db, $userId, $courses, $taskSummaryForInsights, 'semester');

    $progressContext = [
        'overall_task_progress_pct' => $overallTaskProgress,
        'total_tasks'               => $totalTasksCount,
        'completed_tasks'           => count($completedTasks),
        'active_tasks'              => count($activeTasks),
        'overdue_tasks'             => $overdueCount,
        'remaining_workload_hours'  => round($totalRemainingWorkload, 1),
        'insights'                  => $progressInsights,
    ];

    // --- 8. REPORTS SUMMARY ---
    $nowDt = new DateTime();
    $startWeek = (clone $nowDt)->modify('-7 days');
    $stmt = $db->prepare("SELECT COUNT(*) AS n FROM tasks WHERE user_id = ? AND created_at >= ?");
    $stmt->execute([$userId, $startWeek->format('Y-m-d H:i:s')]);
    $tasksCreatedWeek = (int)$stmt->fetch()['n'];

    $stmt = $db->prepare("SELECT COUNT(*) AS n FROM tasks WHERE user_id = ? AND status = 'completed' AND completed_at >= ?");
    $stmt->execute([$userId, $startWeek->format('Y-m-d H:i:s')]);
    $tasksCompletedWeek = (int)$stmt->fetch()['n'];

    $reportComparisons = computeReportComparisons(
        $db,
        $userId,
        'week',
        $startWeek,
        $nowDt,
        $tasksCreatedWeek,
        $tasksCompletedWeek,
        $overdueCount
    );

    $reportsContext = [
        'period'                  => 'week',
        'tasks_created'           => $tasksCreatedWeek,
        'tasks_completed'         => $tasksCompletedWeek,
        'completion_rate_pct'     => $tasksCreatedWeek > 0 ? (int)round(($tasksCompletedWeek / $tasksCreatedWeek) * 100) : 0,
        'overdue_tasks'           => $overdueCount,
        'logged_study_hours'      => $planningContext['logged_study_hours'],
        'weekly_goal_hours'       => $weeklyGoal,
        'goal_progress_percent'   => $planningContext['goal_progress_percent'],
        'historical_comparison'   => $reportComparisons,
        'highest_progress_course' => $reportComparisons['highest_progress_course'],
        'attention_course'        => $reportComparisons['attention_course'],
    ];

    // --- 9. NOTIFICATIONS (Read-only active contextual alerts) ---

    $stmt = $db->prepare(
        "SELECT n.id, n.task_id, n.channel, n.message, n.send_at, n.read_at, n.created_at,
                t.title AS task_title
         FROM notifications n
         LEFT JOIN tasks t ON t.id = n.task_id AND t.user_id = n.user_id
         WHERE n.user_id = ? AND n.channel = 'in_app' AND n.send_at <= NOW() AND n.read_at IS NULL
         ORDER BY n.send_at DESC LIMIT 10"
    );
    $stmt->execute([$userId]);
    $unreadNotifs = array_map('decorateNotification', $stmt->fetchAll());

    $stmt = $db->prepare(
        "SELECT COUNT(*) AS n FROM notifications
         WHERE user_id = ? AND channel = 'in_app' AND send_at <= NOW() AND read_at IS NULL"
    );
    $stmt->execute([$userId]);
    $totalUnreadCount = (int)$stmt->fetch()['n'];

    $notificationsContext = [
        'unread_count'  => $totalUnreadCount,
        'active_alerts' => array_map(fn($n) => [
            'id'         => (int)$n['id'],
            'category'   => $n['category'] ?? 'academic',
            'title'      => $n['title'] ?? 'Academic Update',
            'message'    => $n['message'],
            'tone'       => $n['tone'] ?? 'info',
            'task_id'    => $n['task_id'] ? (int)$n['task_id'] : null,
            'task_title' => $n['task_title'] ?? null,
            'time_ago'   => timeAgo($n['send_at']),
        ], $unreadNotifs),
    ];

    // --- ASSEMBLE FULL GROUNDED CONTEXT ---
    return [
        'generated_at'       => date('c'),
        'user'               => $userContext,
        'personal_planning'  => $planningContext,
        'today'              => $todayContext,
        'tasks'              => [
            'summary'          => [
                'total'                    => $totalTasksCount,
                'active'                   => count($activeTasks),
                'completed'                => count($completedTasks),
                'pending'                  => count($activeTasks) - $overdueCount,
                'overdue'                  => $overdueCount,
                'due_soon'                 => count($dueSoonTasks),
                'remaining_workload_hours' => round($totalRemainingWorkload, 1),
            ],
            'active_items'     => $activeTasks,
        ],
        'deadlines'          => [
            'overdue'          => $overdueTasks,
            'upcoming'         => $deadlinesUpcoming,
        ],
        'courses'            => $courses,
        'schedule'           => $scheduleContext,
        'progress'           => $progressContext,
        'reports'            => $reportsContext,
        'notifications'      => $notificationsContext,
    ];
}

/* ============================================================
   FOUNDATION 8A: STUDY SESSION & TIME-BASED PROGRESS HELPERS
   Backend calculations for focus sessions and actual study time.
============================================================ */

/**
 * Obtain a map of total focused seconds for all tasks of a user in a single query.
 * Prevents N+1 query amplification across task lists and academic intelligence.
 */
function getUserTasksFocusedSecondsMap(PDO $db, int $userId): array
{
    return getUsersTasksFocusedSeconds($db, $userId);
}

/**
 * Compute total focused seconds spent on a specific task by the user.
 * Direct query guarantees authoritative fresh values for the requested task.
 */
function getTaskTotalFocusedSeconds(PDO $db, int $userId, int $taskId): int
{
    $stmt = $db->prepare(
        "SELECT 
            COALESCE(SUM(
                CASE 
                    WHEN status = 'running' THEN duration_seconds + GREATEST(0, TIMESTAMPDIFF(SECOND, started_at, NOW()))
                    ELSE duration_seconds 
                END
            ), 0)
         FROM task_work_sessions
         WHERE user_id = ? AND task_id = ?"
    );
    $stmt->execute([$userId, $taskId]);
    return max(0, (int) $stmt->fetchColumn());
}

/**
 * Compute total focused seconds spent today by the user across all tasks.
 */
function getUserTodayFocusedSeconds(PDO $db, int $userId): int
{
    $stmt = $db->prepare(
        "SELECT 
            COALESCE(SUM(
                CASE 
                    WHEN status = 'running' THEN duration_seconds + GREATEST(0, TIMESTAMPDIFF(SECOND, started_at, NOW()))
                    ELSE duration_seconds 
                END
            ), 0) AS today_seconds
         FROM task_work_sessions
         WHERE user_id = ? AND DATE(started_at) = CURDATE()"
    );
    $stmt->execute([$userId]);
    return max(0, (int) $stmt->fetchColumn());
}

/**
 * Compute total focused seconds spent within a date/time range by the user across all tasks.
 */
function getUserPeriodFocusedSeconds(PDO $db, int $userId, string $startStr, string $endStr): int
{
    $stmt = $db->prepare(
        "SELECT 
            COALESCE(SUM(
                CASE 
                    WHEN status = 'running' THEN duration_seconds + GREATEST(0, TIMESTAMPDIFF(SECOND, started_at, NOW()))
                    ELSE duration_seconds 
                END
            ), 0) AS period_seconds
         FROM task_work_sessions
         WHERE user_id = ? AND started_at BETWEEN ? AND ?"
    );
    $stmt->execute([$userId, $startStr, $endStr]);
    return max(0, (int) $stmt->fetchColumn());
}

/**
 * Compute total focused seconds for all tasks of a user in a single batch query.
 * Includes duration from completed/paused/stopped sessions plus live elapsed seconds
 * for any session that is currently running.
 *
 * @return array<int, int> Map of task_id => total_focused_seconds
 */
function getUsersTasksFocusedSeconds(PDO $db, int $userId): array
{
    $stmt = $db->prepare(
        "SELECT 
            task_id,
            COALESCE(SUM(
                CASE 
                    WHEN status = 'running' THEN duration_seconds + GREATEST(0, TIMESTAMPDIFF(SECOND, started_at, NOW()))
                    ELSE duration_seconds 
                END
            ), 0) AS total_seconds
         FROM task_work_sessions
         WHERE user_id = ?
         GROUP BY task_id"
    );
    $stmt->execute([$userId]);
    $map = [];
    while ($row = $stmt->fetch()) {
        $map[(int) $row['task_id']] = max(0, (int) $row['total_seconds']);
    }
    return $map;
}

/**
 * Compute total focused seconds spent today per course by the user.
 * @return array<int, int> Map of course_id => today_focused_seconds
 */
function getUsersCoursesTodayFocusedSeconds(PDO $db, int $userId): array
{
    $stmt = $db->prepare(
        "SELECT 
            t.course_id,
            COALESCE(SUM(
                CASE 
                    WHEN tws.status = 'running' THEN tws.duration_seconds + GREATEST(0, TIMESTAMPDIFF(SECOND, tws.started_at, NOW()))
                    ELSE tws.duration_seconds 
                END
            ), 0) AS today_seconds
         FROM task_work_sessions tws
         JOIN tasks t ON t.id = tws.task_id AND t.user_id = tws.user_id
         WHERE tws.user_id = ? AND DATE(tws.started_at) = CURDATE() AND t.course_id IS NOT NULL
         GROUP BY t.course_id"
    );
    $stmt->execute([$userId]);
    $map = [];
    while ($row = $stmt->fetch()) {
        $map[(int) $row['course_id']] = max(0, (int) $row['today_seconds']);
    }
    return $map;
}

/**
 * Retrieve the current active (running or paused) study session for the user,
 * enriched with task details and live elapsed seconds.
 */
function getUserActiveStudySession(PDO $db, int $userId): ?array
{
    $stmt = $db->prepare(
        "SELECT s.*, 
                t.title AS task_title, 
                t.duration_hours AS task_duration_hours,
                t.progress_percent AS task_progress_percent, 
                t.status AS task_status,
                t.due_at AS task_due_at,
                c.id AS course_id,
                c.code AS course_code, 
                c.name AS course_name, 
                c.color AS course_color
         FROM task_work_sessions s
         INNER JOIN tasks t ON t.id = s.task_id AND t.user_id = s.user_id
         LEFT JOIN courses c ON c.id = t.course_id AND c.user_id = s.user_id
         WHERE s.user_id = ? AND s.status IN ('running', 'paused')
         ORDER BY s.id DESC
         LIMIT 1"
    );
    $stmt->execute([$userId]);
    $session = $stmt->fetch();
    if (!$session) {
        return null;
    }

    $elapsed = (int) $session['duration_seconds'];
    if ($session['status'] === 'running') {
        $startedTs = strtotime((string) $session['started_at']);
        if ($startedTs !== false) {
            $elapsed += max(0, time() - $startedTs);
        }
    }

    $session['current_elapsed_seconds'] = $elapsed;
    $session['current_elapsed_minutes'] = round($elapsed / 60, 1);
    $session['current_elapsed_hours'] = round($elapsed / 3600, 2);

    $totalFocused = getTaskTotalFocusedSeconds($db, $userId, (int) $session['task_id']);
    $taskMock = [
        'duration_hours'   => $session['task_duration_hours'],
        'progress_percent' => $session['task_progress_percent'],
        'status'           => $session['task_status'],
    ];
    $session['task_total_focused_seconds'] = $totalFocused;
    $session['time_progress'] = calculateTaskTimeProgress($taskMock, $totalFocused);

    return $session;
}

/**
 * Calculate time-based progress metrics for a task based on recorded focus time
 * and estimated duration, strictly bounded and preserving manual progress.
 *
 * Foundation 8C Centralized Formula:
 * - If duration > 0: calculates time_progress (0-100%) and remaining_seconds/hours
 * - If duration <= 0: safely handles without division-by-zero, does NOT invent fake estimates
 * - Preserves manual_progress_percent
 */
function calculateTaskTimeProgress(array $task, int $focusedSeconds): array
{
    $focusedSeconds = max(0, $focusedSeconds);
    $focusedMinutes = round($focusedSeconds / 60, 1);
    $focusedHours = round($focusedSeconds / 3600, 2);

    $manualProgress = max(0, min(100, (int) ($task['progress_percent'] ?? 0)));

    $rawDuration = isset($task['duration_hours']) ? (float) $task['duration_hours'] : 0.0;
    $hasEstimate = ($rawDuration > 0);

    if ($hasEstimate) {
        $estimatedHours = round(max(0.1, min(999.0, $rawDuration)), 2);
    } else {
        // Fallback to academic intelligence workload estimation
        $fallback = estimateTaskWorkloadHours($task);
        $estimatedHours = round(max(0.0, $fallback), 2);
    }

    $estimatedSeconds = (int) round($estimatedHours * 3600);
    $estimatedMinutes = round($estimatedSeconds / 60, 1);

    if ($estimatedHours > 0) {
        $timeProgress = round(($focusedSeconds / $estimatedSeconds) * 100, 1);
        $timeProgress = max(0.0, min(100.0, $timeProgress));
        $remainingSeconds = max(0, $estimatedSeconds - $focusedSeconds);
        $remainingMinutes = round($remainingSeconds / 60, 1);
        $remainingHours = round($remainingSeconds / 3600, 2);
    } else {
        $timeProgress = 0.0;
        $remainingSeconds = 0;
        $remainingMinutes = 0.0;
        $remainingHours = 0.0;
    }

    return [
        'has_estimate'             => $hasEstimate,
        'focused_seconds'          => $focusedSeconds,
        'focused_minutes'          => $focusedMinutes,
        'focused_hours'            => $focusedHours,
        'estimated_duration_hours' => $estimatedHours,
        'estimated_seconds'        => $estimatedSeconds,
        'estimated_minutes'        => $estimatedMinutes,
        'time_progress'            => $timeProgress,
        'time_progress_percent'    => $timeProgress,
        'remaining_seconds'        => $remainingSeconds,
        'remaining_minutes'        => $remainingMinutes,
        'remaining_hours'          => $remainingHours,
        'manual_progress_percent'  => $manualProgress,
    ];
}

/**
 * Decorate a single task with both standard intelligence and time tracking progress.
 * Leaves tasks.progress_percent and status unchanged.
 */
function decorateTaskWithTimeTracking(PDO $db, int $userId, array $task): array
{
    $taskId = (int) ($task['id'] ?? 0);
    $focusedSeconds = ($taskId > 0) ? getTaskTotalFocusedSeconds($db, $userId, $taskId) : 0;
    $task['total_focused_seconds'] = $focusedSeconds;
    $task['user_id'] = $userId;
    return decorateTask($task);
}

/**
 * Detect available free study periods between scheduled events for a given day of the week.
 * Analyzes recurring schedule_events between windowStart and windowEnd.
 *
 * @param PDO    $db
 * @param int    $userId
 * @param int    $dayOfWeek          0 (Sun) to 6 (Sat)
 * @param string $windowStart        Default '08:00:00'
 * @param string $windowEnd          Default '21:00:00'
 * @param int    $minDurationMinutes Default 45
 * @return array
 */
function detectFreeStudyPeriods(
    PDO $db,
    int $userId,
    int $dayOfWeek,
    string $windowStart = '08:00:00',
    string $windowEnd = '21:00:00',
    int $minDurationMinutes = 45
): array {
    $stmt = $db->prepare(
        'SELECT se.start_time, se.end_time, se.title, se.event_type, c.code AS course_code, c.name AS course_name
         FROM schedule_events se
         LEFT JOIN courses c ON c.id = se.course_id AND c.user_id = se.user_id
         WHERE se.user_id = ? AND se.day_of_week = ?
         ORDER BY se.start_time ASC'
    );
    $stmt->execute([$userId, $dayOfWeek]);
    $events = $stmt->fetchAll();

    // Normalize and merge busy intervals
    $busyIntervals = [];
    foreach ($events as $ev) {
        $st = max($windowStart, min($windowEnd, $ev['start_time']));
        $et = max($windowStart, min($windowEnd, $ev['end_time']));
        if ($et > $st) {
            $label = !empty($ev['course_code']) ? $ev['course_code'] : $ev['title'];
            $busyIntervals[] = ['start' => $st, 'end' => $et, 'title' => $label];
        }
    }

    // Sort intervals
    usort($busyIntervals, fn($a, $b) => strcmp($a['start'], $b['start']));

    // Merge overlapping intervals
    $mergedBusy = [];
    foreach ($busyIntervals as $int) {
        if (empty($mergedBusy)) {
            $mergedBusy[] = $int;
            continue;
        }
        $lastIdx = count($mergedBusy) - 1;
        if ($int['start'] <= $mergedBusy[$lastIdx]['end']) {
            if ($int['end'] > $mergedBusy[$lastIdx]['end']) {
                $mergedBusy[$lastIdx]['end'] = $int['end'];
                $mergedBusy[$lastIdx]['title'] .= ' / ' . $int['title'];
            }
        } else {
            $mergedBusy[] = $int;
        }
    }

    // Find gaps between windowStart and windowEnd
    $freePeriods = [];
    $cursor = $windowStart;
    $lastBusyTitle = null;

    foreach ($mergedBusy as $busy) {
        if ($busy['start'] > $cursor) {
            $diffSecs = strtotime($busy['start']) - strtotime($cursor);
            $diffMins = (int) round($diffSecs / 60);
            if ($diffMins >= $minDurationMinutes) {
                $context = '';
                if ($lastBusyTitle && !empty($busy['title'])) {
                    $context = "between {$lastBusyTitle} and {$busy['title']}";
                } elseif ($lastBusyTitle) {
                    $context = "after {$lastBusyTitle}";
                } elseif (!empty($busy['title'])) {
                    $context = "before {$busy['title']}";
                }

                $freePeriods[] = [
                    'start_time'       => $cursor,
                    'end_time'         => $busy['start'],
                    'duration_minutes' => $diffMins,
                    'duration_hours'   => round($diffMins / 60, 1),
                    'gap_context'      => $context,
                ];
            }
        }
        if ($busy['end'] > $cursor) {
            $cursor = $busy['end'];
            $lastBusyTitle = $busy['title'];
        }
    }

    if ($cursor < $windowEnd) {
        $diffSecs = strtotime($windowEnd) - strtotime($cursor);
        $diffMins = (int) round($diffSecs / 60);
        if ($diffMins >= $minDurationMinutes) {
            $context = $lastBusyTitle ? "after {$lastBusyTitle}" : "free study window";
            $freePeriods[] = [
                'start_time'       => $cursor,
                'end_time'         => $windowEnd,
                'duration_minutes' => $diffMins,
                'duration_hours'   => round($diffMins / 60, 1),
                'gap_context'      => $context,
            ];
        }
    }

    // Enrich free periods with metadata
    foreach ($freePeriods as &$fp) {
        $startHour = (int) date('H', strtotime($fp['start_time']));
        if ($startHour < 12) {
            $fp['time_of_day'] = 'morning';
            $fp['time_of_day_label'] = 'Morning';
        } elseif ($startHour < 17) {
            $fp['time_of_day'] = 'afternoon';
            $fp['time_of_day_label'] = 'Afternoon';
        } else {
            $fp['time_of_day'] = 'evening';
            $fp['time_of_day_label'] = 'Evening';
        }
        $fp['start_label'] = date('g:i A', strtotime($fp['start_time']));
        $fp['end_label'] = date('g:i A', strtotime($fp['end_time']));
        $hrs = floor($fp['duration_minutes'] / 60);
        $mins = $fp['duration_minutes'] % 60;
        $fp['duration_label'] = ($hrs > 0 ? "{$hrs}h " : '') . ($mins > 0 ? "{$mins}m" : '');
        $fp['duration_label'] = trim($fp['duration_label']) . ' free';
    }
    unset($fp);

    return $freePeriods;
}

/**
 * Generate a smart recommended study plan for the student based on:
 * - Active tasks (sorted by urgency, deadline proximity, remaining work)
 * - Available free periods in recurring schedule
 * - Preferred study times & realistic daily study target
 * - Existing schedule conflicts
 * - Active curriculum phase and Focus Timer study balance
 *
 * @param PDO      $db
 * @param int      $userId
 * @param int|null $dayOfWeek Optional day of week (defaults to current day)
 * @return array
 */
function generateRecommendedStudyPlan(PDO $db, int $userId, ?int $dayOfWeek = null): array
{
    if ($dayOfWeek === null) {
        $dayOfWeek = (int) date('w');
    }

    $planningContext = getPersonalPlanningContext($db, $userId);
    $preferredTime = $planningContext['preferred_study_time'] ?? 'flexible';

    // 1. Fetch active tasks and focus timer tracking
    $stmt = $db->prepare(
        "SELECT t.*, c.code AS course_code, c.name AS course_name, c.color AS course_color, c.credits AS course_credits
         FROM tasks t
         LEFT JOIN courses c ON c.id = t.course_id AND c.user_id = t.user_id
         WHERE t.user_id = ?
         ORDER BY t.due_at ASC"
    );
    $stmt->execute([$userId]);
    $allTasks = $stmt->fetchAll();
    $focusedMap = getUsersTasksFocusedSeconds($db, $userId);
    $coursesTodayFocused = getUsersCoursesTodayFocusedSeconds($db, $userId);

    $activeTasks = [];
    foreach ($allTasks as $taskRow) {
        $taskRow['total_focused_seconds'] = $focusedMap[(int) $taskRow['id']] ?? 0;
        $dec = decorateTask($taskRow);
        if (($dec['system_status'] ?? $dec['status']) === 'completed') {
            continue;
        }
        $cId = (int) ($taskRow['course_id'] ?? 0);
        $dec['course_today_focused_seconds'] = $coursesTodayFocused[$cId] ?? 0;
        $activeTasks[] = $dec;
    }

    // 2. Detect free periods on this day
    $freePeriods = detectFreeStudyPeriods($db, $userId, $dayOfWeek);
    if (empty($freePeriods)) {
        // Fallback default periods if timetable is empty or fully booked
        $freePeriods = [
            [
                'start_time'       => '16:00:00',
                'end_time'         => '18:00:00',
                'duration_minutes' => 120,
                'duration_hours'   => 2.0,
                'time_of_day'      => 'afternoon',
                'time_of_day_label'=> 'Afternoon',
                'start_label'      => '4:00 PM',
                'end_label'        => '6:00 PM',
                'duration_label'   => '2h free',
                'gap_context'      => 'afternoon study window',
            ],
            [
                'start_time'       => '19:00:00',
                'end_time'         => '21:00:00',
                'duration_minutes' => 120,
                'duration_hours'   => 2.0,
                'time_of_day'      => 'evening',
                'time_of_day_label'=> 'Evening',
                'start_label'      => '7:00 PM',
                'end_label'        => '9:00 PM',
                'duration_label'   => '2h free',
                'gap_context'      => 'evening study window',
            ],
        ];
    }

    // Sort free periods to prioritize the student's preferred study time
    if ($preferredTime !== 'flexible') {
        usort($freePeriods, function ($a, $b) use ($preferredTime) {
            $aPref = ($a['time_of_day'] === $preferredTime) ? 1 : 0;
            $bPref = ($b['time_of_day'] === $preferredTime) ? 1 : 0;
            if ($bPref !== $aPref) {
                return $bPref - $aPref;
            }
            return strcmp($a['start_time'], $b['start_time']);
        });
    }

    $semContext = getSemesterContext($db, $userId);
    $weekType = $semContext['current_week_type'] ?? 'teaching';
    $special = $semContext['upcoming_special'] ?? null;

    // CASE A: No active tasks -> Fall back to registered courses
    if (empty($activeTasks)) {
        $cStmt = $db->prepare('SELECT id, code, name, color, credits FROM courses WHERE user_id = ? ORDER BY credits DESC, code ASC');
        $cStmt->execute([$userId]);
        $userCourses = $cStmt->fetchAll();
        if (empty($userCourses)) {
            return [];
        }

        // Check if student had classes today to prioritize post-lecture review
        $todayClassCodes = [];
        $scStmt = $db->prepare('SELECT DISTINCT UPPER(COALESCE(c.code, se.title)) as c_code FROM schedule_events se LEFT JOIN courses c ON c.id = se.course_id WHERE se.user_id = ? AND se.day_of_week = ?');
        $scStmt->execute([$userId, $dayOfWeek]);
        foreach ($scStmt->fetchAll(PDO::FETCH_COLUMN) as $cc) {
            if ($cc) $todayClassCodes[$cc] = true;
        }

        // Sort courses: prioritize courses with 0 focus today, then courses that had a class today, then credits
        usort($userCourses, function($a, $b) use ($coursesTodayFocused, $todayClassCodes) {
            $aFocused = $coursesTodayFocused[(int) $a['id']] ?? 0;
            $bFocused = $coursesTodayFocused[(int) $b['id']] ?? 0;
            $aStudied = $aFocused >= 2700 ? 1 : 0;
            $bStudied = $bFocused >= 2700 ? 1 : 0;
            if ($aStudied !== $bStudied) return $aStudied - $bStudied;

            $aClass = isset($todayClassCodes[strtoupper($a['code'])]) ? 1 : 0;
            $bClass = isset($todayClassCodes[strtoupper($b['code'])]) ? 1 : 0;
            if ($aClass !== $bClass) return $bClass - $aClass;

            return ((int) ($b['credits'] ?? 3)) <=> ((int) ($a['credits'] ?? 3));
        });

        $recommendations = [];
        $courseIdx = 0;
        $maxRecs = min(3, count($userCourses));

        foreach ($freePeriods as $period) {
            if ($courseIdx >= $maxRecs) break;
            $course = $userCourses[$courseIdx];
            $cId = (int) $course['id'];
            $cCode = $course['code'];
            $credits = (int) ($course['credits'] ?? 3);
            $todayFocSecs = $coursesTodayFocused[$cId] ?? 0;
            $todayMins = round($todayFocSecs / 60);

            $slotMins = $period['duration_minutes'];
            $sessionMins = $slotMins >= 90 ? 90 : ($slotMins >= 60 ? 60 : 45);

            $startTimeStr = $period['start_time'];
            $endTimeStr = date('H:i:s', strtotime($startTimeStr) + ($sessionMins * 60));
            $startLabel = date('g:i A', strtotime($startTimeStr));
            $endLabel = date('g:i A', strtotime($endTimeStr));
            $durationLabel = ($sessionMins === 60) ? '1 hour recommended' : ($sessionMins === 90 ? '1h 30m recommended' : "{$sessionMins}m recommended");

            $gapNote = !empty($period['gap_context']) ? " ({$period['gap_context']})" : "";

            if ($weekType === 'revision') {
                $title = "Revise {$cCode}: Past Questions & Summaries";
                $reason = "Revision Week: Review lecture summaries and practice past questions for {$cCode} during your {$period['time_of_day']} window{$gapNote}.";
            } elseif ($weekType === 'exam') {
                $title = "Exam Prep: {$cCode} Core Concepts";
                $reason = "Examination Week: Consolidate formulas, key concepts, and exam prep for {$cCode} during your free {$period['time_of_day']} window{$gapNote}.";
            } elseif (isset($todayClassCodes[strtoupper($cCode)])) {
                $title = "Review Today's {$cCode} Lecture Notes";
                $reason = "Post-lecture review: Consolidate today's {$cCode} material while it's fresh in your memory{$gapNote}.";
            } elseif ($todayMins >= 30) {
                $title = "Consolidate {$cCode} Notes";
                $reason = "You've logged {$todayMins}m on {$cCode} today! A focused session now will reinforce what you learned.";
            } else {
                $title = "Study & Read {$cCode}";
                $reason = "Dedicate {$sessionMins}m during your {$period['time_of_day']} free period{$gapNote} to stay ahead in {$cCode} ({$credits} credits).";
            }

            $recommendations[] = [
                'id'               => 'rec_course_' . $cId . '_' . $dayOfWeek,
                'task_id'          => null,
                'title'            => $title,
                'task_title'       => $title,
                'course_id'        => $cId,
                'course_code'      => $cCode,
                'course_name'      => $course['name'] ?? $cCode,
                'course_color'     => $course['color'] ?? '#087b55',
                'day_of_week'      => $dayOfWeek,
                'start_time'       => $startTimeStr,
                'end_time'         => $endTimeStr,
                'start_label'      => $startLabel,
                'end_label'        => $endLabel,
                'duration_minutes' => $sessionMins,
                'duration_label'   => $durationLabel,
                'due_label'        => 'Weekly Study',
                'urgency'          => 'upcoming',
                'system_progress'  => 0,
                'remaining_hours'  => round($sessionMins / 60, 1),
                'action_label'     => 'Start Studying',
                'reason'           => $reason,
            ];
            $courseIdx++;
        }

        return $recommendations;
    }

    // CASE B: Active tasks exist -> Prioritize with intelligence and balance
    usort($activeTasks, function ($a, $b) {
        $aOver = (($a['urgency'] ?? '') === 'overdue' || ($a['hours_remaining'] ?? 0) < 0) ? 1 : 0;
        $bOver = (($b['urgency'] ?? '') === 'overdue' || ($b['hours_remaining'] ?? 0) < 0) ? 1 : 0;
        if ($bOver !== $aOver) {
            return $bOver - $aOver;
        }

        // If one task's course was already heavily studied today (>= 45m) and the other was not:
        $aStudied = (($a['course_today_focused_seconds'] ?? 0) >= 2700) ? 1 : 0;
        $bStudied = (($b['course_today_focused_seconds'] ?? 0) >= 2700) ? 1 : 0;
        if ($aStudied !== $bStudied) {
            return $aStudied - $bStudied;
        }

        $dueA = strtotime($a['due_at'] ?? 'now');
        $dueB = strtotime($b['due_at'] ?? 'now');
        if ($dueA !== $dueB) {
            return $dueA - $dueB;
        }

        return ($b['smart_priority_score'] ?? 0) <=> ($a['smart_priority_score'] ?? 0);
    });

    $recommendations = [];
    $taskIndex = 0;
    $maxRecommendations = min(3, count($activeTasks));

    foreach ($freePeriods as $period) {
        if ($taskIndex >= $maxRecommendations) {
            break;
        }

        $task = $activeTasks[$taskIndex];
        $remainingHours = (float) ($task['remaining_hours'] ?? $task['duration_hours'] ?? 1.0);
        $slotMinutes = $period['duration_minutes'];

        if ($slotMinutes >= 90 && $remainingHours >= 1.5) {
            $sessionMins = 90;
        } elseif ($slotMinutes >= 60) {
            $sessionMins = 60;
        } else {
            $sessionMins = 45;
        }

        $startTimeStr = $period['start_time'];
        $endTimeStr = date('H:i:s', strtotime($startTimeStr) + ($sessionMins * 60));
        $startLabel = date('g:i A', strtotime($startTimeStr));
        $endLabel = date('g:i A', strtotime($endTimeStr));
        $durationLabel = ($sessionMins === 60) ? '1 hour recommended' : ($sessionMins === 90 ? '1h 30m recommended' : "{$sessionMins}m recommended");

        $isOverdue = (($task['urgency'] ?? '') === 'overdue' || ($task['hours_remaining'] ?? 0) < 0);
        $dueText = $task['due_label'] ?? date('M j', strtotime($task['due_at']));

        $gapNote = !empty($period['gap_context']) ? " ({$period['gap_context']})" : "";
        $todayCourseSecs = (int) ($task['course_today_focused_seconds'] ?? 0);
        $todayCourseMins = round($todayCourseSecs / 60);

        if ($isOverdue) {
            $reason = "Overdue task needing immediate attention during your free {$period['time_of_day']} window{$gapNote}.";
        } elseif ($weekType === 'revision') {
            $reason = "Revision Week focus: review and consolidate key concepts for {$task['title']} during your free {$period['time_of_day']} slot{$gapNote}.";
        } elseif ($weekType === 'exam') {
            $reason = "Exam period priority: prepare for upcoming assessments on {$task['title']} during your free {$period['time_of_day']} slot{$gapNote}.";
        } elseif ($todayCourseMins >= 45) {
            $reason = "You've logged {$todayCourseMins}m on this course today! A {$sessionMins}m session will complete your target.";
        } elseif ($special && $special['type'] === 'revision' && $special['starts_in_days'] <= 14) {
            $reason = "Consolidate coursework ahead of Revision Week (starts in {$special['starts_in_days']} days){$gapNote}.";
        } elseif ($special && $special['type'] === 'exam' && $special['starts_in_days'] <= 21) {
            $reason = "Exam preparation slot ahead of examination period (starts in {$special['starts_in_days']} days){$gapNote}.";
        } else {
            $reason = "Best study slot for {$task['title']} before its deadline{$gapNote}.";
        }

        $recommendations[] = [
            'id'               => 'rec_' . $task['id'] . '_' . $dayOfWeek,
            'task_id'          => (int) $task['id'],
            'title'            => $task['title'],
            'task_title'       => $task['title'],
            'course_id'        => $task['course_id'] ? (int) $task['course_id'] : null,
            'course_code'      => $task['course_code'] ?? '',
            'course_name'      => $task['course_name'] ?? '',
            'course_color'     => $task['course_color'] ?? '#087b55',
            'day_of_week'      => $dayOfWeek,
            'start_time'       => $startTimeStr,
            'end_time'         => $endTimeStr,
            'start_label'      => $startLabel,
            'end_label'        => $endLabel,
            'duration_minutes' => $sessionMins,
            'duration_label'   => $durationLabel,
            'due_label'        => $isOverdue ? 'Overdue' : $dueText,
            'urgency'          => $isOverdue ? 'overdue' : ($task['urgency'] ?? 'upcoming'),
            'system_progress'  => (int) ($task['system_progress'] ?? $task['progress_percent'] ?? 0),
            'remaining_hours'  => round($remainingHours, 1),
            'action_label'     => 'Start Studying',
            'reason'           => $reason,
        ];

        $taskIndex++;
    }

    return $recommendations;
}

// ============================================================================
// SEMESTER CURRICULUM & DOCUMENT EXTRACTION ENGINE
// ============================================================================

/**
 * Pure-PHP PDF text extractor for standard document streams.
 */
function extractTextFromPdfContent(string $binaryData): string {
    if (empty($binaryData)) {
        return '';
    }
    $res = DocumentProcessor::extractFromPdf($binaryData);
    return $res['text'];
}

/**
 * Parses registered courses from university course registration form text.
 */
function parseCoursesFromText(string $rawText): array {
    return DocumentProcessor::parseCourses($rawText);
}

/**
 * Parses semester calendar / curriculum from text.
 */
function parseCurriculumFromText(string $rawText): array {
    return DocumentProcessor::parseCurriculum($rawText);
}

/**
 * Computes the semester context for the authenticated user based on current date.
 */
function getSemesterContext(PDO $db, int $userId): array {
    $emptyContext = [
        'has_semester'             => false,
        'semester_id'              => null,
        'semester_name'            => '',
        'start_date'               => null,
        'end_date'                 => null,
        'current_week_number'      => null,
        'current_week_label'       => null,
        'current_week_type'        => null,
        'total_weeks'              => 0,
        'phase_badge'              => 'No Semester Calendar',
        'student_guidance'         => 'Add your semester calendar to let the planner organize your study around key academic dates.',
        'upcoming_special'         => null,
        'days_to_exams'            => null,
        'days_to_revision'         => null,
        'remaining_teaching_weeks' => 0,
        'weeks'                    => [],
    ];

    try {
        $stmt = $db->prepare('SELECT * FROM semesters WHERE user_id = ? AND is_current = 1 ORDER BY id DESC LIMIT 1');
        $stmt->execute([$userId]);
        $sem = $stmt->fetch();
        if (!$sem) {
            return $emptyContext;
        }

        $wStmt = $db->prepare('SELECT * FROM curriculum_weeks WHERE semester_id = ? AND user_id = ? ORDER BY week_number ASC');
        $wStmt->execute([$sem['id'], $userId]);
        $weeks = $wStmt->fetchAll();

        if (empty($weeks)) {
            return array_merge($emptyContext, [
                'has_semester'  => true,
                'semester_id'   => (int) $sem['id'],
                'semester_name' => $sem['name'],
                'start_date'    => $sem['start_date'],
                'end_date'      => $sem['end_date'],
                'phase_badge'   => $sem['name'],
            ]);
        }

        $today = date('Y-m-d');
        $todayTs = strtotime($today);
        $startTs = strtotime($sem['start_date']);
        $endTs = strtotime($sem['end_date']);
        $totalWeeks = count($weeks);

        $currentWeek = null;
        $remainingTeaching = 0;
        $upcomingSpecial = null;
        $daysToExams = null;
        $daysToRevision = null;

        foreach ($weeks as $w) {
            $wStartTs = strtotime($w['start_date']);
            $wEndTs = strtotime($w['end_date']);
            $wType = $w['week_type'];

            if ($today >= $w['start_date'] && $today <= $w['end_date']) {
                $currentWeek = $w;
            }

            if ($wType === 'teaching' && $wEndTs >= $todayTs) {
                $remainingTeaching++;
            }

            if ($wStartTs > $todayTs) {
                $diffDays = (int) ceil(($wStartTs - $todayTs) / 86400);
                if (in_array($wType, ['revision', 'exam', 'student_week', 'break'], true)) {
                    if ($upcomingSpecial === null || $diffDays < $upcomingSpecial['starts_in_days']) {
                        $upcomingSpecial = [
                            'type'           => $wType,
                            'label'          => $w['label'],
                            'week_number'    => (int) $w['week_number'],
                            'starts_in_days' => $diffDays,
                            'start_date'     => $w['start_date'],
                        ];
                    }
                }

                if ($wType === 'exam' && ($daysToExams === null || $diffDays < $daysToExams)) {
                    $daysToExams = $diffDays;
                }
                if ($wType === 'revision' && ($daysToRevision === null || $diffDays < $daysToRevision)) {
                    $daysToRevision = $diffDays;
                }
            }
        }

        if ($todayTs < $startTs) {
            $daysUntilStart = (int) ceil(($startTs - $todayTs) / 86400);
            $phaseBadge = "Starts in {$daysUntilStart} days";
            $guidance = "Semester begins on " . date('M j, Y', $startTs) . " • Get your courses and timetable ready.";
            $currentWeekNum = 0;
            $currentWeekLabel = "Pre-Semester";
            $currentWeekType = "pre_semester";
        } elseif ($todayTs > $endTs) {
            $phaseBadge = "Semester Concluded";
            $guidance = "Semester completed • Review your final progress and achievements.";
            $currentWeekNum = $totalWeeks;
            $currentWeekLabel = "Semester Ended";
            $currentWeekType = "post_semester";
        } elseif ($currentWeek) {
            $currentWeekNum = (int) $currentWeek['week_number'];
            $currentWeekLabel = $currentWeek['label'];
            $currentWeekType = $currentWeek['week_type'];

            $typeTitle = ucwords(str_replace('_', ' ', $currentWeekType));
            if ($currentWeekType === 'teaching') {
                $phaseBadge = "Week {$currentWeekNum} of {$totalWeeks} • Teaching Week";
            } else {
                $phaseBadge = "Week {$currentWeekNum} of {$totalWeeks} • {$typeTitle}";
            }

            if ($currentWeekType === 'revision') {
                $guidance = "Revision Week • Focus on review and consolidating your course materials.";
            } elseif ($currentWeekType === 'exam') {
                $guidance = "Examination Week • Prioritize your upcoming exam dates and review summaries.";
            } elseif ($currentWeekType === 'student_week') {
                $guidance = "Student Week • Catch up on overdue coursework or take a well-deserved breather.";
            } elseif ($currentWeekType === 'break') {
                $guidance = "Academic Break • Take time to rest and recharge for upcoming coursework.";
            } elseif ($daysToRevision !== null && $daysToRevision <= 14) {
                $guidance = "Revision week is coming up • Start reviewing your unfinished topics.";
            } elseif ($daysToExams !== null && $daysToExams <= 21) {
                $guidance = "Exams are approaching • Here are the courses that need attention.";
            } else {
                $guidance = "Teaching week {$currentWeekNum} • Stay steady with your assignments and classes.";
            }
        } else {
            $weekOffset = max(1, min($totalWeeks, (int) floor(($todayTs - $startTs) / (7 * 86400)) + 1));
            $currentWeekNum = $weekOffset;
            $currentWeekLabel = "Week {$weekOffset}";
            $currentWeekType = 'teaching';
            $phaseBadge = "Week {$weekOffset} of {$totalWeeks}";
            $guidance = "Week {$weekOffset} • Keep your academic workload on track.";
        }

        return [
            'has_semester'             => true,
            'semester_id'              => (int) $sem['id'],
            'semester_name'            => $sem['name'],
            'start_date'               => $sem['start_date'],
            'end_date'                 => $sem['end_date'],
            'current_week_number'      => $currentWeekNum,
            'current_week_label'       => $currentWeekLabel,
            'current_week_type'        => $currentWeekType,
            'total_weeks'              => $totalWeeks,
            'phase_badge'              => $phaseBadge,
            'student_guidance'         => $guidance,
            'upcoming_special'         => $upcomingSpecial,
            'days_to_exams'            => $daysToExams,
            'days_to_revision'         => $daysToRevision,
            'remaining_teaching_weeks' => $remainingTeaching,
            'weeks'                    => $weeks,
        ];
    } catch (Throwable $e) {
        error_log('getSemesterContext error: ' . $e->getMessage());
        return $emptyContext;
    }
}




