<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';

header('Content-Type: text/plain; charset=utf-8');

$payload = [
    'from' => MAIL_FROM_NAME . ' <' . MAIL_FROM_EMAIL . '>',
    'to' => ['delivered@resend.dev'],
    'subject' => 'Study Planner Resend Test',
    'html' => '<h2>Resend test successful</h2><p>This is a test email from Study Planner.</p>',
    'text' => 'Resend test successful. This is a test email from Study Planner.'
];

$ch = curl_init('https://api.resend.com/emails');

curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . RESEND_API_KEY,
        'Content-Type: application/json',
        'Accept: application/json'
    ],
    CURLOPT_POSTFIELDS => json_encode($payload),
    CURLOPT_TIMEOUT => 30,
    CURLOPT_CONNECTTIMEOUT => 10
]);

$response = curl_exec($ch);
$curlError = curl_error($ch);
$httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

curl_close($ch);

echo "HTTP STATUS: " . $httpCode . PHP_EOL;
echo "CURL ERROR: " . ($curlError ?: 'none') . PHP_EOL;
echo "RESEND RESPONSE:" . PHP_EOL;
echo $response . PHP_EOL;