<?php
/**
 * FOUNDATION 7A: READ-ONLY AI ACADEMIC CONTEXT ENDPOINT
 *
 * Provides an authenticated, session-scoped inspection endpoint for the
 * centralized AI academic context.
 *
 * Security & Constraints:
 * - Requires active session login (HTTP 401 if unauthenticated).
 * - Read-only GET requests only (HTTP 405 for other methods).
 * - Scoped strictly to currentUserId(); never accepts user ID from request parameters.
 * - Zero database mutations.
 * - Zero external AI provider calls.
 * - Excludes sensitive credentials, hashes, CSRF tokens, and secrets.
 */

ob_start();

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

requireLogin();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'GET') {
    while (ob_get_level() > 0) ob_end_clean();
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed. This endpoint is strictly read-only.']);
    exit;
}

$userId = currentUserId();
session_write_close();
$db = getDb();

$academicContext = getAIAcademicContext($db, $userId);

while (ob_get_level() > 0) ob_end_clean();
echo json_encode([
    'ok'               => true,
    'context_version'  => '2.0',
    'read_only'        => true,
    'environment'      => 'production',
    'academic_context' => $academicContext,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
