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
   PROCESS NORMAL NOTIFICATIONS
   ============================================================ */

$notificationStmt = $db->query("
    SELECT
        n.id,
        n.user_id,
        n.channel,
        n.message,
        n.send_at,
        u.email,
        u.full_name
    FROM notifications n
    INNER JOIN users u
        ON u.id = n.user_id
    WHERE n.sent_at IS NULL
      AND n.send_at <= NOW()
    ORDER BY n.send_at ASC, n.id ASC
");

$pendingNotifications = $notificationStmt->fetchAll();

$processedNotifications = 0;
$failedNotifications = 0;


/* ============================================================
   PROCESS EMAIL / IN-APP
   ============================================================ */

$markNotificationStmt = $db->prepare("
    UPDATE notifications
    SET sent_at = NOW()
    WHERE id = ?
");


foreach ($pendingNotifications as $notification) {

    $channel = (string) $notification['channel'];

    /*
     * EMAIL
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
     * IN-APP
     *
     * The notification already exists in the database and is
     * available to the bell/notifications page.
     */
    $markNotificationStmt->execute([
        (int) $notification['id']
    ]);

    $processedNotifications++;
}


/* ============================================================
   BROWSER PUSH REMINDERS
   ============================================================ */

$pushProcessed = 0;
$pushSent = 0;
$pushSkipped = 0;
$pushExpired = 0;
$pushErrors = 0;


try {

    /*
     * Daily reminder hour.
     *
     * Default = 08:00 Lagos time.
     *
     * Task Scheduler can run every 15 minutes, but a reminder
     * will only be created after this hour.
     */
    $dailyPushHour = defined('DAILY_PUSH_REMINDER_HOUR')
        ? (int) DAILY_PUSH_REMINDER_HOUR
        : 8;


    $currentHour = (int) date('G');


    if ($currentHour >= $dailyPushHour) {

        /*
         * Get unfinished tasks whose deadline has not passed.
         */
        $taskStmt = $db->query("
            SELECT
                t.id AS task_id,
                t.user_id,
                t.title,
                t.due_at,
                t.status,

                c.code AS course_code,

                u.full_name

            FROM tasks t

            INNER JOIN users u
                ON u.id = t.user_id

            LEFT JOIN courses c
                ON c.id = t.course_id
                AND c.user_id = t.user_id

            WHERE t.status <> 'completed'

              AND t.due_at >= NOW()

              AND u.notifications_enabled = 1

            ORDER BY
                t.due_at ASC,
                t.id ASC
        ");

        $tasks = $taskStmt->fetchAll();


        /*
         * Prepared statements used repeatedly.
         */

        $alreadySentStmt = $db->prepare("
            SELECT id
            FROM push_daily_reminders
            WHERE user_id = ?
              AND task_id = ?
              AND reminder_date = CURDATE()
            LIMIT 1
        ");


        $insertDailyStmt = $db->prepare("
            INSERT IGNORE INTO push_daily_reminders
                (
                    user_id,
                    task_id,
                    reminder_date
                )
            VALUES
                (
                    ?,
                    ?,
                    CURDATE()
                )
        ");


        $subscriptionStmt = $db->prepare("
            SELECT
                id,
                endpoint,
                p256dh,
                auth,
                content_encoding,
                expiration_time

            FROM browser_push_subscriptions

            WHERE user_id = ?

            ORDER BY id DESC
        ");


        $deleteSubscriptionStmt = $db->prepare("
            DELETE FROM browser_push_subscriptions
            WHERE id = ?
        ");


        /*
         * Process every unfinished task.
         */

        foreach ($tasks as $task) {

            $taskId = (int) $task['task_id'];
            $userId = (int) $task['user_id'];


            /*
             * Prevent duplicate reminders on the same day.
             */

            $alreadySentStmt->execute([
                $userId,
                $taskId
            ]);


            if ($alreadySentStmt->fetchColumn()) {
                $pushSkipped++;
                continue;
            }


            /*
             * Calculate days remaining.
             */

            $today = new DateTimeImmutable(
                date('Y-m-d'),
                new DateTimeZone('Africa/Lagos')
            );


            $dueDate = new DateTimeImmutable(
                date('Y-m-d', strtotime((string) $task['due_at'])),
                new DateTimeZone('Africa/Lagos')
            );


            $daysLeft = (int) $today
                ->diff($dueDate)
                ->format('%r%a');


            /*
             * Safety check.
             */
            if ($daysLeft < 0) {
                $pushSkipped++;
                continue;
            }


            /*
             * Build reminder wording.
             */

            if ($daysLeft === 0) {

                $whenText = 'is due today';

            } elseif ($daysLeft === 1) {

                $whenText = 'is due tomorrow';

            } else {

                $whenText =
                    'is due in ' .
                    $daysLeft .
                    ' days';
            }


            $message =
                (string) $task['title'] .
                ' ' .
                $whenText .
                '.';


            if (
                isset($task['course_code']) &&
                trim((string) $task['course_code']) !== ''
            ) {

                $message .=
                    ' Course: ' .
                    trim((string) $task['course_code']) .
                    '.';
            }


            /*
             * Get all browser subscriptions for this user.
             */

            $subscriptionStmt->execute([
                $userId
            ]);

            $subscriptions =
                $subscriptionStmt->fetchAll();


            /*
             * No browser subscription.
             */

            if (!$subscriptions) {
                $pushSkipped++;
                continue;
            }


            $successForTask = false;


            /*
             * Send to every registered browser.
             */

            foreach ($subscriptions as $subscription) {

                try {

                    $result = webPushSend(
                        $subscription,
                        [
                            'title' =>
                                'Study Planner — Deadline Reminder',

                            'body' =>
                                $message,

                            'tag' =>
                                'deadline-task-' .
                                $taskId,

                            'renotify' =>
                                true,

                            'data' => [
                                'url' =>
                                    APP_URL .
                                    '/deadlines.php',

                                'task_id' =>
                                    $taskId
                            ]
                        ]
                    );


                    if (
                        isset($result['success']) &&
                        $result['success'] === true
                    ) {

                        $successForTask = true;
                        $pushSent++;
                    }


                } catch (Throwable $pushError) {

                    $pushErrors++;


                    /*
                     * A 404 or 410 normally means the push
                     * subscription is no longer valid.
                     */
                    $errorMessage =
                        strtolower(
                            $pushError->getMessage()
                        );


                    if (
                        str_contains(
                            $errorMessage,
                            'http 404'
                        ) ||
                        str_contains(
                            $errorMessage,
                            'http 410'
                        ) ||
                        str_contains(
                            $errorMessage,
                            'returned http 404'
                        ) ||
                        str_contains(
                            $errorMessage,
                            'returned http 410'
                        )
                    ) {

                        $deleteSubscriptionStmt->execute([
                            (int) $subscription['id']
                        ]);

                        $pushExpired++;
                    }


                    error_log(
                        'Study Planner push error: ' .
                        $pushError->getMessage()
                    );
                }
            }


            /*
             * Only mark the task as reminded after at least
             * one browser accepted the push.
             */

            if ($successForTask) {

                $insertDailyStmt->execute([
                    $userId,
                    $taskId
                ]);
            }
        }
    }

} catch (Throwable $pushFatalError) {

    error_log(
        'Study Planner browser reminder fatal error: ' .
        $pushFatalError->getMessage()
    );
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