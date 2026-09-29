<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
requireLogin();

$apiKey = (string)(getenv('GEMINI_API_KEY') ?: (getenv('GOOGLE_API_KEY') ?: (getenv('GOOGLE_AI_API_KEY') ?: (getenv('AI_API_KEY') ?: ''))));

$ch = curl_init('https://generativelanguage.googleapis.com/v1beta/models?key=' . urlencode($apiKey));
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 15,
    CURLOPT_SSL_VERIFYPEER => false,
]);
$res = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

header('Content-Type: application/json');
echo json_encode([
    'http_code' => $httpCode,
    'models' => json_decode($res ?: '{}', true)
], JSON_PRETTY_PRINT);
