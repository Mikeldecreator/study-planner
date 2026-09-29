<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

requireLogin();

$userId = currentUserId();
$sessionUserName = $_SESSION['user_name'] ?? 'Student';
$csrfToken = csrfToken();

// Release session lock immediately so parallel page requests execute without waiting
session_write_close();

$db = null;
$row = [];
$avatar = '';

try {
    $db = getDb();
    $row = getUserProfileRow($userId);

    try {
        $stmt = $db->prepare(
            'SELECT avatar_path
             FROM users
             WHERE id = ?'
        );
        $stmt->execute([$userId]);
        $avatar = $stmt->fetchColumn() ?: '';
    } catch (Throwable $e) {
        $avatar = '';
    }
} catch (Throwable $e) {
    // Database connection error fallback
    $row = [];
}

$tagline = trim((string)($row['tagline'] ?? ''));
if ($tagline === '') {
    $tagline = 'Better plans. Bigger goals.';
}

$user = [
    'id' => $userId,
    'name' => $row['full_name'] ?? $sessionUserName,
    'email' => $row['email'] ?? '',
    'avatar_path' => $avatar,
    'tagline' => $tagline,
    'dark_mode' => (bool)($row['dark_mode'] ?? false),
    'level' => $row['level'] ?? '',
    'program' => $row['program'] ?? '',
    'weekly_goal_hours' => (float)($row['weekly_goal_hours'] ?? 0),
    'notifications_enabled' => (bool)($row['notifications_enabled'] ?? true),
    'week_start_day' => (int)($row['week_start_day'] ?? 1),
    'tour_completed' => (bool)($row['tour_completed'] ?? false),
];

// Contextual notification sync (non-blocking)
if ($db && function_exists('syncContextualNotifications')) {
    try {
        syncContextualNotifications($db, $userId);
    } catch (Throwable $t) {
        // Non-blocking
    }
}

// Unread in-app notification count & latest unread list for popup alerts
$unreadCount = 0;
$latestNotifications = [];
if ($db) {
    try {
        $stmt = $db->prepare(
            "SELECT COUNT(*) AS n FROM notifications
             WHERE user_id = ? AND channel = 'in_app' AND send_at <= NOW() AND read_at IS NULL"
        );
        $stmt->execute([$userId]);
        $unreadCount = (int) $stmt->fetch()['n'];

        if ($unreadCount > 0) {
            $stmt = $db->prepare(
                "SELECT n.id, n.task_id, n.channel, n.event_key, n.message, n.send_at, n.read_at, n.created_at,
                        t.title AS task_title
                 FROM notifications n
                 LEFT JOIN tasks t ON t.id = n.task_id AND t.user_id = n.user_id
                 WHERE n.user_id = ? AND n.channel = 'in_app' AND n.send_at <= NOW() AND n.read_at IS NULL
                 ORDER BY n.send_at DESC LIMIT 5"
            );
            $stmt->execute([$userId]);
            $rawRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $latestNotifications = array_map('decorateNotification', $rawRows);
        }
    } catch (Throwable $e) {
        $unreadCount = 0;
        $latestNotifications = [];
    }
}

echo json_encode([
    'ok' => true,
    'user' => $user,
    'csrf_token' => $csrfToken,
    'unread_notifications' => $unreadCount,
    'latest_notifications' => $latestNotifications,
]);
