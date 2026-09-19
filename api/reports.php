<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
header('Content-Type: application/json');
requireLogin();

$userId = currentUserId();
session_write_close();
$db = null;
try {
    $db = getDb();
} catch (Throwable $e) {
    $db = null;
}

$range = $_GET['range'] ?? 'week'; // week | month | semester

$now = new DateTime();
if ($range === 'week') {
    $start = (clone $now)->modify('monday this week')->setTime(0, 0);
    $end   = (clone $start)->modify('+6 days')->setTime(23, 59, 59);
    $rangeLabel = $start->format('F j') . ' - ' . $end->format('F j, Y');
} elseif ($range === 'month') {
    $start = (clone $now)->modify('first day of this month')->setTime(0, 0);
    $end   = (clone $now)->modify('last day of this month')->setTime(23, 59, 59);
    $rangeLabel = $start->format('F Y');
} else {
    $semStmt = $db ? $db->prepare(
        "SELECT name, start_date, end_date FROM semesters 
         WHERE user_id = ? AND (is_current = 1 OR (start_date <= CURDATE() AND end_date >= CURDATE())) 
         ORDER BY is_current DESC, id DESC LIMIT 1"
    ) : null;
    $activeSem = null;
    if ($semStmt) {
        $semStmt->execute([$userId]);
        $activeSem = $semStmt->fetch();
    }
    if ($activeSem && !empty($activeSem['start_date']) && !empty($activeSem['end_date'])) {
        $start = new DateTime($activeSem['start_date'] . ' 00:00:00');
        $end   = new DateTime($activeSem['end_date'] . ' 23:59:59');
        $rangeLabel = ($activeSem['name'] ?? 'Current Semester') . ' (' . $start->format('M j') . ' - ' . $end->format('M j, Y') . ')';
    } else {
        $start = new DateTime('2000-01-01 00:00:00');
        $end   = new DateTime('2100-01-01 23:59:59');
        $rangeLabel = 'All time on record';
    }
}
$startStr = $start->format('Y-m-d H:i:s');
$endStr   = $end->format('Y-m-d H:i:s');

if ($db === null) {
    $tasksCompleted = 0;
    $tasksCreated = 0;
    $completionRate = 0.0;
    $overdueTasks = 0;
    $studyHours = 0.0;
    $weeklyActivity = [];
    foreach (['Mon','Tue','Wed','Thu','Fri','Sat','Sun'] as $lbl) {
        $weeklyActivity[] = ['label' => $lbl, 'hours' => 0.0];
    }
    $insights = [];
    $suggestedAction = "You're clear for now — no course has a heavy backlog.";
    $historicalComparison = [
        'has_previous_data' => false,
        'completion_trend'  => 'Not enough historical data to determine a reliable trend.',
        'workload_trend'    => 'Not enough historical data to compare workload between periods.',
        'rate_delta'        => 0.0,
    ];
    $courseComparison = [];
    $planningContext = [
        'weekly_goal_hours'     => 15.0,
        'logged_study_hours'    => 0.0,
        'goal_progress_percent' => 0.0,
    ];
    $studyGoal = [
        'weekly_goal_hours'     => 15.0,
        'logged_study_hours'    => 0.0,
        'goal_progress_percent' => 0.0,
    ];
    $taskStatusDistribution = [
        'completed'   => 0,
        'in_progress' => 0,
        'pending'     => 0,
        'overdue'     => 0,
        'not_started' => 0,
    ];
    $performanceTrend = [
        'has_history'      => false,
        'labels'           => ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'],
        'completion_rates' => [0, 0, 0, 0, 0, 0, 0],
        'study_hours'      => [0, 0, 0, 0, 0, 0, 0],
        'tasks_completed'  => [0, 0, 0, 0, 0, 0, 0],
    ];
} else {
    // ---- Core period stats ----
    $stmt = $db->prepare(
        "SELECT COUNT(*) AS n FROM tasks WHERE user_id = ? AND status = 'completed' AND completed_at BETWEEN ? AND ?"
    );
    $stmt->execute([$userId, $startStr, $endStr]);
    $tasksCompleted = (int) $stmt->fetch()['n'];

    $stmt = $db->prepare(
        "SELECT COUNT(*) AS n FROM tasks WHERE user_id = ? AND created_at BETWEEN ? AND ?"
    );
    $stmt->execute([$userId, $startStr, $endStr]);
    $tasksCreated = (int) $stmt->fetch()['n'];

    // Decimal precision
    $completionRate = $tasksCreated > 0 ? round(($tasksCompleted / $tasksCreated) * 100, 2) : 0.0;

    $stmt = $db->prepare(
        "SELECT COUNT(*) AS n FROM tasks WHERE user_id = ? AND status != 'completed' AND due_at < NOW() AND due_at BETWEEN ? AND ?"
    );
    $stmt->execute([$userId, $startStr, $endStr]);
    $overdueTasks = (int) $stmt->fetch()['n'];

    // Study hours: strictly actual focused study time from task_work_sessions (do NOT add scheduled classes!)
    $focusedSeconds = function_exists('getUserPeriodFocusedSeconds')
        ? getUserPeriodFocusedSeconds($db, $userId, $startStr, $endStr)
        : 0;
    $studyHours = round($focusedSeconds / 3600, 2);

    // ---- Weekly activity bars (Mon–Sun): actual focused work sessions for each weekday ----
    $stmt = $db->prepare(
        "SELECT DAYOFWEEK(started_at) AS dow, SUM(
            CASE 
                WHEN status = 'running' THEN duration_seconds + GREATEST(0, TIMESTAMPDIFF(SECOND, started_at, NOW()))
                ELSE duration_seconds 
            END
         ) / 3600 AS hrs
         FROM task_work_sessions
         WHERE user_id = ? AND started_at BETWEEN ? AND ?
         GROUP BY DAYOFWEEK(started_at)"
    );
    $stmt->execute([$userId, $startStr, $endStr]);
    $focusedHoursByDow = [];
    foreach ($stmt->fetchAll() as $row) {
        $focusedHoursByDow[(int) $row['dow']] = (float) $row['hrs'];
    }

    $dayLabels = ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];
    $mysqlDowForLabel = [2, 3, 4, 5, 6, 7, 1]; // Mon=2 ... Sat=7, Sun=1 in MySQL DAYOFWEEK
    $weeklyActivity = [];
    foreach ($dayLabels as $i => $label) {
        $dow = $mysqlDowForLabel[$i];
        $hrs = $focusedHoursByDow[$dow] ?? 0.0;
        $weeklyActivity[] = ['label' => $label, 'hours' => round($hrs, 2)];
    }

    // ---- Task status distribution for Chart.js doughnut ----
    $stmtStatus = $db->prepare(
        "SELECT 
            COUNT(CASE WHEN status = 'completed' THEN 1 END) AS completed,
            COUNT(CASE WHEN status = 'in_progress' THEN 1 END) AS in_progress,
            COUNT(CASE WHEN status = 'pending' AND (due_at IS NULL OR due_at >= NOW()) THEN 1 END) AS pending,
            COUNT(CASE WHEN status != 'completed' AND due_at < NOW() THEN 1 END) AS overdue,
            COUNT(CASE WHEN status = 'not_started' THEN 1 END) AS not_started
         FROM tasks 
         WHERE user_id = ?"
    );
    $stmtStatus->execute([$userId]);
    $taskStatusDistribution = $stmtStatus->fetch(PDO::FETCH_ASSOC) ?: [
        'completed'   => 0,
        'in_progress' => 0,
        'pending'     => 0,
        'overdue'     => 0,
        'not_started' => 0,
    ];
    foreach ($taskStatusDistribution as $k => $v) {
        $taskStatusDistribution[$k] = (int) $v;
    }

    // ---- Real Academic Performance Trend data (honest historical timeline) ----
    $trendLabels = [];
    $trendCompletionRates = [];
    $trendStudyHours = [];
    $trendTasksCompleted = [];

    if ($range === 'week') {
        for ($d = 0; $d < 7; $d++) {
            $dayDate = (clone $start)->modify("+$d days");
            $dayStr = $dayDate->format('Y-m-d');
            $trendLabels[] = $dayDate->format('D');

            $st = $db->prepare("SELECT COUNT(*) FROM tasks WHERE user_id = ? AND status = 'completed' AND DATE(completed_at) = ?");
            $st->execute([$userId, $dayStr]);
            $dayCompleted = (int) $st->fetchColumn();
            $trendTasksCompleted[] = $dayCompleted;

            $st2 = $db->prepare("SELECT COUNT(*) FROM tasks WHERE user_id = ? AND DATE(created_at) <= ?");
            $st2->execute([$userId, $dayStr]);
            $dayTotal = (int) $st2->fetchColumn();
            $trendCompletionRates[] = $dayTotal > 0 ? round(($dayCompleted / $dayTotal) * 100, 2) : 0.0;

            $st3 = $db->prepare("SELECT COALESCE(SUM(duration_seconds), 0) / 3600 FROM task_work_sessions WHERE user_id = ? AND DATE(started_at) = ?");
            $st3->execute([$userId, $dayStr]);
            $trendStudyHours[] = round((float) $st3->fetchColumn(), 2);
        }
    } elseif ($range === 'month') {
        for ($w = 0; $w < 4; $w++) {
            $wStart = (clone $start)->modify("+" . ($w * 7) . " days");
            $wEnd = (clone $wStart)->modify("+6 days");
            if ($w === 3) $wEnd = clone $end;
            $trendLabels[] = "W" . ($w + 1);

            $st = $db->prepare("SELECT COUNT(*) FROM tasks WHERE user_id = ? AND status = 'completed' AND completed_at BETWEEN ? AND ?");
            $st->execute([$userId, $wStart->format('Y-m-d 00:00:00'), $wEnd->format('Y-m-d 23:59:59')]);
            $wCompleted = (int) $st->fetchColumn();
            $trendTasksCompleted[] = $wCompleted;

            $st2 = $db->prepare("SELECT COUNT(*) FROM tasks WHERE user_id = ? AND created_at <= ?");
            $st2->execute([$userId, $wEnd->format('Y-m-d 23:59:59')]);
            $wTotal = (int) $st2->fetchColumn();
            $trendCompletionRates[] = $wTotal > 0 ? round(($wCompleted / $wTotal) * 100, 2) : 0.0;

            $st3 = $db->prepare("SELECT COALESCE(SUM(duration_seconds), 0) / 3600 FROM task_work_sessions WHERE user_id = ? AND started_at BETWEEN ? AND ?");
            $st3->execute([$userId, $wStart->format('Y-m-d 00:00:00'), $wEnd->format('Y-m-d 23:59:59')]);
            $trendStudyHours[] = round((float) $st3->fetchColumn(), 2);
        }
    } else {
        for ($m = 5; $m >= 0; $m--) {
            $mDate = (clone $now)->modify("-$m months");
            $mStart = (clone $mDate)->modify('first day of this month')->setTime(0, 0);
            $mEnd = (clone $mDate)->modify('last day of this month')->setTime(23, 59, 59);
            $trendLabels[] = $mDate->format('M');

            $st = $db->prepare("SELECT COUNT(*) FROM tasks WHERE user_id = ? AND status = 'completed' AND completed_at BETWEEN ? AND ?");
            $st->execute([$userId, $mStart->format('Y-m-d H:i:s'), $mEnd->format('Y-m-d H:i:s')]);
            $mCompleted = (int) $st->fetchColumn();
            $trendTasksCompleted[] = $mCompleted;

            $st2 = $db->prepare("SELECT COUNT(*) FROM tasks WHERE user_id = ? AND created_at <= ?");
            $st2->execute([$userId, $mEnd->format('Y-m-d H:i:s')]);
            $mTotal = (int) $st2->fetchColumn();
            $trendCompletionRates[] = $mTotal > 0 ? round(($mCompleted / $mTotal) * 100, 2) : 0.0;

            $st3 = $db->prepare("SELECT COALESCE(SUM(duration_seconds), 0) / 3600 FROM task_work_sessions WHERE user_id = ? AND started_at BETWEEN ? AND ?");
            $st3->execute([$userId, $mStart->format('Y-m-d H:i:s'), $mEnd->format('Y-m-d H:i:s')]);
            $trendStudyHours[] = round((float) $st3->fetchColumn(), 2);
        }
    }

    $hasHistory = (array_sum($trendTasksCompleted) > 0 || array_sum($trendStudyHours) > 0 || $tasksCompleted > 0 || $studyHours > 0);

    $performanceTrend = [
        'has_history'      => $hasHistory,
        'labels'           => $trendLabels,
        'completion_rates' => $trendCompletionRates,
        'study_hours'      => $trendStudyHours,
        'tasks_completed'  => $trendTasksCompleted,
    ];

    // ---- Rule-based performance summary ----
    $insights = [];
    if ($tasksCreated > 0) {
        if ($completionRate >= 70) {
            $insights[] = ['icon' => 'success', 'text' => 'Strong task completion this period'];
        } elseif ($completionRate >= 40) {
            $insights[] = ['icon' => 'info', 'text' => 'Task completion is on track, but there is room to catch up'];
        } else {
            $insights[] = ['icon' => 'warning', 'text' => 'Task completion is behind — consider reprioritizing'];
        }
    }

    $stmt = $db->prepare(
        "SELECT COUNT(*) AS n FROM tasks WHERE user_id = ? AND status != 'completed'
         AND due_at BETWEEN NOW() AND DATE_ADD(NOW(), INTERVAL " . DUE_SOON_WINDOW_DAYS . " DAY)"
    );
    $stmt->execute([$userId]);
    $dueSoonCount = (int) $stmt->fetch()['n'];
    if ($dueSoonCount > 0) {
        $insights[] = ['icon' => 'warning', 'text' => "You have {$dueSoonCount} deadline" . ($dueSoonCount === 1 ? '' : 's') . ' approaching'];
    }

    $stmt = $db->prepare(
        "SELECT c.code, c.name, COUNT(t.id) AS pending_count
         FROM courses c JOIN tasks t ON t.course_id = c.id AND t.user_id = c.user_id AND t.status != 'completed'
         WHERE c.user_id = ? GROUP BY c.id ORDER BY pending_count DESC LIMIT 1"
    );
    $stmt->execute([$userId]);
    $heaviestCourse = $stmt->fetch();
    if ($heaviestCourse && $heaviestCourse['pending_count'] > 0) {
        $insights[] = ['icon' => 'warning', 'text' => "{$heaviestCourse['code']} has the highest pending workload"];
    }

    $suggestedAction = $heaviestCourse
        ? "Schedule an extra study session for {$heaviestCourse['code']} to work through its pending tasks."
        : "You're clear for now — no course has a heavy backlog.";

    // ---- Historical comparison & course comparison ----
    $historicalComparison = function_exists('computeReportComparisons')
        ? computeReportComparisons($db, $userId, $range, $start, $end, $tasksCreated, $tasksCompleted, $overdueTasks)
        : [
            'has_previous_data' => false,
            'completion_trend'  => 'Not enough historical data to determine a reliable trend.',
            'workload_trend'    => 'Not enough historical data to compare workload between periods.',
            'rate_delta'        => 0.0,
        ];

    $courseFocusStmt = $db->prepare(
        "SELECT t.course_id, SUM(ws.duration_seconds) AS total_sec
         FROM task_work_sessions ws
         INNER JOIN tasks t ON t.id = ws.task_id AND t.user_id = ws.user_id
         WHERE ws.user_id = ? AND ws.started_at BETWEEN ? AND ? AND t.course_id IS NOT NULL
         GROUP BY t.course_id"
    );
    $courseFocusStmt->execute([$userId, $startStr, $endStr]);
    $courseFocusMap = $courseFocusStmt->fetchAll(PDO::FETCH_KEY_PAIR);

    $stmt = $db->prepare(
        "SELECT c.id, c.code, c.name, c.color, c.credits,
                COUNT(t.id) AS total_tasks,
                COUNT(CASE WHEN t.status = 'completed' THEN 1 END) AS completed_tasks,
                COUNT(CASE WHEN t.status != 'completed' AND t.due_at < NOW() THEN 1 END) AS overdue_tasks
         FROM courses c
         LEFT JOIN tasks t ON t.course_id = c.id AND t.user_id = c.user_id
         WHERE c.user_id = ?
         GROUP BY c.id
         ORDER BY c.code ASC"
    );
    $stmt->execute([$userId]);
    $courseComparison = [];
    foreach ($stmt->fetchAll() as $crow) {
        $cId = (int) $crow['id'];
        $cFocusSec = (int) ($courseFocusMap[$cId] ?? 0);
        $cFocusHrs = round($cFocusSec / 3600, 2);
        $cTotal = (int) $crow['total_tasks'];
        $cComp  = (int) $crow['completed_tasks'];
        // Decimal precision
        $cPct   = $cTotal > 0 ? round(($cComp / $cTotal) * 100, 2) : 0.0;
        $courseComparison[] = [
            'id'              => $cId,
            'code'            => $crow['code'],
            'name'            => $crow['name'],
            'color'           => $crow['color'] ?? '#10b981',
            'credits'         => (int) $crow['credits'],
            'total_tasks'     => $cTotal,
            'completed_tasks' => $cComp,
            'overdue_tasks'   => (int) $crow['overdue_tasks'],
            'completion_rate' => $cPct,
            'focus_seconds'   => $cFocusSec,
            'focus_hours'     => $cFocusHrs,
            'study_hours'     => $cFocusHrs,
        ];
    }

    // ---- Personal planning context & study goal ----
    $planningContext = function_exists('getPersonalPlanningContext')
        ? getPersonalPlanningContext($db, $userId)
        : [
            'weekly_goal_hours'     => 15.0,
            'logged_study_hours'    => $studyHours,
            'goal_progress_percent' => min(100.0, round(($studyHours / 15.0) * 100, 2)),
        ];

    $weeklyGoal = (float) ($planningContext['weekly_goal_hours'] ?? 15.0);
    $goalPct = $weeklyGoal > 0 ? round(($studyHours / $weeklyGoal) * 100, 2) : 0.0;
    $studyGoal = [
        'weekly_goal_hours'     => $weeklyGoal,
        'logged_study_hours'    => $studyHours,
        'goal_progress_percent' => $goalPct,
    ];
}

// ---- Optional CSV export ----
if (
    (isset($_GET['export']) && strtolower((string) $_GET['export']) === 'csv') ||
    (isset($_GET['download']) && strtolower((string) $_GET['download']) === 'csv')
) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="study-planner-report-' . $range . '-' . date('Ymd') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Report Period', $rangeLabel]);
    fputcsv($out, ['Generated At', date('Y-m-d H:i:s')]);
    fputcsv($out, []);
    fputcsv($out, ['Metric', 'Value']);
    fputcsv($out, ['Tasks Completed', $tasksCompleted]);
    fputcsv($out, ['Tasks Created', $tasksCreated]);
    fputcsv($out, ['Completion Rate (%)', $completionRate]);
    fputcsv($out, ['Overdue Tasks', $overdueTasks]);
    fputcsv($out, ['Total Study Hours', $studyHours]);
    fputcsv($out, ['Weekly Study Goal (hrs)', $studyGoal['weekly_goal_hours']]);
    fputcsv($out, ['Goal Progress (%)', $studyGoal['goal_progress_percent']]);
    fputcsv($out, []);
    fputcsv($out, ['Course Code', 'Course Name', 'Total Tasks', 'Completed Tasks', 'Overdue Tasks', 'Completion Rate (%)']);
    foreach ($courseComparison as $c) {
        fputcsv($out, [$c['code'], $c['name'], $c['total_tasks'], $c['completed_tasks'], $c['overdue_tasks'], $c['completion_rate']]);
    }
    fclose($out);
    exit;
}

echo json_encode([
    'range'                    => $range,
    'range_label'              => $rangeLabel,
    'tasks_completed'          => $tasksCompleted,
    'tasks_created'            => $tasksCreated,
    'completion_rate'          => $completionRate,
    'overdue_tasks'            => $overdueTasks,
    'study_hours'              => $studyHours,
    'weekly_activity'          => $weeklyActivity,
    'task_status_distribution' => $taskStatusDistribution,
    'performance_trend'        => $performanceTrend,
    'insights'                 => $insights,
    'suggested_action'         => $suggestedAction,
    'historical_comparison'    => $historicalComparison,
    'course_comparison'        => $courseComparison,
    'personal_planning'        => $planningContext,
    'study_goal'               => $studyGoal,
], JSON_UNESCAPED_UNICODE);
