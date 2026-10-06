<?php

declare(strict_types=1);

/**
 * ============================================================
 * STUDY PLANNER - HEAVY ACADEMIC REMINDER GENERATOR CRON
 * File: cron/generate_reminders.php
 * ============================================================
 *
 * Runs on a coarse cadence (every 3-5 minutes).
 * Evaluates curriculum weeks, timetable classes, tomorrow digests,
 * and upcoming deadlines globally across all users.
 * Does NOT dispatch Web Push notifications directly.
 * ============================================================
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';

// Prevent overlapping concurrent executions
$lockFile = sys_get_temp_dir() . '/study_planner_generate_reminders.lock';
$lockFp = fopen($lockFile, 'c');
if (!$lockFp || !flock($lockFp, LOCK_EX | LOCK_NB)) {
    // Another instance is already running
    exit(0);
}

$db = getDb();
$t0 = microtime(true);
ensureNotificationSchema($db);
$genResult = generateAcademicReminders($db);
$durationS = round(microtime(true) - $t0, 2);

$totalGenerated = is_array($genResult) ? ($genResult['generated'] ?? count($genResult)) : (int)$genResult;

// Record heartbeat observability for scheduler_generator
try {
    $hbMeta = json_encode([
        'generated' => $totalGenerated,
        'duration_seconds' => $durationS
    ]);
    $hbStmt = $db->prepare("
        INSERT INTO system_heartbeats (service_name, last_run_at, status, meta_json)
        VALUES ('scheduler_generator', NOW(), 'ok', ?)
        ON DUPLICATE KEY UPDATE last_run_at = NOW(), status = VALUES(status), meta_json = VALUES(meta_json)
    ");
    $hbStmt->execute([$hbMeta]);
} catch (Throwable $e) {
    // Non-blocking heartbeat log
}

$logLine = sprintf(
    "[%s] Heavy Academic Generator — reminders generated: %d (took %.2fs)\n",
    date('Y-m-d H:i:s'),
    $totalGenerated,
    $durationS
);
echo $logLine;
@file_put_contents('/var/log/check_deadlines.log', $logLine, FILE_APPEND);

flock($lockFp, LOCK_UN);
fclose($lockFp);
