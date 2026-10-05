<?php

declare(strict_types=1);

/**
 * ============================================================
 * STUDY PLANNER - DEADLINE CRON
 * ============================================================
 *
 * Run this file from Windows Task Scheduler using:
 *
 * C:\xampp\php\php.exe
 *
 * Arguments:
 *
 * "C:\xampp\htdocs\study-planner10\study-planner\cron\check_deadlines.php"
 *
 * This script:
 *
 * 1. Processes pending email/in-app notifications.
 * 2. Checks unfinished tasks.
 * 3. Sends one browser reminder per task per day.
 * 4. Does not remind completed tasks.
 * 5. Removes expired browser subscriptions.
 *
 * ============================================================
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/Mailer.php';
require_once __DIR__ . '/../includes/webpush.php';


/* ============================================================
   DATABASE
   ============================================================ */

$db = getDb();


/* ============================================================
   CREATE PUSH TABLES IF THEY DO NOT EXIST
   ============================================================ */

$db->exec("
    CREATE TABLE IF NOT EXISTS browser_push_subscriptions (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id INT NOT NULL,
        endpoint TEXT NOT NULL,
        endpoint_hash CHAR(64) NOT NULL,
        p256dh TEXT NOT NULL,
        auth TEXT NOT NULL,
        content_encoding VARCHAR(50) NOT NULL DEFAULT 'aes128gcm',
        expiration_time BIGINT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
            ON UPDATE CURRENT_TIMESTAMP,

        PRIMARY KEY (id),

        UNIQUE KEY uq_browser_push_endpoint_hash (
            endpoint_hash
        ),

        KEY idx_browser_push_user_id (
            user_id
        ),

        CONSTRAINT fk_browser_push_user
            FOREIGN KEY (user_id)
            REFERENCES users(id)
            ON DELETE CASCADE
    )
    ENGINE=InnoDB
    DEFAULT CHARSET=utf8mb4
    COLLATE=utf8mb4_unicode_ci
");


$db->exec("
    CREATE TABLE IF NOT EXISTS push_daily_reminders (
        id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
        user_id INT NOT NULL,
        task_id INT NOT NULL,
        reminder_date DATE NOT NULL,
        created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,

        PRIMARY KEY (id),

        UNIQUE KEY uq_push_daily_task (
            user_id,
            task_id,
            reminder_date
        ),

        KEY idx_push_daily_user (
            user_id
        ),

        KEY idx_push_daily_task (
            task_id
        ),

        CONSTRAINT fk_push_daily_user
            FOREIGN KEY (user_id)
            REFERENCES users(id)
            ON DELETE CASCADE,

        CONSTRAINT fk_push_daily_task
            FOREIGN KEY (task_id)
            REFERENCES tasks(id)
            ON DELETE CASCADE
    )
    ENGINE=InnoDB
    DEFAULT CHARSET=utf8mb4
    COLLATE=utf8mb4_unicode_ci
");

$db->exec("
    CREATE TABLE IF NOT EXISTS browser_push_dead_endpoints (
        endpoint_hash CHAR(64) NOT NULL PRIMARY KEY,
        user_id INT NOT NULL,
        expired_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
        KEY idx_dead_user_id (user_id)
    )
    ENGINE=InnoDB
    DEFAULT CHARSET=utf8mb4
    COLLATE=utf8mb4_unicode_ci
");

$db->exec("
    CREATE TABLE IF NOT EXISTS system_heartbeats (
        service_name VARCHAR(50) NOT NULL PRIMARY KEY,
        last_run_at  DATETIME NOT NULL,
        status       VARCHAR(20) NOT NULL DEFAULT 'ok',
        meta_json    TEXT NULL
    )
    ENGINE=InnoDB
    DEFAULT CHARSET=utf8mb4
    COLLATE=utf8mb4_unicode_ci
");


/* ============================================================
   PROCESS PENDING NOTIFICATIONS & SMART DELIVERY
   ============================================================ */

ensureNotificationSchema($db);
generateAcademicReminders($db);

$notificationStmt = $db->query("
    SELECT
        n.id,
        n.user_id,
        n.task_id,
        n.channel,
        n.event_key,
        n.message,
        n.send_at,
        n.sent_at,
        n.read_at,
        n.push_status,
        u.email,
        u.full_name,
        u.notifications_enabled,
        u.last_active_at
    FROM notifications n
    INNER JOIN users u
        ON u.id = n.user_id
    WHERE (n.sent_at IS NULL AND (n.push_status IS NULL OR n.push_status = 'skipped_active'))
      AND n.send_at <= NOW()
    ORDER BY n.send_at ASC, n.id ASC
");

$pendingNotifications = $notificationStmt->fetchAll(PDO::FETCH_ASSOC);

$processedNotifications = 0;
$failedNotifications = 0;
$pushSent = 0;
$pushSkipped = 0;
$pushExpired = 0;
$pushErrors = 0;

$markSentStmt = $db->prepare("
    UPDATE notifications
    SET sent_at = NOW(), push_status = ?
    WHERE id = ?
");

$updatePushStatusStmt = $db->prepare("
    UPDATE notifications
    SET push_status = ?
    WHERE id = ?
");

$taskStatusStmt = $db->prepare("
    SELECT status FROM tasks WHERE id = ?
");

$userPushStmt = $db->prepare("
    SELECT id, endpoint, p256dh, auth, content_encoding, expiration_time
    FROM browser_push_subscriptions
    WHERE user_id = ?
    ORDER BY id DESC
");

$deleteDeadSubStmt = $db->prepare("
    DELETE FROM browser_push_subscriptions
    WHERE id = ?
");

$recordDeadSubStmt = $db->prepare("
    INSERT INTO browser_push_dead_endpoints (endpoint_hash, user_id, expired_at)
    VALUES (?, ?, NOW())
    ON DUPLICATE KEY UPDATE expired_at = NOW()
");

$checkDailyStmt = $db->prepare("
    SELECT id
    FROM push_daily_reminders
    WHERE user_id = ?
      AND task_id = ?
      AND reminder_date = CURDATE()
    LIMIT 1
");

$insertDailyStmt = $db->prepare("
    INSERT IGNORE INTO push_daily_reminders
        (user_id, task_id, reminder_date)
    VALUES
        (?, ?, CURDATE())
");

foreach ($pendingNotifications as $notification) {
    $userId = (int) $notification['user_id'];
    $notifId = (int) $notification['id'];
    $taskId = !empty($notification['task_id']) ? (int) $notification['task_id'] : null;
    $channel = (string) $notification['channel'];

    // If notifications disabled for this user, mark processed and skip
    if (empty($notification['notifications_enabled'])) {
        $markSentStmt->execute(['disabled', $notifId]);
        $processedNotifications++;
        continue;
    }

    /*
     * 1. EMAIL DELIVERY
     */
    if ($channel === 'email') {
        $emailOk = sendReminderEmail(
            (string) $notification['email'],
            (string) $notification['full_name'],
            'Study Planner reminder',
            (string) $notification['message']
        );

        if (!$emailOk) {
            $failedNotifications++;
            continue;
        }

        $markSentStmt->execute(['sent', $notifId]);
        $processedNotifications++;
        continue;
    }

    /*
     * 2. IN-APP & WEB PUSH DELIVERY
     */
    if ($channel === 'in_app') {
        // Rule 0: Window Expiration Check (prevent stale alerts after event has passed)
        if (isNotificationWindowExpired($db, $notification)) {
            $markSentStmt->execute(['expired', $notifId]);
            $processedNotifications++;
            $pushExpired++;
            continue;
        }

        // Rule A: In-app only notification (tomorrow digest, P3 study suggestions, curriculum alerts)
        if (!isNotificationPushEligible($notification)) {
            $markSentStmt->execute(['in_app_only', $notifId]);
            $processedNotifications++;
            $pushSkipped++;
            continue;
        }

        // Push-eligible notification: check if task is completed or already read
        if (!empty($notification['read_at'])) {
            $markSentStmt->execute(['resolved', $notifId]);
            $processedNotifications++;
            continue;
        }

        $isImminent = false;
        if ($taskId !== null) {
            $taskMetaStmt = $db->prepare("SELECT due_at, status FROM tasks WHERE id = ?");
            $taskMetaStmt->execute([$taskId]);
            $taskMeta = $taskMetaStmt->fetch(PDO::FETCH_ASSOC);
            if ($taskMeta) {
                if ($taskMeta['status'] === 'completed') {
                    $markSentStmt->execute(['resolved', $notifId]);
                    $processedNotifications++;
                    continue;
                }
                if (!empty($taskMeta['due_at'])) {
                    $dueTime = strtotime($taskMeta['due_at']);
                    if ($dueTime <= time() + 1800) {
                        $isImminent = true;
                    }
                }
            }
        } elseif (!empty($notification['event_key']) && (str_contains($notification['event_key'], 'class_') || str_contains($notification['event_key'], 'urgent_') || str_contains($notification['event_key'], 'overdue_'))) {
            $isImminent = true;
        }

        // Rule B: Active user push postponement
        // Active window calibrated to 2 minutes (120s) to avoid delaying pushes by 15 minutes.
        // We postpone at most once: if already 'skipped_active', proceed with push.
        // Imminent events (<= 30 mins or overdue) are never postponed.
        $isActive = isUserRecentlyActive($db, $userId, 2);
        $alreadyPostponed = ($notification['push_status'] === 'skipped_active');

        if ($isActive && !$alreadyPostponed && !$isImminent) {
            $updatePushStatusStmt->execute(['skipped_active', $notifId]);
            $pushSkipped++;
            continue;
        }

        // User is outside the 15-minute active window: check daily reminder deduplication
        $alreadyReminded = false;
        if ($taskId !== null) {
            $checkDailyStmt->execute([$userId, $taskId]);
            if ($checkDailyStmt->fetchColumn()) {
                $alreadyReminded = true;
                $pushSkipped++;
                $markSentStmt->execute(['already_reminded_today', $notifId]);
                $processedNotifications++;
                continue;
            }
        }

        // Check registered browser subscriptions
        try {
            $userPushStmt->execute([$userId]);
            $userSubs = $userPushStmt->fetchAll(PDO::FETCH_ASSOC);

            // Rule C: No browser subscription registered
            if (empty($userSubs)) {
                // Do not fabricate delivery success (sent_at stays NULL); avoid infinite reprocessing
                $updatePushStatusStmt->execute(['no_subscription', $notifId]);
                $pushSkipped++;
                continue;
            }

            // Subscriptions exist: dispatch Web Push
            $pushTag = $taskId !== null
                ? 'deadline-task-' . $taskId
                : 'academic-notif-' . $notifId;

            $pushPayload = [
                'title' => 'Study Planner',
                'body'  => (string) $notification['message'],
                'tag'   => $pushTag,
                'renotify' => true,
                'data'  => [
                    'url' => APP_URL . ($taskId !== null ? '/deadlines.php' : '/notifications.php'),
                    'notification_id' => $notifId,
                    'task_id' => $taskId
                ]
            ];

            $taskPushed = false;
            foreach ($userSubs as $sub) {
                try {
                    $sendRes = webPushSend($sub, $pushPayload);
                    if (!empty($sendRes['success'])) {
                        $pushSent++;
                        $taskPushed = true;
                    }
                } catch (Throwable $pushEx) {
                    $pushErrors++;
                    $err = strtolower($pushEx->getMessage());
                    if (
                        str_contains($err, 'http 404') ||
                        str_contains($err, 'http 410') ||
                        str_contains($err, 'returned http 404') ||
                        str_contains($err, 'returned http 410')
                    ) {
                        $recordDeadSubStmt->execute([hash('sha256', $sub['endpoint']), $userId]);
                        $deleteDeadSubStmt->execute([(int) $sub['id']]);
                        $pushExpired++;
                    }
                }
            }

            // Rule D: Web Push successfully dispatched
            if ($taskPushed) {
                if ($taskId !== null) {
                    $insertDailyStmt->execute([$userId, $taskId]);
                }
                $markSentStmt->execute(['sent', $notifId]);
                $processedNotifications++;
            } else {
                $updatePushStatusStmt->execute(['failed', $notifId]);
                $pushErrors++;
            }
        } catch (Throwable $subEx) {
            error_log('Push delivery error: ' . $subEx->getMessage());
        }
    }
}

/* ============================================================
   SCHEDULER HEARTBEAT OBSERVABILITY
   ============================================================ */

try {
    $heartbeatStmt = $db->prepare("
        INSERT INTO system_heartbeats (service_name, last_run_at, status, meta_json)
        VALUES ('scheduler', NOW(), 'ok', ?)
        ON DUPLICATE KEY UPDATE last_run_at = NOW(), status = VALUES(status), meta_json = VALUES(meta_json)
    ");
    $heartbeatStmt->execute([json_encode([
        'checked' => count($pendingNotifications),
        'processed' => $processedNotifications,
        'failed' => $failedNotifications,
        'push_sent' => $pushSent,
        'push_skipped' => $pushSkipped,
        'push_expired' => $pushExpired,
        'push_errors' => $pushErrors
    ])]);
} catch (Throwable $e) {
    // Non-blocking heartbeat log
}

/* ============================================================
   OUTPUT
   ============================================================ */

echo
    date('Y-m-d H:i:s') .
    ' — notifications checked: ' .
    count($pendingNotifications) .
    ', processed: ' .
    $processedNotifications .
    ', failed: ' .
    $failedNotifications .
    ', browser pushes sent: ' .
    $pushSent .
    ', skipped: ' .
    $pushSkipped .
    ', expired subscriptions removed: ' .
    $pushExpired .
    ', push errors: ' .
    $pushErrors .
    PHP_EOL;