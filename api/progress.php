<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
header('Content-Type: application/json');
requireLogin();

$userId = currentUserId();
session_write_close();
$db = getDb();

// ?range=week|month|semester
$range = $_GET['range'] ?? 'semester';
$dateFilter = '';
$sessionDateFilter = '';

if ($range === 'week') {
    $dateFilter = " AND due_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
    $sessionDateFilter = " AND started_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
} elseif ($range === 'month') {
    $dateFilter = " AND due_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
    $sessionDateFilter = " AND started_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
} else {
    // Semester range: check active semester from database
    $semStmt = $db->prepare(
        "SELECT start_date, end_date FROM semesters 
         WHERE user_id = ? AND (is_current = 1 OR (start_date <= CURDATE() AND end_date >= CURDATE())) 
         ORDER BY is_current DESC, id DESC LIMIT 1"
    );
    $semStmt->execute([$userId]);
    $activeSem = $semStmt->fetch();
    if ($activeSem && !empty($activeSem['start_date']) && !empty($activeSem['end_date'])) {
        $dateFilter = " AND due_at BETWEEN '{$activeSem['start_date']} 00:00:00' AND '{$activeSem['end_date']} 23:59:59'";
        $sessionDateFilter = " AND started_at BETWEEN '{$activeSem['start_date']} 00:00:00' AND '{$activeSem['end_date']} 23:59:59'";
    } else {
        $dateFilter = '';
        $sessionDateFilter = '';
    }
}

// ---- Overall progress: tasks ----
$stmt = $db->prepare("SELECT status, due_at FROM tasks WHERE user_id = ?" . $dateFilter);
$stmt->execute([$userId]);
$tasks = $stmt->fetchAll();

$taskCompleted = 0; $taskOverdue = 0; $taskPending = 0;
foreach ($tasks as $t) {
    if ($t['status'] === 'completed') { $taskCompleted++; continue; }
    if (classifyTaskUrgency($t['due_at'], $t['status']) === 'overdue') { $taskOverdue++; }
    else { $taskPending++; }
}
$taskTotal = count($tasks);
// Preserve 2-decimal precision (e.g. 1.67%, 50.00%, 0.25%)
$overallProgress = $taskTotal > 0 ? round(($taskCompleted / $taskTotal) * 100, 2) : 0.0;

// ---- Overall progress: study sessions (authoritative task_work_sessions) ----
$stmtFocus = $db->prepare(
    "SELECT 
        COALESCE(SUM(
            CASE 
                WHEN status = 'running' THEN duration_seconds + GREATEST(0, TIMESTAMPDIFF(SECOND, started_at, NOW()))
                ELSE duration_seconds 
            END
        ), 0) AS total_seconds,
        COUNT(CASE WHEN status IN ('completed', 'stopped') AND duration_seconds > 0 THEN 1 END) AS completed_sessions
     FROM task_work_sessions 
     WHERE user_id = ?" . $sessionDateFilter
);
$stmtFocus->execute([$userId]);
$focusStats = $stmtFocus->fetch();
$focusedStudySeconds = (int) ($focusStats['total_seconds'] ?? 0);
$focusedStudyHours = round($focusedStudySeconds / 3600, 2);
$completedFocusSessions = (int) ($focusStats['completed_sessions'] ?? 0);

// Scheduled study blocks from calendar (distinct from scheduled classes)
$stmtSched = $db->prepare("SELECT COUNT(*) AS total_study, COUNT(CASE WHEN is_completed = 1 THEN 1 END) AS completed_study FROM schedule_events WHERE user_id = ? AND event_type = 'study'");
$stmtSched->execute([$userId]);
$schedStudy = $stmtSched->fetch();
$scheduledStudyCount = (int) ($schedStudy['total_study'] ?? 0);
$completedStudyCount = (int) ($schedStudy['completed_study'] ?? 0);

// Scheduled classes/lectures (timetable) kept distinct!
$stmtClass = $db->prepare("SELECT COUNT(*) FROM schedule_events WHERE user_id = ? AND event_type = 'lecture'");
$stmtClass->execute([$userId]);
$scheduledClassesCount = (int) $stmtClass->fetchColumn();

$sessionTotal = max($completedFocusSessions, $scheduledStudyCount);
$sessionCompleted = $completedFocusSessions;

// ---- Per-course progress ----
$courseTaskFilter = str_replace('due_at', 't.due_at', $dateFilter);
$stmt = $db->prepare(
    "SELECT c.id, c.code, c.name, c.icon, c.color, c.credits, c.grade_point,
        COUNT(t.id) AS task_count,
        SUM(t.status = 'completed') AS completed_count
     FROM courses c
     LEFT JOIN tasks t ON t.course_id = c.id AND t.user_id = c.user_id{$courseTaskFilter}
     WHERE c.user_id = ?
     GROUP BY c.id
     ORDER BY c.code ASC"
);
$stmt->execute([$userId]);
$courses = $stmt->fetchAll();

$focusByCourseStmt = $db->prepare(
    "SELECT t.course_id, SUM(ws.duration_seconds) AS total_focus_sec
     FROM task_work_sessions ws
     INNER JOIN tasks t ON t.id = ws.task_id AND t.user_id = ws.user_id
     WHERE ws.user_id = ? AND t.course_id IS NOT NULL" . str_replace('started_at', 'ws.started_at', $sessionDateFilter) . "
     GROUP BY t.course_id"
);
$focusByCourseStmt->execute([$userId]);
$focusByCourse = $focusByCourseStmt->fetchAll(PDO::FETCH_KEY_PAIR);

foreach ($courses as &$c) {
    $count = (int) $c['task_count'];
    $completed = (int) $c['completed_count'];
    $c['task_count'] = $count;
    $c['completed_count'] = $completed;
    // Preserve 2 decimal precision
    $pct = $count > 0 ? round(($completed / $count) * 100, 2) : 0.0;
    $c['progress'] = $pct;
    $c['rating'] = $pct >= 70 ? 'Good' : ($pct >= 40 ? 'Average' : 'Needs Improvement');

    $courseId = (int) $c['id'];
    $focusSec = (int) ($focusByCourse[$courseId] ?? 0);
    $c['focused_seconds'] = $focusSec;
    $c['focused_hours']   = round($focusSec / 3600, 2);

    $credits = (int) ($c['credits'] ?? 3);
    $gradePoint = $c['grade_point'] !== null ? (float) $c['grade_point'] : null;
    $courseRisk = courseRiskScore($gradePoint, $credits);
    $c['course_risk_score'] = $courseRisk;
    $c['course_risk_label'] = $courseRisk >= 80 ? 'Critical Risk' : ($courseRisk >= 60 ? 'High Risk' : ($courseRisk >= 35 ? 'Moderate Risk' : 'Low Risk'));

    // Remaining workload and academic pressure context
    $workloadHours = calculateCourseWorkload($db, $userId, $courseId);
    $c['remaining_workload_hours'] = $workloadHours;

    $stmtOverdue = $db->prepare(
        "SELECT COUNT(*) FROM tasks 
         WHERE user_id = ? AND course_id = ? AND status != 'completed' AND due_at < NOW()"
    );
    $stmtOverdue->execute([$userId, $courseId]);
    $overdueCount = (int) $stmtOverdue->fetchColumn();
    $c['overdue_tasks_count'] = $overdueCount;

    $pendingCount = max(0, $count - $completed);
    $c['pending_tasks_count'] = $pendingCount;

    $pressure = determineCoursePressure($courseRisk, $workloadHours, $overdueCount, $pendingCount);
    $c['course_pressure'] = $pressure;

    if ($overdueCount > 0) {
        $c['context_message'] = "{$overdueCount} overdue • {$workloadHours}h remaining • {$c['course_risk_label']}";
    } elseif ($pendingCount > 0) {
        $c['context_message'] = "{$pendingCount} pending task" . ($pendingCount === 1 ? '' : 's') . " • {$workloadHours}h remaining";
    } else {
        $c['context_message'] = 'All tasks completed • On track';
    }
}
unset($c);

$tasksSummary = [
    'completed' => $taskCompleted,
    'pending'   => $taskPending,
    'overdue'   => $taskOverdue,
    'total'     => $taskTotal,
];

$progressInsights = computeProgressInsights($db, $userId, $courses, $tasksSummary, $range);
$planningContext = getPersonalPlanningContext($db, $userId);

echo json_encode([
    'range'             => $range,
    'overall_progress'  => $overallProgress,
    'tasks'             => $tasksSummary,
    'study_sessions'    => [
        'completed'                => $sessionCompleted,
        'scheduled'                => $sessionTotal,
        'completed_sessions_count' => $completedFocusSessions,
        'scheduled_study_sessions' => $scheduledStudyCount,
        'scheduled_classes'        => $scheduledClassesCount,
        'focused_study_seconds'    => $focusedStudySeconds,
        'focused_study_hours'      => $focusedStudyHours,
    ],
    'courses'           => $courses,
    'progress_insights' => $progressInsights,
    'personal_planning' => $planningContext,
], JSON_UNESCAPED_UNICODE);
