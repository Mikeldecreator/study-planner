<?php
declare(strict_types=1);

/**
 * ============================================================================
 * FOUNDATION LAYER 2 — AUTOMATED ACADEMIC SCHEDULING GENERATION API
 *
 * Dedicated endpoint for previewing, confirming/applying, and clearing
 * automated study plans.
 *
 * Controlled Operation:
 * - GET: Computes preview plan in memory (Zero DB writes).
 * - POST action=preview: Computes preview plan with options (Zero DB writes).
 * - POST action=apply: Generates and persists plan safely under DB authority.
 * - POST action=clear: Clears future incomplete scheduler sessions.
 * ============================================================================
 */

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/scheduler.php';

header('Content-Type: application/json; charset=utf-8');

// Require authentication
requireLogin();

$userId = currentUserId();
$db = getDb();
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

function jsonOut(array $payload, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

switch ($method) {
    case 'GET':
        session_write_close();
        try {
            $startDate = !empty($_GET['start_date']) ? (string)$_GET['start_date'] : date('Y-m-d');
            $days = !empty($_GET['days']) ? max(1, min(14, (int)$_GET['days'])) : SCHEDULER_DEFAULT_HORIZON_DAYS;

            $plan = generateAutomatedSchedule($db, $userId, [
                'start_date' => $startDate,
                'days'       => $days,
            ]);

            jsonOut([
                'ok'   => true,
                'mode' => 'preview',
                'plan' => $plan,
            ]);
        } catch (Throwable $e) {
            error_log('Schedule preview error: ' . $e->getMessage());
            jsonOut(['error' => 'Unable to calculate study plan: ' . $e->getMessage()], 500);
        }
        break;

    case 'POST':
        $body = json_decode(file_get_contents('php://input'), true) ?? [];
        verifyCsrf($body['csrf_token'] ?? null);

        $action = trim((string)($body['action'] ?? 'apply'));

        if ($action === 'preview') {
            session_write_close();
            try {
                $startDate = !empty($body['start_date']) ? (string)$body['start_date'] : date('Y-m-d');
                $days = !empty($body['days']) ? max(1, min(14, (int)$body['days'])) : SCHEDULER_DEFAULT_HORIZON_DAYS;

                $plan = generateAutomatedSchedule($db, $userId, [
                    'start_date' => $startDate,
                    'days'       => $days,
                ]);

                jsonOut([
                    'ok'   => true,
                    'mode' => 'preview',
                    'plan' => $plan,
                ]);
            } catch (Throwable $e) {
                error_log('Schedule preview error: ' . $e->getMessage());
                jsonOut(['error' => 'Unable to calculate study plan: ' . $e->getMessage()], 500);
            }
        } elseif ($action === 'apply' || $action === 'confirm') {
            try {
                $startDate = !empty($body['start_date']) ? (string)$body['start_date'] : date('Y-m-d');
                $days = !empty($body['days']) ? max(1, min(14, (int)$body['days'])) : SCHEDULER_DEFAULT_HORIZON_DAYS;

                // 1. Generate plan
                $plan = generateAutomatedSchedule($db, $userId, [
                    'start_date' => $startDate,
                    'days'       => $days,
                ]);

                // 2. Persist with safe reconciliation (preserves manual sessions)
                $result = persistGeneratedSchedule($db, $userId, $plan, true);

                jsonOut([
                    'ok'              => true,
                    'mode'            => 'apply',
                    'scheduled_count' => $result['scheduled_count'],
                    'sessions'        => $result['sessions'],
                    'summary'         => $plan['summary'],
                    'message'         => $result['message'],
                ]);
            } catch (Throwable $e) {
                error_log('Schedule apply error: ' . $e->getMessage());
                jsonOut(['error' => 'Failed to apply study plan: ' . $e->getMessage()], 500);
            }
        } elseif ($action === 'clear') {
            try {
                // Clear only uncompleted scheduler-generated sessions
                $stmt = $db->prepare(
                    "DELETE FROM schedule_events
                     WHERE user_id = ? AND source = 'scheduler' AND is_completed = 0"
                );
                $stmt->execute([$userId]);
                $cleared = $stmt->rowCount();

                logActivity($userId, "Cleared {$cleared} automated study session(s).", 'info');

                jsonOut([
                    'ok'            => true,
                    'cleared_count' => $cleared,
                    'message'       => "Cleared {$cleared} automated study session(s).",
                ]);
            } catch (Throwable $e) {
                error_log('Schedule clear error: ' . $e->getMessage());
                jsonOut(['error' => 'Failed to clear automated sessions: ' . $e->getMessage()], 500);
            }
        } else {
            jsonOut(['error' => "Unknown action '{$action}'."], 422);
        }
        break;

    default:
        jsonOut(['error' => 'Method not allowed.'], 405);
}
