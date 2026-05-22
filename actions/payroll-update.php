<?php
/**
 * actions/payroll-update.php
 * Updates allowances, deductions, and recomputed totals for one payroll record.
 * Only DRAFT records whose period is still OPEN may be edited.
 *
 * POST params:
 *   payroll_id    — record to update
 *   basic         — updated basic pay
 *   pa[{type_id}] — allowance amounts keyed by allowance_type_id
 *   pd[{type_id}] — deduction amounts keyed by deduction_type_id
 *
 * Redirects back to admin payroll index on completion.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/payroll-utils.php';

requireAdminAction();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: ' . BASE_URL . 'modules/payroll/index.php');
    exit;
}

$payrollId = (int)($_POST['payroll_id'] ?? 0);

if (!$payrollId) {
    header('Location: ' . BASE_URL . 'modules/payroll/index.php?error=no_id');
    exit;
}

// Guard: only DRAFT records in OPEN periods may be edited
$guard = $pdo->prepare("
    SELECT pr.payroll_status, pp.status AS period_status
    FROM payroll_records pr
    JOIN payroll_periods pp ON pr.period_id = pp.period_id
    WHERE pr.payroll_id = ?
");
$guard->execute([$payrollId]);
$guardRow = $guard->fetch();

if (!$guardRow) {
    header('Location: ' . BASE_URL . 'modules/payroll/index.php?error=not_found');
    exit;
}
if ($guardRow['payroll_status'] !== 'DRAFT' || $guardRow['period_status'] !== 'OPEN') {
    header('Location: ' . BASE_URL . 'modules/payroll/index.php?error=locked');
    exit;
}

// ── Basic pay ─────────────────────────────────────────────────────────────────
$basic = max(0.0, (float)($_POST['basic'] ?? 0));
$pdo->prepare("UPDATE payroll_records SET basic_pay = ? WHERE payroll_id = ?")
    ->execute([$basic, $payrollId]);

// ── Allowances — update by allowance_type_id (not by name) ───────────────────
$allowances = $_POST['pa'] ?? [];
if (!empty($allowances) && is_array($allowances)) {
    $updAllowance = $pdo->prepare("
        UPDATE payroll_allowances
        SET    amount = ?
        WHERE  payroll_id = ? AND allowance_type_id = ?
    ");
    foreach ($allowances as $typeId => $amount) {
        $typeId = (int)$typeId;
        $amount = max(0.0, (float)$amount);
        if ($typeId > 0) {
            $updAllowance->execute([$amount, $payrollId, $typeId]);
        }
    }
}

// ── Deductions — update by deduction_type_id (not by name) ───────────────────
$deductions = $_POST['pd'] ?? [];
if (!empty($deductions) && is_array($deductions)) {
    $updDeduction = $pdo->prepare("
        UPDATE payroll_deductions
        SET    amount = ?
        WHERE  payroll_id = ? AND deduction_type_id = ?
    ");
    foreach ($deductions as $typeId => $amount) {
        $typeId = (int)$typeId;
        $amount = max(0.0, (float)$amount);
        if ($typeId > 0) {
            $updDeduction->execute([$amount, $payrollId, $typeId]);
        }
    }
}

// ── New manual allowance adjustments ─────────────────────────────────────────
// Sent as new_pa[n][label] + new_pa[n][amount]. Uses the reserved 'Adjustment'
// allowance type (is_active=0) so these never appear in auto-generation.
$newAllowances = $_POST['new_pa'] ?? [];
if (!empty($newAllowances) && is_array($newAllowances)) {
    $adjTypeRow = $pdo->query("
        SELECT allowance_type_id FROM allowance_types
        WHERE allowance_name = 'Adjustment' AND is_active = 0 LIMIT 1
    ")->fetch();
    if ($adjTypeRow) {
        $adjTypeId = (int)$adjTypeRow['allowance_type_id'];
        $insAdj = $pdo->prepare("
            INSERT INTO payroll_allowances
                (payroll_id, allowance_type_id, amount, is_adjustment, adjustment_label)
            VALUES (?, ?, ?, 1, ?)
        ");
        foreach ($newAllowances as $item) {
            $label  = trim($item['label'] ?? '');
            $amount = max(0.0, (float)($item['amount'] ?? 0));
            if ($label !== '' && $amount > 0) {
                $insAdj->execute([$payrollId, $adjTypeId, $amount, $label]);
            }
        }
    }
}

// ── New manual deduction adjustments ─────────────────────────────────────────
$newDeductions = $_POST['new_pd'] ?? [];
if (!empty($newDeductions) && is_array($newDeductions)) {
    $adjTypeRow = $pdo->query("
        SELECT deduction_type_id FROM deduction_types
        WHERE deduction_name = 'Adjustment' AND is_active = 0 LIMIT 1
    ")->fetch();
    if ($adjTypeRow) {
        $adjTypeId = (int)$adjTypeRow['deduction_type_id'];
        $insAdj = $pdo->prepare("
            INSERT INTO payroll_deductions
                (payroll_id, deduction_type_id, amount, is_adjustment, adjustment_label)
            VALUES (?, ?, ?, 1, ?)
        ");
        foreach ($newDeductions as $item) {
            $label  = trim($item['label'] ?? '');
            $amount = max(0.0, (float)($item['amount'] ?? 0));
            if ($label !== '' && $amount > 0) {
                $insAdj->execute([$payrollId, $adjTypeId, $amount, $label]);
            }
        }
    }
}

// ── Recompute totals from DB rows (uses shared helper from payroll-utils.php) ─
recalculatePayrollTotals($pdo, $payrollId);

// ── Audit ─────────────────────────────────────────────────────────────────────
$netStmt = $pdo->prepare("SELECT net_pay FROM payroll_records WHERE payroll_id = ?");
$netStmt->execute([$payrollId]);
$netPay = (float)($netStmt->fetchColumn() ?? 0);

$uid = $_SESSION['user']['user_id'] ?? null;
if ($uid) {
    $pdo->prepare("
        INSERT INTO audit_logs (user_id, action, table_name, record_id, description)
        VALUES (?, 'UPDATE', 'payroll_records', ?, ?)
    ")->execute([
        $uid,
        $payrollId,
        "Manually updated payroll record #{$payrollId} — net pay: ₱" . number_format($netPay, 2),
    ]);
}

header('Location: ' . BASE_URL . 'modules/payroll/index.php?updated=1');
exit;
