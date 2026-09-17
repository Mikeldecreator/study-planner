<?php

declare(strict_types=1);

/**
 * Study Planner - Resend Mailer
 *
 * Sends email through the Resend API using cURL.
 */

function sendReminderEmail(
    string $toEmail,
    string $toName,
    string $subject,
    string $bodyText,
    ?string $resetUrl = null
): bool {

    // --------------------------------------------------------
    // EMAIL ENABLED CHECK
    // --------------------------------------------------------

    if (
        !defined('EMAIL_ENABLED') ||
        EMAIL_ENABLED !== true
    ) {
        error_log(
            '[RESEND] Email sending is disabled.'
        );

        return false;
    }


    // --------------------------------------------------------
    // RESEND API KEY
    // --------------------------------------------------------

    if (
        !defined('RESEND_API_KEY') ||
        trim((string) RESEND_API_KEY) === ''
    ) {
        error_log(
            '[RESEND] RESEND_API_KEY is missing.'
        );

        return false;
    }


    // --------------------------------------------------------
    // SENDER
    // --------------------------------------------------------

    if (
        !defined('MAIL_FROM_EMAIL') ||
        trim((string) MAIL_FROM_EMAIL) === ''
    ) {
        error_log(
            '[RESEND] MAIL_FROM_EMAIL is missing.'
        );

        return false;
    }


    $fromName =
        defined('MAIL_FROM_NAME')
            ? MAIL_FROM_NAME
            : 'Study Planner';


    // --------------------------------------------------------
    // VALIDATE RECIPIENT
    // --------------------------------------------------------

    if (
        !filter_var(
            $toEmail,
            FILTER_VALIDATE_EMAIL
        )
    ) {
        error_log(
            '[RESEND] Invalid recipient email: ' .
            $toEmail
        );

        return false;
    }


    // --------------------------------------------------------
    // CREATE HTML EMAIL
    // --------------------------------------------------------

    $safeName =
        htmlspecialchars(
            $toName,
            ENT_QUOTES,
            'UTF-8'
        );


    $safeSubject =
        htmlspecialchars(
            $subject,
            ENT_QUOTES,
            'UTF-8'
        );


    $safeBody =
        nl2br(
            htmlspecialchars(
                $bodyText,
                ENT_QUOTES,
                'UTF-8'
            )
        );


    $buttonHtml = '';


    if (
        !empty($resetUrl)
    ) {

        $safeResetUrl =
            htmlspecialchars(
                $resetUrl,
                ENT_QUOTES,
                'UTF-8'
            );


        $buttonHtml = '
            <p style="margin: 30px 0;">
                <a
                    href="' . $safeResetUrl . '"
                    style="
                        display:inline-block;
                        padding:14px 24px;
                        background:#2563eb;
                        color:#ffffff;
                        text-decoration:none;
                        border-radius:8px;
                        font-weight:600;
                    "
                >
                    Reset My Password
                </a>
            </p>
        ';
    }


    $html = '
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>' . $safeSubject . '</title>
    </head>

    <body
        style="
            margin:0;
            padding:0;
            background:#f5f7fb;
            font-family:Arial,Helvetica,sans-serif;
            color:#1f2937;
        "
    >

        <div
            style="
                max-width:600px;
                margin:40px auto;
                background:#ffffff;
                border-radius:12px;
                padding:32px;
                box-shadow:0 4px 20px rgba(0,0,0,.08);
            "
        >

            <h2
                style="
                    margin-top:0;
                    color:#111827;
                "
            >
                Study Planner
            </h2>

            <p>
                Hello ' . $safeName . ',
            </p>

            <p>
                We received a request to reset your
                Study Planner password.
            </p>

            <p>
                Click the button below to create a
                new password.
            </p>

            ' . $buttonHtml . '

            <div
                style="
                    margin-top:25px;
                    padding:15px;
                    background:#f3f4f6;
                    border-radius:8px;
                    font-size:14px;
                    line-height:1.6;
                "
            >
                This password reset link will expire
                after <strong>60 minutes</strong>
                and can only be used once.
            </div>

            <p
                style="
                    margin-top:25px;
                    color:#6b7280;
                    font-size:14px;
                "
            >
                If you did not request this password
                reset, you can safely ignore this email.
            </p>

            <p
                style="
                    margin-top:30px;
                    color:#6b7280;
                    font-size:14px;
                "
            >
                Study Planner
            </p>

        </div>

    </body>
    </html>
    ';


    // --------------------------------------------------------
    // RESEND PAYLOAD
    // --------------------------------------------------------

    $payload = [
        'from' =>
            $fromName .
            ' <' .
            MAIL_FROM_EMAIL .
            '>',

        'to' => [
            $toEmail
        ],

        'subject' =>
            $subject,

        'html' =>
            $html,

        'text' =>
            $bodyText
    ];


    // --------------------------------------------------------
    // CURL
    // --------------------------------------------------------

    $ch =
        curl_init(
            'https://api.resend.com/emails'
        );


    if ($ch === false) {

        error_log(
            '[RESEND] Unable to initialize cURL.'
        );

        return false;
    }


    curl_setopt_array(
        $ch,
        [
            CURLOPT_POST => true,

            CURLOPT_RETURNTRANSFER => true,

            CURLOPT_HTTPHEADER => [
                'Authorization: Bearer ' .
                RESEND_API_KEY,

                'Content-Type: application/json',

                'Accept: application/json'
            ],

            CURLOPT_POSTFIELDS =>
                json_encode(
                    $payload,
                    JSON_UNESCAPED_SLASHES |
                    JSON_UNESCAPED_UNICODE
                ),

            CURLOPT_TIMEOUT => 30,

            CURLOPT_CONNECTTIMEOUT => 10
        ]
    );


    $response =
        curl_exec(
            $ch
        );


    $curlError =
        curl_error(
            $ch
        );


    $httpCode =
        (int) curl_getinfo(
            $ch,
            CURLINFO_HTTP_CODE
        );


    curl_close(
        $ch
    );


    // --------------------------------------------------------
    // CURL ERROR
    // --------------------------------------------------------

    if (
        $response === false
    ) {

        error_log(
            '[RESEND CURL ERROR] ' .
            $curlError
        );

        return false;
    }


    // --------------------------------------------------------
    // RESEND RESPONSE
    // --------------------------------------------------------

    if (
        $httpCode < 200 ||
        $httpCode >= 300
    ) {

        error_log(
            '[RESEND ERROR] HTTP ' .
            $httpCode .
            ' Response: ' .
            $response
        );

        return false;
    }


    error_log(
        '[RESEND EMAIL SENT] ' .
        $toEmail
    );


    return true;
}