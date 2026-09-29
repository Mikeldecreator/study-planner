<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
header('Content-Type: application/json; charset=utf-8');
requireLogin();

$userId = currentUserId();
$db = getDb();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    session_write_close();
    if (function_exists('syncContextualNotifications')) {
        try {
            syncContextualNotifications($db, $userId);
        } catch (\Throwable $t) {
            // Non-blocking notification sync
        }
    }

    $limit = isset($_GET['limit']) ? max(1, min(200, (int)$_GET['limit'])) : 100;

    // Fetch notifications whose send_at has arrived
    $stmt = $db->prepare(
        "SELECT n.id, n.user_id, n.task_id, n.channel, n.event_key, n.message, n.send_at, n.read_at, n.created_at,
                t.title AS task_title
         FROM notifications n
         LEFT JOIN tasks t ON t.id = n.task_id AND t.user_id = n.user_id
         WHERE n.user_id = ? AND n.channel = 'in_app' AND n.send_at <= NOW()
         ORDER BY n.send_at DESC LIMIT " . (int)$limit
    );
    $stmt->execute([$userId]);
    $rawRows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $rows = array_map('decorateNotification', $rawRows);

    // True unread count
    $stmt = $db->prepare(
        "SELECT COUNT(*) AS n FROM notifications
         WHERE user_id = ? AND channel = 'in_app' AND send_at <= NOW() AND read_at IS NULL"
    );
    $stmt->execute([$userId]);
    $unread = (int) $stmt->fetchColumn();

    // Authoritative Quick Stats & tab counts calculation across all active in-app notifications
    $stmt = $db->prepare(
        "SELECT id, message, read_at FROM notifications
         WHERE user_id = ? AND channel = 'in_app' AND send_at <= NOW()"
    );
    $stmt->execute([$userId]);
    $allUserNotifs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $stats = [
        'total'     => count($allUserNotifs),
        'unread'    => 0,
        'academic'  => 0,
        'deadline'  => 0,
        'class'     => 0,
        'study'     => 0,
        'general'   => 0,
    ];

    foreach ($allUserNotifs as $n) {
        $msg = strtolower((string)$n['message']);
        $isUnread = empty($n['read_at']);
        if ($isUnread) {
            $stats['unread']++;
        }

        $isAcademic = (strpos($msg, 'curriculum') !== false || strpos($msg, 'exam') !== false || strpos($msg, 'academic') !== false || strpos($msg, 'course') !== false || strpos($msg, 'grade') !== false || strpos($msg, 'completed') !== false);
        $isDeadline = (strpos($msg, 'deadline') !== false || strpos($msg, 'due') !== false || strpos($msg, 'overdue') !== false || strpos($msg, 'urgent') !== false);
        $isClass    = (strpos($msg, 'class') !== false || strpos($msg, 'lecture') !== false || strpos($msg, 'timetable') !== false);
        $isStudy    = (strpos($msg, 'study') !== false || strpos($msg, 'focus') !== false || strpos($msg, 'goal') !== false || strpos($msg, 'free block') !== false);

        if ($isAcademic) $stats['academic']++;
        if ($isDeadline) $stats['deadline']++;
        if ($isClass)    $stats['class']++;
        if ($isStudy)    $stats['study']++;

        if (!$isAcademic && !$isDeadline && !$isClass && !$isStudy) {
            $stats['general']++;
        }
    }

    echo json_encode([
        'ok'            => true,
        'notifications' => $rows,
        'unread_count'  => $unread,
        'stats'         => $stats
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

if ($method === 'POST' || $method === 'PUT' || $method === 'PATCH') {
    $raw = file_get_contents('php://input');
    $body = json_decode($raw ?: '', true);
    if (!is_array($body)) {
        $body = !empty($_POST) ? $_POST : [];
    }

    // CSRF verification (permissive fallback if token passed in headers)
    $token = $body['csrf_token'] ?? ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null);
    verifyCsrf($token);

    $isMarkAll = !empty($body['all']) || (($body['action'] ?? '') === 'mark_all_read');

    if ($isMarkAll) {
        $stmt = $db->prepare('UPDATE notifications SET read_at = NOW() WHERE user_id = ? AND read_at IS NULL');
        $stmt->execute([$userId]);
    } elseif (!empty($body['id'])) {
        $stmt = $db->prepare('UPDATE notifications SET read_at = NOW() WHERE id = ? AND user_id = ? AND read_at IS NULL');
        $stmt->execute([(int)$body['id'], $userId]);
    }

    // Recalculate unread count
    $stmt = $db->prepare(
        "SELECT COUNT(*) AS n FROM notifications
         WHERE user_id = ? AND channel = 'in_app' AND send_at <= NOW() AND read_at IS NULL"
    );
    $stmt->execute([$userId]);
    $unread = (int) $stmt->fetchColumn();

    echo json_encode([
        'ok'           => true,
        'unread_count' => $unread
    ]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
