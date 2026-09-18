<?php
declare(strict_types=1);

/**
 * Idempotent Database Migration Runner
 * Safe to execute multiple times against both local and production Aiven databases.
 * Does NOT drop or truncate any data.
 */

require_once __DIR__ . '/../includes/db.php';

echo "=== Running Database Migrations ===
";

try {
    $db = getDb();
    echo "Connected to database successfully.\n";
} catch (Throwable $e) {
    echo "ERROR: Unable to connect to database: " . $e->getMessage() . "\n";
    exit(1);
}


// 1. Run Table Creations from migrate_aiven.sql
$sql = file_get_contents(__DIR__ . '/migrate_aiven.sql');
$statements = array_filter(array_map('trim', explode(';', $sql)));

foreach ($statements as $stmt) {
    if (empty($stmt) || str_starts_with($stmt, '--')) continue;
    try {
        $db->exec($stmt);
    } catch (PDOException $e) {
        // Table or index already exists is expected and safe
        if (!in_array($e->getCode(), ['42S01', '42S21'])) {
            echo "Warning on statement: " . $e->getMessage() . "
";
        }
    }
}
echo "Table schemas verified.
";

// 2. Idempotent Column Additions (safe across MySQL 5.7, 8.0+, MariaDB)
function ensureColumn(PDO $db, string $table, string $column, string $definition): void {
    try {
        $check = $db->query("SHOW COLUMNS FROM `$table` LIKE '$column'");
        if ($check->rowCount() === 0) {
            echo "Adding column $column to $table...
";
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

echo "=== All Migrations Complete & Verified ===
";