<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
header('Content-Type: application/json');
requireLogin();

$userId = currentUserId();
$db = getDb();

// Everything not yet completed — this page is specifically about deadlines
// still ahead of (or just behind) the student, not a general task archive.
$stmt = $db->prepare(
    "SELECT t.*, c.code AS course_code, c.name AS course_name
     FROM tasks t LEFT JOIN courses c ON c.id = t.course_id AND c.user_id = t.user_id
     WHERE t.user_id = ? AND t.status != 'completed'
     ORDER BY t.due_at ASC"
);
$stmt->execute([$userId]);
$deadlines = $stmt->fetchAll();

$summary = ['on_track' => 0, 'due_soon' => 0, 'overdue' => 0];
foreach ($deadlines as &$t) {
    $bucket = classifyTaskUrgency($t['due_at'], $t['status']);
    $t['urgency']   = $bucket === 'upcoming' ? 'on_track' : $bucket; // rename for this page's vocabulary
    $t['due_label'] = dueRelativeLabel($t['due_at']);
    if (isset($summary[$t['urgency']])) {
        $summary[$t['urgency']]++;
    }
}
unset($t);

$summary['total'] = count($deadlines);

echo json_encode([
    'deadlines' => $deadlines,
    'summary'   => $summary,
]);
