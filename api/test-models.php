<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
requireLogin();

$apiKey = (string)(getenv('GEMINI_API_KEY') ?: (getenv('GOOGLE_API_KEY') ?: (getenv('GOOGLE_AI_API_KEY') ?: (getenv('AI_API_KEY') ?: ''))));
$model = $_GET['model'] ?? 'gemini-2.5-flash';

$url = 'https://generativelanguage.googleapis.com/v1beta/models/' . urlencode($model) . ':generateContent?key=' . urlencode($apiKey);

$body = [
    'contents' => [
        [
            'role' => 'user',
            'parts' => [
                ['text' => 'Hello! Say OK in one word.']
            ]
        ]
    ]
];

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($body),
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_TIMEOUT => 20,
    CURLOPT_SSL_VERIFYPEER => false,
]);
$res = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

header('Content-Type: application/json');
echo json_encode([
    'model' => $model,
    'http_code' => $httpCode,
    'response' => json_decode($res ?: '{}', true)
], JSON_PRETTY_PRINT);
