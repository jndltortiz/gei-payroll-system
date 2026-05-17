<?php
require_once __DIR__ . '/../includes/auth.php';

// destroy session
session_unset();
session_destroy();

// redirect to login
header('Location: ' . BASE_URL . 'modules/auth/login.php');
exit;