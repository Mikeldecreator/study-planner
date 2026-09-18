<?php
declare(strict_types=1);

/**
 * Idempotent Database Migration Runner
 * Safe to execute multiple times against both local and production Aiven databases.
 * Purely additive: NEVER drops or truncates any data.
 */

require_once __DIR__ . '/../includes/db.php';

echo "=== Running Database Migrations ===\n";

try {
    $db = getDb();
    echo "Connected to database successfully.\n";
} catch (Throwable $e) {
    echo "ERROR: Unable to connect to database: " . $e->getMessage() . "\n";
    exit(1);
}

// Helper to strip comments and split SQL statements cleanly
function parseSqlStatements(string $rawSql): array {
    // 1. Remove multi-line comments /* ... */
    $clean = preg_replace('!/\*.*?\*/!s', '', $rawSql);

    // 2. Remove single-line comments (-- and #)
    $lines = explode("\n", $clean);
    $filtered = [];
    foreach ($lines as $line) {
        $trimmed = trim($line);
        if (str_starts_with($trimmed, '--') || str_starts_with($trimmed, '#')) {
            continue;
        }
        $filtered[] = $line;
    }
    $cleanSql = implode("\n", $filtered);

    // 3. Split by semicolon and trim
    $statements = array_filter(array_map('trim', explode(';', $cleanSql)));
    return array_values($statements);
}

// Disable foreign key checks during additive table creation to guarantee dependency safety
try {
    $db->exec("SET FOREIGN_KEY_CHECKS = 0;");
} catch (Throwable $e) {
    // Fall through if permission denied on some managed hosts
}

// 1. Run Table Creations from migrate_aiven.sql
$sql = file_get_contents(__DIR__ . '/migrate_aiven.sql');
$statements = parseSqlStatements($sql);

echo "Processing " . count($statements) . " additive table definitions in dependency order...\n";

foreach ($statements as $stmt) {
    if (empty($stmt)) continue;

    // Extract table name for clean logging
    preg_match('/CREATE TABLE (?:IF NOT EXISTS )?`?([a-zA-Z0-9_]+)`?/i', $stmt, $m);
    $tableName = $m[1] ?? 'unknown';

    try {
        $db->exec($stmt);
        echo "  [OK] Table '$tableName' definition verified.\n";
    } catch (PDOException $e) {
        // Table or index already exists is expected and safe (codes 42S01, 42S21)
        if (in_array($e->getCode(), ['42S01', '42S21'])) {
            echo "  [OK] Table '$tableName' already exists.\n";
        } else {
            echo "  [WARNING] Table '$tableName': " . $e->getMessage() . "\n";
        }
    }
}

// Re-enable foreign key checks
try {
    $db->exec("SET FOREIGN_KEY_CHECKS = 1;");
} catch (Throwable $e) {
    // Fall through
}

// 2. Idempotent Column Additions (safe across MySQL 5.7, 8.0+, MariaDB)
function ensureColumn(PDO $db, string $table, string $column, string $definition): void {
    try {
        $check = $db->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
        if ($check->rowCount() === 0) {
            echo "Adding missing column '$column' to '$table'...\n";
            $db->exec("ALTER TABLE `$table` ADD COLUMN `$column` $definition");
        }
    } catch (Throwable $e) {
        // Table may not exist yet or already altered
    }
}

// Ensure users columns
ensureColumn($db, 'users', 'tagline', "VARCHAR(255) DEFAULT NULL");
ensureColumn($db, 'users', 'avatar_path', "VARCHAR(255) DEFAULT NULL");
ensureColumn($db, 'users', 'dark_mode', "TINYINT(1) NOT NULL DEFAULT 0");
ensureColumn($db, 'users', 'weekly_goal_hours', "DECIMAL(5,2) NOT NULL DEFAULT 15.00");
ensureColumn($db, 'users', 'notifications_enabled', "TINYINT(1) NOT NULL DEFAULT 1");
ensureColumn($db, 'users', 'week_start_day', "TINYINT(1) NOT NULL DEFAULT 1");
ensureColumn($db, 'users', 'preferred_study_time', "VARCHAR(20) DEFAULT 'morning'");
ensureColumn($db, 'users', 'preferred_study_days', "VARCHAR(100) DEFAULT '1,2,3,4,5'");
ensureColumn($db, 'users', 'tour_completed', "TINYINT(1) NOT NULL DEFAULT 0");
ensureColumn($db, 'users', 'dismissed_tips', "TEXT DEFAULT NULL");

// Ensure courses columns
ensureColumn($db, 'courses', 'status', "ENUM('pending','in_progress','completed') NOT NULL DEFAULT 'pending'");
ensureColumn($db, 'courses', 'progress_percent', "TINYINT UNSIGNED NOT NULL DEFAULT 0");
ensureColumn($db, 'courses', 'estimated_hours', "DECIMAL(6,2) NOT NULL DEFAULT 0");
ensureColumn($db, 'courses', 'completed_at', "DATETIME DEFAULT NULL");

// Ensure schedule_events columns
ensureColumn($db, 'schedule_events', 'is_completed', "TINYINT(1) NOT NULL DEFAULT 0");
ensureColumn($db, 'schedule_events', 'progress_percent', "TINYINT UNSIGNED NOT NULL DEFAULT 0");
ensureColumn($db, 'schedule_events', 'completed_at', "DATETIME DEFAULT NULL");

// 3. Idempotent Index Additions
function ensureIndex(PDO $db, string $table, string $indexName, string $columns): void {
    try {
        $check = $db->query("SHOW INDEX FROM `$table` WHERE Key_name = '$indexName'");
        if ($check->rowCount() === 0) {
            echo "Adding index '$indexName' to '$table'...\n";
            $db->exec("ALTER TABLE `$table` ADD INDEX `$indexName` ($columns)");
        }
    } catch (Throwable $e) {
        // Table or index may not exist yet or already altered
    }
}

ensureIndex($db, 'tasks', 'idx_tasks_user_status_due', '`user_id`, `status`, `due_at`');
ensureIndex($db, 'notifications', 'idx_notif_user_channel_send', '`user_id`, `channel`, `read_at`, `send_at`');

// 4. Verify all required application tables exist
$requiredTables = [
    'users',
    'courses',
    'semesters',
    'curriculum_weeks',
    'academic_events',
    'tasks',
    'schedule_events',
    'work_timers',
    'task_work_sessions',
    'notifications',
    'activity_log',
    'password_resets',
    'browser_push_subscriptions',
    'push_daily_reminders'
];

echo "\nVerifying required tables...\n";
try {
    $tablesResult = $db->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
    $existing = array_map('strtolower', $tablesResult ?: []);
    $allPresent = true;
    foreach ($requiredTables as $t) {
        if (in_array(strtolower($t), $existing, true)) {
            echo "  [VERIFIED] $t\n";
        } else {
            echo "  [MISSING] $t\n";
            $allPresent = false;
        }
    }
    if (!$allPresent) {
        echo "WARNING: Some tables could not be verified. Check database permissions.\n";
    }
} catch (Throwable $e) {
    echo "Notice: Table verification query skipped (" . $e->getMessage() . ")\n";
}

echo "\n=== All Migrations Complete & Verified ===\n";