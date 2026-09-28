<?php

require_once __DIR__ . '/../includes/auth.php';

const LOGIN_RATE_LIMIT_MAX_ATTEMPTS = 5;
const LOGIN_RATE_LIMIT_WINDOW_MINUTES = 15;
const LOGIN_RATE_LIMIT_LOCK_MINUTES = 15;

function ensureLoginRateLimitTable(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `login_rate_limits` (
            `rate_limit_id`   INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `identifier_type` ENUM('username','ip') NOT NULL,
            `identifier`      VARCHAR(191) NOT NULL,
            `failed_count`    INT UNSIGNED NOT NULL DEFAULT 0,
            `first_failed_at` DATETIME NOT NULL,
            `last_failed_at`  DATETIME NOT NULL,
            `locked_until`    DATETIME DEFAULT NULL,
            PRIMARY KEY (`rate_limit_id`),
            UNIQUE KEY `uniq_login_rate_identifier` (`identifier_type`, `identifier`),
            KEY `idx_login_rate_locked_until` (`locked_until`),
            KEY `idx_login_rate_last_failed` (`last_failed_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function loginClientIp(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    return substr(trim((string)$ip), 0, 45) ?: 'unknown';
}

function normalizeLoginUsername(string $username): string
{
    return strtolower(trim($username));
}

function loginIdentifierRows(string $username, string $ip): array
{
    $rows = [['ip', $ip]];
    $username = normalizeLoginUsername($username);

    if ($username !== '') {
        $rows[] = ['username', substr($username, 0, 191)];
    }

    return $rows;
}

function getLoginLockout(PDO $pdo, string $username, string $ip): ?array
{
    $rows = loginIdentifierRows($username, $ip);
    $where = [];
    $params = [];

    foreach ($rows as [$type, $identifier]) {
        $where[] = '(identifier_type = ? AND identifier = ?)';
        $params[] = $type;
        $params[] = $identifier;
    }

    $stmt = $pdo->prepare("
        SELECT identifier_type,
               locked_until,
               GREATEST(TIMESTAMPDIFF(SECOND, NOW(), locked_until), 0) AS seconds_remaining
        FROM login_rate_limits
        WHERE locked_until IS NOT NULL
          AND locked_until > NOW()
          AND (" . implode(' OR ', $where) . ")
        ORDER BY locked_until DESC
        LIMIT 1
    ");
    $stmt->execute($params);
    $lock = $stmt->fetch(PDO::FETCH_ASSOC);

    return $lock ?: null;
}

function recordLoginFailure(PDO $pdo, string $username, string $ip): void
{
    $select = $pdo->prepare("
        SELECT failed_count, first_failed_at, locked_until
        FROM login_rate_limits
        WHERE identifier_type = ? AND identifier = ?
        FOR UPDATE
    ");

    $insert = $pdo->prepare("
        INSERT INTO login_rate_limits
            (identifier_type, identifier, failed_count, first_failed_at, last_failed_at, locked_until)
        VALUES (?, ?, 1, NOW(), NOW(), NULL)
    ");

    $update = $pdo->prepare("
        UPDATE login_rate_limits
        SET failed_count = ?,
            first_failed_at = ?,
            last_failed_at = NOW(),
            locked_until = ?
        WHERE identifier_type = ? AND identifier = ?
    ");

    $pdo->beginTransaction();

    try {
        foreach (loginIdentifierRows($username, $ip) as [$type, $identifier]) {
            $select->execute([$type, $identifier]);
            $row = $select->fetch(PDO::FETCH_ASSOC);

            if (!$row) {
                $insert->execute([$type, $identifier]);
                continue;
            }

            $firstFailed = strtotime((string)$row['first_failed_at']);
            $lockedUntil = $row['locked_until'] ? strtotime((string)$row['locked_until']) : null;
            $now = time();
            $windowExpired = !$firstFailed || $firstFailed < ($now - (LOGIN_RATE_LIMIT_WINDOW_MINUTES * 60));
            $lockExpired = $lockedUntil !== null && $lockedUntil <= $now;

            if ($windowExpired || $lockExpired) {
                $failedCount = 1;
                $firstFailedAt = date('Y-m-d H:i:s', $now);
            } else {
                $failedCount = ((int)$row['failed_count']) + 1;
                $firstFailedAt = (string)$row['first_failed_at'];
            }

            $newLockedUntil = $failedCount >= LOGIN_RATE_LIMIT_MAX_ATTEMPTS
                ? date('Y-m-d H:i:s', $now + (LOGIN_RATE_LIMIT_LOCK_MINUTES * 60))
                : null;

            $update->execute([$failedCount, $firstFailedAt, $newLockedUntil, $type, $identifier]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }

        throw $e;
    }
}

function resetLoginFailures(PDO $pdo, string $username, string $ip): void
{
    $rows = loginIdentifierRows($username, $ip);
    $where = [];
    $params = [];

    foreach ($rows as [$type, $identifier]) {
        $where[] = '(identifier_type = ? AND identifier = ?)';
        $params[] = $type;
        $params[] = $identifier;
    }

    $stmt = $pdo->prepare("
        DELETE FROM login_rate_limits
        WHERE " . implode(' OR ', $where)
    );
    $stmt->execute($params);
}

function redirectLockedOut(int $secondsRemaining): void
{
    $minutes = max(1, (int)ceil($secondsRemaining / 60));
    setFlash(
        'danger',
        'Too many failed attempts, try again in ' . $minutes . ' minute' . ($minutes === 1 ? '' : 's') . '.'
    );
    header('Location: ' . BASE_URL . 'modules/auth/login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . BASE_URL . 'modules/auth/login.php');
    exit;
}

if (!validateCsrfToken($_POST['csrf_token'] ?? null)) {
    http_response_code(403);
    exit('Invalid CSRF token.');
}

$username = trim($_POST['username'] ?? '');
$password = $_POST['password'] ?? '';
$clientIp = loginClientIp();

ensureLoginRateLimitTable($pdo);

$lockout = getLoginLockout($pdo, $username, $clientIp);
if ($lockout) {
    redirectLockedOut((int)$lockout['seconds_remaining']);
}

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
    recordLoginFailure($pdo, $username, $clientIp);
    setFlash('danger', 'Invalid username or password.');
    header('Location: ' . BASE_URL . 'modules/auth/login.php');
    exit;
}

if ((int)$user['is_active'] !== 1) {
    recordLoginFailure($pdo, $username, $clientIp);
    setFlash('danger', 'Your account is inactive.');
    header('Location: ' . BASE_URL . 'modules/auth/login.php');
    exit;
}

if (!password_verify($password, $user['password_hash'])) {
    recordLoginFailure($pdo, $username, $clientIp);
    setFlash('danger', 'Invalid username or password.');
    header('Location: ' . BASE_URL . 'modules/auth/login.php');
    exit;
}

resetLoginFailures($pdo, $username, $clientIp);

session_regenerate_id(true);

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
