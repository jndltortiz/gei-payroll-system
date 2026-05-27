<?php
/**
 * includes/notif-utils.php
 * Thin helpers for inserting rows into the notifications table.
 * All inserts are wrapped in try/catch — notifications must never break
 * the main workflow transaction.
 */

function sendNotif(PDO $pdo, int $userId, string $title, string $message,
                   string $type, string $linkUrl = '', int $relatedId = 0): void
{
    if (!$userId) return;
    try {
        $pdo->prepare("
            INSERT INTO notifications (user_id, title, message, type, link_url, related_id)
            VALUES (?, ?, ?, ?, ?, ?)
        ")->execute([$userId, $title, $message, $type, $linkUrl ?: null, $relatedId ?: null]);
    } catch (Exception $ignored) {}
}

function notifyUsers(PDO $pdo, array $userIds, string $title, string $message,
                     string $type, string $linkUrl = '', int $relatedId = 0): void
{
    foreach (array_unique(array_filter(array_map('intval', $userIds))) as $uid) {
        sendNotif($pdo, $uid, $title, $message, $type, $linkUrl, $relatedId);
    }
}

function notifAdmins(PDO $pdo, string $title, string $message,
                     string $type, string $linkUrl = '', int $relatedId = 0): void
{
    try {
        $stmt = $pdo->query("
            SELECT u.user_id FROM users u
            JOIN roles r ON u.role_id = r.role_id
            WHERE LOWER(r.role_name) = 'admin' AND u.is_active = 1
        ");
        notifyUsers($pdo, $stmt->fetchAll(PDO::FETCH_COLUMN), $title, $message, $type, $linkUrl, $relatedId);
    } catch (Exception $ignored) {}
}

function notifPrincipals(PDO $pdo, string $title, string $message,
                         string $type, string $linkUrl = '', int $relatedId = 0): void
{
    try {
        $stmt = $pdo->query("
            SELECT u.user_id FROM users u
            JOIN roles r ON u.role_id = r.role_id
            WHERE LOWER(r.role_name) IN ('principal', 'special assistant') AND u.is_active = 1
        ");
        notifyUsers($pdo, $stmt->fetchAll(PDO::FETCH_COLUMN), $title, $message, $type, $linkUrl, $relatedId);
    } catch (Exception $ignored) {}
}

function notifAdminsAndPrincipals(PDO $pdo, string $title, string $message,
                                   string $type, string $linkUrl = '', int $relatedId = 0): void
{
    try {
        $stmt = $pdo->query("
            SELECT u.user_id FROM users u
            JOIN roles r ON u.role_id = r.role_id
            WHERE LOWER(r.role_name) IN ('admin', 'principal', 'special assistant') AND u.is_active = 1
        ");
        notifyUsers($pdo, $stmt->fetchAll(PDO::FETCH_COLUMN), $title, $message, $type, $linkUrl, $relatedId);
    } catch (Exception $ignored) {}
}

function getEmployeeUserId(PDO $pdo, int $employeeId): int
{
    if (!$employeeId) return 0;
    try {
        $stmt = $pdo->prepare("SELECT user_id FROM users WHERE employee_id = ? AND is_active = 1 LIMIT 1");
        $stmt->execute([$employeeId]);
        return (int)($stmt->fetchColumn() ?: 0);
    } catch (Exception $ignored) { return 0; }
}

function getEmployeeName(PDO $pdo, int $employeeId): string
{
    if (!$employeeId) return 'An employee';
    try {
        $stmt = $pdo->prepare("SELECT CONCAT(first_name, ' ', last_name) FROM employees WHERE employee_id = ? LIMIT 1");
        $stmt->execute([$employeeId]);
        return $stmt->fetchColumn() ?: 'An employee';
    } catch (Exception $ignored) { return 'An employee'; }
}
