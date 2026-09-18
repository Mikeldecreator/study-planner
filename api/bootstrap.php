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

// Unread in-app notification count
$unreadCount = 0;
if ($db) {
    try {
        $stmt = $db->prepare(
            "SELECT COUNT(*) AS n FROM notifications
             WHERE user_id = ? AND channel = 'in_app' AND send_at <= NOW() AND read_at IS NULL"
        );
        $stmt->execute([$userId]);
        $unreadCount = (int) $stmt->fetch()['n'];
    } catch (Throwable $e) {
        $unreadCount = 0;
    }
}

echo json_encode([
    'ok' => true,
    'user' => $user,
    'csrf_token' => $csrfToken,
    'unread_notifications' => $unreadCount,
]);
