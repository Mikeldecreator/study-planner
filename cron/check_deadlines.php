<?php

declare(strict_types=1);

/**
 * ============================================================
 * STUDY PLANNER - DEADLINE CRON (BACKWARD COMPATIBLE ENTRY POINT)
 * ============================================================
 *
 * This script serves as the unified entry point for manual or CLI invocation:
 * 1. Ensures push tables and schema.
 * 2. Runs academic reminder evaluation across all users.
 * 3. Delegates push delivery to dispatchPendingPushes().
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
   EXECUTE REMINDER EVALUATION & FAST PUSH DISPATCH
   ============================================================ */

ensureNotificationSchema($db);
generateAcademicReminders($db);
$res = dispatchPendingPushes($db);

/* ============================================================
   OUTPUT (LEGACY FORMAT COMPATIBLE)
   ============================================================ */

echo
    date('Y-m-d H:i:s') .
    ' — notifications checked: ' .
    ($res['checked'] ?? 0) .
    ', processed: ' .
    ($res['processed'] ?? 0) .
    ', failed: ' .
    ($res['failed'] ?? 0) .
    ', browser pushes sent: ' .
    ($res['push_sent'] ?? 0) .
    ', skipped: ' .
    ($res['push_skipped'] ?? 0) .
    ', expired subscriptions removed: ' .
    ($res['push_expired'] ?? 0) .
    ', push errors: ' .
    ($res['push_errors'] ?? 0) .
    PHP_EOL;