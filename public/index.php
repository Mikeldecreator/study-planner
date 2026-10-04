<?php
declare(strict_types=1);

/**
 * Application Entry Point / Root Router
 * Automatically directs users to dashboard if authenticated, or login page otherwise.
 */

require_once __DIR__ . '/../config/config.php';

if (function_exists('initAppSession')) {
    initAppSession();
} elseif (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!empty($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

header('Location: login.php');
exit;
