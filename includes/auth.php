<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/** Every PHP file is now an API endpoint — always fail with JSON 401, never redirect. */
function requireLogin(): void
{
    if (empty($_SESSION['user_id'])) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Not authenticated']);
        exit;
    }

    // Repair legacy demo data once for a newly-created/empty account.
    // This keeps existing databases usable when schema.sql was imported
    // before the current account was created.
    ensureUserDataSeeded(currentUserId());
}

function currentUserId(): int
{
    $id = (int) ($_SESSION['user_id'] ?? 0);
    error_log('CURRENT USER ID: ' . $id);
    return $id;
}

/** Generate (or reuse) a CSRF token for this session. */
function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** Verify a submitted CSRF token; call this on every POST/PUT/DELETE. */
function verifyCsrf(?string $token): void
{
    if (!$token || !hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        http_response_code(419);
        header('Content-Type: application/json');
        echo json_encode(['error' => 'Invalid or expired form token, please refresh and try again.']);
        exit;
    }
}

function attemptLogin(string $email, string $password): bool
{
    $stmt = getDb()->prepare('SELECT * FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password_hash'])) {
        session_regenerate_id(true); // prevent session fixation
        $_SESSION['user_id']   = (int) $user['id'];
        $_SESSION['user_name'] = $user['full_name'];
        ensureUserDataSeeded((int) $user['id']);
        return true;
    }
    return false;
}

function registerUser(string $name, string $email, string $password): array
{
    $db = getDb();

    $stmt = $db->prepare('SELECT id FROM users WHERE email = ?');
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        return ['ok' => false, 'error' => 'That email is already registered.'];
    }

    if (strlen($password) < 8) {
        return ['ok' => false, 'error' => 'Password must be at least 8 characters.'];
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $db->prepare('INSERT INTO users (full_name, email, password_hash) VALUES (?, ?, ?)');
    $stmt->execute([$name, $email, $hash]);

    $newUserId = (int) $db->lastInsertId();
    ensureUserDataSeeded($newUserId);

    return ['ok' => true, 'id' => $newUserId];
}

function logActivity(int $userId, string $message, string $icon = 'info'): void
{
    $stmt = getDb()->prepare(
        'INSERT INTO activity_log (user_id, message, icon) VALUES (?, ?, ?)'
    );
    $stmt->execute([$userId, $message, $icon]);
}

function requirePageLogin(): void
{
    if (empty($_SESSION['user_id'])) {
        header('Location: login.php');
        exit;
    }
}