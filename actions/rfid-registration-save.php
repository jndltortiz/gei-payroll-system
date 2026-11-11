<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/rfid-utils.php';
requireAdminPage();

$redirect = BASE_URL . 'modules/employees/rfid-registration.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: ' . $redirect); exit; }
if (!validateCsrfToken($_POST['csrf_token'] ?? null)) {
    setFlash('danger', 'Your session expired. Please try again.');
    header('Location: ' . $redirect); exit;
}

$action = (string)($_POST['action'] ?? '');
$employeeId = (int)($_POST['employee_id'] ?? 0);
$userId = $_SESSION['user']['user_id'] ?? null;

$fail = static function (string $message, int $employeeId = 0) use ($redirect): void {
    setFlash('danger', $message);
    header('Location: ' . $redirect . ($employeeId > 0 ? '?employee_id=' . $employeeId : '')); exit;
};

if (!in_array($action, ['assign', 'unassign'], true) || $employeeId <= 0) {
    $fail('Please choose an employee.');
}

try {
    $pdo->beginTransaction();

    $empStmt = $pdo->prepare('SELECT employee_id, employee_no, first_name, last_name, employee_status, rfid_uid FROM employees WHERE employee_id = ? FOR UPDATE');
    $empStmt->execute([$employeeId]);
    $employee = $empStmt->fetch(PDO::FETCH_ASSOC);
    if (!$employee) throw new RuntimeException('Employee not found.');
    $name = trim($employee['first_name'] . ' ' . $employee['last_name']);

    if ($action === 'assign') {
        $uid = normalizeRfidUid($_POST['rfid_uid'] ?? '');
        if (!isValidRfidUid($uid)) throw new RuntimeException('Card number not recognized. Tap the card again (4–64 letters or digits).');
        if ($employee['employee_status'] !== 'ACTIVE') throw new RuntimeException("{$name} is not active. Only active employees can be given a card.");
        if ($employee['rfid_uid'] === $uid) throw new RuntimeException("This card is already registered to {$name}.");

        $ownerStmt = $pdo->prepare('SELECT employee_no, first_name, last_name FROM employees WHERE rfid_uid = ? AND employee_id <> ? LIMIT 1');
        $ownerStmt->execute([$uid, $employeeId]);
        if ($owner = $ownerStmt->fetch(PDO::FETCH_ASSOC)) {
            $ownerName = trim($owner['first_name'] . ' ' . $owner['last_name']);
            throw new RuntimeException("This card is already registered to {$ownerName} ({$owner['employee_no']}). Unlink it from them first.");
        }

        $pdo->prepare('UPDATE employees SET rfid_uid = ?, rfid_registered_at = NOW() WHERE employee_id = ?')->execute([$uid, $employeeId]);
        $replaced = $employee['rfid_uid'] !== null && $employee['rfid_uid'] !== '';
        $description = ($replaced ? 'Replaced RFID card ' . maskRfidUid($employee['rfid_uid']) . ' with ' : 'Registered RFID card ') . maskRfidUid($uid) . " for {$name} ({$employee['employee_no']})";
        $logAction = $replaced ? 'RFID_REPLACE' : 'RFID_ASSIGN';
        $message = ($replaced ? 'Card replaced' : 'Card registered') . " for {$name}. They can now tap in at the RFID kiosk.";
    } else {
        if ($employee['rfid_uid'] === null || $employee['rfid_uid'] === '') throw new RuntimeException("{$name} has no RFID card to unlink.");
        $pdo->prepare('UPDATE employees SET rfid_uid = NULL, rfid_registered_at = NULL WHERE employee_id = ?')->execute([$employeeId]);
        $description = 'Unlinked RFID card ' . maskRfidUid($employee['rfid_uid']) . " from {$name} ({$employee['employee_no']})";
        $logAction = 'RFID_UNASSIGN';
        $message = "Card unlinked from {$name}.";
    }

    if ($userId) {
        $pdo->prepare("INSERT INTO audit_logs (user_id, action, table_name, record_id, description) VALUES (?, ?, 'employees', ?, ?)")
            ->execute([$userId, $logAction, $employeeId, $description]);
    }

    $pdo->commit();
    setFlash('success', $message);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    if ($e instanceof PDOException && $e->getCode() === '23000') {
        $fail('This card was just registered to another employee. Refresh and try again.', $employeeId);
    }
    if (!$e instanceof RuntimeException) error_log('[rfid-registration] ' . $e->getMessage());
    $fail($e instanceof RuntimeException ? $e->getMessage() : 'Unable to save the RFID card. Please try again.', $employeeId);
}

header('Location: ' . $redirect); exit;
