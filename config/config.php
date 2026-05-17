<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

date_default_timezone_set('Asia/Manila');

define('APP_NAME', 'GEI Payroll System');
define('BASE_URL', 'http://localhost/gei-payroll-system/');

require_once __DIR__ . '/database.php';
