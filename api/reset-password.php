<?php

declare(strict_types=1);


// ============================================================
// OUTPUT BUFFERING
// ============================================================

ob_start();


// ============================================================
// PHP ERROR SETTINGS
// ============================================================

ini_set(
    'display_errors',
    '0'
);

ini_set(
    'display_startup_errors',
    '0'
);

error_reporting(E_ALL);


// ============================================================
// CLEAN FATAL ERROR RESPONSE
// ============================================================

register_shutdown_function(
    static function (): void {

        $error = error_get_last();

        if (
            $error === null
        ) {
            return;
        }

        $fatalTypes = [
            E_ERROR,
            E_PARSE,
            E_CORE_ERROR,
            E_COMPILE_ERROR
        ];

        if (
            !in_array(
                $error['type'],
                $fatalTypes,
                true
            )
        ) {
            return;
        }

        while (
            ob_get_level() > 0
        ) {
            ob_end_clean();
        }

        http_response_code(500);

        header(
            'Content-Type: application/json; charset=utf-8'
        );

        echo json_encode(
            [
                'ok' => false,
                'error' =>
                    'A server error occurred while resetting your password.'
            ],
            JSON_UNESCAPED_SLASHES |
            JSON_UNESCAPED_UNICODE
        );
    }
);


// ============================================================
// LOAD FILES
// ============================================================

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/db.php';


// ============================================================
// JSON HEADER
// ============================================================

header(
    'Content-Type: application/json; charset=utf-8'
);


// ============================================================
// JSON RESPONSE
// ============================================================

function resetResponse(
    array $data,
    int $status = 200
): never {

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

    $json = json_encode(
        $data,
        JSON_UNESCAPED_SLASHES |
        JSON_UNESCAPED_UNICODE
    );

    if (
        $json === false
    ) {
        http_response_code(500);

        $json =
            '{"ok":false,"error":"Unable to create server response."}';
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

    resetResponse(
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
    // READ REQUEST
    // ========================================================

    $rawBody =
        file_get_contents(
            'php://input'
        );


    if (
        $rawBody === false ||
        trim($rawBody) === ''
    ) {

        resetResponse(
            [
                'ok' => false,
                'error' =>
                    'Invalid request.'
            ],
            400
        );
    }


    // ========================================================
    // DECODE JSON
    // ========================================================

    $body =
        json_decode(
            $rawBody,
            true
        );


    if (
        !is_array($body)
    ) {

        resetResponse(
            [
                'ok' => false,
                'error' =>
                    'Invalid request data.'
            ],
            400
        );
    }


    // ========================================================
    // GET VALUES
    // ========================================================

    $token =
        trim(
            (string)(
                $body['token'] ?? ''
            )
        );


    $password =
        (string)(
            $body['password'] ?? ''
        );


    $confirmPassword =
        (string)(
            $body['confirm_password'] ?? ''
        );


    // ========================================================
    // TOKEN VALIDATION
    // ========================================================

    if (
        $token === '' ||
        !preg_match(
            '/^[a-f0-9]{64}$/i',
            $token
        )
    ) {

        resetResponse(
            [
                'ok' => false,
                'error' =>
                    'This password reset link is invalid or has expired.'
            ],
            400
        );
    }


    // ========================================================
    // PASSWORD VALIDATION
    // ========================================================

    if (
        strlen($password) < 8
    ) {

        resetResponse(
            [
                'ok' => false,
                'error' =>
                    'Password must be at least 8 characters long.'
            ],
            422
        );
    }


    if (
        $password !== $confirmPassword
    ) {

        resetResponse(
            [
                'ok' => false,
                'error' =>
                    'Passwords do not match.'
            ],
            422
        );
    }


    // ========================================================
    // DATABASE
    // ========================================================

    $db =
        getDb();


    // ========================================================
    // HASH TOKEN
    // ========================================================

    $tokenHash =
        hash(
            'sha256',
            $token
        );


    // ========================================================
    // FIND TOKEN
    // ========================================================

    $stmt =
        $db->prepare(
            'SELECT
                id,
                user_id
             FROM password_resets
             WHERE token_hash = ?
               AND expires_at > NOW()
             LIMIT 1'
        );


    $stmt->execute([
        $tokenHash
    ]);


    $reset =
        $stmt->fetch();


    if (
        !$reset
    ) {

        resetResponse(
            [
                'ok' => false,
                'error' =>
                    'This password reset link is invalid or has expired.'
            ],
            400
        );
    }


    // ========================================================
    // PASSWORD HASH
    // ========================================================

    $passwordHash =
        password_hash(
            $password,
            PASSWORD_DEFAULT
        );


    if (
        $passwordHash === false
    ) {

        resetResponse(
            [
                'ok' => false,
                'error' =>
                    'Unable to secure your new password.'
            ],
            500
        );
    }


    // ========================================================
    // TRANSACTION
    // ========================================================

    $db->beginTransaction();


    // ========================================================
    // UPDATE PASSWORD
    // ========================================================

    $update =
        $db->prepare(
            'UPDATE users
             SET password_hash = ?
             WHERE id = ?'
        );


    $update->execute([
        $passwordHash,
        (int)$reset['user_id']
    ]);


    if (
        $update->rowCount() < 1
    ) {

        throw new RuntimeException(
            'The password could not be updated.'
        );
    }


    // ========================================================
    // DELETE TOKEN
    // ========================================================

    $delete =
        $db->prepare(
            'DELETE FROM password_resets
             WHERE id = ?'
        );


    $delete->execute([
        (int)$reset['id']
    ]);


    // ========================================================
    // COMMIT
    // ========================================================

    $db->commit();


    // ========================================================
    // SUCCESS
    // ========================================================

    resetResponse(
        [
            'ok' => true,
            'message' =>
                'Your password has been reset successfully.'
        ],
        200
    );


} catch (
    Throwable $e
) {

    if (
        isset($db) &&
        $db instanceof PDO &&
        $db->inTransaction()
    ) {
        $db->rollBack();
    }


    error_log(
        '[RESET PASSWORD ERROR] ' .
        $e->getMessage()
    );


    resetResponse(
        [
            'ok' => false,
            'error' =>
                'Unable to reset your password. Please try again.'
        ],
        500
    );
}

