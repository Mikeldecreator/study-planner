<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

requireLogin();

$userId = currentUserId();
$sessionUserName = $_SESSION['user_name'] ?? 'Student';
session_write_close();

$row = getUserProfileRow($userId);

try {
    $stmt = getDb()->prepare(
        'SELECT avatar_path
         FROM users
         WHERE id = ?'
    );

    $stmt->execute([$userId]);

    $avatar = $stmt->fetchColumn() ?: '';

} catch (Throwable $e) {
    $avatar = '';
}

$tagline = trim(
    (string)($row['tagline'] ?? '')
);

if ($tagline === '') {
    $tagline = 'Better plans. Bigger goals.';
}

echo json_encode([
    'id' => $userId,

    'name' =>
        $row['full_name']
        ?? $sessionUserName,

    'email' =>
        $row['email'] ?? '',

    'avatar_path' =>
        $avatar,

    'tagline' =>
        $tagline,

    'dark_mode' =>
        (bool)($row['dark_mode'] ?? false),

    'level' =>
        $row['level'] ?? '',

    'program' =>
        $row['program'] ?? '',

    'weekly_goal_hours' =>
        (float)($row['weekly_goal_hours'] ?? 0),

    'notifications_enabled' =>
        (bool)($row['notifications_enabled'] ?? true),

    'week_start_day' =>
        (int)($row['week_start_day'] ?? 1),
]);