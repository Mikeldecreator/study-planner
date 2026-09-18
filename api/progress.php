<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
header('Content-Type: application/json');
requireLogin();

$userId = currentUserId();
session_write_close();
$db = getDb();

// ?range=week|month|semester (default: semester = all-time, since there's
// no semester start/end date in the schema — "semester" just means
// "everything on record", which is the honest default for a course that's
// been running for a while.)
$range = $_GET['range'] ?? 'semester';
$dateFilter = '';
if ($range === 'week') {
    $dateFilter = " AND due_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)";
} elseif ($range === 'month') {
    $dateFilter = " AND due_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)";
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
$overallProgress = $taskTotal > 0 ? round($taskCompleted / $taskTotal * 100) : 0;

// ---- Overall progress: study sessions ----
$stmt = $db->prepare('SELECT is_completed FROM schedule_events WHERE user_id = ?');
$stmt->execute([$userId]);
$sessions = $stmt->fetchAll();
$sessionCompleted = count(array_filter($sessions, fn($s) => $s['is_completed']));
$sessionTotal = count($sessions);

// ---- Per-course progress ----
// Apply the same range filter used for the overall task stats above, so the
// per-course list never disagrees with the headline numbers when the user
// switches between Week / Month / Semester.
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

foreach ($courses as &$c) {
    $count = (int) $c['task_count'];
    $completed = (int) $c['completed_count'];
    $c['task_count'] = $count;
    $c['completed_count'] = $completed;
    $pct = $count > 0 ? round($completed / $count * 100) : 0;
    $c['progress'] = $pct;
    $c['rating'] = $pct >= 70 ? 'Good' : ($pct >= 40 ? 'Average' : 'Needs Improvement');

    $credits = (int) ($c['credits'] ?? 3);
    $gradePoint = $c['grade_point'] !== null ? (float) $c['grade_point'] : null;
    $courseRisk = courseRiskScore($gradePoint, $credits);
    $c['course_risk_score'] = $courseRisk;
    $c['course_risk_label'] = $courseRisk >= 80 ? 'Critical Risk' : ($courseRisk >= 60 ? 'High Risk' : ($courseRisk >= 35 ? 'Moderate Risk' : 'Low Risk'));

    // Foundation 5: Remaining workload and academic pressure context
    $courseId = (int) $c['id'];
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
    'study_sessions'    => ['completed' => $sessionCompleted, 'scheduled' => $sessionTotal],
    'courses'           => $courses,
    'progress_insights' => $progressInsights,
    'personal_planning' => $planningContext,
]);
