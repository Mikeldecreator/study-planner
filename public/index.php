<?php
declare(strict_types=1);

/**
 * Application Entry Point / Root Router
 * Automatically directs users to dashboard if authenticated, or login page otherwise.
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!empty($_SESSION['user_id'])) {
    header('Location: dashboard.php');
    exit;
}

header('Location: login.php');
exit;
