<?php

declare(strict_types=1);

/**
 * Study Planner
 * Browser Push API
 *
 * Handles:
 *   ?action=vapid_public_key
 *   ?action=status
 *   ?action=subscribe
 *   ?action=unsubscribe
 *   ?action=test
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/webpush.php';

header('Content-Type: application/json; charset=UTF-8');

if (function_exists('initAppSession')) {
    initAppSession();
} elseif (session_status() === PHP_SESSION_NONE) {
    session_start();
}


/* ============================================================
   RESPONSE HELPERS
   ============================================================ */

function jsonResponse(
    bool $success,
    array $data = [],
    int $status = 200
): never {
    http_response_code($status);

    echo json_encode(
        array_merge(
            ['success' => $success],
            $data
        ),
        JSON_UNESCAPED_SLASHES
    );

    exit;
}


/* ============================================================
   DATABASE
   ============================================================ */

function getPDO(): PDO
{
    return getDb();
}


/* ============================================================
   CURRENT USER
   ============================================================ */

if (!function_exists('currentUserId')) {
    function currentUserId(): int
    {
        $possibleKeys = [
            'user_id',
            'id',
            'uid'
        ];

        foreach ($possibleKeys as $key) {
            if (
                isset($_SESSION[$key]) &&
                is_numeric($_SESSION[$key])
            ) {
                return (int) $_SESSION[$key];
            }
        }

        return 0;
    }
}


/* ============================================================
   REQUEST METHOD
   ============================================================ */

$method = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET');

$action = strtolower(
    trim(
        (string) ($_GET['action'] ?? $_POST['action'] ?? '')
    )
);


/* ============================================================
   PUBLIC VAPID KEY
   ============================================================ */

if ($action === 'vapid_public_key') {

    if (
        !defined('VAPID_PUBLIC_KEY') ||
        trim((string) VAPID_PUBLIC_KEY) === ''
    ) {
        jsonResponse(
            false,
            [
                'message' =>
                    'VAPID public key is not configured.'
            ],
            500
        );
    }

    jsonResponse(
        true,
        [
            'public_key' => VAPID_PUBLIC_KEY
        ]
    );
}


/* ============================================================
   DATABASE TABLE CHECK
   ============================================================ */

function ensurePushTableExists(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS browser_push_subscriptions (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            user_id BIGINT UNSIGNED NOT NULL,
            endpoint TEXT NOT NULL,
            endpoint_hash CHAR(64) NOT NULL,
            p256dh TEXT NOT NULL,
            auth TEXT NOT NULL,
            content_encoding VARCHAR(50) NOT NULL DEFAULT 'aes128gcm',
            expiration_time BIGINT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
                ON UPDATE CURRENT_TIMESTAMP,

            PRIMARY KEY (id),
            UNIQUE KEY uq_push_endpoint_hash (endpoint_hash),
            KEY idx_push_user_id (user_id),

            CONSTRAINT fk_push_user
                FOREIGN KEY (user_id)
                REFERENCES users(id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB
          DEFAULT CHARSET=utf8mb4
          COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS browser_push_dead_endpoints (
            endpoint_hash CHAR(64) NOT NULL PRIMARY KEY,
            user_id BIGINT UNSIGNED NOT NULL,
            expired_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_dead_user_id (user_id)
        ) ENGINE=InnoDB
          DEFAULT CHARSET=utf8mb4
          COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS system_heartbeats (
            service_name VARCHAR(50) NOT NULL PRIMARY KEY,
            last_run_at  DATETIME NOT NULL,
            status       VARCHAR(20) NOT NULL DEFAULT 'ok',
            meta_json    TEXT NULL
        ) ENGINE=InnoDB
          DEFAULT CHARSET=utf8mb4
          COLLATE=utf8mb4_unicode_ci
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS browser_push_telemetry (
            id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            user_id BIGINT UNSIGNED NULL,
            endpoint_hash CHAR(64) NULL,
            device_fingerprint VARCHAR(64) NULL,
            notification_id BIGINT UNSIGNED NULL,
            event_key VARCHAR(100) NULL,
            event VARCHAR(50) NOT NULL,
            user_agent VARCHAR(500) NULL,
            payload_json TEXT NULL,
            error_message TEXT NULL,
            device_timestamp BIGINT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_telemetry_created (created_at),
            KEY idx_telemetry_hash (endpoint_hash),
            KEY idx_telemetry_fp (device_fingerprint),
            KEY idx_telemetry_notif (notification_id),
            KEY idx_telemetry_key (event_key)
        ) ENGINE=InnoDB
          DEFAULT CHARSET=utf8mb4
          COLLATE=utf8mb4_unicode_ci
    ");

    try {
        $pdo->exec("ALTER TABLE browser_push_telemetry ADD COLUMN device_fingerprint VARCHAR(64) NULL AFTER endpoint_hash");
    } catch (Throwable $e) {}
    try {
        $pdo->exec("ALTER TABLE browser_push_telemetry ADD COLUMN notification_id BIGINT UNSIGNED NULL AFTER device_fingerprint");
    } catch (Throwable $e) {}
    try {
        $pdo->exec("ALTER TABLE browser_push_telemetry ADD COLUMN event_key VARCHAR(100) NULL AFTER notification_id");
    } catch (Throwable $e) {}
}


/*
 * Create the table automatically.
 */
$pdo = getPDO();

try {
    ensurePushTableExists($pdo);
} catch (Throwable $e) {

    jsonResponse(
        false,
        [
            'message' =>
                APP_DEBUG
                    ? $e->getMessage()
                    : 'Unable to initialize browser push storage.'
        ],
        500
    );
}

$userId = currentUserId();


/* ============================================================
   SUBSCRIBE (HANDLES USER SESSIONS AND SW BACKGROUND ROTATIONS)
   ============================================================ */

if (
    $action === 'subscribe' &&
    $method === 'POST'
) {

    $raw = file_get_contents('php://input');

    if ($raw === false || trim($raw) === '') {

        jsonResponse(
            false,
            [
                'message' => 'Empty subscription request.'
            ],
            400
        );
    }

    $payload = json_decode(
        $raw,
        true
    );

    if (!is_array($payload)) {

        jsonResponse(
            false,
            [
                'message' => 'Invalid JSON subscription.'
            ],
            400
        );
    }

    $oldEndpoint = trim((string) ($payload['old_endpoint'] ?? ''));

    /*
     * If user is not logged in via session (e.g. background Service Worker
     * pushsubscriptionchange event firing while tab is closed),
     * verify if old_endpoint matches an existing subscription in the database.
     */
    if ($userId <= 0 && $oldEndpoint !== '') {
        $oldHash = hash('sha256', $oldEndpoint);
        $findOldStmt = $pdo->prepare("
            SELECT user_id
            FROM browser_push_subscriptions
            WHERE endpoint_hash = ?
            LIMIT 1
        ");
        $findOldStmt->execute([$oldHash]);
        $verifiedUserId = (int) $findOldStmt->fetchColumn();
        if ($verifiedUserId <= 0) {
            $findDeadStmt = $pdo->prepare("
                SELECT user_id
                FROM browser_push_dead_endpoints
                WHERE endpoint_hash = ?
                LIMIT 1
            ");
            $findDeadStmt->execute([$oldHash]);
            $verifiedUserId = (int) $findDeadStmt->fetchColumn();
        }
        if ($verifiedUserId > 0) {
            $userId = $verifiedUserId;
        }
    }

    if ($userId <= 0) {
        jsonResponse(
            false,
            [
                'message' => 'You must be logged in.'
            ],
            401
        );
    }

    $endpoint = trim(
        (string) ($payload['endpoint'] ?? '')
    );

    $p256dh = trim(
        (string) ($payload['p256dh'] ?? '')
    );

    $auth = trim(
        (string) ($payload['auth'] ?? '')
    );

    $contentEncoding = trim(
        (string) (
            $payload['content_encoding']
            ?? 'aes128gcm'
        )
    );

    $expirationTime =
        isset($payload['expiration_time']) &&
        is_numeric($payload['expiration_time'])
            ? (int) $payload['expiration_time']
            : null;


    /*
     * Validate required fields.
     */

    if ($endpoint === '') {

        jsonResponse(
            false,
            [
                'message' => 'Push endpoint is missing.'
            ],
            400
        );
    }

    if ($p256dh === '') {

        jsonResponse(
            false,
            [
                'message' => 'p256dh key is missing.'
            ],
            400
        );
    }

    if ($auth === '') {

        jsonResponse(
            false,
            [
                'message' => 'Auth key is missing.'
            ],
            400
        );
    }


    /*
     * Basic endpoint validation.
     */

    if (
        !filter_var(
            $endpoint,
            FILTER_VALIDATE_URL
        )
    ) {

        jsonResponse(
            false,
            [
                'message' => 'Invalid push endpoint.'
            ],
            400
        );
    }


    /*
     * Browser push endpoints are unique.
     */

    $endpointHash = hash(
        'sha256',
        $endpoint
    );


    /*
     * If old_endpoint is specified and differs from new endpoint,
     * atomically remove the old subscription so we don't retain dead endpoints.
     */
    if ($oldEndpoint !== '' && $oldEndpoint !== $endpoint) {
        $oldHash = hash('sha256', $oldEndpoint);
        $delOldStmt = $pdo->prepare("
            DELETE FROM browser_push_subscriptions
            WHERE endpoint_hash = ?
        ");
        $delOldStmt->execute([$oldHash]);

        $delDeadStmt = $pdo->prepare("
            DELETE FROM browser_push_dead_endpoints
            WHERE endpoint_hash = ?
        ");
        $delDeadStmt->execute([$oldHash]);
    }


    /*
     * Insert or update.
     */

    $stmt = $pdo->prepare("
        INSERT INTO browser_push_subscriptions (
            user_id,
            endpoint,
            endpoint_hash,
            p256dh,
            auth,
            content_encoding,
            expiration_time
        )
        VALUES (
            :user_id,
            :endpoint,
            :endpoint_hash,
            :p256dh,
            :auth,
            :content_encoding,
            :expiration_time
        )
        ON DUPLICATE KEY UPDATE
            user_id = VALUES(user_id),
            endpoint = VALUES(endpoint),
            p256dh = VALUES(p256dh),
            auth = VALUES(auth),
            content_encoding = VALUES(content_encoding),
            expiration_time = VALUES(expiration_time),
            updated_at = CURRENT_TIMESTAMP
    ");

    $stmt->execute([
        ':user_id'          => $userId,
        ':endpoint'         => $endpoint,
        ':endpoint_hash'    => $endpointHash,
        ':p256dh'           => $p256dh,
        ':auth'             => $auth,
        ':content_encoding' => $contentEncoding,
        ':expiration_time'  => $expirationTime,
    ]);


    jsonResponse(
        true,
        [
            'message' => 'Browser push subscription saved.'
        ]
    );
}


/* ============================================================
   SW TELEMETRY LOGGER (PUBLIC / CALLED BY BACKGROUND SW)
   ============================================================ */

if ($action === 'sw_log' && $method === 'POST') {
    $raw = file_get_contents('php://input');
    $payload = json_decode($raw ?: '', true) ?: [];

    $event = trim((string)($payload['event'] ?? 'unknown'));
    $endpoint = trim((string)($payload['endpoint'] ?? ''));
    $userAgent = substr(trim((string)($_SERVER['HTTP_USER_AGENT'] ?? ($payload['user_agent'] ?? ''))), 0, 500);
    $deviceTs = isset($payload['timestamp']) && is_numeric($payload['timestamp']) ? (int)$payload['timestamp'] : null;
    $errorMsg = !empty($payload['error']) ? (string)$payload['error'] : null;

    $endpointHash = trim((string)($payload['endpoint_hash'] ?? ''));
    if ($endpointHash === '' && $endpoint !== '') {
        $endpointHash = hash('sha256', $endpoint);
    } elseif ($endpointHash === '') {
        $endpointHash = null;
    }

    $subFingerprint = trim((string)($payload['sub_fingerprint'] ?? ''));
    if ($subFingerprint === '' && $endpointHash !== null) {
        $subFingerprint = substr($endpointHash, 0, 16);
    } elseif ($subFingerprint === '') {
        $subFingerprint = null;
    }

    $notificationId = !empty($payload['notification_id']) ? (int)$payload['notification_id'] : null;
    $eventKey = !empty($payload['event_key']) ? trim((string)$payload['event_key']) : null;

    $loggedUserId = null;

    if ($endpointHash !== null) {
        $findUser = $pdo->prepare("SELECT user_id FROM browser_push_subscriptions WHERE endpoint_hash = ? LIMIT 1");
        $findUser->execute([$endpointHash]);
        $found = (int)$findUser->fetchColumn();
        if ($found > 0) {
            $loggedUserId = $found;
        } else {
            $findDead = $pdo->prepare("SELECT user_id FROM browser_push_dead_endpoints WHERE endpoint_hash = ? LIMIT 1");
            $findDead->execute([$endpointHash]);
            $foundDead = (int)$findDead->fetchColumn();
            if ($foundDead > 0) {
                $loggedUserId = $foundDead;
            }
        }
    }
    if ($loggedUserId === null && $notificationId !== null) {
        $findNotifUser = $pdo->prepare("SELECT user_id FROM notifications WHERE id = ? LIMIT 1");
        $findNotifUser->execute([$notificationId]);
        $foundNotifUser = (int)$findNotifUser->fetchColumn();
        if ($foundNotifUser > 0) {
            $loggedUserId = $foundNotifUser;
        }
    }
    if ($loggedUserId === null && $userId > 0) {
        $loggedUserId = $userId;
    }

    $stmt = $pdo->prepare("
        INSERT INTO browser_push_telemetry (user_id, endpoint_hash, device_fingerprint, notification_id, event_key, event, user_agent, payload_json, error_message, device_timestamp)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $loggedUserId,
        $endpointHash,
        $subFingerprint,
        $notificationId,
        $eventKey,
        $event,
        $userAgent,
        $raw,
        $errorMsg,
        $deviceTs
    ]);

    jsonResponse(true, ['message' => 'Telemetry logged.', 'fingerprint' => $subFingerprint]);
}


/* ============================================================
   LOGIN REQUIRED FOR ALL REMAINING ACTIONS
   ============================================================ */

if ($userId <= 0) {
    jsonResponse(
        false,
        [
            'message' => 'You must be logged in.'
        ],
        401
    );
}


/* ============================================================
   STATUS
   ============================================================ */

if ($action === 'status') {

    $stmt = $pdo->prepare("
        SELECT
            id,
            endpoint,
            content_encoding,
            expiration_time,
            created_at,
            updated_at
        FROM browser_push_subscriptions
        WHERE user_id = ?
        ORDER BY id DESC
    ");

    $stmt->execute([$userId]);

    $subscriptions = $stmt->fetchAll();

    jsonResponse(
        true,
        [
            'enabled' => count($subscriptions) > 0,
            'count'   => count($subscriptions),
            'subscriptions' => $subscriptions
        ]
    );
}


/* ============================================================
   UNSUBSCRIBE
   ============================================================ */

if (
    $action === 'unsubscribe' &&
    $method === 'POST'
) {

    $raw = file_get_contents('php://input');

    $payload = json_decode(
        $raw ?: '',
        true
    );

    if (!is_array($payload)) {
        $payload = [];
    }

    $endpoint = trim(
        (string) ($payload['endpoint'] ?? '')
    );


    if ($endpoint === '') {

        jsonResponse(
            false,
            [
                'message' => 'Push endpoint is missing.'
            ],
            400
        );
    }


    $endpointHash = hash(
        'sha256',
        $endpoint
    );


    $stmt = $pdo->prepare("
        DELETE FROM browser_push_subscriptions
        WHERE user_id = ?
          AND endpoint_hash = ?
    ");

    $stmt->execute([
        $userId,
        $endpointHash
    ]);


    jsonResponse(
        true,
        [
            'message' => 'Browser push subscription removed.'
        ]
    );
}


/* ============================================================
   TEST PUSH
   ============================================================ */

if (
    $action === 'test' &&
    ($method === 'GET' || $method === 'POST')
) {

    $stmt = $pdo->prepare("
        SELECT
            id,
            endpoint,
            p256dh,
            auth,
            content_encoding,
            expiration_time
        FROM browser_push_subscriptions
        WHERE user_id = ?
        ORDER BY id DESC
    ");

    $stmt->execute([
        $userId
    ]);

    $subscriptions = $stmt->fetchAll();


    if (!$subscriptions) {

        jsonResponse(
            false,
            [
                'message' =>
                    'No browser push subscription is registered.'
            ],
            400
        );
    }


    $notification = [
        'title' =>
            'Study Planner',

        'body' =>
            'Browser notifications are working. 🎉',

        'tag' =>
            'study-planner-test',

        'data' => [
            'url' =>
                APP_URL . '/notifications.php'
        ],
    ];


    $results = webPushSendToSubscriptions(
        $subscriptions,
        $notification
    );


    $successful = 0;
    $failed = 0;

    $deleteExpiredStmt = $pdo->prepare("
        DELETE FROM browser_push_subscriptions
        WHERE id = ?
    ");

    $recordDeadStmt = $pdo->prepare("
        INSERT INTO browser_push_dead_endpoints (endpoint_hash, user_id, expired_at)
        VALUES (?, ?, NOW())
        ON DUPLICATE KEY UPDATE expired_at = NOW()
    ");

    foreach ($results as $result) {
        if (!empty($result['success'])) {
            $successful++;
        } else {
            $failed++;
            $errorMsg = strtolower((string)($result['error'] ?? ''));
            if (str_contains($errorMsg, 'http 404') || str_contains($errorMsg, 'http 410')) {
                foreach ($subscriptions as $sub) {
                    if ($sub['endpoint'] === ($result['endpoint'] ?? '')) {
                        $recordDeadStmt->execute([hash('sha256', $sub['endpoint']), $userId]);
                        $deleteExpiredStmt->execute([(int)$sub['id']]);
                        break;
                    }
                }
            }
        }
    }

    try {
        $tStmt = $pdo->prepare("
            INSERT INTO browser_push_telemetry (user_id, event, user_agent, payload_json, device_timestamp)
            VALUES (?, 'server_test_push_dispatched', ?, ?, ?)
        ");
        $tStmt->execute([
            $userId,
            substr($_SERVER['HTTP_USER_AGENT'] ?? 'Server', 0, 500),
            json_encode(['successful' => $successful, 'failed' => $failed, 'results' => $results]),
            (int)(microtime(true) * 1000)
        ]);
    } catch (Throwable $e) {}

    jsonResponse(
        $successful > 0,
        [
            'message' =>
                $successful > 0
                    ? 'Test notification sent.'
                    : 'Unable to deliver the test notification.',

            'successful' =>
                $successful,

            'failed' =>
                $failed,

            'results' =>
                $results
        ],
        $successful > 0 ? 200 : 500
    );
}


/* ============================================================
   EXPLAIN ACADEMIC TASK NOTIFICATION (DIAGNOSTIC EXPLAINABILITY)
   ============================================================ */

if ($action === 'explain_task' && ($method === 'GET' || $method === 'POST')) {
    if ($userId <= 0) {
        jsonResponse(false, ['message' => 'Not authenticated.'], 401);
    }

    $taskId = isset($_REQUEST['task_id']) ? (int)$_REQUEST['task_id'] : 0;
    if ($taskId <= 0) {
        jsonResponse(false, ['message' => 'Missing task_id.'], 400);
    }

    $explanation = explainAcademicNotification($pdo, $userId, $taskId);
    jsonResponse(true, $explanation);
}


/* ============================================================
   CHECK DEADLINES (ON-DEMAND AUDIT & SCHEDULING TRIGGER)
   ============================================================ */

if (
    $action === 'check_deadlines' &&
    ($method === 'GET' || $method === 'POST')
) {
    if ($userId <= 0) {
        jsonResponse(false, ['message' => 'Not authenticated.'], 401);
    }

    $userStmt = $pdo->prepare("
        SELECT notifications_enabled, email, full_name
        FROM users
        WHERE id = ?
    ");
    $userStmt->execute([$userId]);
    $userRow = $userStmt->fetch(PDO::FETCH_ASSOC);

    if (!$userRow || empty($userRow['notifications_enabled'])) {
        jsonResponse(
            true,
            [
                'message' => 'Notifications are disabled for this user.',
                'notifications_checked' => 0,
                'push_sent' => 0,
                'push_skipped' => 0
            ]
        );
    }

        $ignoreSuppression = !empty($_REQUEST['ignore_suppression']) || !empty($_REQUEST['force']);

    // Run unified decision engine to produce prioritized, deduplicated notifications
    generateAcademicReminders($pdo, $userId);

    // Delegate to shared lightweight dispatcher
    $dispatchRes = dispatchPendingPushesForUser($pdo, $userId, [
        'ignore_suppression' => $ignoreSuppression
    ]);

    jsonResponse(
        true,
        [
            'message' => "Deadline reminder check completed: {$dispatchRes['push_sent']} sent, {$dispatchRes['push_skipped']} skipped, {$dispatchRes['push_expired']} expired removed.",
            'notifications_checked' => $dispatchRes['checked'],
            'push_sent' => $dispatchRes['push_sent'],
            'push_skipped' => $dispatchRes['push_skipped'],
            'push_expired' => $dispatchRes['push_expired'],
            'results' => $dispatchRes['results']
        ]
    );
}


/* ============================================================
   SCHEDULER STATUS (OBSERVABILITY & HEALTH)
   ============================================================ */

if ($action === 'scheduler_status') {
    $stmt = $pdo->prepare("
        SELECT service_name, last_run_at, status, meta_json,
               TIMESTAMPDIFF(SECOND, last_run_at, NOW()) AS elapsed_seconds
        FROM system_heartbeats
        WHERE service_name IN ('scheduler', 'scheduler_dispatcher', 'scheduler_generator')
    ");
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $dispatcherHb = null;
    $generatorHb = null;
    $legacyHb = null;

    foreach ($rows as $r) {
        $meta = !empty($r['meta_json']) ? json_decode((string)$r['meta_json'], true) : [];
        $r['meta'] = $meta ?: [];
        if ($r['service_name'] === 'scheduler_dispatcher') {
            $dispatcherHb = $r;
        } elseif ($r['service_name'] === 'scheduler_generator') {
            $generatorHb = $r;
        } elseif ($r['service_name'] === 'scheduler') {
            $legacyHb = $r;
        }
    }

    $daemonLog = '';
    if (file_exists('/var/log/check_deadlines.log')) {
        $daemonLog = (string)@file_get_contents('/var/log/check_deadlines.log');
        if (strlen($daemonLog) > 3000) {
            $daemonLog = substr($daemonLog, -3000);
        }
    }

    $psAux = (string)@shell_exec('ps aux 2>&1');

    $isDispatcherActive = $dispatcherHb && (int)($dispatcherHb['elapsed_seconds'] ?? 999) <= 30;
    $isGeneratorActive = $generatorHb && (int)($generatorHb['elapsed_seconds'] ?? 999) <= 360;
    $isLegacyActive = $legacyHb && (int)($legacyHb['elapsed_seconds'] ?? 999) <= 120;

    jsonResponse(
        true,
        [
            'active' => $isDispatcherActive || $isLegacyActive,
            'dispatcher_active' => $isDispatcherActive,
            'generator_active' => $isGeneratorActive,
            'dispatcher_heartbeat' => $dispatcherHb,
            'generator_heartbeat' => $generatorHb,
            'heartbeat' => $legacyHb ?: $dispatcherHb,
            'daemon_log' => $daemonLog,
            'ps_aux' => $psAux,
            'server_time' => date('Y-m-d H:i:s')
        ]
    );
}


/* ============================================================
   PUSH TELEMETRY LOGS (OBSERVABILITY & REAL-DEVICE PROOF)
   ============================================================ */

if ($action === 'telemetry') {
    $stmt = $pdo->prepare("
        SELECT id, user_id, endpoint_hash, device_fingerprint, notification_id, event_key, event, user_agent, payload_json, error_message, device_timestamp, created_at
        FROM browser_push_telemetry
        WHERE user_id = ? OR user_id IS NULL
        ORDER BY id DESC
        LIMIT 100
    ");
    $stmt->execute([$userId]);
    $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

    jsonResponse(
        true,
        [
            'count' => count($logs),
            'logs' => $logs
        ]
    );
}

if ($action === 'clear_telemetry' && $method === 'POST') {
    $stmt = $pdo->prepare("DELETE FROM browser_push_telemetry WHERE user_id = ? OR user_id IS NULL");
    $stmt->execute([$userId]);
    jsonResponse(true, ['message' => 'Push telemetry logs cleared.']);
}


/* ============================================================
   UNKNOWN ACTION
   ============================================================ */

jsonResponse(
    false,
    [
        'message' => 'Unknown push action.'
    ],
    400
);