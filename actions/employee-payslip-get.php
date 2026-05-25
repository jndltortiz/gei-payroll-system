<?php
/**
 * actions/employee-payslip-get.php
 * Returns a single payslip's breakdown as JSON.
 * SECURITY: Anchors to session employee_id — cannot view other employees.
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
header('Content-Type: application/json');
requireEmployeeAction();

$payrollId = (int)($_GET['payroll_id'] ?? 0);
$empId     = (int)($_SESSION['user']['employee_id'] ?? 0);

if (!$payrollId || !$empId) {
    echo json_encode(['success' => false, 'message' => 'Invalid request.']);
    exit;
}

// Verify this payslip belongs to this employee AND is from a RELEASED period
$stmt = $pdo->prepare("
    SELECT pr.payroll_id, pr.basic_pay, pr.gross_pay, pr.total_deductions, pr.net_pay,
           pr.released_by, pr.released_at, pr.payroll_status,
           pp.period_id, pp.period_name, pp.pay_period_start, pp.pay_period_end,
           pp.payroll_number, pp.status AS period_status,
           CONCAT(e.first_name, ' ', e.last_name) AS employee_name,
           COALESCE(e.employee_no, CONCAT('EMP-', LPAD(e.employee_id, 5, '0'))) AS employee_no,
           e.employment_type,
           p.position_name,
           d.department_name,
           COALESCE(ec.monthly_salary, 0) AS monthly_salary,
           ps.payslip_number
    FROM payroll_records pr
    JOIN payroll_periods pp ON pr.period_id = pp.period_id
    JOIN employees        e  ON pr.employee_id = e.employee_id
    LEFT JOIN positions   p  ON e.position_id  = p.position_id
    LEFT JOIN departments d  ON e.department_id = d.department_id
    LEFT JOIN employee_compensations ec
           ON ec.employee_id = e.employee_id AND ec.is_active = 1
    LEFT JOIN payslips ps ON ps.payroll_id = pr.payroll_id
    WHERE pr.payroll_id = ? AND pr.employee_id = ? AND pp.status = 'RELEASED'
");
$stmt->execute([$payrollId, $empId]);
$record = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$record) {
    echo json_encode(['success' => false, 'message' => 'Payslip not found or not accessible.']);
    exit;
}

// Allowances
$allowances = $pdo->prepare("
    SELECT at.allowance_name AS name, pa.amount,
           COALESCE(at.is_service_credit_target, 0) AS is_service_credit
    FROM payroll_allowances pa
    JOIN allowance_types at ON pa.allowance_type_id = at.allowance_type_id
    WHERE pa.payroll_id = ? AND pa.amount > 0
    ORDER BY at.allowance_name
");
$allowances->execute([$payrollId]);
$allowanceRows = $allowances->fetchAll(PDO::FETCH_ASSOC);

// Deductions
$deductions = $pdo->prepare("
    SELECT dt.deduction_name AS name, pd.amount
    FROM payroll_deductions pd
    JOIN deduction_types dt ON pd.deduction_type_id = dt.deduction_type_id
    WHERE pd.payroll_id = ? AND pd.amount > 0
    ORDER BY dt.deduction_name
");
$deductions->execute([$payrollId]);
$deductionRows = $deductions->fetchAll(PDO::FETCH_ASSOC);

// Attendance summary for pay period range (display only, no computation)
$attStmt = $pdo->prepare("
    SELECT
        SUM(attendance_status IN ('PRESENT','LATE','HALF_DAY')) AS days_present,
        SUM(attendance_status = 'ABSENT')  AS days_absent,
        SUM(attendance_status = 'LEAVE')   AS days_leave,
        SUM(attendance_status = 'LATE')    AS days_late,
        SUM(attendance_status = 'HALF_DAY') AS days_halfday,
        COUNT(*) AS total_records
    FROM attendance_records
    WHERE employee_id = ?
      AND attendance_date BETWEEN ? AND ?
");
$attStmt->execute([$empId, $record['pay_period_start'], $record['pay_period_end']]);
$attSummary = $attStmt->fetch(PDO::FETCH_ASSOC) ?: [];

echo json_encode([
    'success'     => true,
    'record'      => $record,
    'allowances'  => $allowanceRows,
    'deductions'  => $deductionRows,
    'attendance'  => $attSummary,
]);
