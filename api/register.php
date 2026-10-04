<?php

declare(strict_types=1);

/*
 * Start output buffering BEFORE loading application files.
 * This prevents accidental PHP output from corrupting JSON.
 */
ob_start();

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');


/**
 * Always return clean JSON.
 */
function registerResponse(
    array $data,
    int $status = 200
): never {

    if (ob_get_level() > 0) {
        ob_clean();
    }

    http_response_code($status);

    echo json_encode(
        $data,
        JSON_UNESCAPED_SLASHES |
        JSON_UNESCAPED_UNICODE
    );

    exit;
}


// ============================================================
// REQUEST METHOD
// ============================================================

if (
    ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST'
) {
    registerResponse(
        [
            'ok' => false,
            'error' => 'Method not allowed.'
        ],
        405
    );
}


// ============================================================
// READ JSON
// ============================================================

try {

    $rawBody = file_get_contents('php://input');

    if (
        $rawBody === false ||
        trim($rawBody) === ''
    ) {
        registerResponse(
            [
                'ok' => false,
                'error' => 'Please complete all required fields.'
            ],
            422
        );
    }


    $body = json_decode(
        $rawBody,
        true
    );


    if (
        !is_array($body)
    ) {
        registerResponse(
            [
                'ok' => false,
                'error' => 'Invalid request data.'
            ],
            400
        );
    }


    // ========================================================
    // FORM VALUES
    // ========================================================

    $name = trim(
        (string) (
            $body['full_name'] ??
            $body['name'] ??
            ''
        )
    );


    $email = strtolower(
        trim(
            (string) (
                $body['email'] ??
                ''
            )
        )
    );


    $password = (string) (
        $body['password'] ??
        ''
    );


    // ========================================================
    // VALIDATION
    // ========================================================

    if (
        $name === '' ||
        $email === '' ||
        $password === ''
    ) {
        registerResponse(
            [
                'ok' => false,
                'error' => 'All fields are required.'
            ],
            422
        );
    }


    if (
        !filter_var(
            $email,
            FILTER_VALIDATE_EMAIL
        )
    ) {
        registerResponse(
            [
                'ok' => false,
                'error' => 'Please enter a valid email address.'
            ],
            422
        );
    }

    // Strict domain syntax and DNS check
    $domain = substr(strrchr($email, '@') ?: '', 1);
    if ($domain === '' || (!checkdnsrr($domain, 'MX') && !checkdnsrr($domain, 'A'))) {
        registerResponse(
            [
                'ok' => false,
                'error' => 'Email domain is invalid or does not have mail records. Please use a valid email address.'
            ],
            422
        );
    }


    if (
        strlen($password) < 8
    ) {
        registerResponse(
            [
                'ok' => false,
                'error' =>
                    'Password must be at least 8 characters.'
            ],
            422
        );
    }


    // ========================================================
    // DATABASE
    // ========================================================

    $db = getDb();


    // ========================================================
    // CHECK EXISTING EMAIL
    // ========================================================

    $stmt = $db->prepare(
        'SELECT id, full_name, email_verified
         FROM users
         WHERE email = ?
         LIMIT 1'
    );


    $stmt->execute([
        $email
    ]);

    $existingUser = $stmt->fetch();

    if ($existingUser) {
        $requireVerification = defined('REQUIRE_EMAIL_VERIFICATION')
            ? (bool) REQUIRE_EMAIL_VERIFICATION
            : false;

        // If verification is disabled or user is already verified, direct to login
        if (!$requireVerification || (isset($existingUser['email_verified']) && (int) $existingUser['email_verified'] === 1)) {
            registerResponse(
                [
                    'ok' => false,
                    'error' =>
                        'An account with this email already exists. Please sign in instead.'
                ],
                409
            );
        }

        // If unverified, regenerate token, resend verification email
        $rawToken = bin2hex(random_bytes(32));
        $tokenHash = hash('sha256', $rawToken);
        $expiresAt = date('Y-m-d H:i:s', time() + 86400);

        try {
            $upStmt = $db->prepare(
                'UPDATE users
                 SET email_verification_token = ?,
                     email_verification_expires_at = ?
                 WHERE id = ?'
            );
            $upStmt->execute([$tokenHash, $expiresAt, $existingUser['id']]);
        } catch (Throwable $e) {}

        require_once __DIR__ . '/../cron/Mailer.php';
        $baseUrl = defined('APP_URL') ? rtrim(APP_URL, '/') : 'https://study-planner-gf2i.onrender.com';
        $verifyUrl = $baseUrl . '/verify-email.php?token=' . urlencode($rawToken);
        $mailDetails = [];
        $mailSent = sendVerificationEmail(
            $email,
            (string) ($existingUser['full_name'] ?: $name),
            $verifyUrl,
            $mailDetails
        );

        if (!$mailSent) {
            $errorMsg = 'An unverified account exists with this email, but we could not deliver the verification email. ';
            if (!empty($mailDetails['error_message'])) {
                $errorMsg .= $mailDetails['error_message'] . ' ';
            }
            $errorMsg .= 'Please try resending later.';

            registerResponse(
                [
                    'ok' => false,
                    'requires_verification' => true,
                    'mail_sent' => false,
                    'email' => $email,
                    'error' => trim($errorMsg)
                ],
                502
            );
        }

        registerResponse(
            [
                'ok' => true,
                'requires_verification' => true,
                'mail_sent' => true,
                'message' =>
                    'An unverified account already exists with this email. A fresh verification link has been sent to your inbox.',
                'email' => $email
            ],
            200
        );
    }


    // ========================================================
    // CREATE USER WITH HASHED VERIFICATION TOKEN
    // ========================================================

    $rawToken = bin2hex(random_bytes(32));
    $tokenHash = hash('sha256', $rawToken);
    $expiresAt = date('Y-m-d H:i:s', time() + 86400);

    $result = registerUser(
        $name,
        $email,
        $password,
        0, // email_verified = 0
        $tokenHash,
        $expiresAt
    );


    if (
        !is_array($result) ||
        !($result['ok'] ?? false)
    ) {
        registerResponse(
            [
                'ok' => false,
                'error' =>
                    $result['error'] ??
                    'Could not create your account.'
            ],
            422
        );
    }


    // ========================================================
    // EMAIL VERIFICATION HANDLING (RESPECTS FEATURE FLAG)
    // ========================================================

    $requireVerification = defined('REQUIRE_EMAIL_VERIFICATION')
        ? (bool) REQUIRE_EMAIL_VERIFICATION
        : false;

    $newUserId = (int) ($result['id'] ?? 0);

    if (!$requireVerification) {
        // PRESENTATION MODE: Immediate Login & Access
        // Attempt background email delivery non-blockingly if email is enabled
        if (defined('EMAIL_ENABLED') && EMAIL_ENABLED) {
            try {
                require_once __DIR__ . '/../cron/Mailer.php';
                $baseUrl = defined('APP_URL') ? rtrim(APP_URL, '/') : 'https://study-planner-gf2i.onrender.com';
                $verifyUrl = $baseUrl . '/verify-email.php?token=' . urlencode($rawToken);
                $mailDetails = [];
                sendVerificationEmail($email, $name, $verifyUrl, $mailDetails);
            } catch (Throwable $e) {
                // Email delivery failure must NOT block registration when verification is not required
            }
        }

        // Establish authenticated session with 20-day persistent cookie
        if ($newUserId > 0) {
            session_regenerate_id(true);
            $_SESSION['user_id']       = $newUserId;
            $_SESSION['user_name']     = $name;
            $_SESSION['last_activity'] = time();
            ensureUserDataSeeded($newUserId);

            $timeout = defined('AUTH_INACTIVITY_TIMEOUT') ? (int) AUTH_INACTIVITY_TIMEOUT : (20 * 86400);
            $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
                || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443)
                || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

            if (!headers_sent()) {
                setcookie(
                    session_name(),
                    session_id(),
                    [
                        'expires'  => time() + $timeout,
                        'path'     => '/',
                        'domain'   => '',
                        'secure'   => $isSecure,
                        'httponly' => true,
                        'samesite' => 'Lax'
                    ]
                );
            }
        }

        registerResponse([
            'ok' => true,
            'requires_verification' => false,
            'redirect' => './onboarding.php',
            'message' => 'Account created successfully! Welcome to Study Planner.',
            'email' => $email
        ], 200);
    }

    // ========================================================
    // MANDATORY EMAIL VERIFICATION MODE (STRICT / PRODUCTION)
    // ========================================================

    require_once __DIR__ . '/../cron/Mailer.php';
    $baseUrl = defined('APP_URL') ? rtrim(APP_URL, '/') : 'https://study-planner-gf2i.onrender.com';
    $verifyUrl = $baseUrl . '/verify-email.php?token=' . urlencode($rawToken);
    $mailDetails = [];
    $mailSent = sendVerificationEmail($email, $name, $verifyUrl, $mailDetails);

    if (!$mailSent) {
        $errorMsg = 'Account created, but we could not deliver your verification email. ';
        if (!empty($mailDetails['error_message'])) {
            $errorMsg .= $mailDetails['error_message'] . ' ';
        }
        $errorMsg .= 'Your account remains unverified. Please try resending the verification email.';

        registerResponse([
            'ok' => false,
            'requires_verification' => true,
            'mail_sent' => false,
            'email' => $email,
            'error' => trim($errorMsg)
        ], 502);
    }

    // ========================================================
    // SUCCESS (VERIFICATION PENDING - NEVER EXPOSES TOKENS/URLS)
    // ========================================================

    registerResponse([
        'ok' => true,
        'requires_verification' => true,
        'mail_sent' => true,
        'message' =>
            'Account created! Please check your email to verify your account before signing in.',
        'email' => $email
    ], 200);


} catch (
    PDOException $e
) {

    error_log(
        '[REGISTER DB ERROR] ' .
        $e->getMessage()
    );


    registerResponse(
        [
            'ok' => false,
            'error' =>
                'The database is currently unavailable. Please try again.'
        ],
        503
    );


} catch (
    Throwable $e
) {

    error_log(
        '[REGISTER ERROR] ' .
        $e->getMessage()
    );


    registerResponse(
        [
            'ok' => false,
            'error' =>
                'Unable to create the account right now. Please try again.'
        ],
        500
    );
}