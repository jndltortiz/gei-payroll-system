<?php
/**
 * actions/payroll-delete.php
 * Deletes a single DRAFT payroll record (and its allowance/deduction rows).
 * Only works when the parent period is still OPEN.
 *
 * POST params:
 *   payroll_id  (int) — required
 *
 * Returns JSON { success, message }
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
header('Content-Type: application/json');
requireAdminAction(); // Admin only — principals cannot delete payroll records

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit;
}

$payrollId = (int)($_POST['payroll_id'] ?? 0);
$uid       = $_SESSION['user']['user_id'] ?? null;

if (!$payrollId) {
    echo json_encode(['success' => false, 'message' => 'No payroll record specified.']);
    exit;
}

// Fetch record + parent period status in one query
$stmt = $pdo->prepare("
    SELECT pr.payroll_id,
           pr.payroll_status,
           pr.employee_id,
           pp.status  AS period_status,
           pp.period_name
    FROM payroll_records pr
    JOIN payroll_periods pp ON pr.period_id = pp.period_id
    WHERE pr.payroll_id = ?
");
$stmt->execute([$payrollId]);
$record = $stmt->fetch();

if (!$record) {
    echo json_encode(['success' => false, 'message' => 'Payroll record not found.']);
    exit;
}

// Guard: only DRAFT records in OPEN periods may be deleted
if ($record['payroll_status'] !== 'DRAFT') {
    echo json_encode([
        'success' => false,
        'message' => "Cannot delete: payroll record is {$record['payroll_status']}. "
                   . "Only DRAFT records can be removed."
    ]);
    exit;
}

if ($record['period_status'] !== 'OPEN') {
    echo json_encode([
        'success' => false,
        'message' => "Cannot delete: the period is {$record['period_status']}. "
                   . "Only records in OPEN periods can be removed."
    ]);
    exit;
}

try {
    // Cascade-delete child rows first (FK safety)
    $pdo->prepare("DELETE FROM payroll_allowances WHERE payroll_id = ?")->execute([$payrollId]);
    $pdo->prepare("DELETE FROM payroll_deductions WHERE payroll_id = ?")->execute([$payrollId]);
    $pdo->prepare("DELETE FROM payslips          WHERE payroll_id = ?")->execute([$payrollId]);
    $pdo->prepare("DELETE FROM payroll_records   WHERE payroll_id = ?")->execute([$payrollId]);

    // Audit log
    if ($uid) {
        $pdo->prepare("
            INSERT INTO audit_logs (user_id, action, table_name, record_id, description)
            VALUES (?, 'DELETE', 'payroll_records', ?, ?)
        ")->execute([
            $uid,
            $payrollId,
            "Deleted DRAFT payroll record #{$payrollId} "
              . "(employee_id={$record['employee_id']}, period=\"{$record['period_name']}\")"
        ]);
    }

    echo json_encode([
        'success' => true,
        'message' => 'Payroll record deleted successfully.',
    ]);

} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $e->getMessage()]);
}