<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

if (function_exists('initAppSession')) {
    initAppSession();
} elseif (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/** Every PHP file is now an API endpoint — always fail with JSON 401, never redirect. */
function requireLogin(bool $touchActive = true): void
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
    if ($touchActive) {
        touchUserLastActive();
    }
}

if (!function_exists('currentUserId')) {
    function currentUserId(): int
    {
        return (int) ($_SESSION['user_id'] ?? 0);
    }
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

function verifyCredentials(string $email, string $password): ?array
{
    $stmt = getDb()->prepare('SELECT * FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password_hash'])) {
        return $user;
    }
    return null;
}

function attemptLogin(string $email, string $password): bool
{
    $user = verifyCredentials($email, $password);
    if (!$user) {
        return false;
    }

    // Gate unverified accounts only when email verification is required
    $requireVerification = defined('REQUIRE_EMAIL_VERIFICATION')
        ? (bool) REQUIRE_EMAIL_VERIFICATION
        : false;

    if ($requireVerification && isset($user['email_verified']) && (int) $user['email_verified'] === 0) {
        return false;
    }

    session_regenerate_id(true); // prevent session fixation
    $_SESSION['user_id']       = (int) $user['id'];
    $_SESSION['user_name']     = $user['full_name'];
    $_SESSION['last_activity'] = time();
    ensureUserDataSeeded((int) $user['id']);
    return true;
}

function registerUser(string $name, string $email, string $password, int $emailVerified = 0, ?string $token = null, ?string $expiresAt = null): array
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
    try {
        $stmt = $db->prepare('INSERT INTO users (full_name, email, password_hash, email_verified, email_verification_token, email_verification_expires_at, onboarding_completed, onboarding_step) VALUES (?, ?, ?, ?, ?, ?, 0, 1)');
        $stmt->execute([$name, $email, $hash, $emailVerified, $token, $expiresAt]);
    } catch (PDOException $e) {
        if ($e->getCode() === '42S22') {
            try {
                $stmt = $db->prepare('INSERT INTO users (full_name, email, password_hash, onboarding_completed, onboarding_step) VALUES (?, ?, ?, 0, 1)');
                $stmt->execute([$name, $email, $hash]);
            } catch (PDOException $e2) {
                if ($e2->getCode() === '42S22') {
                    $stmt = $db->prepare('INSERT INTO users (full_name, email, password_hash) VALUES (?, ?, ?)');
                    $stmt->execute([$name, $email, $hash]);
                } else {
                    throw $e2;
                }
            }
        } else {
            throw $e;
        }
    }

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

/**
 * Check whether the given user has completed academic onboarding.
 * Strictly respects an explicitly stored onboarding_completed = 0 or 1.
 */
function isOnboardingComplete(int $userId, ?PDO $db = null): bool
{
    if ($userId <= 0) {
        return false;
    }

    if (!empty($_SESSION['onboarding_completed'])) {
        return true;
    }

    try {
        $conn = $db ?? getDb();
        $stmt = $conn->prepare('SELECT onboarding_completed FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $val = $stmt->fetchColumn();

        if ($val !== false && $val !== null) {
            return (int) $val === 1;
        }

        return false;
    } catch (PDOException $e) {
        if ($e->getCode() === '42S22') {
            try {
                $conn = $db ?? getDb();
                $cStmt = $conn->prepare('SELECT COUNT(*) FROM courses WHERE user_id = ?');
                $cStmt->execute([$userId]);
                if ((int) $cStmt->fetchColumn() > 0) return true;

                $tStmt = $conn->prepare('SELECT COUNT(*) FROM tasks WHERE user_id = ?');
                $tStmt->execute([$userId]);
                if ((int) $tStmt->fetchColumn() > 0) return true;
            } catch (Throwable $t) {}
        }
        return false;
    } catch (Throwable $e) {
        return false;
    }
}

function requirePageLogin(): void
{
    if (empty($_SESSION['user_id'])) {
        header('Location: login.php');
        exit;
    }

    touchUserLastActive(null, (int) $_SESSION['user_id']);

    $currentScript = basename($_SERVER['PHP_SELF'] ?? '');
    if ($currentScript !== 'onboarding.php' && $currentScript !== 'logout.php') {
        if (!isOnboardingComplete((int) $_SESSION['user_id'])) {
            header('Location: onboarding.php');
            exit;
        }
    }
}

/**
 * Throttled update of user's last_active_at timestamp (at most once every 60 seconds).
 */
function touchUserLastActive(?PDO $db = null, ?int $userId = null): void
{
    $uid = $userId ?? currentUserId();
    if ($uid <= 0) {
        return;
    }

    $now = time();
    if (session_status() === PHP_SESSION_ACTIVE) {
        $lastTouched = (int) ($_SESSION['last_active_touched_' . $uid] ?? 0);
        if (($now - $lastTouched) < 60) {
            return;
        }
    }

    try {
        $conn = $db ?? getDb();
        $stmt = $conn->prepare('UPDATE users SET last_active_at = NOW() WHERE id = ?');
        $stmt->execute([$uid]);
        if (session_status() === PHP_SESSION_ACTIVE) {
            $_SESSION['last_active_touched_' . $uid] = $now;
        }
    } catch (Throwable $e) {
        // Fail silently
    }
}

/**
 * Check if the user was active within the given number of minutes (default: 15).
 */
function isUserRecentlyActive(?PDO $db = null, int $userId = 0, int $minutes = 15): bool
{
    if ($userId <= 0) {
        return false;
    }

    try {
        $conn = $db ?? getDb();
        $stmt = $conn->prepare('SELECT (last_active_at IS NOT NULL AND last_active_at >= DATE_SUB(NOW(), INTERVAL ? MINUTE)) AS is_active FROM users WHERE id = ?');
        $stmt->execute([$minutes, $userId]);
        return (bool) $stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
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

function ensureRateLimitsTable(PDO $db): void {
    static $ensured = false;
    if ($ensured) return;
    try {
        $db->exec("CREATE TABLE IF NOT EXISTS rate_limits (
            rate_key CHAR(64) PRIMARY KEY,
            attempts INT NOT NULL DEFAULT 1,
            expires_at DATETIME NOT NULL,
            INDEX idx_rate_expires (expires_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
        $ensured = true;
    } catch (Throwable $e) {}
}

/**
 * Check if a specific action and identifier combination is rate limited.
 * Returns true if attempts >= maxAttempts within the unexpired window.
 */
function isRateLimited(string $action, string $identifier, int $maxAttempts): bool
{
    $rateKey = hash('sha256', strtolower($action . ':' . trim($identifier)));
    $db = getDb();
    ensureRateLimitsTable($db);

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
    ensureRateLimitsTable($db);
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