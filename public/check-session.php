<?php

require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: text/plain');

echo 'Session ID: ' . session_id() . PHP_EOL;
echo 'User ID: ' . ($_SESSION['user_id'] ?? 'NOT LOGGED IN') . PHP_EOL;
echo 'User Name: ' . ($_SESSION['user_name'] ?? 'NONE') . PHP_EOL;