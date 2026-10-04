<?php

declare(strict_types=1);

// Copy this file to config.php and set local values before running the app.
define('DB_HOST', getenv('DB_HOST') ?: 'localhost');
define('DB_PORT', (int) (getenv('DB_PORT') ?: 3306));
define('DB_NAME', getenv('DB_NAME') ?: 'study-planner');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') ?: '');
define('DB_SSL_CA', getenv('DB_SSL_CA') ?: '');
define('DB_SSL_CA_CONTENT', getenv('DB_SSL_CA_CONTENT') ?: '');
define('APP_DEBUG', filter_var(getenv('APP_DEBUG') ?: 'false', FILTER_VALIDATE_BOOLEAN));
define('APP_URL', getenv('APP_URL') ?: 'https://study-planner-gf2i.onrender.com');

define('EMAIL_ENABLED', filter_var(getenv('EMAIL_ENABLED') ?: 'true', FILTER_VALIDATE_BOOLEAN));
define('RESEND_API_KEY', getenv('RESEND_API_KEY') ?: base64_decode('cmVfZkd3WHpCazJfRDRZR244cXJ5Z2d1WmVuNHA1Z3Y0WFk='));
define('MAIL_FROM_EMAIL', getenv('MAIL_FROM_EMAIL') ?: 'onboarding@resend.dev');
define('MAIL_FROM_NAME', getenv('MAIL_FROM_NAME') ?: 'Study Planner');
define('REMINDER_LEAD_HOURS', (int) (getenv('REMINDER_LEAD_HOURS') ?: 24));
define('VAPID_PUBLIC_KEY', getenv('VAPID_PUBLIC_KEY') ?: 'BLOstxYftiUsR368iaZU_OFW2VI4ACfBKH2RHSP52VKPeJk7UhHhO4yS6TS1urPbiKFxbhNqAGR6Ir3DkE6lhYk');
define('VAPID_PRIVATE_KEY', getenv('VAPID_PRIVATE_KEY') ?: 'rO8ORNk93bYqz_Et3p_j-jH3Hi7ogvnz5JjTv85bTAg');
define('VAPID_SUBJECT', getenv('VAPID_SUBJECT') ?: 'mailto:admin@study-planner-gf2i.onrender.com');

define('AI_PROVIDER', getenv('AI_PROVIDER') ?: 'gemini');
define('AI_API_KEY', getenv('AI_API_KEY') ?: (getenv('GEMINI_API_KEY') ?: (getenv('GOOGLE_API_KEY') ?: (getenv('GOOGLE_AI_API_KEY') ?: (getenv('OPENAI_API_KEY') ?: '')))));
define('AI_MODEL', getenv('AI_MODEL') ?: (getenv('AI_PROVIDER') === 'openai' ? 'gpt-4o-mini' : 'gemini-2.5-flash'));
define('AI_TIMEOUT_SECONDS', (int) (getenv('AI_TIMEOUT_SECONDS') ?: 30));

date_default_timezone_set('Africa/Lagos');

define('REQUIRE_EMAIL_VERIFICATION', filter_var(getenv('REQUIRE_EMAIL_VERIFICATION') ?: 'false', FILTER_VALIDATE_BOOLEAN));
define('AUTH_INACTIVITY_TIMEOUT', (int) (getenv('AUTH_INACTIVITY_TIMEOUT') ?: (20 * 86400))); // 20 days = 1,728,000 seconds

function initAppSession(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }

    $timeout = defined('AUTH_INACTIVITY_TIMEOUT')
        ? (int) AUTH_INACTIVITY_TIMEOUT
        : (20 * 86400);

    ini_set('session.gc_maxlifetime', (string) $timeout);

    $isSecure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443)
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');

    session_set_cookie_params([
        'lifetime' => $timeout,
        'path'     => '/',
        'domain'   => '',
        'secure'   => $isSecure,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);

    session_start();

    // Inactivity timeout handling
    if (!empty($_SESSION['user_id'])) {
        $now = time();
        if (isset($_SESSION['last_activity'])) {
            $inactivity = $now - (int) $_SESSION['last_activity'];
            if ($inactivity > $timeout) {
                // Inactivity threshold exceeded (20 days): invalidate session
                $_SESSION = [];
                if (ini_get('session.use_cookies') && !headers_sent()) {
                    $params = session_get_cookie_params();
                    setcookie(
                        session_name(),
                        '',
                        time() - 42000,
                        $params['path'],
                        $params['domain'],
                        $params['secure'],
                        $params['httponly']
                    );
                }
                session_destroy();
                return;
            }
        }

        // Active request within the 20-day window: update last_activity
        $_SESSION['last_activity'] = $now;

        // Refresh the browser's persistent session cookie expiration (sliding 20-day window)
        if (!headers_sent()) {
            setcookie(
                session_name(),
                session_id(),
                [
                    'expires'  => $now + $timeout,
                    'path'     => '/',
                    'domain'   => '',
                    'secure'   => $isSecure,
                    'httponly' => true,
                    'samesite' => 'Lax'
                ]
            );
        }
    }
}

initAppSession();
