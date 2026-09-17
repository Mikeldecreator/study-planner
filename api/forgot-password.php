<?php

declare(strict_types=1);

/*
 * Start buffering before loading other PHP files.
 * This prevents accidental output from corrupting JSON.
 */
ob_start();

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);


/*
 * ============================================================
 * RESEND TEST MODE
 * ============================================================
 *
 * TRUE:
 *   Reset emails are sent to Resend's test recipient:
 *   delivered@resend.dev
 *
 * FALSE:
 *   Reset emails are sent to the actual user's email.
 *
 * Keep this TRUE while testing locally.
 */
define(
    'EMAIL_TEST_MODE',
    true
);

define(
    'EMAIL_TEST_RECIPIENT',
    'delivered@resend.dev'
);


require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../cron/Mailer.php';


header(
    'Content-Type: application/json; charset=utf-8'
);


// ============================================================
// JSON RESPONSE
// ============================================================

function forgotResponse(
    array $data,
    int $status = 200
): never {

    /*
     * Remove every buffered output before returning JSON.
     */
    while (
        ob_get_level() > 0
    ) {
        ob_end_clean();
    }

    http_response_code(
        $status
    );

    header(
        'Content-Type: application/json; charset=utf-8'
    );

    $json =
        json_encode(
            $data,
            JSON_UNESCAPED_SLASHES |
            JSON_UNESCAPED_UNICODE
        );

    if (
        $json === false
    ) {
        $json =
            '{"ok":false,"error":"Unable to create server response."}';

        http_response_code(500);
    }

    echo $json;

    exit;
}


// ============================================================
// REQUEST METHOD
// ============================================================

if (
    ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST'
) {

    forgotResponse(
        [
            'ok' => false,
            'error' =>
                'Method not allowed.'
        ],
        405
    );
}


// ============================================================
// MAIN PROCESS
// ============================================================

try {

    // ========================================================
    // READ JSON REQUEST
    // ========================================================

    $rawBody =
        file_get_contents(
            'php://input'
        );


    if (
        $rawBody === false ||
        trim($rawBody) === ''
    ) {

        forgotResponse(
            [
                'ok' => false,
                'error' =>
                    'Please enter your email address.'
            ],
            422
        );
    }


    $body =
        json_decode(
            $rawBody,
            true
        );


    if (
        !is_array($body)
    ) {

        forgotResponse(
            [
                'ok' => false,
                'error' =>
                    'Invalid request data.'
            ],
            400
        );
    }


    // ========================================================
    // GET EMAIL
    // ========================================================

    $email =
        strtolower(
            trim(
                (string)(
                    $body['email'] ?? ''
                )
            )
        );


    // ========================================================
    // VALIDATE EMAIL
    // ========================================================

    if (
        !filter_var(
            $email,
            FILTER_VALIDATE_EMAIL
        )
    ) {

        forgotResponse(
            [
                'ok' => false,
                'error' =>
                    'Please enter a valid email address.'
            ],
            422
        );
    }


    // ========================================================
    // DATABASE
    // ========================================================

    $db =
        getDb();


    $stmt =
        $db->prepare(
            'SELECT id, full_name, email
             FROM users
             WHERE email = ?
             LIMIT 1'
        );


    $stmt->execute([
        $email
    ]);


    $user =
        $stmt->fetch();


    // ========================================================
    // ACCOUNT DOES NOT EXIST
    // ========================================================

    if (
        !$user
    ) {

        forgotResponse(
            [
                'ok' => true,
                'message' =>
                    'If an account exists with that email address, a password reset link has been sent.'
            ]
        );
    }


    // ========================================================
    // REMOVE OLD RESET TOKENS
    // ========================================================

    $delete =
        $db->prepare(
            'DELETE FROM password_resets
             WHERE user_id = ?'
        );


    $delete->execute([
        (int)$user['id']
    ]);


    // ========================================================
    // GENERATE SECURE TOKEN
    // ========================================================

    $rawToken =
        bin2hex(
            random_bytes(32)
        );


    $tokenHash =
        hash(
            'sha256',
            $rawToken
        );


    $expiresAt =
        date(
            'Y-m-d H:i:s',
            time() + 3600
        );


    // ========================================================
    // SAVE TOKEN
    // ========================================================

    $insert =
        $db->prepare(
            'INSERT INTO password_resets
            (
                user_id,
                token_hash,
                expires_at
            )
            VALUES (?, ?, ?)'
        );


    $insert->execute([
        (int)$user['id'],
        $tokenHash,
        $expiresAt
    ]);


    // ========================================================
    // CREATE RESET URL
    // ========================================================

    $resetUrl =
        rtrim(
            APP_URL,
            '/'
        ) .
        '/reset-password.php?token=' .
        urlencode(
            $rawToken
        );


    // ========================================================
    // EMAIL CONTENT
    // ========================================================

    $subject =
        'Reset your Study Planner password';


    $bodyText =
        "Hello " .
        $user['full_name'] .
        ",\n\n" .

        "We received a request to reset your Study Planner password.\n\n" .

        "Open the following link to create a new password:\n\n" .

        $resetUrl .
        "\n\n" .

        "This password reset link will expire after 60 minutes and can only be used once.\n\n" .

        "If you did not request this password reset, you can safely ignore this email.\n\n" .

        "Study Planner";


    // ========================================================
    // SELECT EMAIL RECIPIENT
    // ========================================================

    if (
        EMAIL_TEST_MODE
    ) {

        /*
         * Resend testing:
         * always send to delivered@resend.dev
         */
        $recipientEmail =
            EMAIL_TEST_RECIPIENT;

        $recipientName =
            'Resend Test Recipient';

    } else {

        /*
         * Production:
         * send to the actual registered user.
         */
        $recipientEmail =
            $user['email'];

        $recipientName =
            $user['full_name'];
    }


    // ========================================================
    // SEND EMAIL
    // ========================================================

    $emailSent =
        sendReminderEmail(
            $recipientEmail,
            $recipientName,
            $subject,
            $bodyText,
            $resetUrl
        );


    // ========================================================
    // EMAIL FAILURE
    // ========================================================

    if (
        !$emailSent
    ) {

        /*
         * Delete token because the email was not sent.
         */
        $cleanup =
            $db->prepare(
                'DELETE FROM password_resets
                 WHERE token_hash = ?'
            );


        $cleanup->execute([
            $tokenHash
        ]);


        error_log(
            '[FORGOT PASSWORD] Resend failed. ' .
            'Original user: ' .
            $user['email'] .
            ' | Recipient: ' .
            $recipientEmail
        );


        forgotResponse(
            [
                'ok' => false,
                'error' =>
                    'The reset email could not be sent. Please check your Resend settings and try again.'
            ],
            500
        );
    }


    // ========================================================
    // SUCCESS
    // ========================================================

    forgotResponse(
        [
            'ok' => true,
            'message' =>
                'Your password reset request was processed successfully.'
        ]
    );


} catch (
    PDOException $e
) {

    error_log(
        '[FORGOT PASSWORD DB ERROR] ' .
        $e->getMessage()
    );


    forgotResponse(
        [
            'ok' => false,
            'error' =>
                'The database is currently unavailable. Please try again.'
        ],
        503
    );


} catch (
    Throwable $e
) {

    error_log(
        '[FORGOT PASSWORD ERROR] ' .
        $e->getMessage()
    );


    forgotResponse(
        [
            'ok' => false,
            'error' =>
                'Unable to process your request. Please try again.'
        ],
        500
    );
}
