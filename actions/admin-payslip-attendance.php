<?php
/**
 * actions/admin-payslip-attendance.php
 * Returns attendance summary for a payroll record's pay period.
 * Used by admin/principal payslip modal.
 *
 * GET params: payroll_id
 * Returns JSON: { success, attendance: { days_present, days_late, days_halfday, days_absent, days_leave, total_records } }
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
header('Content-Type: application/json');
requireLogin();

if (!isAdmin() && !isPrincipalRole()) {
    echo json_encode(['success' => false, 'message' => 'Access denied.']);
    exit;
}

$payrollId = (int)($_GET['payroll_id'] ?? 0);
if (!$payrollId) {
    echo json_encode(['success' => false, 'message' => 'Invalid payroll ID.']);
    exit;
}

// Get employee_id and pay period dates from the payroll record
$row = $pdo->prepare("
    SELECT pr.employee_id, pp.pay_period_start, pp.pay_period_end
    FROM payroll_records pr
    JOIN payroll_periods pp ON pr.period_id = pp.period_id
    WHERE pr.payroll_id = ?
");
$row->execute([$payrollId]);
$info = $row->fetch(PDO::FETCH_ASSOC);

if (!$info) {
    echo json_encode(['success' => false, 'message' => 'Payroll record not found.']);
    exit;
}

$attStmt = $pdo->prepare("
    SELECT
        SUM(attendance_status IN ('PRESENT','LATE','HALF_DAY')) AS days_present,
        SUM(attendance_status = 'ABSENT')                       AS days_absent,
        SUM(attendance_status = 'LEAVE')                        AS days_leave,
        SUM(attendance_status = 'LATE')                         AS days_late,
        SUM(attendance_status = 'HALF_DAY')                     AS days_halfday,
        COUNT(*)                                                 AS total_records
    FROM attendance_records
    WHERE employee_id = ?
      AND attendance_date BETWEEN ? AND ?
");
$attStmt->execute([$info['employee_id'], $info['pay_period_start'], $info['pay_period_end']]);
$att = $attStmt->fetch(PDO::FETCH_ASSOC) ?: [];

echo json_encode(['success' => true, 'attendance' => $att]);
