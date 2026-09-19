<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');
requireLogin();

$userId = currentUserId();
session_write_close();
$db = getDb();

// 1. Active Focus Session
$activeSession = getUserActiveStudySession($db, $userId);

// 2. Fetch User Prefs (Weekly Study Goal)
$userStmt = $db->prepare('SELECT weekly_goal_hours, preferred_study_time, preferred_study_days FROM users WHERE id = ?');
$userStmt->execute([$userId]);
$userRow = $userStmt->fetch();
$weeklyGoalHours = (float) ($userRow['weekly_goal_hours'] ?? 15.0);

// Current week boundary (Monday to Sunday)
$now = new DateTime();
$weekStart = (clone $now)->modify('monday this week')->setTime(0, 0, 0)->format('Y-m-d H:i:s');
$weekEnd   = (clone $now)->modify('sunday this week')->setTime(23, 59, 59)->format('Y-m-d H:i:s');

// Real focused seconds
$weekFocusedSeconds = getUserPeriodFocusedSeconds($db, $userId, $weekStart, $weekEnd);
$weekFocusedHours   = round($weekFocusedSeconds / 3600, 2);
$todayFocusedSeconds = getUserTodayFocusedSeconds($db, $userId);
$todayFocusedHours   = round($todayFocusedSeconds / 3600, 2);
$goalProgressPercent = $weeklyGoalHours > 0 ? min(100.0, round(($weekFocusedHours / $weeklyGoalHours) * 100, 2)) : 0.0;

// 3. Active Tasks with Time Tracking & Intelligence
$tasksStmt = $db->prepare(
    "SELECT t.*, c.code AS course_code, c.name AS course_name, c.color AS course_color, c.credits AS course_credits, c.grade_point AS course_grade_point
     FROM tasks t
     LEFT JOIN courses c ON c.id = t.course_id AND c.user_id = t.user_id
     WHERE t.user_id = ? AND t.status != 'completed'
     ORDER BY t.due_at ASC"
);
$tasksStmt->execute([$userId]);
$rawTasks = $tasksStmt->fetchAll();

$tasksFocusedMap = getUsersTasksFocusedSeconds($db, $userId);
$activeTasks = [];
foreach ($rawTasks as $t) {
    $tId = (int) $t['id'];
    $t['total_focused_seconds'] = $tasksFocusedMap[$tId] ?? 0;
    $t['user_id'] = $userId;
    $decorated = decorateTask($t);
    $activeTasks[] = $decorated;
}

// 4. Determine "Today's Study Focus" (What should I study now?)
$todaysFocus = null;
if (!empty($activeTasks)) {
    $sorted = [...$activeTasks];
    usort($sorted, function ($a, $b) {
        $scoreA = (float) ($a['smart_priority_score'] ?? 0);
        $scoreB = (float) ($b['smart_priority_score'] ?? 0);
        if ($scoreB !== $scoreA) {
            return $scoreB <=> $scoreA;
        }
        $dueA = !empty($a['due_at']) ? strtotime($a['due_at']) : PHP_INT_MAX;
        $dueB = !empty($b['due_at']) ? strtotime($b['due_at']) : PHP_INT_MAX;
        return $dueA <=> $dueB;
    });
    $top = $sorted[0];
    $todaysFocus = [
        'task_id'            => (int) $top['id'],
        'title'              => $top['title'],
        'course_code'        => $top['course_code'] ?? null,
        'course_name'        => $top['course_name'] ?? null,
        'course_color'       => $top['course_color'] ?? '#059669',
        'priority'           => $top['priority'] ?? 'medium',
        'priority_score'     => (int) round($top['smart_priority_score'] ?? 50),
        'priority_label'     => $top['smart_priority_label'] ?? ucfirst($top['priority'] ?? 'medium'),
        'remaining_hours'    => (float) ($top['remaining_hours'] ?? $top['duration_hours'] ?? 1.0),
        'due_at'             => $top['due_at'],
        'deadline_display'   => $top['deadline_display'] ?? $top['due_label'] ?? 'Upcoming',
        'risk_label'         => $top['risk_label'] ?? 'Normal',
        'reason'             => $top['priority_reason'] ?? 'Top academic priority based on upcoming deadline and workload requirements.',
        'recommended_action' => $top['recommended_action'] ?? 'Start a focused study session now.',
        'time_progress'      => $top['time_progress'] ?? null,
    ];
}

// 5. Course Needing Attention
$coursesStmt = $db->prepare(
    "SELECT c.id, c.code, c.name, c.color, c.credits, c.grade_point,
            COUNT(t.id) AS task_count,
            COUNT(CASE WHEN t.status = 'completed' THEN 1 END) AS completed_count,
            COUNT(CASE WHEN t.status != 'completed' AND t.due_at < NOW() THEN 1 END) AS overdue_count
     FROM courses c
     LEFT JOIN tasks t ON t.course_id = c.id AND t.user_id = c.user_id
     WHERE c.user_id = ?
     GROUP BY c.id
     ORDER BY c.code ASC"
);
$coursesStmt->execute([$userId]);
$courses = $coursesStmt->fetchAll();

$courseNeedingAttention = null;
$maxPressure = -1;
foreach ($courses as $c) {
    $cId = (int) $c['id'];
    $risk = courseRiskScore($c['grade_point'] !== null ? (float) $c['grade_point'] : null, (int) $c['credits']);
    $workload = calculateCourseWorkload($db, $userId, $cId);
    $pending = max(0, (int) $c['task_count'] - (int) $c['completed_count']);
    $overdue = (int) $c['overdue_count'];
    $pressure = determineCoursePressure($risk, $workload, $overdue, $pending);
    $pressureScore = $pressure['score'] ?? 0;
    if ($pressureScore > $maxPressure) {
        $maxPressure = $pressureScore;
        $courseNeedingAttention = [
            'id'              => $cId,
            'code'            => $c['code'],
            'name'            => $c['name'],
            'color'           => $c['color'] ?? '#059669',
            'pending_tasks'   => $pending,
            'overdue_tasks'   => $overdue,
            'workload_hours'  => $workload,
            'pressure_level'  => $pressure['level'] ?? 'Normal',
            'context_message' => $overdue > 0 ? "{$overdue} overdue task(s) needing immediate attention" : ($pending > 0 ? "{$pending} pending task(s) • {$workload}h remaining" : "All current tasks completed"),
        ];
    }
}

// 6. Upcoming Study Sessions / Classes (Today & Tomorrow)
$todayDow = (int) date('w'); // 0 (Sun) - 6 (Sat)
$tomorrowDow = ($todayDow + 1) % 7;
$schedStmt = $db->prepare(
    "SELECT se.*, c.code AS course_code, c.name AS course_name, c.color AS course_color
     FROM schedule_events se
     LEFT JOIN courses c ON c.id = se.course_id AND c.user_id = se.user_id
     WHERE se.user_id = ? AND se.day_of_week IN (?, ?)
     ORDER BY se.day_of_week ASC, se.start_time ASC
     LIMIT 5"
);
$schedStmt->execute([$userId, $todayDow, $tomorrowDow]);
$upcomingSessions = $schedStmt->fetchAll();

// 7. Recently Studied Work (Last 5 completed/stopped task sessions)
$recentStmt = $db->prepare(
    "SELECT ws.*, t.title AS task_title, t.duration_hours AS task_duration_hours,
            c.code AS course_code, c.name AS course_name, c.color AS course_color
     FROM task_work_sessions ws
     INNER JOIN tasks t ON t.id = ws.task_id AND t.user_id = ws.user_id
     LEFT JOIN courses c ON c.id = t.course_id AND c.user_id = ws.user_id
     WHERE ws.user_id = ? AND ws.duration_seconds > 0
     ORDER BY ws.ended_at DESC, ws.started_at DESC
     LIMIT 5"
);
$recentStmt->execute([$userId]);
$recentSessions = $recentStmt->fetchAll();

echo json_encode([
    'ok'                        => true,
    'active_session'            => $activeSession,
    'todays_focus'              => $todaysFocus,
    'tasks'                     => $activeTasks,
    'weekly_goal'               => [
        'weekly_goal_hours'     => $weeklyGoalHours,
        'logged_study_hours'    => $weekFocusedHours,
        'today_study_hours'     => $todayFocusedHours,
        'goal_progress_percent' => $goalProgressPercent,
    ],
    'course_needing_attention'  => $courseNeedingAttention,
    'upcoming_sessions'         => $upcomingSessions,
    'recent_sessions'           => $recentSessions,
], JSON_UNESCAPED_UNICODE);
