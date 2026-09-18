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
    return (int) ($_SESSION['user_id'] ?? 0);
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

/**
 * Resolve client IP address safely.
 */
function getClientIp(): string
{
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
    // If multiple comma-separated IPs in forwarded header, pick the first
    if (str_contains($ip, ',')) {
        $parts = explode(',', $ip);
        $ip = trim($parts[0]);
    }
    return filter_var($ip, FILTER_VALIDATE_IP) ? $ip : '127.0.0.1';
}

/**
 * Check if a specific action and identifier combination is rate limited.
 * Returns true if attempts >= maxAttempts within the unexpired window.
 */
function isRateLimited(string $action, string $identifier, int $maxAttempts): bool
{
    $rateKey = hash('sha256', strtolower($action . ':' . trim($identifier)));
    $db = getDb();

    // Probabilistic cleanup of expired limits (1 in 20 requests)
    if (random_int(1, 20) === 1) {
        try {
            $db->exec("DELETE FROM rate_limits WHERE expires_at < NOW()");
        } catch (Throwable $e) {}
    }

    try {
        $stmt = $db->prepare('SELECT attempts FROM rate_limits WHERE rate_key = ? AND expires_at > NOW() LIMIT 1');
        $stmt->execute([$rateKey]);
        $row = $stmt->fetch();
        if ($row && (int)$row['attempts'] >= $maxAttempts) {
            return true;
        }
    } catch (Throwable $e) {
        // Fail open if rate_limits is temporarily unreachable
    }

    return false;
}

/**
 * Record a failed attempt or request hit against the rate limit window.
 */
function recordRateLimitHit(string $action, string $identifier, int $decaySeconds): void
{
    $rateKey = hash('sha256', strtolower($action . ':' . trim($identifier)));
    $db = getDb();
    $expiresAt = date('Y-m-d H:i:s', time() + $decaySeconds);

    try {
        $stmt = $db->prepare('
            INSERT INTO rate_limits (rate_key, attempts, expires_at)
            VALUES (?, 1, ?)
            ON DUPLICATE KEY UPDATE
                attempts = attempts + 1,
                expires_at = IF(expires_at > VALUES(expires_at), expires_at, VALUES(expires_at))
        ');
        $stmt->execute([$rateKey, $expiresAt]);
    } catch (Throwable $e) {}
}

/**
 * Clear rate limit records upon successful authentication.
 */
function clearRateLimit(string $action, string $identifier): void
{
    $rateKey = hash('sha256', strtolower($action . ':' . trim($identifier)));
    try {
        $stmt = getDb()->prepare('DELETE FROM rate_limits WHERE rate_key = ?');
        $stmt->execute([$rateKey]);
    } catch (Throwable $e) {}
}