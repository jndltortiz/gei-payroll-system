<?php

require_once __DIR__ . '/../includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . BASE_URL . 'modules/auth/login.php');
    exit;
}

$username = trim($_POST['username'] ?? '');
$password = $_POST['password'] ?? '';

if ($username === '' || $password === '') {
    setFlash('danger', 'Username and password are required.');
    header('Location: ' . BASE_URL . 'modules/auth/login.php');
    exit;
}

$sql = "
    SELECT
        u.user_id,
        u.username,
        u.password_hash,
        u.is_active,
        u.must_change_password,
        r.role_name,
        e.employee_id,
        e.first_name,
        e.last_name
    FROM users u
    INNER JOIN roles r ON u.role_id = r.role_id
    LEFT JOIN employees e ON u.employee_id = e.employee_id
    WHERE u.username = :username
    LIMIT 1
";

$stmt = $pdo->prepare($sql);
$stmt->execute(['username' => $username]);
$user = $stmt->fetch();

if (!$user) {
    setFlash('danger', 'Invalid username or password.');
    header('Location: ' . BASE_URL . 'modules/auth/login.php');
    exit;
}

if ((int)$user['is_active'] !== 1) {
    setFlash('danger', 'Your account is inactive.');
    header('Location: ' . BASE_URL . 'modules/auth/login.php');
    exit;
}

if (!password_verify($password, $user['password_hash'])) {
    setFlash('danger', 'Invalid username or password.');
    header('Location: ' . BASE_URL . 'modules/auth/login.php');
    exit;
}

$_SESSION['user'] = [
    'user_id'             => $user['user_id'],
    'employee_id'         => $user['employee_id'],
    'username'            => $user['username'],
    'role_name'           => $user['role_name'],
    'first_name'          => $user['first_name'] ?? '',
    'last_name'           => $user['last_name']  ?? '',
    'must_change_password'=> (int)($user['must_change_password'] ?? 0),
];

$updateLogin = $pdo->prepare("UPDATE users SET last_login = NOW() WHERE user_id = :user_id");
$updateLogin->execute(['user_id' => $user['user_id']]);

setFlash('success', 'Login successful.');

// ── Force password change — redirect before entering any portal ───────────────
if ((int)($user['must_change_password'] ?? 0) === 1) {
    header('Location: ' . BASE_URL . 'modules/auth/change-password.php');
    exit;
}

// ── Role-based redirect ───────────────────────────────────────────────────────
switch ($user['role_name']) {
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