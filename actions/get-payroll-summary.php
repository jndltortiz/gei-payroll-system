<?php
/**
 * actions/get-payroll-summary.php
 * Returns payroll period summary or detailed register as JSON.
 * Used by: Principal Portal – Payroll Approvals page
 *
 * Query params:
 *   period_id  (int)    – Required
 *   type       (string) – 'summary' | 'register'
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
header('Content-Type: application/json');
requireLogin();

$periodId = (int)($_GET['period_id'] ?? 0);
$type     = $_GET['type'] ?? 'summary';

if (!$periodId) {
    echo json_encode(['success' => false, 'message' => 'No period specified.']);
    exit;
}

// ── Fetch period ──────────────────────────────────────────────────────────────
$stmt = $pdo->prepare("SELECT * FROM payroll_periods WHERE period_id = ?");
$stmt->execute([$periodId]);
$period = $stmt->fetch();

if (!$period) {
    echo json_encode(['success' => false, 'message' => 'Period not found.']);
    exit;
}

// ── Aggregate totals ──────────────────────────────────────────────────────────
$totStmt = $pdo->prepare("
    SELECT
        COUNT(*)              AS emp_count,
        SUM(gross_pay)        AS total_gross,
        SUM(total_deductions) AS total_deductions,
        SUM(net_pay)          AS total_net
    FROM payroll_records
    WHERE period_id = ?
");
$totStmt->execute([$periodId]);
$totals = $totStmt->fetch();

// ════════════════════════════════════════════════════════════════════════════
// SUMMARY MODE
// ════════════════════════════════════════════════════════════════════════════
if ($type === 'summary') {

    $deptStmt = $pdo->prepare("
        SELECT d.department_name,
               COUNT(pr.payroll_id) AS emp_count,
               SUM(pr.gross_pay)    AS gross_total
        FROM payroll_records pr
        JOIN employees   e ON pr.employee_id  = e.employee_id
        JOIN departments d ON e.department_id = d.department_id
        WHERE pr.period_id = ?
        GROUP BY d.department_id, d.department_name
        ORDER BY d.department_name
    ");
    $deptStmt->execute([$periodId]);
    $departments = $deptStmt->fetchAll();

    echo json_encode([
        'success'     => true,
        'period'      => $period,
        'totals'      => $totals,
        'departments' => $departments,
    ]);
    exit;
}

// ════════════════════════════════════════════════════════════════════════════
// REGISTER MODE
// FIX #4 — original query referenced non-existent columns on payroll_records
// (pr.additional_assignment, pr.rice_subsidy, pr.peraa_employee, etc.)
// These are stored in payroll_allowances / payroll_deductions and must be
// pivoted via conditional MAX() aggregation.
// ════════════════════════════════════════════════════════════════════════════
$recStmt = $pdo->prepare("
    SELECT
        pr.payroll_id,
        CONCAT(e.first_name, ' ', COALESCE(e.middle_name, ''), ' ', e.last_name) AS employee_name,
        e.employee_no,
        p.position_name,
        d.department_name,
        pr.basic_pay,
        pr.gross_pay,
        pr.total_deductions,
        pr.net_pay,

        /* ── Allowances pivot ── */
        COALESCE(MAX(CASE WHEN atype.allowance_name = 'Additional Assignment Pay'
                          THEN pa.amount END), 0) AS addl_assign,
        COALESCE(MAX(CASE WHEN atype.allowance_name = 'Rice Subsidy'
                          THEN pa.amount END), 0) AS rice_sub,
        COALESCE(MAX(CASE WHEN atype.allowance_name = 'Laundry Allowance'
                          THEN pa.amount END), 0) AS laundry,

        /* ── Deductions pivot ── */
        COALESCE(MAX(CASE WHEN dtype.deduction_name = 'PERAA Premium'
                          THEN pd.amount END), 0) AS peraa_p,
        COALESCE(MAX(CASE WHEN dtype.deduction_name = 'PERAA Loan'
                          THEN pd.amount END), 0) AS peraa_l,
        COALESCE(MAX(CASE WHEN dtype.deduction_name = 'HDMF Premium'
                          THEN pd.amount END), 0) AS hdmf_p,
        COALESCE(MAX(CASE WHEN dtype.deduction_name = 'HDMF Loan'
                          THEN pd.amount END), 0) AS hdmf_l,
        COALESCE(MAX(CASE WHEN dtype.deduction_name = 'PhilHealth'
                          THEN pd.amount END), 0) AS philhealth,
        COALESCE(MAX(CASE WHEN dtype.deduction_name = 'SSS Premium'
                          THEN pd.amount END), 0) AS sss_p,
        COALESCE(MAX(CASE WHEN dtype.deduction_name = 'SSS Loan'
                          THEN pd.amount END), 0) AS sss_l

    FROM payroll_records pr
    JOIN employees   e ON pr.employee_id  = e.employee_id
    LEFT JOIN positions   p     ON e.position_id   = p.position_id
    LEFT JOIN departments d     ON e.department_id = d.department_id

    /* Allowance pivot joins */
    LEFT JOIN payroll_allowances pa    ON pr.payroll_id = pa.payroll_id
    LEFT JOIN allowance_types    atype ON pa.allowance_type_id = atype.allowance_type_id

    /* Deduction pivot joins */
    LEFT JOIN payroll_deductions pd    ON pr.payroll_id = pd.payroll_id
    LEFT JOIN deduction_types    dtype ON pd.deduction_type_id = dtype.deduction_type_id

    WHERE pr.period_id = ?

    GROUP BY
        pr.payroll_id,
        e.employee_no, e.first_name, e.middle_name, e.last_name,
        p.position_name, d.department_name,
        pr.basic_pay, pr.gross_pay, pr.total_deductions, pr.net_pay

    ORDER BY e.last_name, e.first_name
");
$recStmt->execute([$periodId]);
$records = $recStmt->fetchAll();

echo json_encode([
    'success' => true,
    'period'  => $period,
    'records' => $records,
    'totals'  => [
        'gross'      => $totals['total_gross'],
        'deductions' => $totals['total_deductions'],
        'net'        => $totals['total_net'],
    ],
]);