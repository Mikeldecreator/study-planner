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
        $delOldStmt = $pdo->prepare("
            DELETE FROM browser_push_subscriptions
            WHERE endpoint_hash = ?
        ");
        $delOldStmt->execute([hash('sha256', $oldEndpoint)]);
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

    foreach ($results as $result) {
        if (!empty($result['success'])) {
            $successful++;
        } else {
            $failed++;
            $errorMsg = strtolower((string)($result['error'] ?? ''));
            if (str_contains($errorMsg, 'http 404') || str_contains($errorMsg, 'http 410')) {
                foreach ($subscriptions as $sub) {
                    if ($sub['endpoint'] === ($result['endpoint'] ?? '')) {
                        $deleteExpiredStmt->execute([(int)$sub['id']]);
                        break;
                    }
                }
            }
        }
    }

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
        SELECT id, user_id, task_id, channel, event_key, message, send_at
        FROM notifications
        WHERE user_id = ?
          AND sent_at IS NULL
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

    $markSentStmt = $pdo->prepare("
        UPDATE notifications SET sent_at = NOW() WHERE id = ?
    ");

    $pushSent = 0;
    $pushSkipped = 0;
    $pushExpired = 0;
    $results = [];

    $ignoreSuppression = !empty($_REQUEST['ignore_suppression']) || !empty($_REQUEST['force']);
    $isActive = !$ignoreSuppression && isUserRecentlyActive($pdo, $userId, 15);

    foreach ($pending as $notification) {
        $notifId = (int)$notification['id'];
        $taskId = !empty($notification['task_id']) ? (int)$notification['task_id'] : null;

        if ($isActive) {
            // User active within 15m: suppress background push, keep in-app
            $pushSkipped++;
        } elseif (!isNotificationPushEligible($notification)) {
            // In-app only (e.g. tomorrow digest, P3 suggestions)
            $pushSkipped++;
        } else {
            $alreadyReminded = false;
            if ($taskId !== null) {
                $alreadySentStmt->execute([$userId, $taskId]);
                if ($alreadySentStmt->fetchColumn()) {
                    $alreadyReminded = true;
                    $pushSkipped++;
                }
            }

            if (!$alreadyReminded && !empty($subscriptions)) {
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
                        if (str_contains($errLower, 'http 404') || str_contains($errLower, 'http 410')) {
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

                if ($taskSent && $taskId !== null) {
                    $insertDailyStmt->execute([$userId, $taskId]);
                }
            }
        }

        $markSentStmt->execute([$notifId]);
    }

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
   UNKNOWN ACTION
   ============================================================ */

jsonResponse(
    false,
    [
        'message' => 'Unknown push action.'
    ],
    400
);