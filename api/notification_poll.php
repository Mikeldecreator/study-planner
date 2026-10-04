<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// Authenticate using the current session without updating last_active_at
requireLogin(false);

$userId = currentUserId();
if ($userId <= 0) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Not authenticated'], JSON_UNESCAPED_UNICODE);
    exit;
}

// Release session lock immediately so parallel page requests execute without waiting
if (session_status() === PHP_SESSION_ACTIVE) {
    session_write_close();
}

$db = getDb();
ensureNotificationSchema($db);

$unreadCount = 0;
$latestNotifications = [];
$latestId = 0;
$latestTimestamp = null;

try {
    $stmtCount = $db->prepare("
        SELECT COUNT(*) AS unread_cnt, MAX(id) AS max_id, MAX(send_at) AS max_send_at
        FROM notifications
        WHERE user_id = ?
          AND channel = 'in_app'
          AND send_at <= NOW()
          AND read_at IS NULL
    ");
    $stmtCount->execute([$userId]);
    $meta = $stmtCount->fetch(PDO::FETCH_ASSOC);

    $unreadCount = (int)($meta['unread_cnt'] ?? 0);
    $latestId = !empty($meta['max_id']) ? (int)$meta['max_id'] : 0;
    $latestTimestamp = !empty($meta['max_send_at']) ? (string)$meta['max_send_at'] : null;

    if ($unreadCount > 0) {
        $stmtItems = $db->prepare("
            SELECT n.id, n.task_id, n.channel, n.event_key, n.message, n.send_at, n.push_status, n.read_at, n.created_at,
                   t.title AS task_title
            FROM notifications n
            LEFT JOIN tasks t ON t.id = n.task_id AND t.user_id = n.user_id
            WHERE n.user_id = ?
              AND n.channel = 'in_app'
              AND n.send_at <= NOW()
              AND n.read_at IS NULL
            ORDER BY n.send_at DESC, n.id DESC
            LIMIT 5
        ");
        $stmtItems->execute([$userId]);
        $rawRows = $stmtItems->fetchAll(PDO::FETCH_ASSOC);
        $latestNotifications = array_map('decorateNotification', $rawRows);
    }
} catch (Throwable $e) {
    $unreadCount = 0;
    $latestNotifications = [];
}

echo json_encode([
    'ok' => true,
    'unread_count' => $unreadCount,
    'latest_notifications' => $latestNotifications,
    'latest_id' => $latestId,
    'latest_timestamp' => $latestTimestamp,
    'server_time' => date('c')
], JSON_UNESCAPED_UNICODE);
