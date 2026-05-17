<?php

require_once __DIR__ . '/includes/auth.php';

if (isLoggedIn()) {
    header('Location: ' . BASE_URL . 'modules/dashboard/index.php');
    exit;
}

header('Location: ' . BASE_URL . 'modules/auth/login.php');
exit;