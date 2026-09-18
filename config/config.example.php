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
define('APP_URL', getenv('APP_URL') ?: 'http://localhost');

define('EMAIL_ENABLED', filter_var(getenv('EMAIL_ENABLED') ?: 'false', FILTER_VALIDATE_BOOLEAN));
define('RESEND_API_KEY', getenv('RESEND_API_KEY') ?: '');
define('MAIL_FROM_EMAIL', getenv('MAIL_FROM_EMAIL') ?: 'onboarding@resend.dev');
define('MAIL_FROM_NAME', getenv('MAIL_FROM_NAME') ?: 'Study Planner');
define('REMINDER_LEAD_HOURS', (int) (getenv('REMINDER_LEAD_HOURS') ?: 24));
define('VAPID_PUBLIC_KEY', getenv('VAPID_PUBLIC_KEY') ?: '');
define('VAPID_PRIVATE_KEY', getenv('VAPID_PRIVATE_KEY') ?: '');
define('VAPID_SUBJECT', getenv('VAPID_SUBJECT') ?: 'mailto:you@example.com');
date_default_timezone_set('Africa/Lagos');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
