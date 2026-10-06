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
            event VARCHAR(50) NOT NULL,
            user_agent VARCHAR(500) NULL,
            payload_json TEXT NULL,
            error_message TEXT NULL,
            device_timestamp BIGINT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            KEY idx_telemetry_created (created_at),
            KEY idx_telemetry_hash (endpoint_hash)
        ) ENGINE=InnoDB
          DEFAULT CHARSET=utf8mb4
          COLLATE=utf8mb4_unicode_ci
    ");
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

    $endpointHash = $endpoint !== '' ? hash('sha256', $endpoint) : null;
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
    if ($loggedUserId === null && $userId > 0) {
        $loggedUserId = $userId;
    }

    $stmt = $pdo->prepare("
        INSERT INTO browser_push_telemetry (user_id, endpoint_hash, event, user_agent, payload_json, error_message, device_timestamp)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $loggedUserId,
        $endpointHash,
        $event,
        $userAgent,
        $raw,
        $errorMsg,
        $deviceTs
    ]);

    jsonResponse(true, ['message' => 'Telemetry logged.']);
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

    // Run unified decision engine to produce prioritized, deduplicated notifications
    generateAcademicReminders($pdo, $userId);

    // Query pending notifications for this user
    $notifStmt = $pdo->prepare("
        SELECT id, user_id, task_id, channel, event_key, message, send_at, read_at, push_status
        FROM notifications
        WHERE user_id = ?
          AND (sent_at IS NULL AND (push_status IS NULL OR push_status = 'skipped_active'))
          AND send_at <= NOW()
        ORDER BY send_at ASC, id ASC
    ");
    $notifStmt->execute([$userId]);
    $pending = $notifStmt->fetchAll(PDO::FETCH_ASSOC);

    $subStmt = $pdo->prepare("
        SELECT id, endpoint, p256dh, auth, content_encoding, expiration_time
        FROM browser_push_subscriptions
        WHERE user_id = ?
        ORDER BY id DESC
    ");
    $subStmt->execute([$userId]);
    $subscriptions = $subStmt->fetchAll(PDO::FETCH_ASSOC);

    $alreadySentStmt = $pdo->prepare("
        SELECT id FROM push_daily_reminders
        WHERE user_id = ? AND task_id = ? AND reminder_date = CURDATE()
        LIMIT 1
    ");

    $insertDailyStmt = $pdo->prepare("
        INSERT IGNORE INTO push_daily_reminders (user_id, task_id, reminder_date)
        VALUES (?, ?, CURDATE())
    ");

    $deleteExpiredStmt = $pdo->prepare("
        DELETE FROM browser_push_subscriptions WHERE id = ?
    ");

    $recordDeadStmt = $pdo->prepare("
        INSERT INTO browser_push_dead_endpoints (endpoint_hash, user_id, expired_at)
        VALUES (?, ?, NOW())
        ON DUPLICATE KEY UPDATE expired_at = NOW()
    ");

    $markSentStmt = $pdo->prepare("
        UPDATE notifications SET sent_at = NOW(), push_status = ? WHERE id = ?
    ");

    $updatePushStatusStmt = $pdo->prepare("
        UPDATE notifications SET push_status = ? WHERE id = ?
    ");

    $taskStatusStmt = $pdo->prepare("
        SELECT status FROM tasks WHERE id = ?
    ");

    $taskMetaStmt = $pdo->prepare("
        SELECT due_at, status FROM tasks WHERE id = ?
    ");

    $pushSent = 0;
    $pushSkipped = 0;
    $pushExpired = 0;
    $results = [];

    $ignoreSuppression = !empty($_REQUEST['ignore_suppression']) || !empty($_REQUEST['force']);
    $isActive = !$ignoreSuppression && isUserRecentlyActive($pdo, $userId, 2);

    foreach ($pending as $notification) {
        $notifId = (int)$notification['id'];
        $taskId = !empty($notification['task_id']) ? (int)$notification['task_id'] : null;

        // Rule 0: Window Expiration Check (prevent stale alerts after event has passed)
        if (isNotificationWindowExpired($pdo, $notification)) {
            $markSentStmt->execute(['expired', $notifId]);
            $pushExpired++;
            continue;
        }

        // Rule A: In-app only (e.g. tomorrow digest, P3 suggestions)
        if (!isNotificationPushEligible($notification)) {
            $markSentStmt->execute(['in_app_only', $notifId]);
            $pushSkipped++;
            continue;
        }

        // Push-eligible: Check if task completed or already read
        if (!empty($notification['read_at'])) {
            $markSentStmt->execute(['resolved', $notifId]);
            continue;
        }

        $isImminent = false;
        $eventKey = (string)($notification['event_key'] ?? '');
        if (str_contains($eventKey, 'class_') || str_contains($eventKey, 'urgent_') || str_contains($eventKey, 'overdue')) {
            $isImminent = true;
        }
        if ($taskId !== null) {
            $taskMetaStmt->execute([$taskId]);
            $taskMeta = $taskMetaStmt->fetch(PDO::FETCH_ASSOC);
            if ($taskMeta) {
                if ($taskMeta['status'] === 'completed') {
                    $markSentStmt->execute(['resolved', $notifId]);
                    continue;
                }
                if (!empty($taskMeta['due_at'])) {
                    $dueTime = strtotime($taskMeta['due_at']);
                    if ($dueTime <= time() + 7200) {
                        $isImminent = true;
                    }
                }
            }
        }

        // Rule B: Active user push postponement
        // Active window calibrated to 2 minutes (120s) to avoid delaying pushes by 15 minutes.
        // We postpone at most once: if already 'skipped_active', proceed with push.
        // Imminent events (<= 2h or overdue) are never postponed.
        $alreadyPostponed = ($notification['push_status'] === 'skipped_active');
        if ($isActive && !$alreadyPostponed && !$isImminent) {
            $updatePushStatusStmt->execute(['skipped_active', $notifId]);
            $pushSkipped++;
            continue;
        }

        // User is outside active window: check daily reminder deduplication (only for non-imminent notifications)
        $alreadyReminded = false;
        if ($taskId !== null && !$isImminent) {
            $alreadySentStmt->execute([$userId, $taskId]);
            if ($alreadySentStmt->fetchColumn()) {
                $alreadyReminded = true;
                $pushSkipped++;
                $markSentStmt->execute(['already_reminded_today', $notifId]);
                continue;
            }
        }

        // Rule C: No browser subscription registered
        if (empty($subscriptions)) {
            // Do not fabricate delivery success (sent_at stays NULL); avoid infinite reprocessing
            $updatePushStatusStmt->execute(['no_subscription', $notifId]);
            $pushSkipped++;
            continue;
        }

        // Rule D: Subscriptions exist: dispatch Web Push
        $pushTag = $taskId !== null ? 'deadline-task-' . $taskId : 'academic-notif-' . $notifId;
        $notificationPayload = [
            'title' => 'Study Planner',
            'body'  => (string)$notification['message'],
            'tag'   => $pushTag,
            'renotify' => true,
            'data'  => [
                'url' => APP_URL . ($taskId !== null ? '/deadlines.php' : '/notifications.php'),
                'notification_id' => $notifId,
                'task_id' => $taskId
            ]
        ];

        $taskSent = false;
        foreach ($subscriptions as $sub) {
            try {
                $sendRes = webPushSend($sub, $notificationPayload);
                if (!empty($sendRes['success'])) {
                    $taskSent = true;
                    $pushSent++;
                    $results[] = [
                        'task_id' => $taskId,
                        'notification_id' => $notifId,
                        'endpoint' => $sub['endpoint'],
                        'success' => true
                    ];
                }
            } catch (Throwable $e) {
                $errLower = strtolower($e->getMessage());
                if (
                    str_contains($errLower, 'http 404') ||
                    str_contains($errLower, 'http 410') ||
                    str_contains($errLower, 'returned http 404') ||
                    str_contains($errLower, 'returned http 410')
                ) {
                    $recordDeadStmt->execute([hash('sha256', $sub['endpoint']), $userId]);
                    $deleteExpiredStmt->execute([(int)$sub['id']]);
                    $pushExpired++;
                }
                $results[] = [
                    'task_id' => $taskId,
                    'notification_id' => $notifId,
                    'endpoint' => $sub['endpoint'],
                    'success' => false,
                    'error' => $e->getMessage()
                ];
            }
        }

        if ($taskSent) {
            if ($taskId !== null) {
                $insertDailyStmt->execute([$userId, $taskId]);
            }
            $markSentStmt->execute(['sent', $notifId]);
        } else {
            $updatePushStatusStmt->execute(['failed', $notifId]);
        }
    }

    try {
        $hbStmt = $pdo->prepare("
            INSERT INTO system_heartbeats (service_name, last_run_at, status, meta_json)
            VALUES ('scheduler', NOW(), 'ok', ?)
            ON DUPLICATE KEY UPDATE last_run_at = NOW(), status = VALUES(status), meta_json = VALUES(meta_json)
        ");
        $hbStmt->execute([json_encode([
            'checked' => count($pending),
            'push_sent' => $pushSent,
            'push_skipped' => $pushSkipped,
            'push_expired' => $pushExpired
        ])]);
    } catch (Throwable $e) {}

    jsonResponse(
        true,
        [
            'message' => "Deadline reminder check completed: {$pushSent} sent, {$pushSkipped} skipped, {$pushExpired} expired removed.",
            'notifications_checked' => count($pending),
            'push_sent' => $pushSent,
            'push_skipped' => $pushSkipped,
            'push_expired' => $pushExpired,
            'results' => $results
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
        WHERE service_name = 'scheduler'
        LIMIT 1
    ");
    $stmt->execute();
    $hb = $stmt->fetch(PDO::FETCH_ASSOC);

    $meta = [];
    if (!empty($hb['meta_json'])) {
        $meta = json_decode((string)$hb['meta_json'], true) ?: [];
    }

    $daemonLog = '';
    if (file_exists('/var/log/check_deadlines.log')) {
        $daemonLog = (string)@file_get_contents('/var/log/check_deadlines.log');
        if (strlen($daemonLog) > 3000) {
            $daemonLog = substr($daemonLog, -3000);
        }
    }

    $psAux = (string)@shell_exec('ps aux 2>&1');

    jsonResponse(
        true,
        [
            'active' => $hb && (int)($hb['elapsed_seconds'] ?? 999) <= 120,
            'heartbeat' => $hb ?: null,
            'meta' => $meta,
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
        SELECT id, user_id, endpoint_hash, event, user_agent, payload_json, error_message, device_timestamp, created_at
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