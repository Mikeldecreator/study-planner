<?php
require_once __DIR__ . '/../includes/auth.php';
header('Content-Type: application/json');
$token = csrfToken();
session_write_close();
echo json_encode(['csrf_token' => $token]);
