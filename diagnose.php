<?php
/**
 * ONE-TIME DIAGNOSTIC — not part of the app, safe to delete afterward.
 *
 * Run it directly in your browser:
 *   http://localhost/study-planner/diagnose.php
 *
 * It does three read-only-safe things:
 *   1. Confirms which database PHP is actually connected to.
 *   2. Prints the live structure of `users` and `courses` so you can
 *      compare it column-by-column against database/schema.sql.
 *   3. Attempts a real INSERT into `courses` inside a transaction that is
 *      always rolled back at the end (nothing is kept), specifically to
 *      surface the exact PDO error message that api/courses.php normally
 *      swallows into "A database error prevented the course request from
 *      completing."
 *
 * DELETE THIS FILE when you're done — it exposes schema details and
 * should never sit on a real deployment.
 */

require_once __DIR__ . '/includes/db.php';

header('Content-Type: text/plain; charset=utf-8');

try {
    $db = getDb();
    echo "Connected OK.\n";
    echo "Database PHP is actually using: " . $db->query('SELECT DATABASE()')->fetchColumn() . "\n\n";

    foreach (['users', 'courses'] as $table) {
        echo "=== DESCRIBE {$table} ===\n";
        $stmt = $db->query("DESCRIBE `{$table}`");
        foreach ($stmt->fetchAll() as $col) {
            printf("  %-22s %-20s %-9s %-5s %s\n",
                $col['Field'], $col['Type'], $col['Null'], $col['Key'], $col['Default'] ?? 'NULL');
        }
        echo "\n";
    }

    echo "=== Row counts ===\n";
    foreach (['users', 'courses', 'tasks'] as $table) {
        $count = $db->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
        echo "  {$table}: {$count}\n";
    }
    echo "\n";

    echo "=== Users present ===\n";
    foreach ($db->query('SELECT id, email FROM users') as $u) {
        echo "  id={$u['id']}  email={$u['email']}\n";
    }
    echo "\n";

    echo "=== Attempting a real INSERT into courses (rolled back, not kept) ===\n";
    $firstUserId = (int) $db->query('SELECT id FROM users ORDER BY id LIMIT 1')->fetchColumn();
    if (!$firstUserId) {
        echo "  No users found — cannot test an insert. Register an account first.\n";
    } else {
        $db->beginTransaction();
        try {
            $stmt = $db->prepare(
                'INSERT INTO courses (user_id, code, name, lecturer, credits, semester, icon, color, grade_point)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
            );
            $stmt->execute([$firstUserId, 'DIAG 000', 'Diagnostic Test Course', 'Test', 3, 'Test Semester', '📘', '#059669', null]);
            echo "  SUCCESS — the insert worked against user_id={$firstUserId}. Rolling back now.\n";
            echo "  This means the courses table itself is fine, so the real failure is\n";
            echo "  likely specific to the request coming from the browser (session/CSRF/\n";
            echo "  payload) rather than the database structure. Check the browser's Network\n";
            echo "  tab for the actual POST to api/courses.php and its response body next.\n";
            $db->rollBack();
        } catch (Throwable $e) {
            $db->rollBack();
            echo "  FAILED — this is the real error api/courses.php has been hiding from you:\n";
            echo "  " . $e->getMessage() . "\n";
        }
    }
} catch (Throwable $e) {
    echo "Could not even connect: " . $e->getMessage() . "\n";
    echo "Check config/config.php DB_HOST / DB_NAME / DB_USER / DB_PASS.\n";
}
