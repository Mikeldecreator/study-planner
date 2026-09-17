<?php

declare(strict_types=1);

/**
 * FOUNDATION 7B: READ-ONLY AI CHAT ENDPOINT
 *
 * Provides an authenticated, rate-limited, read-only endpoint for student academic queries.
 *
 * Security & Constraints:
 * - Requires active session login (HTTP 401 if unauthenticated).
 * - POST method only (HTTP 405 for other methods).
 * - User ID derived exclusively from currentUserId(); never accepted from client inputs.
 * - Rate limited: max 10 requests per minute per user (HTTP 429 if throttled).
 * - Input validation: non-empty, max 1000 characters (HTTP 422).
 * - Strictly read-only: Zero database mutations.
 * - Credential safe: Never leaks API keys, session secrets, or raw stack traces.
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/ai.php';

header('Content-Type: application/json; charset=utf-8');

// 1. Enforce active authentication
requireLogin();

// 2. Enforce POST method
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode([
        'ok'        => false,
        'error'     => 'Method Not Allowed. POST is required.',
        'read_only' => true,
    ]);
    exit;
}

$userId = currentUserId();
$db = getDb();

// 3. Abuse / rate-limit protection (max 10 AI queries per 60 seconds)
if (isRateLimited('ai_chat', (string)$userId, 10)) {
    http_response_code(429);
    echo json_encode([
        'ok'        => false,
        'error'     => 'Too many AI requests. Please wait a moment before asking another question.',
        'code'      => 'RATE_LIMITED',
        'read_only' => true,
    ]);
    exit;
}
recordRateLimitHit('ai_chat', (string)$userId, 60);

// 4. Parse request payload (JSON or application/x-www-form-urlencoded)
$rawBody = file_get_contents('php://input');
$data = json_decode($rawBody, true);
if (!is_array($data)) {
    $data = $_POST;
}

$question = trim((string)($data['message'] ?? ($data['question'] ?? '')));

// 5. Input validation
if ($question === '') {
    http_response_code(422);
    echo json_encode([
        'ok'        => false,
        'error'     => 'Question cannot be empty.',
        'code'      => 'EMPTY_QUESTION',
        'read_only' => true,
    ]);
    exit;
}

if (mb_strlen($question) > 1000) {
    http_response_code(422);
    echo json_encode([
        'ok'        => false,
        'error'     => 'Question exceeds maximum allowed length of 1000 characters.',
        'code'      => 'MESSAGE_TOO_LONG',
        'read_only' => true,
    ]);
    exit;
}

// 6. Execute academic AI inquiry
$result = askAcademicAI($db, $userId, $question);

if (!$result['ok']) {
    if (isset($result['configured']) && $result['configured'] === false) {
        http_response_code(503);
    } else {
        http_response_code(502);
    }
}

echo json_encode($result);
