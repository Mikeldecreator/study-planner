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

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');

requireLogin();

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method !== 'GET') {
    http_response_code(405);
    echo json_encode(['error' => 'Method Not Allowed. This endpoint is strictly read-only.']);
    exit;
}

$userId = currentUserId();
$db = getDb();

$academicContext = getAIAcademicContext($db, $userId);

echo json_encode([
    'ok'               => true,
    'context_version'  => '1.0',
    'read_only'        => true,
    'environment'      => 'development',
    'academic_context' => $academicContext,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
