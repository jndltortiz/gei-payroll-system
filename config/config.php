<?php

if (session_status() === PHP_SESSION_NONE) {
    $isHttpsRequest =
        (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || (isset($_SERVER['SERVER_PORT']) && (int)$_SERVER['SERVER_PORT'] === 443)
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https');

    ini_set('session.use_strict_mode', '1');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.cookie_samesite', 'Lax');
    ini_set('session.cookie_secure', $isHttpsRequest ? '1' : '0');

    $cookieParams = session_get_cookie_params();
    session_set_cookie_params([
        'lifetime' => $cookieParams['lifetime'],
        'path'     => $cookieParams['path'] ?: '/',
        'domain'   => $cookieParams['domain'],
        'secure'   => $isHttpsRequest,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    session_start();
}

date_default_timezone_set('Asia/Manila');

define('APP_NAME', 'GEI Payroll System');
define('BASE_URL', 'http://localhost/gei-payroll-system/');

require_once __DIR__ . '/database.php';
