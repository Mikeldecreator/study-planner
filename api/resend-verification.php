<?php

declare(strict_types=1);

ob_start();

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');

function resendResponse(array $data, int $status = 200): never {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    resendResponse(['ok' => false, 'error' => 'Method not allowed.'], 405);
}

$raw = file_get_contents('php://input');
$body = $raw ? json_decode($raw, true) : null;
$email = strtolower(trim((string) ($body['email'] ?? '')));

if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    resendResponse(['ok' => false, 'error' => 'Please provide a valid email address.'], 422);
}

// Rate limiting (max 3 resend requests per 10 minutes)
$clientIp = getClientIp();
$rateId = $clientIp . '|' . $email;
if (isRateLimited('resend_verification', $rateId, 3)) {
    resendResponse([
        'ok' => false,
        'error' => 'Too many requests. Please wait 10 minutes before requesting another verification email.'
    ], 429);
}

try {
    $db = getDb();
    $stmt = $db->prepare('SELECT id, full_name, email_verified FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if (!$user) {
        // Return success to avoid email enumeration
        resendResponse([
            'ok' => true,
            'message' => 'If an unverified account exists for that email, a verification link has been sent.'
        ], 200);
    }

    if (isset($user['email_verified']) && (int) $user['email_verified'] === 1) {
        resendResponse([
            'ok' => true,
            'already_verified' => true,
            'message' => 'Your email address is already verified. You can sign in now.'
        ], 200);
    }

    recordRateLimitHit('resend_verification', $rateId, 600);

    $token = bin2hex(random_bytes(32));
    $expiresAt = date('Y-m-d H:i:s', time() + 86400);

    $up = $db->prepare('UPDATE users SET email_verification_token = ?, email_verification_expires_at = ? WHERE id = ?');
    $up->execute([$token, $expiresAt, $user['id']]);

    require_once __DIR__ . '/../cron/Mailer.php';
    $baseUrl = defined('APP_URL') ? rtrim(APP_URL, '/') : 'https://study-planner-gf2i.onrender.com';
    $verifyUrl = $baseUrl . '/verify-email.php?token=' . urlencode($token);
    $mailSent = sendVerificationEmail($email, (string) $user['full_name'], $verifyUrl);

    $resPayload = [
        'ok' => true,
        'message' => 'A new verification link has been sent to your email. Please check your inbox.',
        'mail_sent' => $mailSent
    ];
    if ((defined('APP_DEBUG') && APP_DEBUG) || !$mailSent) {
        $resPayload['verify_url'] = $verifyUrl;
        $resPayload['token'] = $token;
    }

    resendResponse($resPayload, 200);

} catch (Throwable $e) {
    error_log('[RESEND VERIFICATION ERROR] ' . $e->getMessage());
    resendResponse(['ok' => false, 'error' => 'Unable to send verification email. Please try again.'], 500);
}
