<?php

declare(strict_types=1);

/**
 * ============================================================
 * STUDY PLANNER - FAST PUSH DISPATCHER CRON
 * File: cron/dispatch_pushes.php
 * ============================================================
 *
 * Lightweight, non-blocking queue processor.
 * Runs on a fast 5-10 second cadence.
 * Queries ONLY indexed pending notifications (sent_at IS NULL AND send_at <= NOW()).
 * Does NOT execute heavy academic reminder generation.
 * ============================================================
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/webpush.php';

// Prevent overlapping concurrent executions
$lockFile = sys_get_temp_dir() . '/study_planner_dispatch_pushes.lock';
$lockFp = fopen($lockFile, 'c');
if (!$lockFp || !flock($lockFp, LOCK_EX | LOCK_NB)) {
    // Another instance is already running
    exit(0);
}

$db = getDb();
$t0 = microtime(true);
$res = dispatchPendingPushes($db);
$durationMs = (int)round((microtime(true) - $t0) * 1000);

// Record heartbeat observability for scheduler_dispatcher
try {
    $hbMeta = json_encode(array_merge($res, ['duration_ms' => $durationMs]));
    $hbStmt = $db->prepare("
        INSERT INTO system_heartbeats (service_name, last_run_at, status, meta_json)
        VALUES ('scheduler_dispatcher', NOW(), 'ok', ?)
        ON DUPLICATE KEY UPDATE last_run_at = NOW(), status = VALUES(status), meta_json = VALUES(meta_json)
    ");
    $hbStmt->execute([$hbMeta]);

    // Backward-compatible update for legacy 'scheduler' monitors
    $hbLegacy = $db->prepare("
        INSERT INTO system_heartbeats (service_name, last_run_at, status, meta_json)
        VALUES ('scheduler', NOW(), 'ok', ?)
        ON DUPLICATE KEY UPDATE last_run_at = NOW(), status = VALUES(status), meta_json = VALUES(meta_json)
    ");
    $hbLegacy->execute([$hbMeta]);
} catch (Throwable $e) {
    // Non-blocking heartbeat log
}

// Log execution output
$logLine = sprintf(
    "[%s] Fast Dispatcher — checked: %d, sent: %d, skipped: %d, expired: %d, errors: %d (took %dms)\n",
    date('Y-m-d H:i:s'),
    $res['checked'] ?? 0,
    $res['push_sent'] ?? 0,
    $res['push_skipped'] ?? 0,
    $res['push_expired'] ?? 0,
    $res['push_errors'] ?? 0,
    $durationMs
);

// If pushes were sent or errors occurred, write to main daemon log for visibility
if (!empty($res['push_sent']) || !empty($res['push_errors'])) {
    echo $logLine;
    @file_put_contents('/var/log/check_deadlines.log', $logLine, FILE_APPEND);
}

flock($lockFp, LOCK_UN);
fclose($lockFp);
