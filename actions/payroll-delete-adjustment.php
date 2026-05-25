<?php
/**
 * actions/payroll-delete-adjustment.php
 * Removes a single manually-added adjustment row from payroll_allowances
 * or payroll_deductions. Only rows with is_adjustment=1 on a DRAFT record
 * in an OPEN period may be deleted.
 *
 * POST params:
 *   row_id     — payroll_allowance_id or payroll_deduction_id
 *   type       — 'allowance' | 'deduction'
 *   payroll_id — owning payroll record
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/payroll-utils.php';

requireAdminAction();
header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Method not allowed.']);
    exit;
}

$rowId     = (int)($_POST['row_id']     ?? 0);
$type      = trim($_POST['type']        ?? '');
$payrollId = (int)($_POST['payroll_id'] ?? 0);

if (!$rowId || !$payrollId || !in_array($type, ['allowance', 'deduction'], true)) {
    echo json_encode(['success' => false, 'message' => 'Invalid request parameters.']);
    exit;
}

// Guard: record must be DRAFT in an OPEN period
$guard = $pdo->prepare("
    SELECT pr.payroll_status, pp.status AS period_status
    FROM payroll_records pr
    JOIN payroll_periods pp ON pr.period_id = pp.period_id
    WHERE pr.payroll_id = ?
");
$guard->execute([$payrollId]);
$g = $guard->fetch();

if (!$g) {
    echo json_encode(['success' => false, 'message' => 'Payroll record not found.']);
    exit;
}
if ($g['payroll_status'] !== 'DRAFT' || $g['period_status'] !== 'OPEN') {
    echo json_encode(['success' => false, 'message' => 'Payroll is locked and cannot be edited.']);
    exit;
}

if ($type === 'allowance') {
    $check = $pdo->prepare("
        SELECT payroll_allowance_id FROM payroll_allowances
        WHERE payroll_allowance_id = ? AND payroll_id = ? AND COALESCE(is_adjustment, 0) = 1
    ");
    $check->execute([$rowId, $payrollId]);
    if (!$check->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Adjustment row not found or not removable.']);
        exit;
    }
    $pdo->prepare("DELETE FROM payroll_allowances WHERE payroll_allowance_id = ?")->execute([$rowId]);
} else {
    $check = $pdo->prepare("
        SELECT payroll_deduction_id FROM payroll_deductions
        WHERE payroll_deduction_id = ? AND payroll_id = ? AND COALESCE(is_adjustment, 0) = 1
    ");
    $check->execute([$rowId, $payrollId]);
    if (!$check->fetch()) {
        echo json_encode(['success' => false, 'message' => 'Adjustment row not found or not removable.']);
        exit;
    }
    $pdo->prepare("DELETE FROM payroll_deductions WHERE payroll_deduction_id = ?")->execute([$rowId]);
}

// Recompute totals after deletion
recalculatePayrollTotals($pdo, $payrollId);

// Return updated totals so JS can refresh the computed box immediately
$totRow = $pdo->prepare("
    SELECT basic_pay, total_allowances, total_deductions, gross_pay, net_pay
    FROM payroll_records WHERE payroll_id = ?
");
$totRow->execute([$payrollId]);
$updated = $totRow->fetch();

// Audit log
$uid = $_SESSION['user']['user_id'] ?? null;
if ($uid) {
    $pdo->prepare("
        INSERT INTO audit_logs (user_id, action, table_name, record_id, description)
        VALUES (?, 'DELETE', 'payroll_adjustments', ?, ?)
    ")->execute([
        $uid,
        $payrollId,
        "Removed {$type} adjustment (row #{$rowId}) from payroll #{$payrollId}",
    ]);
}

echo json_encode([
    'success' => true,
    'message' => 'Adjustment removed.',
    'totals'  => [
        'gross'      => (float)($updated['gross_pay']       ?? 0),
        'deductions' => (float)($updated['total_deductions'] ?? 0),
        'net'        => (float)($updated['net_pay']          ?? 0),
    ],
]);
