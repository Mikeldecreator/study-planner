<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
header('Content-Type: application/json');
requireLogin();

$userId = currentUserId();
$userName = $_SESSION['user_name'] ?? 'Student';
session_write_close();
$db = getDb();

// Today's schedule
$todayDow = (int) date('w');
$stmt = $db->prepare(
    'SELECT se.*, c.code AS course_code FROM schedule_events se
     LEFT JOIN courses c ON c.id = se.course_id AND c.user_id = se.user_id
     WHERE se.user_id = ? AND se.day_of_week = ? ORDER BY se.start_time'
);
$stmt->execute([$userId, $todayDow]);
$todaySchedule = $stmt->fetchAll();
foreach ($todaySchedule as &$ev) {
    $ev['start_label'] = date('g:i A', strtotime($ev['start_time']));
}

// Upcoming deadlines — next 4 incomplete tasks
$stmt = $db->prepare(
    "SELECT t.*, c.code AS course_code FROM tasks t
     LEFT JOIN courses c ON c.id = t.course_id AND c.user_id = t.user_id
     WHERE t.user_id = ? AND t.status != 'completed'
     ORDER BY t.due_at ASC LIMIT 4"
);
$stmt->execute([$userId]);
$upcoming = $stmt->fetchAll();
foreach ($upcoming as &$t) {
    $t['urgency']       = classifyTaskUrgency($t['due_at'], $t['status']);
    $t['due_label']     = dueRelativeLabel($t['due_at']);
    $t['priority_label']= priorityLabel($t['priority']);
    $t['due_at_display']= date('g:i A · M j, Y', strtotime($t['due_at']));
}

// Recent activity
$stmt = $db->prepare('SELECT * FROM activity_log WHERE user_id = ? ORDER BY created_at DESC LIMIT 5');
$stmt->execute([$userId]);
$activity = $stmt->fetchAll();
foreach ($activity as &$a) {
    $a['time_ago'] = timeAgo($a['created_at']);
}

// Courses (for the Add Task dropdown)
$stmt = $db->prepare('SELECT id, code, name FROM courses WHERE user_id = ? ORDER BY code');
$stmt->execute([$userId]);
$courses = $stmt->fetchAll();

$hour = (int) date('H');
$greeting = $hour < 12 ? 'morning' : ($hour < 17 ? 'afternoon' : 'evening');

echo json_encode([
    'user_name'      => $userName,
    'greeting'       => $greeting,
    'today_schedule' => $todaySchedule,
    'upcoming'       => $upcoming,
    'activity'       => $activity,
    'courses'        => $courses,
]);
