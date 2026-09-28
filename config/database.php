<?php

require_once __DIR__ . '/env.php';

$host = appEnv('DB_HOST');
$port = appEnv('DB_PORT');
$dbname = appEnv('DB_DATABASE');
$username = appEnv('DB_USERNAME');
$password = appEnv('DB_PASSWORD', '');
$charset = appEnv('DB_CHARSET', 'utf8mb4');

if (!$host || !$dbname || !$username) {
    error_log('[database] Missing required database environment variables.');
    http_response_code(500);
    exit('Database connection is not configured. Please contact the system administrator.');
}

$dsn = "mysql:host={$host};dbname={$dbname};charset={$charset}";
if ($port !== null && $port !== '') {
    $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset={$charset}";
}

try {
    $pdo = new PDO(
        $dsn,
        $username,
        $password,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (PDOException $e) {
    error_log('[database] Connection failed: ' . $e->getMessage());
    http_response_code(500);
    exit('Database connection is temporarily unavailable. Please contact the system administrator.');
}
