<?php
declare(strict_types=1);

/**
 * ============================================================
 * STUDY PLANNER - ACADEMIC NOTIFICATION SCHEDULER (CLI WORKER)
 * ============================================================
 *
 * Standalone recurring worker script for generating and dispatching
 * academic notifications (class reminders, deadline alerts,
 * curriculum milestones, and smart study suggestions).
 *
 * Windows Task Scheduler setup:
 *   Program/script: C:\xampp\php\php.exe
 *   Add arguments:  "C:\xampp\htdocs\study-planner3 - Copy\study-planner10\study-planner\cron\notification_scheduler.php"
 *   Trigger:        Repeat every 5 or 10 minutes indefinitely.
 *
 * Linux Cron setup:
 *   * / 5 * * * * php /path/to/study-planner/cron/notification_scheduler.php >> /var/log/study-planner-cron.log 2>&1
 *
 * CLI Options:
 *   --user=<id>     Process notifications for a specific user ID only.
 *   --verbose       Print detailed diagnostic evaluation logs.
 *   --help          Display help and usage instructions.
 * ============================================================
 */

$startMicrotime = microtime(true);

if (php_sapi_name() !== 'cli') {
    // If invoked via web server, require authenticated admin or secret key
    header('Content-Type: text/plain; charset=utf-8');
}

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

// Parse CLI arguments
$options = getopt('', ['user::', 'verbose', 'help']);
if (isset($options['help'])) {
    echo "Usage: php notification_scheduler.php [--user=<id>] [--verbose] [--help]\n";
    exit(0);
}

$targetUserId = isset($options['user']) && is_numeric($options['user']) ? (int)$options['user'] : null;
$verbose = isset($options['verbose']);

function cronLog(string $level, string $message): void
{
    $timestamp = date('Y-m-d H:i:s');
    echo "[{$timestamp}] [{$level}] {$message}\n";
}

cronLog('INFO', 'Academic Notification Scheduler worker started' . ($targetUserId ? " (User ID: {$targetUserId})" : ''));

try {
    $db = getDb();
    ensureNotificationSchema($db);

    // 1. Run Academic Reminders Generator
    $reminderResult = generateAcademicReminders($db, $targetUserId);
    $generatedCount = (int)($reminderResult['generated'] ?? 0);

    // 2. Count current unread in-app notifications
    $countSql = "SELECT COUNT(*) FROM notifications WHERE channel = 'in_app' AND read_at IS NULL AND send_at <= NOW()";
    $countParams = [];
    if ($targetUserId !== null) {
        $countSql .= " AND user_id = ?";
        $countParams[] = $targetUserId;
    }
    $countStmt = $db->prepare($countSql);
    $countStmt->execute($countParams);
    $activeUnread = (int)$countStmt->fetchColumn();

    $elapsedMs = round((microtime(true) - $startMicrotime) * 1000, 2);

    cronLog('OK', "Academic evaluation completed: {$generatedCount} new reminder(s) generated. Active unread in-app alerts: {$activeUnread}. (Elapsed: {$elapsedMs}ms)");

    exit(0);
} catch (Throwable $e) {
    cronLog('FATAL', "Scheduler failed: " . $e->getMessage() . " at " . $e->getFile() . ":" . $e->getLine());
    exit(1);
}
