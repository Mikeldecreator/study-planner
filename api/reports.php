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
    $rangeLabel = $start->format('F j') . ' - ' . $end->format('j');
} elseif ($range === 'month') {
    $start = (clone $now)->modify('first day of this month')->setTime(0, 0);
    $end   = (clone $now)->modify('last day of this month')->setTime(23, 59, 59);
    $rangeLabel = $start->format('F Y');
} else {
    $start = new DateTime('2000-01-01');
    $end   = new DateTime('2100-01-01');
    $rangeLabel = 'All time on record';
}
$startStr = $start->format('Y-m-d H:i:s');
$endStr   = $end->format('Y-m-d H:i:s');

if ($db === null) {
    $tasksCompleted = 0;
    $tasksCreated = 0;
    $completionRate = 0;
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
        'rate_delta'        => 0,
    ];
    $courseComparison = [];
    $planningContext = [
        'weekly_goal_hours'     => 15.0,
        'logged_study_hours'    => 0.0,
        'goal_progress_percent' => 0,
    ];
    $studyGoal = [
        'weekly_goal_hours'     => 15.0,
        'logged_study_hours'    => 0.0,
        'goal_progress_percent' => 0,
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

    $completionRate = $tasksCreated > 0 ? round($tasksCompleted / $tasksCreated * 100) : 0;

    $stmt = $db->prepare(
        "SELECT COUNT(*) AS n FROM tasks WHERE user_id = ? AND status != 'completed' AND due_at < NOW() AND due_at BETWEEN ? AND ?"
    );
    $stmt->execute([$userId, $startStr, $endStr]);
    $overdueTasks = (int) $stmt->fetch()['n'];

    // Study hours: combined from schedule_events template and task_work_sessions focused time
    $stmt = $db->prepare(
        'SELECT SUM(TIME_TO_SEC(TIMEDIFF(end_time, start_time))) / 3600 AS hrs
         FROM schedule_events WHERE user_id = ? AND is_completed = 1'
    );
    $stmt->execute([$userId]);
    $scheduledHours = (float) ($stmt->fetch()['hrs'] ?? 0);

    $focusedSeconds = function_exists('getUserPeriodFocusedSeconds')
        ? getUserPeriodFocusedSeconds($db, $userId, $startStr, $endStr)
    : 0;
$focusedHours = $focusedSeconds / 3600;

$studyHours = round($scheduledHours + $focusedHours, 1);

// ---- Weekly activity bars (Mon–Sun): tasks completed that weekday (within
// range) + completed recurring sessions + focused work sessions for that weekday ----
$stmt = $db->prepare(
    "SELECT DAYOFWEEK(completed_at) AS dow, SUM(duration_hours) AS hrs FROM tasks
     WHERE user_id = ? AND status = 'completed' AND completed_at BETWEEN ? AND ?
     GROUP BY DAYOFWEEK(completed_at)"
);
$stmt->execute([$userId, $startStr, $endStr]);
$taskHoursByDow = [];
foreach ($stmt->fetchAll() as $row) {
    $taskHoursByDow[(int) $row['dow']] = (float) $row['hrs'];
}

$stmt = $db->prepare(
    'SELECT day_of_week, TIME_TO_SEC(TIMEDIFF(end_time, start_time)) / 3600 AS hrs
     FROM schedule_events WHERE user_id = ? AND is_completed = 1'
);
$stmt->execute([$userId]);
$sessionHoursByDow = [];
foreach ($stmt->fetchAll() as $row) {
    $dow = (int) $row['day_of_week'] + 1; // schedule uses 0=Sun; MySQL DAYOFWEEK also 1=Sun, so +1 aligns 0-indexed to 1-indexed
    $sessionHoursByDow[$dow] = ($sessionHoursByDow[$dow] ?? 0) + (float) $row['hrs'];
}

$stmt = $db->prepare(
    "SELECT DAYOFWEEK(started_at) AS dow, SUM(duration_seconds) / 3600 AS hrs
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
    $hrs = ($taskHoursByDow[$dow] ?? 0) + ($sessionHoursByDow[$dow] ?? 0) + ($focusedHoursByDow[$dow] ?? 0);
    $weeklyActivity[] = ['label' => $label, 'hours' => round($hrs, 1)];
}

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
        'rate_delta'        => 0,
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
            SUM(t.status = 'completed') AS completed_tasks,
            SUM(t.status != 'completed' AND t.due_at < NOW()) AS overdue_tasks
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
    $cFocusHrs = round($cFocusSec / 3600, 1);
    $cTotal = (int) $crow['total_tasks'];
    $cComp  = (int) $crow['completed_tasks'];
    $cPct   = $cTotal > 0 ? round(($cComp / $cTotal) * 100) : 0;
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
        'goal_progress_percent' => min(100, (int) round(($studyHours / 15.0) * 100)),
    ];

    $studyGoal = [
        'weekly_goal_hours'     => (float) ($planningContext['weekly_goal_hours'] ?? 15.0),
        'logged_study_hours'    => (float) ($planningContext['logged_study_hours'] ?? $studyHours),
        'goal_progress_percent' => (int) ($planningContext['goal_progress_percent'] ?? 0),
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
    'range'                 => $range,
    'range_label'           => $rangeLabel,
    'tasks_completed'       => $tasksCompleted,
    'tasks_created'         => $tasksCreated,
    'completion_rate'       => $completionRate,
    'overdue_tasks'         => $overdueTasks,
    'study_hours'           => $studyHours,
    'weekly_activity'       => $weeklyActivity,
    'insights'              => $insights,
    'suggested_action'      => $suggestedAction,
    'historical_comparison' => $historicalComparison,
    'course_comparison'     => $courseComparison,
    'personal_planning'     => $planningContext,
    'study_goal'            => $studyGoal,
]);
