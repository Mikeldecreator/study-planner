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
    'SELECT se.*, c.code AS course_code, c.name AS course_name, c.color AS course_color FROM schedule_events se
     LEFT JOIN courses c ON c.id = se.course_id AND c.user_id = se.user_id
     WHERE se.user_id = ? AND se.day_of_week = ? ORDER BY se.start_time'
);
$stmt->execute([$userId, $todayDow]);
$todaySchedule = $stmt->fetchAll();

$nowTime = date('H:i:s');
$nextClass = null;
$completedSessionsCount = 0;
$upcomingSessionsCount = 0;
$nextSession = null;

foreach ($todaySchedule as &$ev) {
    $ev['start_label'] = date('g:i A', strtotime($ev['start_time']));
    $ev['end_label']   = date('g:i A', strtotime($ev['end_time']));
    $ev['is_study_session'] = ($ev['event_type'] === 'study');

    $isCompleted = !empty($ev['is_completed']);
    if ($isCompleted) {
        $ev['timeline_status'] = 'completed';
        $ev['timeline_label'] = 'Completed';
        $completedSessionsCount++;
    } elseif ($ev['end_time'] < $nowTime) {
        $ev['timeline_status'] = 'missed';
        $ev['timeline_label'] = 'Missed';
    } elseif ($ev['start_time'] <= $nowTime && $nowTime <= $ev['end_time']) {
        $ev['timeline_status'] = 'in_progress';
        $ev['timeline_label'] = 'In Progress';
        if ($nextSession === null) {
            $nextSession = $ev;
        }
    } else {
        $ev['timeline_status'] = 'upcoming';
        $ev['timeline_label'] = 'Upcoming';
        $upcomingSessionsCount++;
        if ($nextSession === null) {
            $nextSession = $ev;
        }
    }

    if ($nextClass === null && $ev['start_time'] >= $nowTime) {
        $nextClass = $ev;
    }
}
unset($ev);

$scheduleSummary = [
    'total_sessions'     => count($todaySchedule),
    'completed_sessions' => $completedSessionsCount,
    'upcoming_sessions'  => $upcomingSessionsCount,
    'next_session'       => $nextSession,
];

// Upcoming deadlines — next 4 incomplete tasks
$stmt = $db->prepare(
    "SELECT t.*, c.code AS course_code, c.name AS course_name, c.color AS course_color FROM tasks t
     LEFT JOIN courses c ON c.id = t.course_id AND c.user_id = t.user_id
     WHERE t.user_id = ? AND t.status != 'completed'
     ORDER BY t.due_at ASC LIMIT 4"
);
$stmt->execute([$userId]);
$upcoming = $stmt->fetchAll();
foreach ($upcoming as &$t) {
    $t = decorateTask($t);
    $t['due_at_display'] = date('g:i A · M j, Y', strtotime($t['due_at']));
}

// Recent activity
$stmt = $db->prepare('SELECT * FROM activity_log WHERE user_id = ? ORDER BY created_at DESC LIMIT 5');
$stmt->execute([$userId]);
$activity = $stmt->fetchAll();
foreach ($activity as &$a) {
    $a['time_ago'] = timeAgo($a['created_at']);
}

// Courses (for dropdown and summary)
$stmt = $db->prepare('SELECT id, code, name, color, credits FROM courses WHERE user_id = ? ORDER BY code');
$stmt->execute([$userId]);
$activeCoursesSummary = $stmt->fetchAll();
$courses = $activeCoursesSummary;

// Totals and academic counts
$totalCourses = count($activeCoursesSummary);

$stmt = $db->prepare('SELECT COUNT(*) FROM schedule_events WHERE user_id = ?');
$stmt->execute([$userId]);
$totalClasses = (int) $stmt->fetchColumn();

$stmt = $db->prepare('SELECT COUNT(*) FROM tasks WHERE user_id = ?');
$stmt->execute([$userId]);
$totalTasks = (int) $stmt->fetchColumn();

$stmt = $db->prepare("SELECT COUNT(*) FROM tasks WHERE user_id = ? AND status != 'completed'");
$stmt->execute([$userId]);
$activeTasksCount = (int) $stmt->fetchColumn();

$stmt = $db->prepare("SELECT COUNT(*) FROM tasks WHERE user_id = ? AND status != 'completed' AND due_at < NOW()");
$stmt->execute([$userId]);
$overdueTasksCount = (int) $stmt->fetchColumn();

// Recommended study plan for today
$recommendedPlan = generateRecommendedStudyPlan($db, $userId, $todayDow);

// Compute today summary string
$recHours = 0;
foreach ($recommendedPlan as $rp) {
    $recHours += (float) ($rp['duration_hours'] ?? 1.0);
}
$recHours = max(1, (int) round($recHours));
$classCount = count($todaySchedule);
$todaySummary = sprintf(
    '%d class%s today · %d active task%s · %dh recommended study',
    $classCount,
    $classCount === 1 ? '' : 'es',
    $activeTasksCount,
    $activeTasksCount === 1 ? '' : 's',
    $recHours
);

// Centralized Academic Context for focus and states
$aiContext = getAIAcademicContext($db, $userId);
$todayCtx = $aiContext['today'] ?? ($aiContext['today_context'] ?? []);
$planningContext = $aiContext['personal_planning'] ?? getPersonalPlanningContext($db, $userId);
$todaysFocus = $todayCtx['todays_focus'] ?? null;
$priorityActions = $todayCtx['priority_actions'] ?? [];
$academicState = $todayCtx['academic_state'] ?? ($todaysFocus ? 'on_track' : ($totalTasks === 0 ? 'empty' : 'all_completed'));
$contextSummary = [
    'headline'                 => $todayCtx['headline'] ?? '',
    'message'                  => $todayCtx['message'] ?? '',
    'total_tasks'              => $totalTasks,
    'active_tasks'             => $activeTasksCount,
    'overdue_tasks'            => $overdueTasksCount,
    'remaining_workload_hours' => (float)($todayCtx['remaining_workload_hours'] ?? 0),
    'weekly_goal_hours'        => (float)($planningContext['weekly_goal_hours'] ?? 0),
    'weekly_logged_hours'      => (float)($planningContext['logged_study_hours'] ?? 0),
    'goal_progress_percent'    => (int)($planningContext['goal_progress_percent'] ?? 0),
];

// Contextual Smart Suggestions in simple student language
$smartSuggestions = [];
if ($overdueTasksCount > 0) {
    $smartSuggestions[] = [
        'title'        => 'Needs Attention',
        'tip'          => "You have {$overdueTasksCount} overdue task" . ($overdueTasksCount === 1 ? '' : 's') . ". Catch up today to stay on track.",
        'message'      => "You have {$overdueTasksCount} overdue task" . ($overdueTasksCount === 1 ? '' : 's') . ". Catch up today to stay on track.",
        'type'         => 'urgent',
        'icon'         => 'alert-triangle',
        'action_url'   => 'tasks.php',
        'action_label' => 'View Tasks',
    ];
}
if (!empty($recommendedPlan)) {
    $firstRec = $recommendedPlan[0];
    $cCode = !empty($firstRec['course_code']) ? $firstRec['course_code'] : 'your coursework';
    $smartSuggestions[] = [
        'title'        => 'Suggested Study Window',
        'tip'          => "Great time to study: {$firstRec['start_label']} – {$firstRec['end_label']} for {$cCode}.",
        'message'      => "Great time to study: {$firstRec['start_label']} – {$firstRec['end_label']} for {$cCode}.",
        'type'         => 'normal',
        'icon'         => 'clock',
        'action_url'   => 'tasks.php?focus=1',
        'action_label' => 'Start Study Session',
    ];
}
if (!empty($upcoming) && in_array($upcoming[0]['urgency'] ?? '', ['urgent', 'due_soon', 'overdue'], true)) {
    $smartSuggestions[] = [
        'title'        => 'Approaching Deadline',
        'tip'          => "“{$upcoming[0]['title']}” is due {$upcoming[0]['due_label']}. Wrap it up early!",
        'message'      => "“{$upcoming[0]['title']}” is due {$upcoming[0]['due_label']}. Wrap it up early!",
        'type'         => 'urgent',
        'icon'         => 'clock',
        'action_url'   => 'tasks.php?focus_task_id=' . $upcoming[0]['id'],
        'action_label' => 'Work on Task',
    ];
}
if (empty($smartSuggestions)) {
    $smartSuggestions[] = [
        'title'        => 'Keep Up The Momentum',
        'tip'          => 'Consistent daily study sessions lead to the best semester results.',
        'message'      => 'Consistent daily study sessions lead to the best semester results.',
        'type'         => 'normal',
        'icon'         => 'sparkles',
        'action_url'   => 'schedule.php',
        'action_label' => 'View Timetable',
    ];
}

$hour = (int) date('H');
$greeting = $hour < 12 ? 'morning' : ($hour < 17 ? 'afternoon' : 'evening');

$semesterContext = getSemesterContext($db, $userId);
$semesterContext['current_phase'] = $semesterContext['phase_badge'] ?? 'Active';

echo json_encode([
    'user_name'              => $userName,
    'greeting'               => $greeting,
    'semester_context'       => $semesterContext,
    'today_summary'          => $todaySummary,
    'next_class'             => $nextClass,
    'recommended_study_plan' => $recommendedPlan,
    'smart_suggestions'      => $smartSuggestions,
    'active_courses_summary' => $activeCoursesSummary,
    'today_schedule'         => $todaySchedule,
    'upcoming'               => $upcoming,
    'activity'               => $activity,
    'courses'                => $courses,
    'todays_focus'           => $todaysFocus,
    'priority_actions'       => $priorityActions,
    'academic_state'         => $academicState,
    'context_summary'        => $contextSummary,
    'personal_planning'      => $planningContext,
    'schedule_summary'       => $scheduleSummary,
    'total_courses'          => $totalCourses,
    'total_classes'          => $totalClasses,
    'total_tasks'            => $totalTasks,
]);
