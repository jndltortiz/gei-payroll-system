<?php
/**
 * actions/loans-action.php
 * Handles all loan CRUD operations.
 * POST: action (add|approve|deny|get_review|get_details|record_payment|adjust|complete)
 * Returns JSON.
 *
 * Role guards:
 *   add / record_payment / adjust / complete  → Admin only
 *   approve / deny                            → Admin OR Principal
 *   get_review / get_details                  → Admin OR Principal (read)
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
header('Content-Type: application/json');
requireLogin();

$action = $_POST['action'] ?? ($_GET['action'] ?? '');
$uid    = $_SESSION['user']['user_id'] ?? null;

// ── GET helpers ───────────────────────────────────────────────────────────────
if ($action === 'get_review' || $action === 'get_details') {
    requireAdminOrPrincipalAction();

    $loanId = (int)($_GET['loan_id'] ?? $_POST['loan_id'] ?? 0);
    if (!$loanId) { echo json_encode(['success'=>false,'message'=>'Invalid ID.']); exit; }

    $stmt = $pdo->prepare("
        SELECT el.*, lt.loan_name,
               CONCAT(e.first_name,' ',e.last_name) AS employee_name,
               p.position_name, d.department_name,
               ec.monthly_salary, e.hire_date, e.employment_type,
               u.username AS approved_by_name
        FROM employee_loans el
        JOIN employees e ON el.employee_id=e.employee_id
        JOIN loan_types lt ON el.loan_type_id=lt.loan_type_id
        LEFT JOIN positions p ON e.position_id=p.position_id
        LEFT JOIN departments d ON e.department_id=d.department_id
        LEFT JOIN employee_compensations ec ON e.employee_id=ec.employee_id AND ec.is_active=1
        LEFT JOIN users u ON el.approved_by=u.user_id
        WHERE el.loan_id=?
    ");
    $stmt->execute([$loanId]);
    $loan = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$loan) { echo json_encode(['success'=>false,'message'=>'Loan not found.']); exit; }

    $active = $pdo->prepare("
        SELECT el2.*, lt2.loan_name
        FROM employee_loans el2
        JOIN loan_types lt2 ON el2.loan_type_id=lt2.loan_type_id
        WHERE el2.employee_id=? AND el2.status='ACTIVE' AND el2.loan_id!=?
    ");
    $active->execute([$loan['employee_id'], $loanId]);
    $activeLoansList = $active->fetchAll(PDO::FETCH_ASSOC);

    $schedule = [];
    $start    = new DateTime($loan['start_date'] ?: date('Y-m-d'));
    $monthly  = (float)$loan['monthly_deduction'];
    $total    = (float)($loan['total_payable'] ?: $loan['total_amount']);
    $terms    = $monthly > 0 ? (int)ceil($total / $monthly) : 0;
    $paid     = $loan['total_amount'] > 0
        ? (float)($loan['total_amount'] - $loan['balance_amount']) : 0;
    $paysMade = $monthly > 0 ? (int)floor($paid / $monthly) : 0;
    $today    = new DateTime();

    for ($i = 1; $i <= min($terms, 60); $i++) {
        $pd = clone $start;
        $pd->modify('+' . ($i-1) . ' months');
        if ($i <= $paysMade) $st = 'paid';
        elseif ($pd <= $today) $st = 'due';
        else $st = 'upcoming';
        $amt = ($i == $terms) ? max(0, $total - $monthly * ($terms - 1)) : $monthly;
        $schedule[] = ['month'=>$i, 'date'=>$pd->format('M d, Y'), 'amount'=>$amt, 'status'=>$st];
    }

    echo json_encode(['success'=>true, 'loan'=>$loan, 'active_loans'=>$activeLoansList, 'schedule'=>$schedule]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success'=>false,'message'=>'Invalid request method.']); exit;
}

// ── ADD ───────────────────────────────────────────────────────────────────────
if ($action === 'add') {
    requireAdminAction(); // Admin only

    $empId    = (int)($_POST['employee_id']  ?? 0);
    $typeId   = (int)($_POST['loan_type_id'] ?? 0);
    $amount   = max(0, (float)($_POST['total_amount']      ?? 0));
    $monthly  = max(0, (float)($_POST['monthly_deduction'] ?? 0));
    $interest = max(0, (float)($_POST['interest_rate']     ?? 0));
    $payable  = max(0, (float)($_POST['total_payable']     ?? $amount));
    $startDt  = trim($_POST['start_date']    ?? date('Y-m-d'));
    $ref      = trim($_POST['account_reference'] ?? '');
    $reason   = trim($_POST['reason']        ?? '');
    $status   = ($_POST['status'] ?? 'ACTIVE') === 'PENDING' ? 'PENDING' : 'ACTIVE';
    $approvedBy = $status === 'ACTIVE' ? $uid : null;
    $approvedAt = $status === 'ACTIVE' ? date('Y-m-d H:i:s') : null;

    $currentBalanceRaw = trim($_POST['current_balance'] ?? '');
    $currentBalance    = $currentBalanceRaw !== '' ? max(0, (float)$currentBalanceRaw) : $amount;
    if ($currentBalance > $amount) $currentBalance = $amount;

    if (!$empId || !$typeId || !$amount || !$monthly || !$startDt) {
        echo json_encode(['success'=>false,'message'=>'Please fill in all required fields.']); exit;
    }
    if (!$payable) $payable = $amount;

    $term    = $monthly > 0 ? (int)ceil($payable / $monthly) : 0;
    $endDate = $term > 0 ? date('Y-m-d', strtotime($startDt . " +{$term} months")) : null;

    try {
        $pdo->prepare("
            INSERT INTO employee_loans
                (employee_id, loan_type_id, account_reference, total_amount, balance_amount,
                 monthly_deduction, interest_rate, total_payable, start_date, end_date,
                 status, reason, approved_by, approved_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ")->execute([$empId,$typeId,$ref,$amount,$currentBalance,$monthly,$interest,$payable,$startDt,$endDate,$status,$reason,$approvedBy,$approvedAt]);

        $lid = $pdo->lastInsertId();
        $priorPaid = $amount - $currentBalance;
        $auditNote = "Added loan #{$lid} for employee #{$empId} as {$status}";
        if ($priorPaid > 0) $auditNote .= " (₱" . number_format($priorPaid, 2) . " already paid before entry)";
        if ($uid) $pdo->prepare("INSERT INTO audit_logs(user_id,action,table_name,record_id,description) VALUES(?,?,?,?,?)")
            ->execute([$uid,'CREATE','employee_loans',$lid,$auditNote]);

        $msg = $status === 'ACTIVE'
            ? 'Loan added and is now active. Deduction will apply from next payroll.'
            : 'Loan submitted and awaiting principal approval.';
        echo json_encode(['success'=>true,'message'=>$msg,'loan_id'=>$lid]);
    } catch (PDOException $e) {
        echo json_encode(['success'=>false,'message'=>'DB error: '.$e->getMessage()]);
    }
    exit;
}

// ── APPROVE ───────────────────────────────────────────────────────────────────
// Admin OR Principal can approve
if ($action === 'approve') {
    requireAdminOrPrincipalAction();

    $loanId = (int)($_POST['loan_id'] ?? 0);
    $notes  = trim($_POST['notes'] ?? '');
    if (!$loanId) { echo json_encode(['success'=>false,'message'=>'Invalid loan ID.']); exit; }

    try {
        $stmt = $pdo->prepare("SELECT status FROM employee_loans WHERE loan_id=?");
        $stmt->execute([$loanId]);
        $row = $stmt->fetch();
        if (!$row || $row['status'] !== 'PENDING') {
            echo json_encode(['success'=>false,'message'=>'Loan is no longer pending.']); exit;
        }

        $pdo->prepare("
            UPDATE employee_loans SET status='ACTIVE', approved_by=?, approved_at=NOW()
            WHERE loan_id=? AND status='PENDING'
        ")->execute([$uid, $loanId]);

        $roleName = isPrincipalRole() ? 'Principal' : 'Admin';
        if ($uid) $pdo->prepare("INSERT INTO audit_logs(user_id,action,table_name,record_id,description) VALUES(?,?,?,?,?)")
            ->execute([$uid,'APPROVE','employee_loans',$loanId,"{$roleName} approved loan #{$loanId}".($notes?" — {$notes}":'')]);

        echo json_encode(['success'=>true,'message'=>'Loan approved and is now active. Deductions will begin on the next payroll.']);
    } catch (PDOException $e) {
        echo json_encode(['success'=>false,'message'=>'DB error: '.$e->getMessage()]);
    }
    exit;
}

// ── DENY ─────────────────────────────────────────────────────────────────────
// Admin OR Principal can deny
if ($action === 'deny') {
    requireAdminOrPrincipalAction();

    $loanId = (int)($_POST['loan_id'] ?? 0);
    $reason = trim($_POST['denied_reason'] ?? '');
    if (!$loanId) { echo json_encode(['success'=>false,'message'=>'Invalid loan ID.']); exit; }

    try {
        $pdo->prepare("
            UPDATE employee_loans SET status='DENIED', denied_reason=?, approved_by=?, approved_at=NOW()
            WHERE loan_id=? AND status='PENDING'
        ")->execute([$reason, $uid, $loanId]);

        $roleName = isPrincipalRole() ? 'Principal' : 'Admin';
        if ($uid) $pdo->prepare("INSERT INTO audit_logs(user_id,action,table_name,record_id,description) VALUES(?,?,?,?,?)")
            ->execute([$uid,'DENY','employee_loans',$loanId,"{$roleName} denied loan #{$loanId}".($reason?" — {$reason}":'')]);

        echo json_encode(['success'=>true,'message'=>'Loan application denied.']);
    } catch (PDOException $e) {
        echo json_encode(['success'=>false,'message'=>'DB error: '.$e->getMessage()]);
    }
    exit;
}

// ── RECORD PAYMENT ────────────────────────────────────────────────────────────
if ($action === 'record_payment') {
    requireAdminAction(); // Admin only

    $loanId = (int)($_POST['loan_id'] ?? 0);
    $amount = max(0, (float)($_POST['amount'] ?? 0));
    if (!$loanId || !$amount) { echo json_encode(['success'=>false,'message'=>'Invalid data.']); exit; }

    $loan = $pdo->prepare("SELECT balance_amount FROM employee_loans WHERE loan_id=? AND status='ACTIVE'");
    $loan->execute([$loanId]);
    $row = $loan->fetch();
    if (!$row) { echo json_encode(['success'=>false,'message'=>'Loan not found or not active.']); exit; }

    $newBalance = max(0, (float)$row['balance_amount'] - $amount);
    $newStatus  = $newBalance <= 0 ? 'COMPLETED' : 'ACTIVE';

    $pdo->prepare("UPDATE employee_loans SET balance_amount=?, status=? WHERE loan_id=?")
        ->execute([$newBalance, $newStatus, $loanId]);

    if ($uid) $pdo->prepare("INSERT INTO audit_logs(user_id,action,table_name,record_id,description) VALUES(?,?,?,?,?)")
        ->execute([$uid,'PAYMENT','employee_loans',$loanId,"Recorded payment of ₱{$amount} for loan #{$loanId}"]);

    $msg = $newStatus === 'COMPLETED' ? 'Payment recorded. Loan is now fully paid!' : 'Payment recorded. Balance updated.';
    echo json_encode(['success'=>true,'message'=>$msg,'new_balance'=>$newBalance,'status'=>$newStatus]);
    exit;
}

// ── MARK FULLY PAID ───────────────────────────────────────────────────────────
if ($action === 'complete') {
    requireAdminAction(); // Admin only

    $loanId = (int)($_POST['loan_id'] ?? 0);
    if (!$loanId) { echo json_encode(['success'=>false,'message'=>'Invalid ID.']); exit; }

    $pdo->prepare("UPDATE employee_loans SET balance_amount=0, status='COMPLETED' WHERE loan_id=? AND status='ACTIVE'")
        ->execute([$loanId]);

    if ($uid) $pdo->prepare("INSERT INTO audit_logs(user_id,action,table_name,record_id,description) VALUES(?,?,?,?,?)")
        ->execute([$uid,'COMPLETE','employee_loans',$loanId,"Marked loan #{$loanId} as fully paid"]);

    echo json_encode(['success'=>true,'message'=>'Loan marked as fully paid.']);
    exit;
}

// ── ADJUST TERMS ─────────────────────────────────────────────────────────────
if ($action === 'adjust') {
    requireAdminAction(); // Admin only

    $loanId  = (int)($_POST['loan_id']          ?? 0);
    $monthly = max(0, (float)($_POST['monthly_deduction'] ?? 0));
    $endDate = trim($_POST['end_date'] ?? '');
    if (!$loanId || !$monthly) { echo json_encode(['success'=>false,'message'=>'Invalid data.']); exit; }

    $pdo->prepare("UPDATE employee_loans SET monthly_deduction=?, end_date=? WHERE loan_id=? AND status='ACTIVE'")
        ->execute([$monthly, $endDate ?: null, $loanId]);

    if ($uid) $pdo->prepare("INSERT INTO audit_logs(user_id,action,table_name,record_id,description) VALUES(?,?,?,?,?)")
        ->execute([$uid,'ADJUST','employee_loans',$loanId,"Adjusted terms for loan #{$loanId}: monthly=₱{$monthly}"]);

    echo json_encode(['success'=>true,'message'=>'Loan terms updated.']);
    exit;
}

echo json_encode(['success'=>false,'message'=>'Unknown action.']);