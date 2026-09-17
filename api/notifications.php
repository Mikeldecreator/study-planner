<?php
require_once __DIR__ . '/../includes/auth.php';
header('Content-Type: application/json');
requireLogin();

$userId = currentUserId();
$db = getDb();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    // Only show in-app notifications whose send_at has arrived (cron marks sent_at,
    // but for in-app we just need send_at <= now — no waiting on the cron for the bell).
    $stmt = $db->prepare(
        "SELECT n.id, n.message, n.send_at, n.read_at, t.title AS task_title
         FROM notifications n
         LEFT JOIN tasks t ON t.id = n.task_id AND t.user_id = n.user_id
         WHERE n.user_id = ? AND n.channel = 'in_app' AND n.send_at <= NOW()
         ORDER BY n.send_at DESC LIMIT 20"
    );
    $stmt->execute([$userId]);
    $rows = $stmt->fetchAll();

    // The bell badge must reflect the TRUE unread count, not just how many of
    // the 20 most-recent rows happen to be unread — a user with more than 20
    // unread notifications would otherwise see an undercounted badge.
    $stmt = $db->prepare(
        "SELECT COUNT(*) AS n FROM notifications
         WHERE user_id = ? AND channel = 'in_app' AND send_at <= NOW() AND read_at IS NULL"
    );
    $stmt->execute([$userId]);
    $unread = (int) $stmt->fetch()['n'];

    echo json_encode(['notifications' => $rows, 'unread_count' => $unread]);
    exit;
}

if ($method === 'POST') {
    // Mark one (or all) as read: { "id": 5 }  or  { "all": true }
    $body = json_decode(file_get_contents('php://input'), true) ?? [];
    verifyCsrf($body['csrf_token'] ?? null);

    if (!empty($body['all'])) {
        $stmt = $db->prepare('UPDATE notifications SET read_at = NOW() WHERE user_id = ? AND read_at IS NULL');
        $stmt->execute([$userId]);
    } elseif (!empty($body['id'])) {
        $stmt = $db->prepare('UPDATE notifications SET read_at = NOW() WHERE id = ? AND user_id = ?');
        $stmt->execute([(int) $body['id'], $userId]);
    }
    echo json_encode(['ok' => true]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
