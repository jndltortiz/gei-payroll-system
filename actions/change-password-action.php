<?php
/**
 * actions/change-password-action.php
 * Handles the forced / voluntary password change form.
 * Uses requireLoginOnly() so users with must_change_password = 1 can still POST.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
requireLoginOnly();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . BASE_URL . 'modules/auth/change-password.php');
    exit;
}

$currentPw  = $_POST['current_password']  ?? '';
$newPw      = $_POST['new_password']       ?? '';
$confirmPw  = $_POST['confirm_password']   ?? '';

// ── Basic validation ──────────────────────────────────────────────────────────
if ($currentPw === '' || $newPw === '' || $confirmPw === '') {
    setFlash('danger', 'All fields are required.');
    header('Location: ' . BASE_URL . 'modules/auth/change-password.php');
    exit;
}
if (strlen($newPw) < 8) {
    setFlash('danger', 'New password must be at least 8 characters.');
    header('Location: ' . BASE_URL . 'modules/auth/change-password.php');
    exit;
}
if ($newPw !== $confirmPw) {
    setFlash('danger', 'New password and confirmation do not match.');
    header('Location: ' . BASE_URL . 'modules/auth/change-password.php');
    exit;
}

// ── Verify current password ───────────────────────────────────────────────────
$userId   = (int)$_SESSION['user']['user_id'];
$userStmt = $pdo->prepare("SELECT password_hash FROM users WHERE user_id = ? LIMIT 1");
$userStmt->execute([$userId]);
$row = $userStmt->fetch();

if (!$row || !password_verify($currentPw, $row['password_hash'])) {
    setFlash('danger', 'Current password is incorrect.');
    header('Location: ' . BASE_URL . 'modules/auth/change-password.php');
    exit;
}

// ── Update password and clear the force flag ──────────────────────────────────
$pdo->prepare("
    UPDATE users
    SET password_hash = ?, must_change_password = 0
    WHERE user_id = ?
")->execute([password_hash($newPw, PASSWORD_DEFAULT), $userId]);

// Update session so the flag is cleared immediately
$_SESSION['user']['must_change_password'] = 0;

// ── Audit ─────────────────────────────────────────────────────────────────────
$pdo->prepare(
    "INSERT INTO audit_logs (user_id, action, table_name, record_id, description)
     VALUES (?, 'UPDATE', 'users', ?, 'Password changed by user')"
)->execute([$userId, $userId]);

setFlash('success', 'Password changed successfully. Welcome!');

// ── Redirect to the user's home portal ───────────────────────────────────────
$role = $_SESSION['user']['role_name'] ?? '';
switch ($role) {
    case 'Principal':
    case 'Special Assistant':
        header('Location: ' . BASE_URL . 'modules/principal/payroll-approval/index.php');
        break;
    case 'Employee':
        header('Location: ' . BASE_URL . 'modules/employee/dashboard/index.php');
        break;
    default:
        header('Location: ' . BASE_URL . 'modules/dashboard/index.php');
        break;
}
exit;
