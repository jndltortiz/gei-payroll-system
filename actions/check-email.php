<?php
/**
 * actions/check-email.php
 * AJAX endpoint: checks duplicate school email and derived username.
 *
 * GET params:
 *   email       – the school email to validate
 *   employee_id – (optional) current employee being edited; exclude from check
 *
 * Response JSON:
 *   email_ok          bool   – true if email is free to use
 *   email_error       string – error message when email_ok is false
 *   username_ok       bool   – true if derived username is free
 *   suggested_username string – unique username to use (may differ from derived)
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

header('Content-Type: application/json');

$email      = trim($_GET['email'] ?? '');
$employeeId = (int)($_GET['employee_id'] ?? 0);

if ($email === '') {
    echo json_encode(['email_ok' => true, 'email_error' => '', 'username_ok' => true, 'suggested_username' => '']);
    exit;
}

// ── Validate format ───────────────────────────────────────────────────────────
if (!str_ends_with(strtolower($email), '@gei.edu.ph')) {
    echo json_encode([
        'email_ok'          => false,
        'email_error'       => 'Email must end with @gei.edu.ph.',
        'username_ok'       => true,
        'suggested_username'=> '',
    ]);
    exit;
}

// ── Duplicate email check ─────────────────────────────────────────────────────
if ($employeeId > 0) {
    // Edit mode: allow same record's own email
    $emailStmt = $pdo->prepare(
        "SELECT employee_id FROM employees WHERE email = ? AND employee_id != ? LIMIT 1"
    );
    $emailStmt->execute([$email, $employeeId]);
} else {
    // Create mode: no exclusions
    $emailStmt = $pdo->prepare(
        "SELECT employee_id FROM employees WHERE email = ? LIMIT 1"
    );
    $emailStmt->execute([$email]);
}
$emailTaken = (bool)$emailStmt->fetch();

// ── Derive username from email prefix ─────────────────────────────────────────
$base     = preg_replace('/[^a-z0-9._-]/', '', strtolower(explode('@', $email)[0]));
$username = $base;
$suffix   = 1;
$uStmt    = $pdo->prepare(
    $employeeId > 0
        ? "SELECT user_id FROM users WHERE username = ? AND employee_id != ? LIMIT 1"
        : "SELECT user_id FROM users WHERE username = ? LIMIT 1"
);

while (true) {
    if ($employeeId > 0) {
        $uStmt->execute([$username, $employeeId]);
    } else {
        $uStmt->execute([$username]);
    }
    if (!$uStmt->fetch()) break;  // username is free
    $username = $base . $suffix++;
}

$usernameOk = ($username === $base); // original derived name was free

echo json_encode([
    'email_ok'          => !$emailTaken,
    'email_error'       => $emailTaken ? 'This school email is already assigned to another account.' : '',
    'username_ok'       => $usernameOk,
    'suggested_username'=> $username,
]);
