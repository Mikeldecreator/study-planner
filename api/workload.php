<?php
require_once __DIR__ . '/../includes/auth.php';
header('Content-Type: application/json');
requireLogin();

$userId = currentUserId();
$db = getDb();

$monday = (new DateTime())->modify('monday this week')->setTime(0, 0);
$sunday = (clone $monday)->modify('+6 days')->setTime(23, 59, 59);

// ---- Weekly workload by category (this week's incomplete tasks) ----
$stmt = $db->prepare(
    "SELECT type, SUM(duration_hours) AS hrs FROM tasks
     WHERE user_id = ? AND status != 'completed' AND due_at BETWEEN ? AND ?
     GROUP BY type"
);
$stmt->execute([$userId, $monday->format('Y-m-d H:i:s'), $sunday->format('Y-m-d H:i:s')]);

$buckets = ['study' => 0, 'assignments' => 0, 'projects' => 0, 'tests' => 0];
$typeToBucket = [
    'study_session' => 'study', 'research' => 'study', 'lab_report' => 'study', 'other' => 'study',
    'assignment' => 'assignments',
    'project' => 'projects',
    'test' => 'tests', 'exam' => 'tests',
];
foreach ($stmt->fetchAll() as $row) {
    $bucket = $typeToBucket[$row['type']] ?? 'study';
    $buckets[$bucket] += (float) $row['hrs'];
}
foreach ($buckets as &$v) { $v = round($v, 1); }
unset($v);
$total = array_sum($buckets);

// ---- Per-weekday status: task hours due that day + recurring session hours ----
$stmt = $db->prepare(
    "SELECT due_at, duration_hours FROM tasks
     WHERE user_id = ? AND status != 'completed' AND due_at BETWEEN ? AND ?"
);
$stmt->execute([$userId, $monday->format('Y-m-d H:i:s'), $sunday->format('Y-m-d H:i:s')]);
$taskRows = $stmt->fetchAll();

$stmt = $db->prepare(
    'SELECT day_of_week, TIME_TO_SEC(TIMEDIFF(end_time, start_time)) / 3600 AS hrs FROM schedule_events WHERE user_id = ?'
);
$stmt->execute([$userId]);
$sessionRows = $stmt->fetchAll();

$hoursByDow = array_fill(0, 7, 0.0); // 0=Sun ... 6=Sat, matches schedule_events convention
foreach ($taskRows as $t) {
    $dow = (int) (new DateTime($t['due_at']))->format('w');
    $hoursByDow[$dow] += (float) $t['duration_hours'];
}
foreach ($sessionRows as $s) {
    $hoursByDow[(int) $s['day_of_week']] += (float) $s['hrs'];
}

$dayNames = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
$order = [1, 2, 3, 4, 5, 6, 0]; // Monday..Sunday
$statusFor = fn($h) => $h >= 4 ? 'Heavy' : ($h >= 2 ? 'Moderate' : 'Light');

$weekdayStatus = [];
foreach ($order as $dow) {
    $hrs = round($hoursByDow[$dow], 1);
    $weekdayStatus[] = ['day' => $dayNames[$dow], 'hours' => $hrs, 'status' => $statusFor($hrs)];
}

echo json_encode([
    'total_hours' => round($total, 1),
    'buckets' => $buckets,
    'weekday_status' => $weekdayStatus,
]);
