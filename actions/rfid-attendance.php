<?php
require_once __DIR__ . '/../includes/auth.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: ' . BASE_URL . 'modules/attendance/rfid-kiosk.php'); exit; }
if (!validateCsrfToken($_POST['csrf_token'] ?? null)) { http_response_code(403); exit('Invalid CSRF token.'); }

$uid = trim((string)($_POST['rfid_uid'] ?? ''));
$action = (string)($_POST['attendance_action'] ?? '');
if ($uid === '' || !in_array($action, ['time_in', 'time_out'], true)) {
    setFlash('danger', 'Please tap a valid RFID card and choose Time In or Time Out.');
    header('Location: ' . BASE_URL . 'modules/attendance/rfid-kiosk.php'); exit;
}

$employeeStmt = $pdo->prepare("SELECT e.*, s.start_time, s.end_time FROM employees e LEFT JOIN shifts s ON s.shift_id = e.shift_id WHERE e.rfid_uid = ? AND e.employee_status = 'ACTIVE' LIMIT 1");
$employeeStmt->execute([$uid]);
$employee = $employeeStmt->fetch(PDO::FETCH_ASSOC);
if (!$employee) {
    setFlash('danger', 'RFID card not recognized. Please contact HR or an administrator.');
    header('Location: ' . BASE_URL . 'modules/attendance/rfid-kiosk.php'); exit;
}

$date = date('Y-m-d'); $time = date('H:i:s'); $name = trim($employee['first_name'] . ' ' . $employee['last_name']);
try {
    $pdo->beginTransaction();
    $recordStmt = $pdo->prepare('SELECT * FROM attendance_records WHERE employee_id = ? AND attendance_date = ? FOR UPDATE');
    $recordStmt->execute([$employee['employee_id'], $date]); $record = $recordStmt->fetch(PDO::FETCH_ASSOC);
    if ($action === 'time_in') {
        if ($record && $record['time_in']) throw new RuntimeException('Time In is already recorded for today.');
        $status = ($employee['start_time'] && $time > $employee['start_time']) ? 'LATE' : 'PRESENT';
        if ($record) {
            $stmt = $pdo->prepare("UPDATE attendance_records SET time_in = ?, attendance_status = ?, attendance_source = 'RFID' WHERE attendance_id = ?");
            $stmt->execute([$time, $status, $record['attendance_id']]); $attendanceId = $record['attendance_id'];
        } else {
            $stmt = $pdo->prepare("INSERT INTO attendance_records (employee_id, attendance_date, time_in, attendance_status, attendance_source) VALUES (?, ?, ?, ?, 'RFID')");
            $stmt->execute([$employee['employee_id'], $date, $time, $status]); $attendanceId = $pdo->lastInsertId();
        }
        $audit = $pdo->prepare("INSERT INTO attendance_audit_log (attendance_id, changed_by, action_type, new_time_in, new_status, new_method, reason) VALUES (?, NULL, 'CREATE', ?, ?, 'RFID', 'RFID kiosk time-in')");
        $audit->execute([$attendanceId, $time, $status]); $message = "Time In recorded for {$name} at " . date('g:i A', strtotime($time)) . '.';
    } else {
        if (!$record || !$record['time_in']) throw new RuntimeException('No Time In exists for today. Please Time In first.');
        if ($record['time_out']) throw new RuntimeException('Time Out is already recorded for today.');
        if ($time < $record['time_in']) throw new RuntimeException('Time Out cannot be earlier than Time In.');
        $overtime = $employee['end_time'] ? max(0, (int)((strtotime($time) - strtotime($date . ' ' . $employee['end_time'])) / 60)) : 0;
        $stmt = $pdo->prepare('UPDATE attendance_records SET time_out = ?, overtime_minutes = ? WHERE attendance_id = ? AND time_out IS NULL');
        $stmt->execute([$time, $overtime, $record['attendance_id']]);
        $audit = $pdo->prepare("INSERT INTO attendance_audit_log (attendance_id, changed_by, action_type, old_time_in, new_time_in, old_time_out, new_time_out, old_status, new_status, old_method, new_method, reason) VALUES (?, NULL, 'TIMEOUT', ?, ?, NULL, ?, ?, ?, ?, ?, 'RFID kiosk time-out')");
        $audit->execute([$record['attendance_id'], $record['time_in'], $record['time_in'], $time, $record['attendance_status'], $record['attendance_status'], $record['attendance_source'], $record['attendance_source']]);
        $message = "Time Out recorded for {$name} at " . date('g:i A', strtotime($time)) . '.';
    }
    $pdo->commit(); setFlash('success', $message);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    setFlash('danger', $e instanceof RuntimeException ? $e->getMessage() : 'Unable to record attendance. Please contact an administrator.');
}
header('Location: ' . BASE_URL . 'modules/attendance/rfid-kiosk.php'); exit;
