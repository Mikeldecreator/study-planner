<?php
declare(strict_types=1);

require_once __DIR__ . '/../config/config.php';

/**
 * Obtain authoritative PDO database connection.
 * Supports standard local MySQL as well as SSL/TLS encrypted cloud databases (Aiven MySQL).
 */
function getDb(): PDO
{
    static $pdo = null;
    if ($pdo === null) {
        $host = defined('DB_HOST') ? DB_HOST : (getenv('DB_HOST') ?: 'localhost');
        $port = defined('DB_PORT') ? (int) DB_PORT : (int) (getenv('DB_PORT') ?: 3306);
        $name = defined('DB_NAME') ? DB_NAME : (getenv('DB_NAME') ?: 'study-planner');
        $user = defined('DB_USER') ? DB_USER : (getenv('DB_USER') ?: 'root');
        $pass = defined('DB_PASS') ? DB_PASS : (getenv('DB_PASS') !== false ? (string) getenv('DB_PASS') : '');

        $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4', $host, $port, $name);

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_TIMEOUT            => 10,
        ];

        // Persistent connection pooling for cloud/remote deployments to eliminate repeated TLS handshake penalty
        $persistent = defined('DB_PERSISTENT') ? (bool) DB_PERSISTENT : (getenv('DB_PERSISTENT') === '1' || (!empty($sslCa) || !empty($sslCaContent) || ($host !== 'localhost' && $host !== '127.0.0.1')));
        if ($persistent) {
            $options[PDO::ATTR_PERSISTENT] = true;
        }

        // SSL / TLS Support for Aiven MySQL and cloud providers
        $sslCa = defined('DB_SSL_CA') ? DB_SSL_CA : (getenv('DB_SSL_CA') ?: '');
        $sslCaContent = defined('DB_SSL_CA_CONTENT') ? DB_SSL_CA_CONTENT : (getenv('DB_SSL_CA_CONTENT') ?: '');

        if (!empty($sslCa) && file_exists($sslCa)) {
            $options[PDO::MYSQL_ATTR_SSL_CA] = $sslCa;
            $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
        } elseif (!empty($sslCaContent)) {
            // Write CA certificate string to a secure temporary file
            $tempCertPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'aiven-ca-' . md5((string) $host) . '.pem';
            if (!file_exists($tempCertPath) || filemtime($tempCertPath) < time() - 86400) {
                file_put_contents($tempCertPath, trim($sslCaContent));
            }
            $options[PDO::MYSQL_ATTR_SSL_CA] = $tempCertPath;
            $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
        }

        try {
            $pdo = new PDO($dsn, $user, $pass, $options);
        } catch (PDOException $e) {
            error_log(sprintf(
                'Database connection failed for host %s:%d, database %s: %s',
                $host,
                $port,
                $name,
                $e->getMessage()
            ));
            throw new PDOException(
                'Database connection could not be established. Please verify database connectivity.',
                (int) $e->getCode(),
                $e
            );
        }
    }
    return $pdo;
}
