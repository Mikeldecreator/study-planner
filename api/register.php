<?php

declare(strict_types=1);

/*
 * Start output buffering BEFORE loading application files.
 * This prevents accidental PHP output from corrupting JSON.
 */
ob_start();

ini_set('display_errors', '0');
ini_set('display_startup_errors', '0');
error_reporting(E_ALL);

require_once __DIR__ . '/../includes/auth.php';

header('Content-Type: application/json; charset=utf-8');


/**
 * Always return clean JSON.
 */
function registerResponse(
    array $data,
    int $status = 200
): never {

    if (ob_get_level() > 0) {
        ob_clean();
    }

    http_response_code($status);

    echo json_encode(
        $data,
        JSON_UNESCAPED_SLASHES |
        JSON_UNESCAPED_UNICODE
    );

    exit;
}


// ============================================================
// REQUEST METHOD
// ============================================================

if (
    ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST'
) {
    registerResponse(
        [
            'ok' => false,
            'error' => 'Method not allowed.'
        ],
        405
    );
}


// ============================================================
// READ JSON
// ============================================================

try {

    $rawBody = file_get_contents('php://input');

    if (
        $rawBody === false ||
        trim($rawBody) === ''
    ) {
        registerResponse(
            [
                'ok' => false,
                'error' => 'Please complete all required fields.'
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
        registerResponse(
            [
                'ok' => false,
                'error' => 'Invalid request data.'
            ],
            400
        );
    }


    // ========================================================
    // FORM VALUES
    // ========================================================

    $name = trim(
        (string) (
            $body['full_name'] ??
            $body['name'] ??
            ''
        )
    );


    $email = strtolower(
        trim(
            (string) (
                $body['email'] ??
                ''
            )
        )
    );


    $password = (string) (
        $body['password'] ??
        ''
    );


    // ========================================================
    // VALIDATION
    // ========================================================

    if (
        $name === '' ||
        $email === '' ||
        $password === ''
    ) {
        registerResponse(
            [
                'ok' => false,
                'error' => 'All fields are required.'
            ],
            422
        );
    }


    if (
        !filter_var(
            $email,
            FILTER_VALIDATE_EMAIL
        )
    ) {
        registerResponse(
            [
                'ok' => false,
                'error' => 'Please enter a valid email address.'
            ],
            422
        );
    }


    if (
        strlen($password) < 8
    ) {
        registerResponse(
            [
                'ok' => false,
                'error' =>
                    'Password must be at least 8 characters.'
            ],
            422
        );
    }


    // ========================================================
    // DATABASE
    // ========================================================

    $db = getDb();


    // ========================================================
    // CHECK EXISTING EMAIL
    // ========================================================

    $stmt = $db->prepare(
        'SELECT id
         FROM users
         WHERE email = ?
         LIMIT 1'
    );


    $stmt->execute([
        $email
    ]);


    if (
        $stmt->fetch()
    ) {
        registerResponse(
            [
                'ok' => false,
                'error' =>
                    'An account with this email already exists. Please log in instead.'
            ],
            409
        );
    }


    // ========================================================
    // CREATE USER
    // ========================================================

    $result = registerUser(
        $name,
        $email,
        $password
    );


    if (
        !is_array($result) ||
        !($result['ok'] ?? false)
    ) {
        registerResponse(
            [
                'ok' => false,
                'error' =>
                    $result['error'] ??
                    'Could not create your account.'
            ],
            422
        );
    }


    // ========================================================
    // AUTOMATIC LOGIN
    // ========================================================

    $loggedIn = attemptLogin(
        $email,
        $password
    );


    if (!$loggedIn) {

        registerResponse(
            [
                'ok' => false,
                'error' =>
                    'Your account was created, but automatic login failed. Please log in manually.'
            ],
            500
        );
    }


    // ========================================================
    // SUCCESS
    // ========================================================

    registerResponse(
        [
            'ok' => true,
            'message' =>
                'Account created successfully.',
            'redirect' =>
                'dashboard.php',
            'user' => [
                'id' =>
                    (int) (
                        $_SESSION['user_id'] ??
                        0
                    ),

                'full_name' =>
                    (string) (
                        $_SESSION['user_name'] ??
                        $name
                    ),

                'name' =>
                    (string) (
                        $_SESSION['user_name'] ??
                        $name
                    )
            ]
        ],
        200
    );


} catch (
    PDOException $e
) {

    error_log(
        '[REGISTER DB ERROR] ' .
        $e->getMessage()
    );


    registerResponse(
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
        '[REGISTER ERROR] ' .
        $e->getMessage()
    );


    registerResponse(
        [
            'ok' => false,
            'error' =>
                'Unable to create the account right now. Please try again.'
        ],
        500
    );
}