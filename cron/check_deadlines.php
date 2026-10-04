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


/* ============================================================
   PROCESS PENDING NOTIFICATIONS & SMART DELIVERY
   ============================================================ */

ensureNotificationSchema($db);

$notificationStmt = $db->query("
    SELECT
        n.id,
        n.user_id,
        n.task_id,
        n.channel,
        n.event_key,
        n.message,
        n.send_at,
        u.email,
        u.full_name,
        u.notifications_enabled,
        u.last_active_at
    FROM notifications n
    INNER JOIN users u
        ON u.id = n.user_id
    WHERE n.sent_at IS NULL
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

$markNotificationStmt = $db->prepare("
    UPDATE notifications
    SET sent_at = NOW()
    WHERE id = ?
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

    // If notifications disabled for this user, mark sent and skip
    if (empty($notification['notifications_enabled'])) {
        $markNotificationStmt->execute([$notifId]);
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
    }

    /*
     * 2. IN-APP & WEB PUSH DELIVERY
     *
     * The notification row is already available to the in-app bell dropdown.
     * Web Push is delivered ONLY if:
     * - User has NOT been active in the app within the last 15 minutes.
     * - The notification is push-eligible (P1 urgent or P2 daily morning summary).
     */
    if ($channel === 'in_app') {
        $isActive = isUserRecentlyActive($db, $userId, 15);

        if ($isActive) {
            // User was active in the app within the last 15 minutes: suppress background Web Push!
            $pushSkipped++;
        } elseif (!isNotificationPushEligible($notification)) {
            // In-app only notification (digests of tomorrow's deadlines, P3 study suggestions, gaps, etc.)
            $pushSkipped++;
        } else {
            // Check daily push reminder deduplication for task-associated reminders
            $alreadyReminded = false;
            if ($taskId !== null) {
                $checkDailyStmt->execute([$userId, $taskId]);
                if ($checkDailyStmt->fetchColumn()) {
                    $alreadyReminded = true;
                    $pushSkipped++;
                }
            }

            if (!$alreadyReminded) {
                try {
                    $userPushStmt->execute([$userId]);
                    $userSubs = $userPushStmt->fetchAll(PDO::FETCH_ASSOC);

                    if (!empty($userSubs)) {
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
                                    $deleteDeadSubStmt->execute([(int) $sub['id']]);
                                    $pushExpired++;
                                }
                            }
                        }

                        if ($taskPushed && $taskId !== null) {
                            $insertDailyStmt->execute([$userId, $taskId]);
                        }
                    } else {
                        $pushSkipped++;
                    }
                } catch (Throwable $subEx) {
                    error_log('Push delivery error: ' . $subEx->getMessage());
                }
            }
        }
    }

    $markNotificationStmt->execute([$notifId]);
    $processedNotifications++;
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