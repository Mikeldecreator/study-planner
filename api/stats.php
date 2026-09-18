<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
header('Content-Type: application/json');
requireLogin();

$userId = currentUserId();
session_write_close();
$db = getDb();

// ---- Top stat cards: total / due soon / overdue / completion % ----
$stmt = $db->prepare('SELECT due_at, status FROM tasks WHERE user_id = ?');
$stmt->execute([$userId]);
$tasks = $stmt->fetchAll();

$counts = ['completed' => 0, 'overdue' => 0, 'due_soon' => 0, 'upcoming' => 0];
foreach ($tasks as $t) {
    $bucket = classifyTaskUrgency($t['due_at'], $t['status']);
    $counts[$bucket]++;
}
$total = count($tasks);
$completionRate = $total > 0 ? round($counts['completed'] / $total * 100) : 0;

// ---- Task breakdown donut (by status, matches mockup's 5 slices) ----
// Buckets must be mutually exclusive so the slices sum to $total: an overdue
// task is only ever counted once, under "overdue" — never also under its raw
// status (pending/in_progress/not_started), even though that status column
// still literally says "pending" etc. in the tasks table.
$byStatus = ['not_started' => 0, 'pending' => 0, 'in_progress' => 0, 'completed' => 0, 'overdue' => 0];
foreach ($tasks as $t) {
    if ($t['status'] === 'completed') {
        $byStatus['completed']++;
        continue;
    }
    if (classifyTaskUrgency($t['due_at'], $t['status']) === 'overdue') {
        $byStatus['overdue']++;
    } else {
        $byStatus[$t['status']] = ($byStatus[$t['status']] ?? 0) + 1;
    }
}

// ---- Weekly progress line chart: % of that day's tasks completed on time ----
// Simplified for a defense build: % of tasks DUE each weekday that are completed.
$weekly = [];
$today = new DateTime();
$monday = (clone $today)->modify('monday this week');
for ($i = 0; $i < 7; $i++) {
    $day = (clone $monday)->modify("+{$i} day");
    $dayStart = $day->format('Y-m-d 00:00:00');
    $dayEnd   = $day->format('Y-m-d 23:59:59');

    $stmt = $db->prepare(
        'SELECT
            COUNT(*) AS due_count,
            SUM(status = "completed") AS done_count
         FROM tasks WHERE user_id = ? AND due_at BETWEEN ? AND ?'
    );
    $stmt->execute([$userId, $dayStart, $dayEnd]);
    $row = $stmt->fetch();
    $due  = (int) $row['due_count'];
    $done = (int) $row['done_count'];
    $weekly[] = $due > 0 ? round($done / $due * 100) : 0;
}

// ---- Workload overview: hours by task type, this week ----
$stmt = $db->prepare(
    "SELECT type, SUM(duration_hours) AS hrs FROM tasks
     WHERE user_id = ? AND due_at BETWEEN ? AND ?
     GROUP BY type"
);
$weekEnd = (clone $monday)->modify('+6 day')->format('Y-m-d 23:59:59');
$stmt->execute([$userId, $monday->format('Y-m-d 00:00:00'), $weekEnd]);
$workload = [];
$workloadTotal = 0;
foreach ($stmt->fetchAll() as $row) {
    $hrs = (float) $row['hrs'];
    $workload[$row['type']] = $hrs;
    $workloadTotal += $hrs;
}

echo json_encode([
    'total_tasks'      => $total,
    'due_soon'         => $counts['due_soon'],
    'overdue'          => $counts['overdue'],
    'completion_rate'  => $completionRate,
    'task_breakdown'   => $byStatus,
    'weekly_progress'  => ['labels' => ['Mon','Tue','Wed','Thu','Fri','Sat','Sun'], 'data' => $weekly],
    'workload'         => $workload,
    'workload_total'   => $workloadTotal,
]);
