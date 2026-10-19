<?php
// Anonymous kiosk: loopback only. Never trust forwarded-IP headers.
if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(403); exit('Kiosk is restricted to localhost.');
}
require_once __DIR__ . '/auth.php';
if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== 'gei_test') {
    http_response_code(403); exit('Kiosk is enabled only on gei_test.');
}
header('Cache-Control: no-store');
header('Permissions-Policy: camera=(self), microphone=()');
header('X-Frame-Options: DENY');