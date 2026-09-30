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


/**
 * Send account email verification link through the Resend API using cURL.
 */
function sendVerificationEmail(
    string $toEmail,
    string $toName,
    string $verifyUrl
): bool {

    if (
        !defined('EMAIL_ENABLED') ||
        EMAIL_ENABLED !== true
    ) {
        error_log(
            '[RESEND] Email sending is disabled.'
        );

        return false;
    }

    if (
        !defined('RESEND_API_KEY') ||
        trim((string) RESEND_API_KEY) === ''
    ) {
        error_log(
            '[RESEND] RESEND_API_KEY is missing.'
        );

        return false;
    }

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

    $safeName = htmlspecialchars($toName, ENT_QUOTES, 'UTF-8');
    $safeUrl = htmlspecialchars($verifyUrl, ENT_QUOTES, 'UTF-8');
    $subject = 'Verify your Study Planner account';

    $bodyText = "Hello {$toName},\n\nThank you for signing up for Study Planner! Please verify your email address to activate your account by clicking the link below:\n\n{$verifyUrl}\n\nThis verification link will expire in 24 hours.\n\nIf you did not create an account, you can safely ignore this email.\n\n— The Study Planner Team";

    $html = '
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Verify your Study Planner account</title>
    </head>
    <body style="margin:0;padding:0;background:#f4f8f6;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,Helvetica,Arial,sans-serif;color:#183e36;">
        <div style="max-width:580px;margin:36px auto;background:#ffffff;border-radius:16px;padding:36px;border:1px solid #e2ece8;box-shadow:0 6px 24px rgba(11,75,66,.06);">
            <div style="display:flex;align-items:center;margin-bottom:24px;">
                <div style="width:40px;height:40px;border-radius:10px;background:#087f55;color:#ffffff;display:inline-flex;align-items:center;justify-content:center;font-size:22px;line-height:40px;text-align:center;margin-right:12px;">
                    🎓
                </div>
                <div>
                    <h2 style="margin:0;font-size:18px;font-weight:800;letter-spacing:-0.02em;color:#082e2a;">STUDY PLANNER</h2>
                    <p style="margin:0;font-size:11px;color:#5c7b74;text-transform:uppercase;letter-spacing:0.08em;font-weight:600;">Academic Workload Manager</p>
                </div>
            </div>

            <h1 style="font-size:20px;font-weight:700;color:#082e2a;margin-top:0;margin-bottom:12px;">
                Verify your email address
            </h1>

            <p style="font-size:14px;line-height:1.6;color:#496861;margin-bottom:20px;">
                Hello <strong>' . $safeName . '</strong>,
            </p>

            <p style="font-size:14px;line-height:1.6;color:#496861;margin-bottom:24px;">
                Welcome to Study Planner! To complete your registration and begin organizing your courses, tasks, and academic timetable, please verify your email address.
            </p>

            <div style="text-align:center;margin:32px 0;">
                <a href="' . $safeUrl . '" style="display:inline-block;padding:14px 32px;background:#087f55;color:#ffffff;text-decoration:none;border-radius:10px;font-size:14px;font-weight:700;box-shadow:0 3px 12px rgba(8,127,85,.25);letter-spacing:0.01em;">
                    Verify My Email &rarr;
                </a>
            </div>

            <div style="margin-top:28px;padding:16px;background:#f3f9f6;border-radius:10px;border:1px solid #dbeee6;font-size:12px;line-height:1.6;color:#42675f;">
                <strong>Security Notice:</strong> This verification link will expire in <strong>24 hours</strong>. If you did not create this account, no further action is required and you can safely ignore this message.
            </div>

            <p style="margin-top:24px;font-size:12px;color:#78918b;line-height:1.5;">
                Button not working? Copy and paste this URL into your browser:<br>
                <a href="' . $safeUrl . '" style="color:#087f55;word-break:break-all;">' . $safeUrl . '</a>
            </p>

            <hr style="border:none;border-top:1px solid #edf4f1;margin:28px 0 20px 0;">

            <p style="font-size:11px;color:#8aa09b;margin:0;text-align:center;">
                &copy; ' . date('Y') . ' Study Planner &bull; Academic Workload &amp; Scheduling Engine
            </p>
        </div>
    </body>
    </html>
    ';

    $payload = [
        'from' => $fromName . ' <' . MAIL_FROM_EMAIL . '>',
        'to' => [$toEmail],
        'subject' => $subject,
        'html' => $html,
        'text' => $bodyText
    ];

    $ch = curl_init('https://api.resend.com/emails');
    if ($ch === false) {
        error_log('[RESEND] Unable to initialize cURL for verification email.');
        return false;
    }

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . RESEND_API_KEY,
            'Content-Type: application/json',
            'Accept: application/json'
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        CURLOPT_TIMEOUT => 30,
        CURLOPT_CONNECTTIMEOUT => 10
    ]);

    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($response === false) {
        error_log('[RESEND CURL ERROR] ' . $curlError);
        return false;
    }

    if ($httpCode < 200 || $httpCode >= 300) {
        error_log('[RESEND ERROR] HTTP ' . $httpCode . ' Response: ' . $response);
        return false;
    }

    error_log('[RESEND VERIFICATION EMAIL SENT] ' . $toEmail);
    return true;
}