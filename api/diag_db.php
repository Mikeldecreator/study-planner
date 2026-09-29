<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';

header('Content-Type: application/json; charset=utf-8');

$host = defined('DB_HOST') ? DB_HOST : (getenv('DB_HOST') ?: 'localhost');
$port = defined('DB_PORT') ? (int) DB_PORT : (int) (getenv('DB_PORT') ?: 3306);
$name = defined('DB_NAME') ? DB_NAME : (getenv('DB_NAME') ?: 'study-planner');
$user = defined('DB_USER') ? DB_USER : (getenv('DB_USER') ?: 'root');
$pass = defined('DB_PASS') ? DB_PASS : (getenv('DB_PASS') !== false ? (string) getenv('DB_PASS') : '');

$sslCa = defined('DB_SSL_CA') ? DB_SSL_CA : (getenv('DB_SSL_CA') ?: '');
$sslCaContent = defined('DB_SSL_CA_CONTENT') ? DB_SSL_CA_CONTENT : (getenv('DB_SSL_CA_CONTENT') ?: '');

$dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $name);
$options = [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_TIMEOUT => 8,
];

if (!empty($sslCa) && file_exists($sslCa)) {
    $options[PDO::MYSQL_ATTR_SSL_CA] = $sslCa;
} elseif (!empty($sslCaContent)) {
    $tempCertPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'aiven-ca-diag.pem';
    file_put_contents($tempCertPath, trim($sslCaContent));
    $options[PDO::MYSQL_ATTR_SSL_CA] = $tempCertPath;
}

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
    $db = $pdo->query('SELECT DATABASE()')->fetchColumn();
    $now = $pdo->query('SELECT CURRENT_TIMESTAMP()')->fetchColumn();
    echo json_encode([
        'ok' => true,
        'database' => $db,
        'host' => $host,
        'server_time' => $now
    ]);
} catch (PDOException $e) {
    echo json_encode([
        'ok' => false,
        'host' => $host,
        'port' => $port,
        'error_code' => $e->getCode(),
        'error_message' => $e->getMessage()
    ]);
}
