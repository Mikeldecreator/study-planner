<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
header('Content-Type: application/json');
requireLogin();

$userId = currentUserId();
$db = getDb();

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

// Study hours: schedule_events is a recurring weekly template (no per-date
// rows — see Schedule page), so "hours completed" reflects the template's
// completed sessions regardless of which range is selected. Documented in
// README rather than faked with a per-range multiplier.
$stmt = $db->prepare(
    'SELECT SUM(TIME_TO_SEC(TIMEDIFF(end_time, start_time))) / 3600 AS hrs
     FROM schedule_events WHERE user_id = ? AND is_completed = 1'
);
$stmt->execute([$userId]);
$studyHours = round((float) ($stmt->fetch()['hrs'] ?? 0), 1);

// ---- Weekly activity bars (Mon–Sun): tasks completed that weekday (within
// range) + completed recurring sessions for that weekday ----
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

$dayLabels = ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'];
$mysqlDowForLabel = [2, 3, 4, 5, 6, 7, 1]; // Mon=2 ... Sat=7, Sun=1 in MySQL DAYOFWEEK
$weeklyActivity = [];
foreach ($dayLabels as $i => $label) {
    $dow = $mysqlDowForLabel[$i];
    $hrs = ($taskHoursByDow[$dow] ?? 0) + ($sessionHoursByDow[$dow] ?? 0);
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

echo json_encode([
    'range' => $range,
    'range_label' => $rangeLabel,
    'tasks_completed' => $tasksCompleted,
    'tasks_created' => $tasksCreated,
    'completion_rate' => $completionRate,
    'overdue_tasks' => $overdueTasks,
    'study_hours' => $studyHours,
    'weekly_activity' => $weeklyActivity,
    'insights' => $insights,
    'suggested_action' => $suggestedAction,
]);
