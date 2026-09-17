<?php

declare(strict_types=1);

/**
 * ============================================================
 * STUDY PLANNER - COMPOSER-FREE WEB PUSH
 * File: includes/webpush.php
 *
 * Handles:
 *   - VAPID JWT authentication
 *   - P-256 ECDH
 *   - HKDF-SHA256
 *   - AES-128-GCM payload encryption
 *   - Web Push HTTP delivery using cURL
 *
 * No Composer required.
 * ============================================================
 */


/* ============================================================
   OPENSSL CONFIGURATION
   ============================================================ */

$opensslConfigCandidates = [
    'C:\\xampp\\php\\extras\\ssl\\openssl.cnf',
    'C:\\xampp\\apache\\conf\\openssl.cnf',
    'C:\\xampp\\apache\\bin\\openssl.cnf',
];

foreach ($opensslConfigCandidates as $opensslConfig) {
    if (is_file($opensslConfig)) {
        putenv('OPENSSL_CONF=' . $opensslConfig);
        break;
    }
}


/* ============================================================
   HELPERS
   ============================================================ */

function webPushBase64UrlEncode(string $data): string
{
    return rtrim(
        strtr(
            base64_encode($data),
            '+/',
            '-_'
        ),
        '='
    );
}


function webPushBase64UrlDecode(string $data): string
{
    $data = strtr(
        $data,
        '-_',
        '+/'
    );

    $padding = strlen($data) % 4;

    if ($padding > 0) {
        $data .= str_repeat('=', 4 - $padding);
    }

    $decoded = base64_decode(
        $data,
        true
    );

    if ($decoded === false) {
        throw new RuntimeException(
            'Invalid Base64URL data.'
        );
    }

    return $decoded;
}


/* ============================================================
   HKDF
   ============================================================ */

function webPushHkdfExtract(
    string $salt,
    string $ikm
): string {
    return hash_hmac(
        'sha256',
        $ikm,
        $salt,
        true
    );
}


function webPushHkdfExpand(
    string $prk,
    string $info,
    int $length
): string {

    if ($length <= 0) {
        return '';
    }

    $result = '';
    $previous = '';
    $counter = 1;

    while (strlen($result) < $length) {

        $previous = hash_hmac(
            'sha256',
            $previous . $info . chr($counter),
            $prk,
            true
        );

        $result .= $previous;

        $counter++;

        if ($counter > 255) {
            throw new RuntimeException(
                'HKDF output is too large.'
            );
        }
    }

    return substr(
        $result,
        0,
        $length
    );
}


/* ============================================================
   ASN.1 HELPERS
   ============================================================ */

function webPushAsn1Length(int $length): string
{
    if ($length < 128) {
        return chr($length);
    }

    $bytes = '';

    while ($length > 0) {
        $bytes = chr($length & 0xff) . $bytes;
        $length >>= 8;
    }

    return chr(0x80 | strlen($bytes)) . $bytes;
}


function webPushAsn1Tag(
    int $tag,
    string $value
): string {
    return chr($tag) .
        webPushAsn1Length(strlen($value)) .
        $value;
}


function webPushAsn1Sequence(
    string $value
): string {
    return webPushAsn1Tag(
        0x30,
        $value
    );
}


function webPushAsn1Integer(
    string $value
): string {

    $value = ltrim(
        $value,
        "\x00"
    );

    if ($value === '') {
        $value = "\x00";
    }

    if ((ord($value[0]) & 0x80) !== 0) {
        $value = "\x00" . $value;
    }

    return webPushAsn1Tag(
        0x02,
        $value
    );
}


/*
 * OBJECT IDENTIFIER:
 *
 * 1.2.840.10045.3.1.7
 *
 * = prime256v1
 */
function webPushPrime256v1Oid(): string
{
    return webPushAsn1Tag(
        0x06,
        "\x2A\x86\x48\xCE\x3D\x03\x01\x07"
    );
}


/* ============================================================
   BUILD EC PUBLIC KEY PEM FROM RAW 65-BYTE POINT
   ============================================================ */

function webPushPublicKeyToPem(
    string $publicKey
): string {

    if (strlen($publicKey) !== 65) {
        throw new RuntimeException(
            'Subscriber public key must be 65 bytes.'
        );
    }

    if ($publicKey[0] !== "\x04") {
        throw new RuntimeException(
            'Subscriber public key must be an uncompressed P-256 point.'
        );
    }


    /*
     * SubjectPublicKeyInfo:
     *
     * SEQUENCE {
     *   AlgorithmIdentifier
     *   BIT STRING publicKey
     * }
     */


    $algorithmIdentifier = webPushAsn1Sequence(
        webPushAsn1Sequence(
            webPushPrime256v1Oid()
        )
    );


    /*
     * The actual algorithm identifier needs:
     *
     * id-ecPublicKey
     * prime256v1
     */

    $algorithmIdentifier = webPushAsn1Sequence(
        webPushAsn1Tag(
            0x06,
            "\x2A\x86\x48\xCE\x3D\x02\x01"
        ) .
        webPushPrime256v1Oid()
    );


    /*
     * BIT STRING starts with unused-bits count = 0.
     */

    $bitString = webPushAsn1Tag(
        0x03,
        "\x00" . $publicKey
    );

    $der = webPushAsn1Sequence(
        $algorithmIdentifier .
        $bitString
    );

    return
        "-----BEGIN PUBLIC KEY-----\n" .
        chunk_split(
            base64_encode($der),
            64,
            "\n"
        ) .
        "-----END PUBLIC KEY-----\n";
}


/* ============================================================
   BUILD VAPID PRIVATE KEY PEM
   ============================================================ */

function webPushVapidPrivateKeyToPem(
    string $privateScalar,
    ?string $publicKey = null
): string {

    if (strlen($privateScalar) !== 32) {

        throw new RuntimeException(
            'VAPID private key must decode to 32 bytes.'
        );
    }


    /*
     * SEC1 ECPrivateKey:
     *
     * SEQUENCE {
     *   version INTEGER 1
     *   privateKey OCTET STRING
     *   parameters [0] OBJECT IDENTIFIER
     *   publicKey [1] BIT STRING
     * }
     */

    $body =
        webPushAsn1Integer("\x01") .
        webPushAsn1Tag(
            0x04,
            $privateScalar
        ) .
        webPushAsn1Tag(
            0xA0,
            webPushPrime256v1Oid()
        );


    if (
        $publicKey !== null &&
        strlen($publicKey) === 65
    ) {

        $body .= webPushAsn1Tag(
            0xA1,
            webPushAsn1Tag(
                0x03,
                "\x00" . $publicKey
            )
        );
    }


    $der = webPushAsn1Sequence($body);

    return
        "-----BEGIN EC PRIVATE KEY-----\n" .
        chunk_split(
            base64_encode($der),
            64,
            "\n"
        ) .
        "-----END EC PRIVATE KEY-----\n";
}


/* ============================================================
   ECDSA DER -> RAW R || S
   ============================================================ */

function webPushEcdsaDerToRaw(
    string $der,
    int $componentLength = 32
): string {

    $offset = 0;

    if (
        !isset($der[$offset]) ||
        ord($der[$offset]) !== 0x30
    ) {
        throw new RuntimeException(
            'Invalid ECDSA signature.'
        );
    }

    $offset++;


    /*
     * Read sequence length.
     */

    $lengthByte = ord($der[$offset++]);

    if (($lengthByte & 0x80) !== 0) {

        $lengthBytes = $lengthByte & 0x7f;
        $length = 0;

        for ($i = 0; $i < $lengthBytes; $i++) {
            $length = ($length << 8) |
                ord($der[$offset++]);
        }

    } else {
        $length = $lengthByte;
    }


    if ($offset + $length > strlen($der)) {
        throw new RuntimeException(
            'Invalid ECDSA signature length.'
        );
    }


    /*
     * R
     */

    if (ord($der[$offset++]) !== 0x02) {
        throw new RuntimeException(
            'Invalid ECDSA R value.'
        );
    }

    $rLengthByte = ord($der[$offset++]);

    if (($rLengthByte & 0x80) !== 0) {

        $rLengthBytes = $rLengthByte & 0x7f;
        $rLength = 0;

        for ($i = 0; $i < $rLengthBytes; $i++) {
            $rLength = ($rLength << 8) |
                ord($der[$offset++]);
        }

    } else {
        $rLength = $rLengthByte;
    }

    $r = substr(
        $der,
        $offset,
        $rLength
    );

    $offset += $rLength;


    /*
     * S
     */

    if (ord($der[$offset++]) !== 0x02) {
        throw new RuntimeException(
            'Invalid ECDSA S value.'
        );
    }

    $sLengthByte = ord($der[$offset++]);

    if (($sLengthByte & 0x80) !== 0) {

        $sLengthBytes = $sLengthByte & 0x7f;
        $sLength = 0;

        for ($i = 0; $i < $sLengthBytes; $i++) {
            $sLength = ($sLength << 8) |
                ord($der[$offset++]);
        }

    } else {
        $sLength = $sLengthByte;
    }

    $s = substr(
        $der,
        $offset,
        $sLength
    );


    /*
     * Strip unnecessary leading zero.
     */

    $r = ltrim(
        $r,
        "\x00"
    );

    $s = ltrim(
        $s,
        "\x00"
    );


    /*
     * Left-pad to exactly 32 bytes.
     */

    $r = str_pad(
        $r,
        $componentLength,
        "\x00",
        STR_PAD_LEFT
    );

    $s = str_pad(
        $s,
        $componentLength,
        "\x00",
        STR_PAD_LEFT
    );


    return $r . $s;
}


/* ============================================================
   VAPID JWT
   ============================================================ */

function webPushCreateVapidJwt(
    string $endpoint,
    string $privateKeyPem,
    string $publicKey
): string {

    $parts = parse_url($endpoint);

    if (
        !is_array($parts) ||
        empty($parts['scheme']) ||
        empty($parts['host'])
    ) {
        throw new RuntimeException(
            'Invalid push endpoint.'
        );
    }


    /*
     * The audience is the push-service origin.
     */

    $audience =
        $parts['scheme'] .
        '://' .
        $parts['host'];

    if (!empty($parts['port'])) {
        $audience .= ':' . $parts['port'];
    }


    $header = [
        'typ' => 'JWT',
        'alg' => 'ES256',
    ];


    $payload = [
        'aud' => $audience,
        'exp' => time() + 3600,
        'sub' => VAPID_SUBJECT,
    ];


    $encodedHeader = webPushBase64UrlEncode(
        json_encode(
            $header,
            JSON_UNESCAPED_SLASHES
        )
    );

    $encodedPayload = webPushBase64UrlEncode(
        json_encode(
            $payload,
            JSON_UNESCAPED_SLASHES
        )
    );


    $signingInput =
        $encodedHeader .
        '.' .
        $encodedPayload;


    $signatureDer = '';

    $ok = openssl_sign(
        $signingInput,
        $signatureDer,
        $privateKeyPem,
        OPENSSL_ALGO_SHA256
    );


    if (!$ok) {

        $errors = [];

        while ($error = openssl_error_string()) {
            $errors[] = $error;
        }

        throw new RuntimeException(
            'Unable to create VAPID signature: ' .
            implode(' | ', $errors)
        );
    }


    $signatureRaw = webPushEcdsaDerToRaw(
        $signatureDer,
        32
    );


    return
        $signingInput .
        '.' .
        webPushBase64UrlEncode(
            $signatureRaw
        );
}


/* ============================================================
   ENCRYPT WEB PUSH PAYLOAD
   ============================================================ */

function webPushEncryptPayload(
    string $payload,
    string $subscriberPublicKeyB64,
    string $subscriberAuthB64
): array {

    $subscriberPublicKey =
        webPushBase64UrlDecode(
            $subscriberPublicKeyB64
        );

    $subscriberAuth =
        webPushBase64UrlDecode(
            $subscriberAuthB64
        );


    if (strlen($subscriberPublicKey) !== 65) {
        throw new RuntimeException(
            'Invalid subscriber p256dh key.'
        );
    }

    if ($subscriberPublicKey[0] !== "\x04") {
        throw new RuntimeException(
            'Subscriber p256dh key is not an uncompressed EC point.'
        );
    }


    /*
     * Generate fresh P-256 ephemeral server key.
     */

    $serverKey = openssl_pkey_new([
        'private_key_type' => OPENSSL_KEYTYPE_EC,
        'curve_name'       => 'prime256v1',
    ]);


    if ($serverKey === false) {

        $errors = [];

        while ($error = openssl_error_string()) {
            $errors[] = $error;
        }

        throw new RuntimeException(
            'Unable to generate ephemeral push key: ' .
            implode(' | ', $errors)
        );
    }


    $serverDetails =
        openssl_pkey_get_details(
            $serverKey
        );


    if (
        $serverDetails === false ||
        !isset(
            $serverDetails['ec']['x'],
            $serverDetails['ec']['y']
        )
    ) {
        throw new RuntimeException(
            'Unable to read ephemeral push key.'
        );
    }


    $serverPublicKey =
        "\x04" .
        $serverDetails['ec']['x'] .
        $serverDetails['ec']['y'];


    $serverPublicKeyPem =
        webPushPublicKeyToPem(
            $serverPublicKey
        );


    $subscriberPublicKeyPem =
        webPushPublicKeyToPem(
            $subscriberPublicKey
        );


    /*
     * Derive ECDH shared secret.
     */

    $sharedSecret = openssl_pkey_derive(
        $subscriberPublicKeyPem,
        $serverKey,
        32
    );


    if (
        $sharedSecret === false ||
        strlen($sharedSecret) !== 32
    ) {
        throw new RuntimeException(
            'ECDH shared-secret derivation failed.'
        );
    }


    /*
     * RFC 8291 key derivation.
     */

    $authInfo =
        "WebPush: info\x00" .
        $subscriberPublicKey .
        $serverPublicKey;


    $prkKey = webPushHkdfExtract(
        $subscriberAuth,
        $sharedSecret
    );


    $ikm = webPushHkdfExpand(
        $prkKey,
        $authInfo,
        32
    );


    /*
     * 16-byte random salt.
     */

    $salt = random_bytes(16);


    $prk = webPushHkdfExtract(
        $salt,
        $ikm
    );


    $cek = webPushHkdfExpand(
        $prk,
        "Content-Encoding: aes128gcm\x00",
        16
    );


    $nonce = webPushHkdfExpand(
        $prk,
        "Content-Encoding: nonce\x00",
        12
    );


    /*
     * AES-128-GCM requires the record terminator byte 0x02.
     */

    $recordPlaintext =
        $payload .
        "\x02";


    $ciphertext = openssl_encrypt(
        $recordPlaintext,
        'aes-128-gcm',
        $cek,
        OPENSSL_RAW_DATA,
        $nonce,
        $tag
    );


    if ($ciphertext === false) {
        throw new RuntimeException(
            'AES-128-GCM encryption failed.'
        );
    }


    /*
     * RFC 8188 aes128gcm content format:
     *
     * salt      16 bytes
     * rs        4 bytes
     * keyidlen  1 byte
     * keyid     65 bytes
     * ciphertext
     */

    $recordSize = 4096;


    $body =
        $salt .
        pack(
            'N',
            $recordSize
        ) .
        chr(strlen($serverPublicKey)) .
        $serverPublicKey .
        $ciphertext .
        $tag;


    return [
        'body' => $body,

        'salt' => $salt,

        'server_public_key' =>
            $serverPublicKey,
    ];
}


/* ============================================================
   SEND ONE PUSH
   ============================================================ */

function webPushSend(
    array $subscription,
    array $notification
): array {

    if (
        !defined('VAPID_PRIVATE_KEY') ||
        !defined('VAPID_PUBLIC_KEY') ||
        !defined('VAPID_SUBJECT')
    ) {
        throw new RuntimeException(
            'VAPID configuration is incomplete.'
        );
    }


    $endpoint = trim(
        (string) (
            $subscription['endpoint']
            ?? ''
        )
    );


    $p256dh = trim(
        (string) (
            $subscription['p256dh']
            ?? ''
        )
    );


    $auth = trim(
        (string) (
            $subscription['auth']
            ?? ''
        )
    );


    if (
        $endpoint === '' ||
        $p256dh === '' ||
        $auth === ''
    ) {
        throw new RuntimeException(
            'Push subscription data is incomplete.'
        );
    }


    /*
     * Decode VAPID private key.
     */

    $privateScalar =
        webPushBase64UrlDecode(
            VAPID_PRIVATE_KEY
        );


    $vapidPublicKey =
        webPushBase64UrlDecode(
            VAPID_PUBLIC_KEY
        );


    if (strlen($privateScalar) !== 32) {
        throw new RuntimeException(
            'VAPID private key must be 32 bytes.'
        );
    }


    if (strlen($vapidPublicKey) !== 65) {
        throw new RuntimeException(
            'VAPID public key must be 65 bytes.'
        );
    }


    /*
     * Reconstruct the VAPID private key as PEM.
     */

    $privateKeyPem =
        webPushVapidPrivateKeyToPem(
            $privateScalar,
            $vapidPublicKey
        );


    /*
     * Encrypt notification JSON.
     */

    $payload = json_encode(
        [
            'title' =>
                (string) (
                    $notification['title']
                    ?? 'Study Planner'
                ),

            'body' =>
                (string) (
                    $notification['body']
                    ?? 'You have a new study reminder.'
                ),

            'icon' =>
                (string) (
                    $notification['icon']
                    ?? ''
                ),

            'badge' =>
                (string) (
                    $notification['badge']
                    ?? ''
                ),

            'tag' =>
                (string) (
                    $notification['tag']
                    ?? 'study-planner'
                ),

            'renotify' =>
                (bool) (
                    $notification['renotify']
                    ?? true
                ),

            'data' =>
                $notification['data']
                ?? [
                    'url' =>
                        '/study-planner/public/notifications.php'
                ],
        ],
        JSON_UNESCAPED_SLASHES
    );


    if ($payload === false) {
        throw new RuntimeException(
            'Unable to encode notification.'
        );
    }


    $encrypted =
        webPushEncryptPayload(
            $payload,
            $p256dh,
            $auth
        );


    /*
     * VAPID JWT.
     */

    $jwt =
        webPushCreateVapidJwt(
            $endpoint,
            $privateKeyPem,
            VAPID_PUBLIC_KEY
        );


    /*
     * Send with cURL.
     */

    $curl = curl_init($endpoint);

    if ($curl === false) {
        throw new RuntimeException(
            'Unable to initialize cURL.'
        );
    }


    curl_setopt_array(
        $curl,
        [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $encrypted['body'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,

            CURLOPT_HTTPHEADER => [
                'Content-Type: application/octet-stream',
                'Content-Encoding: aes128gcm',
                'TTL: 86400',

                'Authorization: vapid t=' .
                    $jwt .
                    ', k=' .
                    VAPID_PUBLIC_KEY,

                'Content-Length: ' .
                    strlen($encrypted['body']),
            ],

            CURLOPT_TIMEOUT => 20,

            CURLOPT_CONNECTTIMEOUT => 10,
        ]
    );


    $response =
        curl_exec($curl);


    $curlError =
        curl_error($curl);

    $httpCode =
        (int) curl_getinfo(
            $curl,
            CURLINFO_HTTP_CODE
        );

    curl_close($curl);


    if ($response === false) {

        throw new RuntimeException(
            'Push request failed: ' .
            $curlError
        );
    }


    /*
     * 201 is the normal success response.
     */

    if (
        $httpCode < 200 ||
        $httpCode >= 300
    ) {

        throw new RuntimeException(
            'Push service returned HTTP ' .
            $httpCode .
            ': ' .
            trim($response)
        );
    }


    return [
        'success'    => true,
        'http_code'  => $httpCode,
        'endpoint'   => $endpoint,
    ];
}


/* ============================================================
   SEND TO MULTIPLE SUBSCRIPTIONS
   ============================================================ */

function webPushSendToSubscriptions(
    array $subscriptions,
    array $notification
): array {

    $results = [];

    foreach ($subscriptions as $subscription) {

        try {

            $results[] = [
                'success' => true,
                'result'  => webPushSend(
                    $subscription,
                    $notification
                ),
            ];

        } catch (Throwable $e) {

            $results[] = [
                'success' => false,
                'error'   => $e->getMessage(),

                'endpoint' =>
                    $subscription['endpoint']
                    ?? null,
            ];
        }
    }

    return $results;
}