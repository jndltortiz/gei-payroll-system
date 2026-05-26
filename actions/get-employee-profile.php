<?php
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
requireLogin();

header('Content-Type: application/json');

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if (!$id) { echo json_encode(['error' => 'Invalid ID']); exit; }

// ── Core employee data ─────────────────────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT
        e.*,
        d.department_name,
        p.position_name,
        s.shift_name,
        s.start_time  AS shift_start,
        s.end_time    AS shift_end,
        ec.monthly_salary,
        COALESCE(NULLIF(ec.daily_rate, 0), ROUND(ec.monthly_salary / 22, 2)) AS daily_rate,
        u.username,
        u.is_active AS user_is_active,
        r.role_name
    FROM employees e
    LEFT JOIN departments d     ON e.department_id = d.department_id
    LEFT JOIN positions p       ON e.position_id   = p.position_id
    LEFT JOIN shifts s          ON e.shift_id       = s.shift_id
    LEFT JOIN employee_compensations ec ON e.employee_id = ec.employee_id AND ec.is_active = 1
    LEFT JOIN users u           ON e.employee_id = u.employee_id
    LEFT JOIN roles r           ON u.role_id = r.role_id
    WHERE e.employee_id = ?
");
$stmt->execute([$id]);
$employee = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$employee) { echo json_encode(['error' => 'Employee not found']); exit; }

// ── Education credentials ──────────────────────────────────────────────────────
$eduStmt = $pdo->prepare("
    SELECT credential_type, institution, year_obtained, description
    FROM employee_credentials
    WHERE employee_id = ? AND credential_type = 'Education'
    ORDER BY year_obtained DESC
");
$eduStmt->execute([$id]);
$education = $eduStmt->fetchAll(PDO::FETCH_ASSOC);

// ── Documents ─────────────────────────────────────────────────────────────────
$docStmt = $pdo->prepare("
    SELECT doc_type, doc_name, file_path, file_size, uploaded_at
    FROM employee_documents
    WHERE employee_id = ?
    ORDER BY uploaded_at DESC
");
$docStmt->execute([$id]);
$documents = $docStmt->fetchAll(PDO::FETCH_ASSOC);

// ── Last payslip ──────────────────────────────────────────────────────────────
$lastPayslip = null;
try {
    $payStmt = $pdo->prepare("
        SELECT pp.period_name AS period_label, pp.pay_period_start AS period_start,
               pp.pay_period_end AS period_end, pr.net_pay, pr.gross_pay
        FROM payroll_records pr
        JOIN payroll_periods pp ON pr.period_id = pp.period_id
        WHERE pr.employee_id = ? AND pr.payroll_status = 'RELEASED'
        ORDER BY pp.pay_period_end DESC
        LIMIT 1
    ");
    $payStmt->execute([$id]);
    $lastPayslip = $payStmt->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (PDOException $e) { /* payroll tables may differ */ }

// ── Active loans ──────────────────────────────────────────────────────────────
$loans = [];
try {
    $loanStmt = $pdo->prepare("
        SELECT lt.loan_name AS loan_type, el.total_amount AS principal_amount,
               el.balance_amount AS outstanding_balance, el.monthly_deduction, el.status
        FROM employee_loans el
        JOIN loan_types lt ON el.loan_type_id = lt.loan_type_id
        WHERE el.employee_id = ? AND el.status IN ('ACTIVE', 'PAUSED')
        ORDER BY el.loan_id DESC
        LIMIT 5
    ");
    $loanStmt->execute([$id]);
    $loans = $loanStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) { /* loan tables may not exist yet */ }

// ── Leave balances ─────────────────────────────────────────────────────────────
$leaveBalances = [];
try {
    $leaveStmt = $pdo->prepare("
        SELECT lt.leave_name, elc.allocated_days, elc.used_days,
               (elc.allocated_days - elc.used_days) AS remaining
        FROM employee_leave_credits elc
        JOIN leave_types lt ON elc.leave_type_id = lt.leave_type_id
        JOIN school_years sy ON elc.school_year_id = sy.school_year_id
        WHERE elc.employee_id = ? AND sy.is_active = 1
        ORDER BY lt.leave_name
    ");
    $leaveStmt->execute([$id]);
    $leaveBalances = $leaveStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // credit system tables may not exist yet; fall back to empty
}

// ── Service credits summary ───────────────────────────────────────────────────
$scSummary = null;
try {
    $scStmt = $pdo->prepare("
        SELECT
            COUNT(*)                                                                    AS total_entries,
            COALESCE(SUM(CASE WHEN status IN ('PENDING','APPROVED') AND payroll_id IS NULL THEN days ELSE 0 END), 0) AS unpaid_days,
            COALESCE(SUM(CASE WHEN payroll_id IS NOT NULL THEN days ELSE 0 END), 0)    AS paid_days
        FROM service_credits
        WHERE employee_id = ? AND status != 'ARCHIVED'
    ");
    $scStmt->execute([$id]);
    $scSummary = $scStmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // Table may not exist
}

echo json_encode([
    'employee'      => $employee,
    'education'     => $education,
    'documents'     => $documents,
    'last_payslip'  => $lastPayslip,
    'loans'         => $loans,
    'leave_balances'=> $leaveBalances,
    'sc_summary'    => $scSummary,
]);
