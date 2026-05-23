<?php
/**
 * actions/payroll-approve.php
 * Handles approve / return / release workflow.
 * Logs every event to payroll_workflow_log so both portals can show history.
 *
 *  approve  PROCESSING → APPROVED   Principal only
 *  return   PROCESSING → OPEN       Principal only  (remarks stored = visible to Admin)
 *  release  APPROVED   → RELEASED   Admin only
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
header('Content-Type: application/json');
requireLogin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request.']); exit;
}

$action   = $_POST['action']    ?? '';
$periodId = (int)($_POST['period_id'] ?? 0);
$notes    = trim($_POST['notes'] ?? '');
$uid      = $_SESSION['user']['user_id'] ?? null;

if (!$periodId) {
    echo json_encode(['success' => false, 'message' => 'No period specified.']); exit;
}

// ── Per-action role guard ─────────────────────────────────────────────────────
switch ($action) {
    case 'approve': case 'return':
        if (!isPrincipalRole()) {
            http_response_code(403);
            echo json_encode(['success'=>false,
                'message'=>'Access denied. Only Principal/Special Assistant may approve or reject.']); exit;
        }
        break;
    case 'release':
        if (!isAdmin()) {
            http_response_code(403);
            echo json_encode(['success'=>false,
                'message'=>'Access denied. Only Admin (Accounting) may release payroll.']); exit;
        }
        break;
    default:
        echo json_encode(['success'=>false,'message'=>'Unknown action.']); exit;
}

$stmt = $pdo->prepare("SELECT * FROM payroll_periods WHERE period_id = ?");
$stmt->execute([$periodId]);
$period = $stmt->fetch();
if (!$period) {
    echo json_encode(['success'=>false,'message'=>'Period not found.']); exit;
}

// Performer name for workflow log
$nameRow = $pdo->prepare("
    SELECT CONCAT(e.first_name,' ',e.last_name)
    FROM users u LEFT JOIN employees e ON u.employee_id = e.employee_id
    WHERE u.user_id = ?
");
$nameRow->execute([$uid]);
$performerName = $nameRow->fetchColumn() ?: 'Unknown';

// Snapshot totals
$snap = $pdo->prepare("SELECT SUM(gross_pay) AS g, SUM(net_pay) AS n, COUNT(*) AS c FROM payroll_records WHERE period_id=?");
$snap->execute([$periodId]);
$snapshot = $snap->fetch();

try {
    switch ($action) {

        // ── APPROVE ───────────────────────────────────────────────────────────
        case 'approve':
            if ($period['status'] !== 'PROCESSING') {
                echo json_encode(['success'=>false,'message'=>'Only PROCESSING periods can be approved.']); exit;
            }
            $pdo->prepare("UPDATE payroll_periods SET status='APPROVED', updated_at=NOW() WHERE period_id=?")->execute([$periodId]);
            $pdo->prepare("UPDATE payroll_records SET payroll_status='APPROVED', approved_by=?, approved_at=NOW()
                           WHERE period_id=? AND payroll_status='DRAFT'")->execute([$uid, $periodId]);

            $pdo->prepare("INSERT INTO payroll_workflow_log
                (period_id,event_type,performed_by,performer_name,remarks,gross_total,net_total,emp_count)
                VALUES(?,'APPROVED',?,?,?,?,?,?)")
                ->execute([$periodId,$uid,$performerName,$notes?:null,
                           $snapshot['g'],$snapshot['n'],$snapshot['c']]);

            if ($uid) $pdo->prepare("INSERT INTO audit_logs(user_id,action,table_name,record_id,description) VALUES(?,?,?,?,?)")
                ->execute([$uid,'APPROVE','payroll_periods',$periodId,
                    "Principal approved \"{$period['period_name']}\""
                    .($notes?" | Notes: $notes":'')]);

            echo json_encode(['success'=>true,
                'message'=>"\"{$period['period_name']}\" approved. Accounting can now release payroll."]);
            break;

        // ── RETURN FOR REVISION ───────────────────────────────────────────────
        case 'return':
            if ($period['status'] !== 'PROCESSING') {
                echo json_encode(['success'=>false,'message'=>'Only PROCESSING periods can be returned.']); exit;
            }
            $pdo->prepare("UPDATE payroll_periods SET status='OPEN', updated_at=NOW() WHERE period_id=?")->execute([$periodId]);
            $pdo->prepare("UPDATE payroll_records SET payroll_status='DRAFT', approved_by=NULL, approved_at=NULL
                           WHERE period_id=?")->execute([$periodId]);

            // The remarks are stored here — this is what the Admin needs to see
            $pdo->prepare("INSERT INTO payroll_workflow_log
                (period_id,event_type,performed_by,performer_name,remarks,gross_total,net_total,emp_count)
                VALUES(?,'RETURNED',?,?,?,?,?,?)")
                ->execute([$periodId,$uid,$performerName,
                           $notes?:null,   // rejection reason — shown to Admin
                           $snapshot['g'],$snapshot['n'],$snapshot['c']]);

            if ($uid) $pdo->prepare("INSERT INTO audit_logs(user_id,action,table_name,record_id,description) VALUES(?,?,?,?,?)")
                ->execute([$uid,'RETURN','payroll_periods',$periodId,
                    "Principal returned \"{$period['period_name']}\" for revision"
                    .($notes?" | Reason: $notes":'')]);

            echo json_encode(['success'=>true,
                'message'=>"\"{$period['period_name']}\" returned to Admin for revision."
                    .($notes?" Reason: $notes":'')]);
            break;

        // ── RELEASE ───────────────────────────────────────────────────────────
        case 'release':
            if ($period['status'] !== 'APPROVED') {
                echo json_encode(['success'=>false,'message'=>'Only APPROVED periods can be released.']); exit;
            }
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE payroll_periods SET status='RELEASED', updated_at=NOW() WHERE period_id=?")->execute([$periodId]);
            $pdo->prepare("UPDATE payroll_records SET payroll_status='RELEASED', released_by=?, released_at=NOW()
                           WHERE period_id=? AND payroll_status='APPROVED'")->execute([$uid, $periodId]);

            // Apply loan deductions only on release. Generation creates draft
            // payroll rows with loan_id set (migration 011); release reduces balances
            // and writes the payment to loan_payment_log for audit history.
            $loanDedStmt = $pdo->prepare("
                SELECT pd.loan_id, pd.amount
                FROM payroll_records pr
                JOIN payroll_deductions pd ON pr.payroll_id = pd.payroll_id
                JOIN deduction_types dt   ON pd.deduction_type_id = dt.deduction_type_id
                WHERE pr.period_id = ?
                  AND pd.amount > 0
                  AND pd.loan_id IS NOT NULL
                  AND dt.is_loan = 1
            ");
            $loanDedStmt->execute([$periodId]);

            $updateLoan = $pdo->prepare("
                UPDATE employee_loans
                SET balance_amount = GREATEST(0, balance_amount - ?),
                    status = IF(GREATEST(0, balance_amount - ?) <= 0, 'COMPLETED', status)
                WHERE loan_id = ? AND status IN ('ACTIVE','PAUSED')
            ");
            $insPayLog = $pdo->prepare("
                INSERT INTO loan_payment_log
                    (loan_id, payment_date, amount, payment_channel, notes, encoded_by)
                VALUES (?, ?, ?, 'PAYROLL', 'Payroll deduction — auto-applied on release', ?)
            ");

            foreach ($loanDedStmt->fetchAll() as $ded) {
                $updateLoan->execute([$ded['amount'], $ded['amount'], $ded['loan_id']]);
                $insPayLog->execute([$ded['loan_id'], date('Y-m-d'), $ded['amount'], $uid]);
            }

            // ── Release linked service credits ───────────────────────────
            $pdo->prepare("
                UPDATE service_credits sc
                JOIN payroll_records pr ON sc.payroll_id = pr.payroll_id
                SET sc.status = 'RELEASED', sc.updated_at = NOW()
                WHERE pr.period_id = ? AND sc.status = 'APPLIED'
            ")->execute([$periodId]);

            $pdo->prepare("INSERT INTO payroll_workflow_log
                (period_id,event_type,performed_by,performer_name,gross_total,net_total,emp_count)
                VALUES(?,'RELEASED',?,?,?,?,?)")
                ->execute([$periodId,$uid,$performerName,
                           $snapshot['g'],$snapshot['n'],$snapshot['c']]);

            if ($uid) $pdo->prepare("INSERT INTO audit_logs(user_id,action,table_name,record_id,description) VALUES(?,?,?,?,?)")
                ->execute([$uid,'RELEASE','payroll_periods',$periodId,
                    "Admin released \"{$period['period_name']}\""]);

            $pdo->commit();
            echo json_encode(['success'=>true,
                'message'=>"\"{$period['period_name']}\" released. Payslips can now be printed."]);
            break;
    }
} catch (PDOException $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    echo json_encode(['success'=>false,'message'=>'DB error: '.$e->getMessage()]);
}
