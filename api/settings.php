<?php
require_once __DIR__ . '/../includes/auth.php';
header('Content-Type: application/json; charset=utf-8');
requireLogin();

$userId = currentUserId();
$db = getDb();

function settingsError(string $message, int $status = 400): never {
    http_response_code($status);
    echo json_encode(['error' => $message]);
    exit;
}

function settingsJsonBody(): array {
    $raw = file_get_contents('php://input');
    $body = json_decode($raw ?: '', true);
    return is_array($body) ? $body : [];
}

function runPreferenceUpdate(PDO $db, string $sql, array $params): void {
    try { $db->prepare($sql)->execute($params); }
    catch (PDOException $e) {
        if ($e->getCode() === '42S22') settingsError('This setting is not available yet. Please run the latest users-table migration from database/schema.sql.', 409);
        throw $e;
    }
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') settingsError('Method not allowed.', 405);

// Multipart upload uses the same settings endpoint; no new endpoint or DB field is required.
if (isset($_POST['action']) && $_POST['action'] === 'avatar') {
    verifyCsrf($_POST['csrf_token'] ?? null);
    if (empty($_FILES['avatar']) || $_FILES['avatar']['error'] !== UPLOAD_ERR_OK) settingsError('Please choose a profile image.', 422);
    $file = $_FILES['avatar'];
    if ($file['size'] > 5 * 1024 * 1024) settingsError('Profile images must be 5MB or smaller.', 422);
    $info = @getimagesize($file['tmp_name']);
    if (!$info || empty($info['mime']) || !in_array($info['mime'], ['image/jpeg','image/png','image/webp'], true)) settingsError('Only JPG, PNG and WebP images are supported.', 422);

    $uploadDir = __DIR__ . '/../public/uploads/avatars';
    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) settingsError('Could not create the avatar upload directory.', 500);
    $extension = $info['mime'] === 'image/jpeg' ? 'jpg' : ($info['mime'] === 'image/png' ? 'png' : 'webp');
    $filename = 'user-' . $userId . '-' . bin2hex(random_bytes(8)) . '.' . $extension;
    $destination = $uploadDir . '/' . $filename;
    if (!move_uploaded_file($file['tmp_name'], $destination)) settingsError('Could not save the profile image.', 500);

    $relative = 'uploads/avatars/' . $filename;
    $stmt = $db->prepare('SELECT avatar_path FROM users WHERE id = ?'); $stmt->execute([$userId]); $old = $stmt->fetchColumn();
    $stmt = $db->prepare('UPDATE users SET avatar_path = ? WHERE id = ?'); $stmt->execute([$relative, $userId]);
    if ($old && strpos((string)$old, 'uploads/avatars/') === 0) {
        $oldFile = __DIR__ . '/../public/' . ltrim($old, '/');
        if (is_file($oldFile)) @unlink($oldFile);
    }
    echo json_encode(['ok'=>true, 'avatar_path'=>$relative]);
    exit;
}

$body = !empty($_POST) ? $_POST : settingsJsonBody();
verifyCsrf($body['csrf_token'] ?? null);

try {
    if (array_key_exists('dark_mode', $body)) runPreferenceUpdate($db, 'UPDATE users SET dark_mode = ? WHERE id = ?', [$body['dark_mode'] ? 1 : 0, $userId]);
    if (array_key_exists('weekly_goal_hours', $body)) runPreferenceUpdate($db, 'UPDATE users SET weekly_goal_hours = ? WHERE id = ?', [(float)$body['weekly_goal_hours'], $userId]);
    if (array_key_exists('notifications_enabled', $body)) runPreferenceUpdate($db, 'UPDATE users SET notifications_enabled = ? WHERE id = ?', [$body['notifications_enabled'] ? 1 : 0, $userId]);
    if (array_key_exists('week_start_day', $body)) runPreferenceUpdate($db, 'UPDATE users SET week_start_day = ? WHERE id = ?', [((int)$body['week_start_day'] === 0 ? 0 : 1), $userId]);

    if (isset($body['action']) && $body['action'] === 'password') {
        $current = (string)($body['current_password'] ?? '');
        $new = (string)($body['new_password'] ?? '');
        $confirm = (string)($body['confirm_password'] ?? '');
        if ($current === '' || $new === '' || $confirm === '') settingsError('All password fields are required.', 422);
        if (strlen($new) < 8) settingsError('New password must be at least 8 characters.', 422);
        if ($new !== $confirm) settingsError('The new passwords do not match.', 422);
        $stmt = $db->prepare('SELECT password_hash FROM users WHERE id = ?'); $stmt->execute([$userId]); $hash = $stmt->fetchColumn();
        if (!$hash || !password_verify($current, $hash)) settingsError('Your current password is incorrect.', 422);
        $stmt = $db->prepare('UPDATE users SET password_hash = ? WHERE id = ?'); $stmt->execute([password_hash($new, PASSWORD_DEFAULT), $userId]);
        echo json_encode(['ok'=>true]); exit;
    }

    if (array_key_exists('full_name', $body) || array_key_exists('email', $body)) {
        $fullName = trim((string)($body['full_name'] ?? ''));
        $email = strtolower(trim((string)($body['email'] ?? '')));
        if ($fullName === '' || $email === '') settingsError('Full name and email are required.', 422);
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) settingsError('Please enter a valid email address.', 422);
        $stmt = $db->prepare('SELECT id FROM users WHERE email = ? AND id != ?'); $stmt->execute([$email,$userId]);
        if ($stmt->fetch()) settingsError('That email is already in use by another account.', 409);
        $stmt = $db->prepare('SELECT program, level FROM users WHERE id = ?'); $stmt->execute([$userId]); $existing=$stmt->fetch() ?: ['program'=>null,'level'=>null];
        $program = array_key_exists('program',$body) ? trim((string)$body['program']) : $existing['program'];
        $level = array_key_exists('level',$body) ? trim((string)$body['level']) : $existing['level'];
        if (strlen($fullName)>100 || strlen($email)>150 || strlen($program)>100 || strlen($level)>20) settingsError('One or more profile fields are too long.',422);
        $stmt=$db->prepare('UPDATE users SET full_name=?, email=?, program=?, level=? WHERE id=?');
        $stmt->execute([$fullName,$email,$program!==''?$program:null,$level!==''?$level:null,$userId]);
        $_SESSION['user_name']=$fullName;
    }

    echo json_encode(['ok'=>true]);
} catch (PDOException $e) {
    error_log('Settings API database error: '.$e->getMessage());
    settingsError('A database error prevented the settings change from completing.',500);
} catch (Throwable $e) {
    error_log('Settings API error: '.$e->getMessage());
    settingsError('The settings change could not be completed.',500);
}
