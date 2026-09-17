<?php

declare(strict_types=1);

/*
|--------------------------------------------------------------------------
| Study Planner VAPID Key Generator
|--------------------------------------------------------------------------
| Composer is NOT required.
|--------------------------------------------------------------------------
*/

header('Content-Type: text/html; charset=UTF-8');


/*
|--------------------------------------------------------------------------
| Try to locate XAMPP's OpenSSL configuration
|--------------------------------------------------------------------------
*/

$possibleConfigs = [
    'C:\\xampp\\php\\extras\\ssl\\openssl.cnf',
    'C:\\xampp\\apache\\conf\\openssl.cnf',
    'C:\\xampp\\apache\\bin\\openssl.cnf',
];

$opensslConfig = null;

foreach ($possibleConfigs as $file) {
    if (is_file($file)) {
        $opensslConfig = $file;
        break;
    }
}


/*
|--------------------------------------------------------------------------
| Tell OpenSSL exactly where its config file is
|--------------------------------------------------------------------------
*/

if ($opensslConfig !== null) {
    putenv('OPENSSL_CONF=' . $opensslConfig);
}


/*
|--------------------------------------------------------------------------
| Helper
|--------------------------------------------------------------------------
*/

function base64UrlEncode(string $data): string
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


/*
|--------------------------------------------------------------------------
| Check OpenSSL extension
|--------------------------------------------------------------------------
*/

if (!extension_loaded('openssl')) {
    die(
        '<h2>OpenSSL is not enabled</h2>
         <p>Please enable the OpenSSL extension in XAMPP PHP.</p>'
    );
}


/*
|--------------------------------------------------------------------------
| Generate EC P-256 key
|--------------------------------------------------------------------------
*/

$key = openssl_pkey_new([
    'private_key_type' => OPENSSL_KEYTYPE_EC,
    'curve_name'      => 'prime256v1',
]);


/*
|--------------------------------------------------------------------------
| Handle failure with actual OpenSSL errors
|--------------------------------------------------------------------------
*/

if ($key === false) {

    echo '<h2>Unable to generate VAPID keys</h2>';

    echo '<p>OpenSSL configuration detected:</p>';

    echo '<pre>';

    echo htmlspecialchars(
        $opensslConfig ?? 'NOT FOUND',
        ENT_QUOTES,
        'UTF-8'
    );

    echo '</pre>';

    echo '<p>OpenSSL errors:</p>';

    echo '<pre>';

    while ($error = openssl_error_string()) {
        echo htmlspecialchars(
            $error,
            ENT_QUOTES,
            'UTF-8'
        );

        echo PHP_EOL;
    }

    echo '</pre>';

    exit;
}


/*
|--------------------------------------------------------------------------
| Extract key details
|--------------------------------------------------------------------------
*/

$details = openssl_pkey_get_details($key);

if (
    $details === false ||
    !isset(
        $details['ec']['x'],
        $details['ec']['y'],
        $details['ec']['d']
    )
) {

    echo '<h2>VAPID key was created but could not be read</h2>';

    echo '<pre>';

    while ($error = openssl_error_string()) {
        echo htmlspecialchars(
            $error,
            ENT_QUOTES,
            'UTF-8'
        );

        echo PHP_EOL;
    }

    echo '</pre>';

    exit;
}


/*
|--------------------------------------------------------------------------
| EC coordinates
|--------------------------------------------------------------------------
*/

$x = $details['ec']['x'];
$y = $details['ec']['y'];
$d = $details['ec']['d'];


/*
|--------------------------------------------------------------------------
| Build Web Push public key
|--------------------------------------------------------------------------
|
| Uncompressed EC public key:
|
| 04 + X + Y
|--------------------------------------------------------------------------
*/

$publicKey = base64UrlEncode(
    "\x04" . $x . $y
);


/*
|--------------------------------------------------------------------------
| Build VAPID private key
|--------------------------------------------------------------------------
*/

$privateKey = base64UrlEncode($d);

?>
<!DOCTYPE html>
<html lang="en">

<head>

<meta charset="UTF-8">

<meta
    name="viewport"
    content="width=device-width, initial-scale=1"
>

<title>Study Planner VAPID Keys</title>

<style>

* {
    box-sizing: border-box;
}

body {
    margin: 0;
    padding: 40px 20px;
    background: #f4f9f7;
    font-family: Arial, sans-serif;
    color: #12352e;
}

.container {
    width: min(900px, 100%);
    margin: auto;
    background: #fff;
    border: 1px solid #dce9e5;
    border-radius: 18px;
    padding: 30px;
    box-shadow: 0 10px 35px rgba(0,0,0,.06);
}

h1 {
    margin-top: 0;
}

.success {
    background: #ecfdf5;
    border: 1px solid #a7f3d0;
    color: #065f46;
    padding: 15px;
    border-radius: 10px;
    margin-bottom: 25px;
}

.field {
    margin-top: 22px;
}

label {
    display: block;
    margin-bottom: 8px;
    font-size: 13px;
    font-weight: 700;
}

textarea {
    width: 100%;
    min-height: 105px;
    padding: 12px;
    border: 1px solid #d6e4df;
    border-radius: 10px;
    background: #f9fcfb;
    font-family: monospace;
    font-size: 13px;
    line-height: 1.5;
    resize: vertical;
}

.warning {
    margin-top: 25px;
    padding: 15px;
    border-radius: 10px;
    background: #fff7ed;
    border: 1px solid #fed7aa;
    color: #9a3412;
    line-height: 1.5;
}

</style>

</head>

<body>

<div class="container">

    <h1>Study Planner VAPID Keys</h1>

    <div class="success">
        VAPID keys generated successfully.
    </div>

    <div class="field">

        <label>
            VAPID PUBLIC KEY
        </label>

        <textarea
            readonly
            onclick="this.select()"
        ><?= htmlspecialchars(
            $publicKey,
            ENT_QUOTES,
            'UTF-8'
        ) ?></textarea>

    </div>


    <div class="field">

        <label>
            VAPID PRIVATE KEY
        </label>

        <textarea
            readonly
            onclick="this.select()"
        ><?= htmlspecialchars(
            $privateKey,
            ENT_QUOTES,
            'UTF-8'
        ) ?></textarea>

    </div>


    <div class="field">

        <label>
            VAPID SUBJECT
        </label>

        <textarea
            readonly
            onclick="this.select()"
        >mailto:your-email@example.com</textarea>

    </div>


    <div class="warning">

        <strong>Important:</strong>

        <br><br>

        Copy the public key and private key into
        <code>config.php</code>.

        <br><br>

        Never put the private key in JavaScript.

        <br><br>

        Delete this
        <code>vapid-keys.php</code>
        file after copying the keys.

    </div>

</div>

</body>

</html>