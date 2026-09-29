<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
requireLogin();

$apiKey = (string)(getenv('GEMINI_API_KEY') ?: (getenv('GOOGLE_API_KEY') ?: (getenv('GOOGLE_AI_API_KEY') ?: (getenv('AI_API_KEY') ?: ''))));
$model = $_GET['model'] ?? 'gemini-3.5-flash-lite';

$url = 'https://generativelanguage.googleapis.com/v1beta/models/' . urlencode($model) . ':generateContent?key=' . urlencode($apiKey);

$sysInstruction = "You are Study AI. When student says to add a task, output a JSON block with action_detected: true, requires_confirmation: true, action: {type: 'create_task', summary: '...', payload: {...}}, and answer: '...'.";

$body = [
    'system_instruction' => [
        'parts' => [
            ['text' => $sysInstruction]
        ]
    ],
    'contents' => [
        [
            'role' => 'user',
            'parts' => [
                ['text' => 'Add a work called Database ERD Assignment for CSC 414 due Friday at 2:30 PM.']
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
