<?php
require_once __DIR__ . '/includes/db.php';

$db = getDb();

echo 'Database: ' . $db->query('SELECT DATABASE()')->fetchColumn();
echo '<br>';

$stmt = $db->query('SELECT id, email FROM users');
$users = $stmt->fetchAll();

echo '<pre>';
print_r($users);
echo '</pre>';