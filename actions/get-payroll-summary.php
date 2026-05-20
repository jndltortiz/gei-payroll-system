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

// ── Totals ────────────────────────────────────────────────────────────────────
$totStmt = $pdo->prepare("
    SELECT
        COUNT(*)            AS emp_count,
        SUM(gross_pay)      AS total_gross,
        SUM(total_deductions) AS total_deductions,
        SUM(net_pay)        AS total_net
    FROM payroll_records
    WHERE period_id = ?
");
$totStmt->execute([$periodId]);
$totals = $totStmt->fetch();

// ── Summary mode ──────────────────────────────────────────────────────────────
if ($type === 'summary') {
    // Department breakdown – sum gross by department
    $deptStmt = $pdo->prepare("
        SELECT d.department_name,
               COUNT(pr.payroll_id)   AS emp_count,
               SUM(pr.gross_pay)      AS gross_total
        FROM payroll_records pr
        JOIN employees e   ON pr.employee_id   = e.employee_id
        JOIN departments d ON e.department_id  = d.department_id
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

// ── Register mode ─────────────────────────────────────────────────────────────
$recStmt = $pdo->prepare("
    SELECT
        CONCAT(e.first_name, ' ', e.last_name) AS employee_name,
        p.position_name,
        pr.basic_pay,
        COALESCE(pr.additional_assignment, 0) AS addl_assign,
        COALESCE(pr.rice_subsidy, 0)          AS rice_sub,
        COALESCE(pr.laundry_allowance, 0)     AS laundry,
        pr.gross_pay,
        COALESCE(pr.peraa_employee, 0)        AS peraa_p,
        COALESCE(pr.peraa_employer, 0)        AS peraa_l,
        COALESCE(pr.hdmf_employee, 0)         AS hdmf_p,
        COALESCE(pr.hdmf_employer, 0)         AS hdmf_l,
        COALESCE(pr.philhealth_employee, 0)   AS philhealth,
        COALESCE(pr.sss_employee, 0)          AS sss_p,
        COALESCE(pr.sss_employer, 0)          AS sss_l,
        pr.total_deductions,
        pr.net_pay
    FROM payroll_records pr
    JOIN employees  e ON pr.employee_id = e.employee_id
    LEFT JOIN positions p ON e.position_id = p.position_id
    WHERE pr.period_id = ?
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