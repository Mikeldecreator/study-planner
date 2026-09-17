<?php

declare(strict_types=1);

// Copy this file to config.php and set local values before running the app.
define('DB_HOST', 'localhost');
define('DB_NAME', 'study-planner');
define('DB_USER', 'root');
define('DB_PASS', '');
define('APP_DEBUG', false);
define('APP_URL', 'http://localhost/study-planner/public');
define('EMAIL_ENABLED', false);
define('RESEND_API_KEY', '');
define('MAIL_FROM_EMAIL', 'onboarding@resend.dev');
define('MAIL_FROM_NAME', 'Study Planner');
define('REMINDER_LEAD_HOURS', 24);
define('VAPID_PUBLIC_KEY', '');
define('VAPID_PRIVATE_KEY', '');
define('VAPID_SUBJECT', 'mailto:you@example.com');
date_default_timezone_set('Africa/Lagos');

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
