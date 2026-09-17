<?php

declare(strict_types=1);

/*
 * JSON API endpoint.
 *
 * IMPORTANT:
 * This file must return JSON only.
 */


/* ------------------------------------------------------------
 * Start output buffering BEFORE loading any other PHP file.
 * ------------------------------------------------------------ */

ob_start();


/* ------------------------------------------------------------
 * Never display PHP warnings/notices directly to the browser.
 * They would corrupt the JSON response.
 * ------------------------------------------------------------ */

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');

error_reporting(E_ALL);


/* ------------------------------------------------------------
 * If a fatal PHP error happens, return JSON instead of HTML.
 * ------------------------------------------------------------ */

register_shutdown_function(
    static function (): void {

        $error = error_get_last();

        if ($error === null) {
            return;
        }

        $fatalTypes = [
            E_ERROR,
            E_PARSE,
            E_CORE_ERROR,
            E_COMPILE_ERROR,
        ];

        if (!in_array($error['type'], $fatalTypes, true)) {
            return;
        }

        if (ob_get_level() > 0) {
            ob_clean();
        }

        http_response_code(500);

        header(
            'Content-Type: application/json; charset=utf-8'
        );

        echo json_encode(
            [
                'ok' => false,
                'error' =>
                    'A server error occurred while processing your login.'
            ],
            JSON_UNESCAPED_SLASHES |
            JSON_UNESCAPED_UNICODE
        );
    }
);


/* ------------------------------------------------------------
 * Load authentication functions.
 * ------------------------------------------------------------ */

require_once __DIR__ . '/../includes/auth.php';


header(
    'Content-Type: application/json; charset=utf-8'
);


/* ============================================================
 * JSON RESPONSE
 * ============================================================ */

function loginResponse(
    array $data,
    int $status = 200
): never {

    /*
     * Remove ALL accidental output.
     */
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    http_response_code($status);

    header(
        'Content-Type: application/json; charset=utf-8'
    );

    $json = json_encode(
        $data,
        JSON_UNESCAPED_SLASHES |
        JSON_UNESCAPED_UNICODE
    );

    if ($json === false) {
        $json = '{"ok":false,"error":"Unable to create server response."}';
        http_response_code(500);
    }

    echo $json;

    exit;
}


/* ============================================================
 * REQUEST METHOD
 * ============================================================ */

if (
    ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST'
) {

    loginResponse(
        [
            'ok' => false,
            'error' => 'Method not allowed.'
        ],
        405
    );
}


/* ============================================================
 * REQUEST
 * ============================================================ */

try {

    $rawBody = file_get_contents(
        'php://input'
    );


    if (
        $rawBody === false ||
        trim($rawBody) === ''
    ) {

        loginResponse(
            [
                'ok' => false,
                'error' =>
                    'Please enter your email and password.'
            ],
            422
        );
    }


    $body = json_decode(
        $rawBody,
        true
    );


    if (
        !is_array($body)
    ) {

        loginResponse(
            [
                'ok' => false,
                'error' =>
                    'Invalid request data.'
            ],
            400
        );
    }


    /* --------------------------------------------------------
     * Read credentials
     * -------------------------------------------------------- */

    $email = strtolower(
        trim(
            (string) (
                $body['email'] ?? ''
            )
        )
    );


    $password = (string) (
        $body['password'] ?? ''
    );


    /* --------------------------------------------------------
     * Validate email
     * -------------------------------------------------------- */

    if (
        !filter_var(
            $email,
            FILTER_VALIDATE_EMAIL
        )
    ) {

        loginResponse(
            [
                'ok' => false,
                'error' =>
                    'Please enter a valid email address.'
            ],
            422
        );
    }


    /* --------------------------------------------------------
     * Validate password
     * -------------------------------------------------------- */

    if (
        $password === ''
    ) {

        loginResponse(
            [
                'ok' => false,
                'error' =>
                    'Please enter your password.'
            ],
            422
        );
    }


    /* --------------------------------------------------------
     * Attempt login
     * -------------------------------------------------------- */

    $loggedIn = attemptLogin(
        $email,
        $password
    );


    /* ========================================================
     * LOGIN FAILED
     * ======================================================== */

    if (
        !$loggedIn
    ) {

        loginResponse(
            [
                'ok' => false,
                'error' =>
                    'Incorrect email or password.'
            ],
            401
        );
    }


    /* ========================================================
     * LOGIN SUCCESS
     * ======================================================== */

    loginResponse(
        [
            'ok' => true,

            'message' =>
                'Login successful.',

            'user' => [
                'id' =>
                    (int) (
                        $_SESSION['user_id'] ?? 0
                    ),

                'name' =>
                    (string) (
                        $_SESSION['user_name'] ?? ''
                    )
            ],

            'redirect' =>
                './dashboard.php'
        ],
        200
    );


} catch (
    PDOException $e
) {

    error_log(
        '[LOGIN DB ERROR] ' .
        $e->getMessage()
    );


    loginResponse(
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
        '[LOGIN ERROR] ' .
        $e->getMessage()
    );


    loginResponse(
        [
            'ok' => false,
            'error' =>
                'Unable to process your login right now. Please try again.'
        ],
        500
    );
}

