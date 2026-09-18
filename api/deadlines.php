<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
header('Content-Type: application/json');
requireLogin();

$userId = currentUserId();
session_write_close();
$db = getDb();

$includeCompleted = !empty($_GET['include_completed']) && $_GET['include_completed'] !== '0' && $_GET['include_completed'] !== 'false';

// Count completed tasks for summary
$compStmt = $db->prepare("SELECT COUNT(*) FROM tasks WHERE user_id = ? AND status = 'completed'");
$compStmt->execute([$userId]);
$completedCount = (int) $compStmt->fetchColumn();

$sql = "SELECT t.*, c.code AS course_code, c.name AS course_name
     FROM tasks t LEFT JOIN courses c ON c.id = t.course_id AND c.user_id = t.user_id
     WHERE t.user_id = ?";
if (!$includeCompleted) {
    $sql .= " AND t.status != 'completed'";
}
$sql .= " ORDER BY t.due_at ASC";

$stmt = $db->prepare($sql);
$stmt->execute([$userId]);
$rawDeadlines = $stmt->fetchAll();

$summary = [
    'upcoming'  => 0,
    'due_soon'  => 0,
    'overdue'   => 0,
    'completed' => $completedCount,
];
$summary['on_track'] = &$summary['upcoming']; // backward compatibility alias

$focusedMap = getUsersTasksFocusedSeconds($db, $userId);
$deadlines = [];

foreach ($rawDeadlines as $t) {
    $t['total_focused_seconds'] = $focusedMap[(int) $t['id']] ?? 0;
    $dec = decorateTask($t);
    $dec['due_label'] = dueRelativeLabel($dec['due_at']);
    $isCompleted = (($dec['system_status'] ?? $dec['status']) === 'completed');
    if ($isCompleted) {
        $dec['urgency'] = 'completed';
    } else {
        if (isset($summary[$dec['urgency']])) {
            $summary[$dec['urgency']]++;
        }
    }
    $deadlines[] = $dec;
}

$summary['total'] = count($deadlines);

echo json_encode([
    'deadlines' => $deadlines,
    'summary'   => $summary,
]);
