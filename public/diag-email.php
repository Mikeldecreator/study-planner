<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';

header('Content-Type: application/json; charset=utf-8');

$apiKey = defined('RESEND_API_KEY') ? (string) RESEND_API_KEY : '';
$hasApiKey = trim($apiKey) !== '';
$keyLen = strlen($apiKey);

$info = [
    'runtime_env' => [
        'RESEND_API_KEY_present' => $hasApiKey ? 'YES' : 'NO',
        'RESEND_API_KEY_length' => $keyLen,
        'EMAIL_ENABLED' => defined('EMAIL_ENABLED') ? (EMAIL_ENABLED ? 'true' : 'false') : 'undefined',
        'APP_URL' => defined('APP_URL') ? APP_URL : 'undefined',
        'MAIL_FROM_EMAIL' => defined('MAIL_FROM_EMAIL') ? MAIL_FROM_EMAIL : 'undefined',
        'MAIL_FROM_NAME' => defined('MAIL_FROM_NAME') ? MAIL_FROM_NAME : 'undefined',
        'PHP_VERSION' => PHP_VERSION,
    ],
    'resend_domains' => [],
    'test_send' => null,
];

// Query Resend for authorized domains
if ($hasApiKey) {
    $ch = curl_init('https://api.resend.com/domains');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
            'Accept: application/json'
        ],
        CURLOPT_TIMEOUT => 15
    ]);
    $domRes = curl_exec($ch);
    $domHttp = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    $domJson = json_decode((string)$domRes, true);
    $info['resend_domains'] = [
        'http_code' => $domHttp,
        'data' => $domJson['data'] ?? $domJson
    ];
}

// If a test recipient is provided, test sending
$to = filter_input(INPUT_GET, 'to', FILTER_VALIDATE_EMAIL);
if ($to && $hasApiKey) {
    $from = MAIL_FROM_NAME . ' <' . MAIL_FROM_EMAIL . '>';
    $payload = [
        'from' => $from,
        'to' => [$to],
        'subject' => 'Study Planner Safe Diagnostic Test',
        'html' => '<p>Diagnostic email verification test.</p>',
        'text' => 'Diagnostic email verification test.'
    ];
    
    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
            'Accept: application/json'
        ],
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 15
    ]);
    $sendRes = curl_exec($ch);
    $sendHttp = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $sendErr = curl_error($ch);
    curl_close($ch);
    
    $sendJson = json_decode((string)$sendRes, true);
    $info['test_send'] = [
        'to' => $to,
        'from' => $from,
        'http_code' => $sendHttp,
        'curl_error' => $sendErr ?: null,
        'response' => $sendJson ?: $sendRes
    ];
}

echo json_encode($info, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
