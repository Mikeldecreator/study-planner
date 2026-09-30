<?php

declare(strict_types=1);

ob_start();

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

function verifyResponse(array $data, int $status = 200): never {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$token = trim((string) ($_GET['token'] ?? ''));

if ($token === '' && ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $raw = file_get_contents('php://input');
    if ($raw) {
        $body = json_decode($raw, true);
        if (is_array($body) && !empty($body['token'])) {
            $token = trim((string) $body['token']);
        }
    }
}

if ($token === '') {
    verifyResponse([
        'ok' => false,
        'error' => 'Verification token is required.'
    ], 400);
}

try {
    $db = getDb();

    $stmt = $db->prepare('SELECT id, full_name, email, email_verified, email_verification_expires_at FROM users WHERE email_verification_token = ? LIMIT 1');
    $stmt->execute([$token]);
    $user = $stmt->fetch();

    if (!$user) {
        verifyResponse([
            'ok' => false,
            'error' => 'Invalid or expired verification link. Please request a new one.'
        ], 404);
    }

    if (!empty($user['email_verification_expires_at'])) {
        $expires = strtotime((string) $user['email_verification_expires_at']);
        if ($expires !== false && $expires < time()) {
            verifyResponse([
                'ok' => false,
                'expired' => true,
                'email' => $user['email'],
                'error' => 'This verification link has expired. Please request a new verification link.'
            ], 410);
        }
    }

    $update = $db->prepare('UPDATE users SET email_verified = 1, email_verification_token = NULL, email_verification_expires_at = NULL WHERE id = ?');
    $update->execute([$user['id']]);

    verifyResponse([
        'ok' => true,
        'message' => 'Email verified successfully! You can now sign in.',
        'email' => $user['email']
    ], 200);

} catch (Throwable $e) {
    error_log('[VERIFY EMAIL ERROR] ' . $e->getMessage());
    verifyResponse([
        'ok' => false,
        'error' => 'A server error occurred while verifying your email.'
    ], 500);
}
