<?php
declare(strict_types=1);

/**
 * ============================================================================
 * FOUNDATION LAYER 2 — AUTOMATED ACADEMIC SCHEDULING ENGINE
 *
 * Deterministic scheduling algorithm operating exclusively on real
 * authenticated student records:
 * - Computes genuine available study windows (7 calendar days).
 * - Prioritizes real pending tasks by urgency, deadline proximity & effort.
 * - Enforces Section 14A Explicit Scheduling Defaults.
 * - Prevents timetable and manual session conflicts.
 * - Performs safe reconciliation without duplicates.
 * - Completely zero external AI API dependency.
 * ============================================================================
 */

require_once __DIR__ . '/functions.php';

/**
 * Section 14A Default Configuration Constants
 */
const SCHEDULER_DEFAULT_HORIZON_DAYS = 7;
const SCHEDULER_DEFAULT_WINDOW_START = '08:00:00';
const SCHEDULER_DEFAULT_WINDOW_END   = '21:00:00';
const SCHEDULER_TARGET_SESSION_MINS  = 60;
const SCHEDULER_MIN_SESSION_MINS     = 30;
const SCHEDULER_MAX_SESSION_MINS     = 90;
const SCHEDULER_MIN_WINDOW_MINS      = 30; // Windows < 30m ignored (Default F)
const SCHEDULER_INTER_TASK_BREAK_MINS = 15; // 10-15m transition break (Default G)

/**
 * Compute available study windows across a multi-day horizon.
 *
 * Availability formula:
 *   Planning hours (08:00 - 21:00)
 *   - Timetable classes (lectures/exams)
 *   - Fixed manual commitments (source = 'manual')
 *   - Past time (for today)
 *   = Candidate study windows (>= 30 mins)
 *
 * @param PDO    $db
 * @param int    $userId
 * @param string $startDate 'Y-m-d'
 * @param int    $days
 * @param array  $options
 * @return array
 */
function calculateAvailableWindows(
    PDO $db,
    int $userId,
    string $startDate = '',
    int $days = SCHEDULER_DEFAULT_HORIZON_DAYS,
    array $options = []
): array {
    if ($startDate === '') {
        $startDate = date('Y-m-d');
    }

    // 1. Retrieve student profile preferences
    $profile = getUserProfileRow($userId);
    $prefTime = (string) ($profile['preferred_study_time'] ?? 'flexible');
    $prefDaysRaw = (string) ($profile['preferred_study_days'] ?? '1,2,3,4,5');

    // Section 14A Default B: Missing preferred days -> all 7 days potentially available
    $prefDays = [];
    if (trim($prefDaysRaw) !== '') {
        $parts = explode(',', $prefDaysRaw);
        foreach ($parts as $p) {
            $trimmed = trim($p);
            if ($trimmed !== '' && is_numeric($trimmed)) {
                $prefDays[] = (int) $trimmed;
            }
        }
    }
    if (empty($prefDays)) {
        $prefDays = [0, 1, 2, 3, 4, 5, 6];
    }

    $nowDate = date('Y-m-d');
    $nowTime = date('H:i:s');
    $windows = [];

    // Query recurring timetable classes and manual sessions
    $stmtSched = $db->prepare(
        "SELECT start_time, end_time, title, event_type, course_id, day_of_week, source, event_date
         FROM schedule_events
         WHERE user_id = ?
           AND (event_type IN ('lecture', 'exam', 'other') OR source = 'manual')
         ORDER BY start_time ASC"
    );
    $stmtSched->execute([$userId]);
    $allCommitments = $stmtSched->fetchAll(PDO::FETCH_ASSOC);

    for ($i = 0; $i < $days; $i++) {
        $currentDate = date('Y-m-d', strtotime("$startDate +$i days"));
        $dow = (int) date('w', strtotime($currentDate));
        $isPreferredDay = in_array($dow, $prefDays, true);

        // Section 14A Default C: Planning hours 08:00 AM - 09:00 PM
        $dayStart = SCHEDULER_DEFAULT_WINDOW_START;
        $dayEnd   = SCHEDULER_DEFAULT_WINDOW_END;

        // Today boundary: cannot schedule in the past
        if ($currentDate === $nowDate) {
            // Buffer 5 minutes ahead
            $earliestPossible = date('H:i:00', strtotime('+5 minutes'));
            if ($earliestPossible > $dayStart) {
                $dayStart = $earliestPossible;
            }
            if ($dayStart >= $dayEnd || (strtotime($dayEnd) - strtotime($dayStart)) < (SCHEDULER_MIN_WINDOW_MINS * 60)) {
                continue; // Insufficient remaining time today
            }
        }

        // Collect busy intervals for this specific date
        $busyIntervals = [];
        foreach ($allCommitments as $ev) {
            $applies = false;
            if (!empty($ev['event_date'])) {
                if ($ev['event_date'] === $currentDate) {
                    $applies = true;
                }
            } elseif ((int)$ev['day_of_week'] === $dow) {
                $applies = true;
            }

            if ($applies) {
                $st = max($dayStart, min($dayEnd, (string) $ev['start_time']));
                $et = max($dayStart, min($dayEnd, (string) $ev['end_time']));
                if ($et > $st) {
                    $busyIntervals[] = [
                        'start' => $st,
                        'end'   => $et,
                        'title' => (string) ($ev['title'] ?? 'Busy'),
                    ];
                }
            }
        }

        // Sort busy intervals by start time
        usort($busyIntervals, fn($a, $b) => strcmp($a['start'], $b['start']));

        // Merge overlapping busy intervals
        $mergedBusy = [];
        foreach ($busyIntervals as $b) {
            if (empty($mergedBusy)) {
                $mergedBusy[] = $b;
                continue;
            }
            $lastIdx = count($mergedBusy) - 1;
            if ($b['start'] <= $mergedBusy[$lastIdx]['end']) {
                if ($b['end'] > $mergedBusy[$lastIdx]['end']) {
                    $mergedBusy[$lastIdx]['end'] = $b['end'];
                }
            } else {
                $mergedBusy[] = $b;
            }
        }

        // Extract free intervals between dayStart and dayEnd
        $freeForDay = [];
        $cursor = $dayStart;

        foreach ($mergedBusy as $busy) {
            if ($busy['start'] > $cursor) {
                $diffSecs = strtotime($busy['start']) - strtotime($cursor);
                $diffMins = (int) round($diffSecs / 60);
                if ($diffMins >= SCHEDULER_MIN_WINDOW_MINS) {
                    $freeForDay[] = [
                        'date'             => $currentDate,
                        'day_of_week'      => $dow,
                        'start_time'       => $cursor,
                        'end_time'         => $busy['start'],
                        'duration_minutes' => $diffMins,
                        'duration_hours'   => round($diffMins / 60, 2),
                        'is_preferred_day' => $isPreferredDay,
                    ];
                }
            }
            if ($busy['end'] > $cursor) {
                $cursor = $busy['end'];
            }
        }

        if ($cursor < $dayEnd) {
            $diffSecs = strtotime($dayEnd) - strtotime($cursor);
            $diffMins = (int) round($diffSecs / 60);
            if ($diffMins >= SCHEDULER_MIN_WINDOW_MINS) {
                $freeForDay[] = [
                    'date'             => $currentDate,
                    'day_of_week'      => $dow,
                    'start_time'       => $cursor,
                    'end_time'         => $dayEnd,
                    'duration_minutes' => $diffMins,
                    'duration_hours'   => round($diffMins / 60, 2),
                    'is_preferred_day' => $isPreferredDay,
                ];
            }
        }

        // Assign time-of-day category
        foreach ($freeForDay as &$fp) {
            $sh = (int) date('H', strtotime($fp['start_time']));
            if ($sh < 12) {
                $fp['time_of_day'] = 'morning';
            } elseif ($sh < 17) {
                $fp['time_of_day'] = 'afternoon';
            } else {
                $fp['time_of_day'] = 'evening';
            }
        }
        unset($fp);

        // Sort intervals by student preferred study time (Default C)
        usort($freeForDay, function ($a, $b) use ($prefTime) {
            $rank = function (string $tod) use ($prefTime): int {
                if ($prefTime === 'morning') {
                    return match ($tod) { 'morning' => 1, 'afternoon' => 2, 'evening' => 3, default => 4 };
                } elseif ($prefTime === 'afternoon') {
                    return match ($tod) { 'afternoon' => 1, 'evening' => 2, 'morning' => 3, default => 4 };
                } elseif ($prefTime === 'evening') {
                    return match ($tod) { 'evening' => 1, 'afternoon' => 2, 'morning' => 3, default => 4 };
                }
                // 'flexible' default order: Morning -> Afternoon -> Early evening
                return match ($tod) { 'morning' => 1, 'afternoon' => 2, 'evening' => 3, default => 4 };
            };

            $rankA = $rank($a['time_of_day']);
            $rankB = $rank($b['time_of_day']);
            if ($rankA !== $rankB) {
                return $rankA <=> $rankB;
            }
            return strcmp($a['start_time'], $b['start_time']);
        });

        foreach ($freeForDay as $fw) {
            $windows[] = $fw;
        }
    }

    return $windows;
}

/**
 * Retrieve and prioritize real active tasks using existing application intelligence.
 *
 * Prioritization:
 * 1. Overdue status (Default N)
 * 2. Proximity to deadline (due_at ASC)
 * 3. Smart Priority Score / Urgency
 * 4. Remaining effort ratio
 * 5. Deterministic tie-breaker: task ID ASC (Default P)
 *
 * @param PDO   $db
 * @param int   $userId
 * @param array $options
 * @return array
 */
function prioritizeStudyTasks(PDO $db, int $userId, array $options = []): array
{
    $stmt = $db->prepare(
        "SELECT t.*, c.code AS course_code, c.name AS course_name, c.color AS course_color,
                c.credits AS course_credits, c.grade_point AS course_grade_point
         FROM tasks t
         LEFT JOIN courses c ON c.id = t.course_id AND c.user_id = t.user_id
         WHERE t.user_id = ? AND t.status != 'completed'
         ORDER BY t.due_at ASC"
    );
    $stmt->execute([$userId]);
    $rawTasks = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($rawTasks)) {
        return [];
    }

    $focusedMap = getUsersTasksFocusedSeconds($db, $userId);
    $prioritized = [];

    foreach ($rawTasks as $t) {
        $taskId = (int) $t['id'];
        $focusedSeconds = (int) ($focusedMap[$taskId] ?? 0);
        $t['total_focused_seconds'] = $focusedSeconds;
        $t['user_id'] = $userId;

        // Reuse existing decoration and intelligence
        $dec = decorateTask($t);

        // Section 8 & Default O: Remaining effort = estimated effort minus actual recorded focus time & progress
        $estHours = (float) (!empty($t['duration_hours']) && (float)$t['duration_hours'] > 0
            ? $t['duration_hours']
            : estimateTaskWorkloadHours($t));

        $focusedHours = $focusedSeconds / 3600.0;
        $progressPct = (int) ($t['progress_percent'] ?? 0);

        // Effective remaining hours: bounded by focus time and entered manual progress
        $effByFocus = max(0.0, $estHours - $focusedHours);
        $effByProg  = max(0.0, $estHours * (1.0 - ($progressPct / 100.0)));
        $remainingHours = max(0.0, min($effByFocus, $effByProg));

        // If completed or negligible remaining work (< 15 mins), do not schedule
        if ($progressPct >= 100 || $remainingHours < 0.25 || ($dec['system_status'] ?? '') === 'completed') {
            continue;
        }

        $dec['remaining_effort_hours'] = max(0.5, round($remainingHours, 2));
        $dec['is_overdue'] = (($dec['urgency'] ?? '') === 'overdue' || ($dec['hours_remaining'] ?? 0) < 0) ? 1 : 0;
        $dec['due_timestamp'] = !empty($t['due_at']) ? strtotime($t['due_at']) : (PHP_INT_MAX - 1000);

        $prioritized[] = $dec;
    }

    // Deterministic Multi-Tier Sort (Section 7, Section 14A Defaults N, P, Q, R)
    usort($prioritized, function ($a, $b) {
        // 1. Overdue tasks first
        if ($b['is_overdue'] !== $a['is_overdue']) {
            return $b['is_overdue'] <=> $a['is_overdue'];
        }

        // 2. Deadline proximity (earlier deadline first)
        if ($a['due_timestamp'] !== $b['due_timestamp']) {
            return $a['due_timestamp'] <=> $b['due_timestamp'];
        }

        // 3. Smart Priority Score (higher score first)
        $scoreA = (float) ($a['smart_priority_score'] ?? 0);
        $scoreB = (float) ($b['smart_priority_score'] ?? 0);
        if ($scoreB !== $scoreA) {
            return $scoreB <=> $scoreA;
        }

        // 4. Workload pressure ratio
        $workA = (float) ($a['workload_pressure'] ?? 0);
        $workB = (float) ($b['workload_pressure'] ?? 0);
        if ($workB !== $workA) {
            return $workB <=> $workA;
        }

        // 5. Stable deterministic tie-breaker: Task ID ASC
        return ((int) $a['id']) <=> ((int) $b['id']);
    });

    return $prioritized;
}

/**
 * Generate practical, conflict-free automated study sessions.
 *
 * Answers: "Given everything this student currently has to do, when should they study?"
 *
 * @param PDO   $db
 * @param int   $userId
 * @param array $options
 * @return array
 */
function generateAutomatedSchedule(PDO $db, int $userId, array $options = []): array
{
    $startDate = $options['start_date'] ?? date('Y-m-d');
    $days = (int) ($options['days'] ?? SCHEDULER_DEFAULT_HORIZON_DAYS);

    // 1. Fetch prioritized active tasks
    $tasks = prioritizeStudyTasks($db, $userId, $options);
    if (empty($tasks)) {
        return [
            'ok'             => true,
            'status'         => 'no_tasks',
            'message'        => 'No active tasks to schedule. You are all caught up on academic work!',
            'sessions'       => [],
            'total_sessions' => 0,
            'total_hours'    => 0.0,
            'summary'        => 'No pending tasks to schedule.',
        ];
    }

    // 2. Fetch available study windows
    $windows = calculateAvailableWindows($db, $userId, $startDate, $days, $options);
    if (empty($windows)) {
        return [
            'ok'             => true,
            'status'         => 'no_availability',
            'message'        => 'No available study windows found in your timetable over the next 7 days.',
            'sessions'       => [],
            'total_sessions' => 0,
            'total_hours'    => 0.0,
            'summary'        => 'No available timetable windows found.',
        ];
    }

    // 3. Weekly Goal & Capacity Target (Section 9 & Section 14A Defaults H & I)
    $profile = getUserProfileRow($userId);
    $weeklyGoal = (float) ($profile['weekly_goal_hours'] ?? 15.0);

    $now = new DateTime();
    $weekStart = (clone $now)->modify('monday this week')->setTime(0, 0, 0)->format('Y-m-d H:i:s');
    $weekEnd   = (clone $now)->modify('sunday this week')->setTime(23, 59, 59)->format('Y-m-d H:i:s');
    $loggedSecs = getUserPeriodFocusedSeconds($db, $userId, $weekStart, $weekEnd);
    $loggedHours = $loggedSecs / 3600.0;
    $remainingGoalHours = max(0.0, $weeklyGoal - $loggedHours);

    // Track mutable remaining hours per task
    $taskState = [];
    $totalBacklogHours = 0.0;
    foreach ($tasks as $t) {
        $rem = (float) $t['remaining_effort_hours'];
        $taskState[(int) $t['id']] = [
            'task'            => $t,
            'remaining_hours' => $rem,
            'sessions_count'  => 0,
        ];
        $totalBacklogHours += $rem;
    }

    // Order windows: Preferred study days first, then non-preferred days
    $preferredWindows = [];
    $otherWindows     = [];
    foreach ($windows as $w) {
        if (!empty($w['is_preferred_day'])) {
            $preferredWindows[] = $w;
        } else {
            $otherWindows[] = $w;
        }
    }

    // Allocate across preferred windows; fallback to other windows if needed
    $orderedWindows = array_merge($preferredWindows, $otherWindows);
    $generatedSessions = [];
    $totalAllocatedHours = 0.0;

    // Track daily task allocations to ensure cognitive variety (max 2 sessions per task per day)
    $dailyTaskAllocations = [];

    foreach ($orderedWindows as $win) {
        $winDate = $win['date'];
        $winDow  = (int) $win['day_of_week'];
        $cursorSec = strtotime($winDate . ' ' . $win['start_time']);
        $endSec    = strtotime($winDate . ' ' . $win['end_time']);

        if (!isset($dailyTaskAllocations[$winDate])) {
            $dailyTaskAllocations[$winDate] = [];
        }

        while (($endSec - $cursorSec) >= (SCHEDULER_MIN_SESSION_MINS * 60)) {
            $currentSlotTime = date('Y-m-d H:i:s', $cursorSec);

            // Find best eligible task
            $chosenTaskId = null;
            foreach ($taskState as $tId => $state) {
                if ($state['remaining_hours'] < 0.25) {
                    continue; // Task work already satisfied
                }

                $taskDue = (string) $state['task']['due_at'];
                // Deadline protection: cannot study for a task after its deadline (Default M)
                if (strtotime($taskDue) <= $cursorSec && $state['task']['is_overdue'] === 0) {
                    continue;
                }

                // Balance check: avoid putting 3+ sessions of the same task on the same day if others exist
                $todayCountForTask = $dailyTaskAllocations[$winDate][$tId] ?? 0;
                if ($todayCountForTask >= 2 && count($taskState) > 1) {
                    // Check if other tasks still have remaining work
                    $otherHasWork = false;
                    foreach ($taskState as $oId => $oState) {
                        if ($oId !== $tId && $oState['remaining_hours'] >= 0.5) {
                            $otherHasWork = true;
                            break;
                        }
                    }
                    if ($otherHasWork) {
                        continue;
                    }
                }

                $chosenTaskId = $tId;
                break;
            }

            if ($chosenTaskId === null) {
                // No more eligible tasks for this window
                break;
            }

            $task = $taskState[$chosenTaskId]['task'];
            $remHours = $taskState[$chosenTaskId]['remaining_hours'];

            // Determine chunk size (Default E: target 60m, min 30m, max 90m)
            $availableSlotMins = (int) floor(($endSec - $cursorSec) / 60);

            if ($availableSlotMins >= 90 && $remHours >= 1.5) {
                $sessionMins = 90;
            } elseif ($availableSlotMins >= 60 && $remHours >= 1.0) {
                $sessionMins = 60;
            } elseif ($availableSlotMins >= 60 && $remHours < 1.0) {
                $sessionMins = max(30, (int) round($remHours * 60));
            } else {
                $sessionMins = min($availableSlotMins, max(30, (int) round($remHours * 60)));
            }

            // Cap at remaining effort
            $taskMinsLeft = (int) ceil($remHours * 60);
            if ($sessionMins > $taskMinsLeft) {
                $sessionMins = max(SCHEDULER_MIN_SESSION_MINS, $taskMinsLeft);
            }

            // Verify window fit
            if ($sessionMins > $availableSlotMins) {
                break;
            }

            $sessionStart = date('H:i:s', $cursorSec);
            $sessionEnd   = date('H:i:s', $cursorSec + ($sessionMins * 60));
            $sessionDurHours = round($sessionMins / 60.0, 2);

            // Contextual Human-Readable Rationale (Section 21)
            $isOverdue = $task['is_overdue'] === 1;
            $dueLabel = $task['due_label'] ?? (!empty($task['due_at']) ? date('M j', strtotime($task['due_at'])) : 'Upcoming');
            if ($isOverdue) {
                $reason = "Immediate priority: Overdue task needing focused study time.";
            } elseif ($task['due_timestamp'] <= strtotime("+2 days")) {
                $reason = "High priority: Due soon ({$dueLabel}) — scheduled before deadline.";
            } elseif (!empty($task['smart_priority_label']) && $task['smart_priority_label'] === 'Critical Priority') {
                $reason = "Critical academic priority based on workload weight and upcoming submission.";
            } else {
                $reason = "Balanced study block for {$task['title']} ({$sessionMins}m allocation).";
            }

            $generatedSessions[] = [
                'task_id'          => (int) $task['id'],
                'course_id'        => !empty($task['course_id']) ? (int) $task['course_id'] : null,
                'course_code'      => (string) ($task['course_code'] ?? ''),
                'course_name'      => (string) ($task['course_name'] ?? ''),
                'course_color'     => (string) ($task['course_color'] ?? '#087b55'),
                'title'            => 'Study: ' . (string) $task['title'],
                'task_title'       => (string) $task['title'],
                'event_type'       => 'study',
                'event_date'       => $winDate,
                'day_of_week'      => $winDow,
                'start_time'       => $sessionStart,
                'end_time'         => $sessionEnd,
                'duration_minutes' => $sessionMins,
                'duration_hours'   => $sessionDurHours,
                'start_label'      => date('g:i A', strtotime($sessionStart)),
                'end_label'        => date('g:i A', strtotime($sessionEnd)),
                'date_label'       => date('D, M j', strtotime($winDate)),
                'reason'           => $reason,
                'source'           => 'scheduler',
            ];

            // Update state
            $taskState[$chosenTaskId]['remaining_hours'] = max(0.0, $remHours - $sessionDurHours);
            $taskState[$chosenTaskId]['sessions_count']++;
            $dailyTaskAllocations[$winDate][$chosenTaskId] = ($dailyTaskAllocations[$winDate][$chosenTaskId] ?? 0) + 1;
            $totalAllocatedHours += $sessionDurHours;

            // Advance cursor
            $cursorSec += ($sessionMins * 60);

            // Default G: 10-15 minute planning break between separate tasks
            if (($endSec - $cursorSec) >= ((SCHEDULER_MIN_SESSION_MINS + SCHEDULER_INTER_TASK_BREAK_MINS) * 60)) {
                $cursorSec += (SCHEDULER_INTER_TASK_BREAK_MINS * 60);
            }
        }
    }

    $unscheduledHours = 0.0;
    foreach ($taskState as $s) {
        $unscheduledHours += $s['remaining_hours'];
    }

    $count = count($generatedSessions);
    $summary = sprintf(
        '%d study session%s scheduled (%.1f hours total across %d days)',
        $count,
        $count === 1 ? '' : 's',
        $totalAllocatedHours,
        count(array_unique(array_column($generatedSessions, 'event_date')))
    );

    return [
        'ok'                         => true,
        'status'                     => $count > 0 ? 'ok' : 'insufficient_time',
        'total_sessions'             => $count,
        'total_hours'                => round($totalAllocatedHours, 1),
        'unscheduled_workload_hours' => round($unscheduledHours, 1),
        'weekly_goal_hours'          => $weeklyGoal,
        'sessions'                   => $generatedSessions,
        'summary'                    => $summary,
    ];
}

/**
 * Persist generated study sessions into schedule_events under database authority.
 *
 * Invariant safeguards:
 * 1. Manual student sessions (`source = 'manual'`) are permanently preserved.
 * 2. Only uncompleted scheduler sessions (`source = 'scheduler' AND is_completed = 0`) are reconciled.
 * 3. Every session is re-verified for conflict and ownership immediately prior to INSERT.
 *
 * @param PDO   $db
 * @param int   $userId
 * @param array $plan
 * @param bool  $reconcileExisting
 * @return array
 */
function persistGeneratedSchedule(
    PDO $db,
    int $userId,
    array $plan,
    bool $reconcileExisting = true
): array {
    $sessions = $plan['sessions'] ?? [];
    if (empty($sessions)) {
        return [
            'ok'              => true,
            'scheduled_count' => 0,
            'sessions'        => [],
            'message'         => 'No sessions to persist.',
        ];
    }

    $db->beginTransaction();

    try {
        // 1. Reconcile existing scheduler-generated sessions
        if ($reconcileExisting) {
            $stmtDel = $db->prepare(
                "DELETE FROM schedule_events
                 WHERE user_id = ?
                   AND source = 'scheduler'
                   AND is_completed = 0
                   AND (event_date >= CURDATE() OR event_date IS NULL)"
            );
            $stmtDel->execute([$userId]);
        }

        // 2. Query fixed commitments for database authority conflict verification
        $stmtFixed = $db->prepare(
            "SELECT id, day_of_week, event_date, start_time, end_time, title
             FROM schedule_events
             WHERE user_id = ?
               AND (event_type IN ('lecture', 'exam', 'other') OR source = 'manual')"
        );
        $stmtFixed->execute([$userId]);
        $existingFixed = $stmtFixed->fetchAll(PDO::FETCH_ASSOC);

        $insertStmt = $db->prepare(
            "INSERT INTO schedule_events
                (user_id, course_id, task_id, title, event_type, day_of_week, start_time, end_time,
                 is_completed, progress_percent, source, event_date)
             VALUES (?, ?, ?, ?, 'study', ?, ?, ?, 0, 0, 'scheduler', ?)"
        );

        $persisted = [];
        $insertedBatches = [];

        foreach ($sessions as $s) {
            $taskId   = !empty($s['task_id']) ? (int) $s['task_id'] : null;
            $courseId = !empty($s['course_id']) ? (int) $s['course_id'] : null;
            $date     = (string) ($s['event_date'] ?? date('Y-m-d'));
            $dow      = (int) ($s['day_of_week'] ?? date('w', strtotime($date)));
            $st       = (string) $s['start_time'];
            $et       = (string) $s['end_time'];
            $title    = (string) ($s['title'] ?? 'Study Session');

            // Verify ownership
            $ownedCourseId = ownedCourseIdOrNull($db, $courseId, $userId);
            $ownedTaskId   = ownedTaskIdOrNull($db, $taskId, $userId);

            // Database Authority Conflict Check against fixed commitments
            $conflict = false;
            foreach ($existingFixed as $fx) {
                $matchesDay = false;
                if (!empty($fx['event_date'])) {
                    $matchesDay = ($fx['event_date'] === $date);
                } else {
                    $matchesDay = ((int) $fx['day_of_week'] === $dow);
                }

                if ($matchesDay) {
                    if ($st < $fx['end_time'] && $et > $fx['start_time']) {
                        $conflict = true;
                        break;
                    }
                }
            }

            if ($conflict) {
                continue; // Skip conflicting session
            }

            // Conflict check against other sessions in this current batch
            foreach ($insertedBatches as $batch) {
                if ($batch['date'] === $date) {
                    if ($st < $batch['end'] && $et > $batch['start']) {
                        $conflict = true;
                        break;
                    }
                }
            }

            if ($conflict) {
                continue;
            }

            $insertStmt->execute([
                $userId,
                $ownedCourseId,
                $ownedTaskId,
                $title,
                $dow,
                $st,
                $et,
                $date,
            ]);

            $newId = (int) $db->lastInsertId();
            $s['id'] = $newId;
            $persisted[] = $s;
            $insertedBatches[] = [
                'date'  => $date,
                'start' => $st,
                'end'   => $et,
            ];
        }

        $db->commit();

        $count = count($persisted);
        logActivity($userId, "Automated study plan applied with {$count} session(s).", 'success');

        return [
            'ok'              => true,
            'scheduled_count' => $count,
            'sessions'        => $persisted,
            'message'         => "Successfully scheduled {$count} study session(s).",
        ];

    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $e;
    }
}
