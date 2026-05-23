<?php
/**
 * actions/loans-action.php
 * Handles all loan CRUD and lifecycle operations.
 * POST: action (add|approve|deny|pause|resume|cancel|archive|
 *                get_review|get_details|record_payment|adjust|complete)
 * Returns JSON.
 *
 * Role guards:
 *   add / record_payment / adjust / complete / pause / resume / cancel / archive → Admin only
 *   approve / deny                                                                → Admin OR Principal
 *   get_review / get_details                                                      → Admin OR Principal
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

    // Other active loans for the same employee
    $active = $pdo->prepare("
        SELECT el2.*, lt2.loan_name
        FROM employee_loans el2
        JOIN loan_types lt2 ON el2.loan_type_id=lt2.loan_type_id
        WHERE el2.employee_id=? AND el2.status IN ('ACTIVE','PAUSED') AND el2.loan_id!=?
    ");
    $active->execute([$loan['employee_id'], $loanId]);
    $activeLoansList = $active->fetchAll(PDO::FETCH_ASSOC);

    // Payment log (manual payments + payroll deductions)
    $payLog = $pdo->prepare("
        SELECT ppl.*, u2.username AS recorded_by_name
        FROM loan_payment_log ppl
        LEFT JOIN users u2 ON ppl.encoded_by = u2.user_id
        WHERE ppl.loan_id = ?
        ORDER BY ppl.payment_date DESC, ppl.created_at DESC
    ");
    $payLog->execute([$loanId]);
    $paymentLog = $payLog->fetchAll(PDO::FETCH_ASSOC);

    // Synthetic monthly payment schedule (for review/schedule tab)
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

    echo json_encode([
        'success'      => true,
        'loan'         => $loan,
        'active_loans' => $activeLoansList,
        'schedule'     => $schedule,
        'payment_log'  => $paymentLog,
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success'=>false,'message'=>'Invalid request method.']); exit;
}

// ── ADD ───────────────────────────────────────────────────────────────────────
if ($action === 'add') {
    requireAdminAction();

    $empId        = (int)($_POST['employee_id']      ?? 0);
    $typeId       = (int)($_POST['loan_type_id']     ?? 0);
    $providerName = trim($_POST['provider_name']     ?? '');
    $ref          = trim($_POST['account_reference'] ?? '');
    $amount       = max(0, (float)($_POST['total_amount']      ?? 0));
    $monthly      = max(0, (float)($_POST['monthly_deduction'] ?? 0));
    $interest     = max(0, (float)($_POST['interest_rate']     ?? 0));
    $payable      = max(0, (float)($_POST['total_payable']     ?? $amount));
    $startDt      = trim($_POST['start_date'] ?? date('Y-m-d'));
    $reason       = trim($_POST['reason']     ?? '');
    // All loans must start PENDING — only Principal can activate.
    $status = 'PENDING';

    // Required field validation
    if (!$empId || !$typeId || !$providerName || !$amount || !$monthly || !$startDt) {
        echo json_encode(['success'=>false,'message'=>'Please fill in all required fields (employee, loan type, provider, amount, amortization, start date).']);
        exit;
    }
    if (!$payable) $payable = $amount;

    // Duplicate active loan validation: same employee + same loan type
    $dupCheck = $pdo->prepare("
        SELECT loan_id FROM employee_loans
        WHERE employee_id = ? AND loan_type_id = ? AND status IN ('ACTIVE','PENDING','PAUSED','RETURNED')
        LIMIT 1
    ");
    $dupCheck->execute([$empId, $typeId]);
    if ($dupCheck->fetchColumn()) {
        echo json_encode(['success'=>false,'message'=>'This employee already has an active or pending loan of this type. Resolve the existing loan before adding a new one.']);
        exit;
    }

    // Duplicate reference number validation (if provided)
    if ($ref !== '') {
        $refCheck = $pdo->prepare("
            SELECT loan_id FROM employee_loans
            WHERE account_reference = ? AND status NOT IN ('CANCELLED','DENIED','ARCHIVED')
            LIMIT 1
        ");
        $refCheck->execute([$ref]);
        if ($refCheck->fetchColumn()) {
            echo json_encode(['success'=>false,'message'=>'A loan with this reference number already exists in the system.']);
            exit;
        }
    }

    $currentBalanceRaw = trim($_POST['current_balance'] ?? '');
    $currentBalance    = $currentBalanceRaw !== '' ? max(0, (float)$currentBalanceRaw) : $amount;
    if ($currentBalance > $amount) $currentBalance = $amount;

    $term    = $monthly > 0 ? (int)ceil($payable / $monthly) : 0;
    $endDate = $term > 0 ? date('Y-m-d', strtotime($startDt . " +{$term} months")) : null;

    $approvedBy = null;
    $approvedAt = null;

    try {
        $pdo->prepare("
            INSERT INTO employee_loans
                (employee_id, loan_type_id, account_reference, provider_name, total_amount,
                 balance_amount, monthly_deduction, interest_rate, total_payable, start_date,
                 end_date, status, reason, approved_by, approved_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
        ")->execute([$empId,$typeId,$ref,$providerName,$amount,$currentBalance,$monthly,
                     $interest,$payable,$startDt,$endDate,$status,$reason,$approvedBy,$approvedAt]);

        $lid = $pdo->lastInsertId();

        // If there were prior payments before entry, log the implied prior balance reduction
        $priorPaid = $amount - $currentBalance;
        if ($priorPaid > 0) {
            $pdo->prepare("
                INSERT INTO loan_payment_log (loan_id, payment_date, amount, payment_channel, notes, encoded_by)
                VALUES (?, ?, ?, 'PRIOR_PAYMENTS', 'Balance at time of entry — payments made before system recording', ?)
            ")->execute([$lid, $startDt, $priorPaid, $uid]);
        }

        $auditNote = "Added loan #{$lid} ({$providerName}) for employee #{$empId} — pending Principal review";
        if ($priorPaid > 0) $auditNote .= " (₱" . number_format($priorPaid, 2) . " prior payments)";
        if ($uid) $pdo->prepare("INSERT INTO audit_logs(user_id,action,table_name,record_id,description) VALUES(?,?,?,?,?)")
            ->execute([$uid,'CREATE','employee_loans',$lid,$auditNote]);

        $msg = 'Loan record submitted for Principal review. Payroll deductions will not begin until the Principal approves.';
        echo json_encode(['success'=>true,'message'=>$msg,'loan_id'=>$lid]);
    } catch (PDOException $e) {
        echo json_encode(['success'=>false,'message'=>'DB error: '.$e->getMessage()]);
    }
    exit;
}

// ── APPROVE (Verify) — Principal only ────────────────────────────────────────
if ($action === 'approve') {
    requirePrincipalAction();

    $loanId = (int)($_POST['loan_id'] ?? 0);
    $notes  = trim($_POST['notes'] ?? '');
    if (!$loanId) { echo json_encode(['success'=>false,'message'=>'Invalid loan ID.']); exit; }

    try {
        $stmt = $pdo->prepare("SELECT status FROM employee_loans WHERE loan_id=?");
        $stmt->execute([$loanId]);
        $row = $stmt->fetch();
        if (!$row || !in_array($row['status'], ['PENDING','RETURNED'])) {
            echo json_encode(['success'=>false,'message'=>'Loan is not pending review — cannot approve.']); exit;
        }

        $pdo->prepare("
            UPDATE employee_loans SET status='ACTIVE', approved_by=?, approved_at=NOW()
            WHERE loan_id=? AND status IN ('PENDING','RETURNED')
        ")->execute([$uid, $loanId]);

        if ($uid) $pdo->prepare("INSERT INTO audit_logs(user_id,action,table_name,record_id,description) VALUES(?,?,?,?,?)")
            ->execute([$uid,'APPROVE','employee_loans',$loanId,
                       "Principal approved loan #{$loanId} for payroll deduction".($notes?" — {$notes}":'')]);

        echo json_encode(['success'=>true,'message'=>'Loan approved. Payroll deductions will begin on the next run.']);
    } catch (PDOException $e) {
        echo json_encode(['success'=>false,'message'=>'DB error: '.$e->getMessage()]);
    }
    exit;
}

// ── DENY (Reject) — Principal only ───────────────────────────────────────────
if ($action === 'deny') {
    requirePrincipalAction();

    $loanId = (int)($_POST['loan_id'] ?? 0);
    $reason = trim($_POST['denied_reason'] ?? '');
    if (!$loanId) { echo json_encode(['success'=>false,'message'=>'Invalid loan ID.']); exit; }

    try {
        $pdo->prepare("
            UPDATE employee_loans SET status='DENIED', denied_reason=?, approved_by=?, approved_at=NOW()
            WHERE loan_id=? AND status IN ('PENDING','RETURNED')
        ")->execute([$reason, $uid, $loanId]);

        if ($uid) $pdo->prepare("INSERT INTO audit_logs(user_id,action,table_name,record_id,description) VALUES(?,?,?,?,?)")
            ->execute([$uid,'DENY','employee_loans',$loanId,
                       "Principal rejected loan #{$loanId}".($reason?" — {$reason}":'')]);

        echo json_encode(['success'=>true,'message'=>'Loan record rejected.']);
    } catch (PDOException $e) {
        echo json_encode(['success'=>false,'message'=>'DB error: '.$e->getMessage()]);
    }
    exit;
}

// ── RETURN FOR CORRECTION — Principal only ────────────────────────────────────
if ($action === 'return_for_correction') {
    requirePrincipalAction();

    $loanId = (int)($_POST['loan_id']    ?? 0);
    $reason = trim($_POST['return_reason'] ?? '');
    if (!$loanId) { echo json_encode(['success'=>false,'message'=>'Invalid loan ID.']); exit; }
    if (!$reason) { echo json_encode(['success'=>false,'message'=>'A reason for return is required.']); exit; }

    try {
        $updated = $pdo->prepare("
            UPDATE employee_loans SET status='RETURNED', return_reason=?
            WHERE loan_id=? AND status IN ('PENDING','RETURNED')
        ");
        $updated->execute([$reason, $loanId]);

        if ($updated->rowCount() === 0) {
            echo json_encode(['success'=>false,'message'=>'Loan cannot be returned in its current status.']); exit;
        }

        if ($uid) $pdo->prepare("INSERT INTO audit_logs(user_id,action,table_name,record_id,description) VALUES(?,?,?,?,?)")
            ->execute([$uid,'RETURN','employee_loans',$loanId,
                       "Principal returned loan #{$loanId} for correction — {$reason}"]);

        echo json_encode(['success'=>true,'message'=>'Loan document returned for correction. Admin will be notified.']);
    } catch (PDOException $e) {
        echo json_encode(['success'=>false,'message'=>'DB error: '.$e->getMessage()]);
    }
    exit;
}

// ── PAUSE ─────────────────────────────────────────────────────────────────────
if ($action === 'pause') {
    requireAdminAction();

    $loanId = (int)($_POST['loan_id'] ?? 0);
    $notes  = trim($_POST['notes'] ?? '');
    if (!$loanId) { echo json_encode(['success'=>false,'message'=>'Invalid loan ID.']); exit; }

    try {
        $updated = $pdo->prepare("
            UPDATE employee_loans SET status='PAUSED', paused_at=NOW()
            WHERE loan_id=? AND status='ACTIVE'
        ");
        $updated->execute([$loanId]);

        if ($updated->rowCount() === 0) {
            echo json_encode(['success'=>false,'message'=>'Loan is not active — cannot pause.']); exit;
        }

        if ($uid) $pdo->prepare("INSERT INTO audit_logs(user_id,action,table_name,record_id,description) VALUES(?,?,?,?,?)")
            ->execute([$uid,'PAUSE','employee_loans',$loanId,
                       "Admin paused loan #{$loanId}".($notes?" — {$notes}":'')]);

        echo json_encode(['success'=>true,'message'=>'Loan deduction paused. No deductions will occur until resumed.']);
    } catch (PDOException $e) {
        echo json_encode(['success'=>false,'message'=>'DB error: '.$e->getMessage()]);
    }
    exit;
}

// ── RESUME ────────────────────────────────────────────────────────────────────
if ($action === 'resume') {
    requireAdminAction();

    $loanId = (int)($_POST['loan_id'] ?? 0);
    $notes  = trim($_POST['notes'] ?? '');
    if (!$loanId) { echo json_encode(['success'=>false,'message'=>'Invalid loan ID.']); exit; }

    try {
        $updated = $pdo->prepare("
            UPDATE employee_loans SET status='ACTIVE', paused_at=NULL
            WHERE loan_id=? AND status='PAUSED'
        ");
        $updated->execute([$loanId]);

        if ($updated->rowCount() === 0) {
            echo json_encode(['success'=>false,'message'=>'Loan is not paused — cannot resume.']); exit;
        }

        if ($uid) $pdo->prepare("INSERT INTO audit_logs(user_id,action,table_name,record_id,description) VALUES(?,?,?,?,?)")
            ->execute([$uid,'RESUME','employee_loans',$loanId,
                       "Admin resumed loan #{$loanId}".($notes?" — {$notes}":'')]);

        echo json_encode(['success'=>true,'message'=>'Loan deduction resumed. Will apply on next payroll run.']);
    } catch (PDOException $e) {
        echo json_encode(['success'=>false,'message'=>'DB error: '.$e->getMessage()]);
    }
    exit;
}

// ── CANCEL ────────────────────────────────────────────────────────────────────
if ($action === 'cancel') {
    requireAdminAction();

    $loanId = (int)($_POST['loan_id']            ?? 0);
    $reason = trim($_POST['cancelled_reason']    ?? '');
    if (!$loanId) { echo json_encode(['success'=>false,'message'=>'Invalid loan ID.']); exit; }

    try {
        $updated = $pdo->prepare("
            UPDATE employee_loans
            SET status='CANCELLED', cancelled_reason=?
            WHERE loan_id=? AND status IN ('ACTIVE','PENDING','PAUSED','RETURNED')
        ");
        $updated->execute([$reason, $loanId]);

        if ($updated->rowCount() === 0) {
            echo json_encode(['success'=>false,'message'=>'Loan cannot be cancelled in its current status.']); exit;
        }

        if ($uid) $pdo->prepare("INSERT INTO audit_logs(user_id,action,table_name,record_id,description) VALUES(?,?,?,?,?)")
            ->execute([$uid,'CANCEL','employee_loans',$loanId,
                       "Admin cancelled loan #{$loanId}".($reason?" — {$reason}":'')]);

        echo json_encode(['success'=>true,'message'=>'Loan record cancelled.']);
    } catch (PDOException $e) {
        echo json_encode(['success'=>false,'message'=>'DB error: '.$e->getMessage()]);
    }
    exit;
}

// ── ARCHIVE ───────────────────────────────────────────────────────────────────
if ($action === 'archive') {
    requireAdminAction();

    $loanId = (int)($_POST['loan_id'] ?? 0);
    if (!$loanId) { echo json_encode(['success'=>false,'message'=>'Invalid loan ID.']); exit; }

    try {
        $updated = $pdo->prepare("
            UPDATE employee_loans
            SET status='ARCHIVED', archived_at=NOW(), archived_by=?
            WHERE loan_id=? AND status IN ('COMPLETED','CANCELLED','DENIED','RETURNED')
        ");
        $updated->execute([$uid, $loanId]);

        if ($updated->rowCount() === 0) {
            echo json_encode(['success'=>false,'message'=>'Only completed or cancelled loans can be archived.']); exit;
        }

        if ($uid) $pdo->prepare("INSERT INTO audit_logs(user_id,action,table_name,record_id,description) VALUES(?,?,?,?,?)")
            ->execute([$uid,'ARCHIVE','employee_loans',$loanId,"Admin archived loan #{$loanId}"]);

        echo json_encode(['success'=>true,'message'=>'Loan record archived.']);
    } catch (PDOException $e) {
        echo json_encode(['success'=>false,'message'=>'DB error: '.$e->getMessage()]);
    }
    exit;
}

// ── RECORD MANUAL PAYMENT ─────────────────────────────────────────────────────
if ($action === 'record_payment') {
    requireAdminAction();

    $loanId  = (int)($_POST['loan_id']         ?? 0);
    $amount  = max(0, (float)($_POST['amount'] ?? 0));
    $channel = trim($_POST['payment_channel']  ?? 'MANUAL');
    $receipt = trim($_POST['receipt_number']   ?? '');
    $notes   = trim($_POST['notes']            ?? '');
    $payDate = trim($_POST['payment_date']     ?? date('Y-m-d'));

    if (!$loanId || !$amount) {
        echo json_encode(['success'=>false,'message'=>'Loan ID and amount are required.']); exit;
    }

    $loan = $pdo->prepare("SELECT balance_amount, status FROM employee_loans WHERE loan_id=? AND status IN ('ACTIVE','PAUSED')");
    $loan->execute([$loanId]);
    $row = $loan->fetch();
    if (!$row) {
        echo json_encode(['success'=>false,'message'=>'Loan not found or not active.']); exit;
    }

    $currentBalance = (float)$row['balance_amount'];
    if ($amount > $currentBalance) {
        echo json_encode(['success'=>false,'message'=>'Payment amount (₱'.number_format($amount,2).') exceeds the remaining balance (₱'.number_format($currentBalance,2).'). Reduce the amount or use "Close Loan (Paid)" to write off the remainder.']);
        exit;
    }

    $newBalance = max(0.0, $currentBalance - $amount);
    $newStatus  = $newBalance <= 0 ? 'COMPLETED' : $row['status'];

    try {
        $pdo->prepare("UPDATE employee_loans SET balance_amount=?, status=? WHERE loan_id=?")
            ->execute([$newBalance, $newStatus, $loanId]);

        $pdo->prepare("
            INSERT INTO loan_payment_log
                (loan_id, payment_date, amount, payment_channel, receipt_number, notes, encoded_by)
            VALUES (?,?,?,?,?,?,?)
        ")->execute([$loanId, $payDate, $amount, $channel, $receipt ?: null, $notes ?: null, $uid]);

        if ($uid) $pdo->prepare("INSERT INTO audit_logs(user_id,action,table_name,record_id,description) VALUES(?,?,?,?,?)")
            ->execute([$uid,'PAYMENT','employee_loans',$loanId,
                       "Manual payment of ₱{$amount} via {$channel} for loan #{$loanId}"
                       .($receipt?" (Receipt: {$receipt})":'')]);

        $msg = $newStatus === 'COMPLETED'
            ? 'Payment recorded. Loan is now fully paid and closed!'
            : 'Payment recorded. Remaining balance: ₱'.number_format($newBalance,2).'.';
        echo json_encode(['success'=>true,'message'=>$msg,'new_balance'=>$newBalance,'status'=>$newStatus]);
    } catch (PDOException $e) {
        echo json_encode(['success'=>false,'message'=>'DB error: '.$e->getMessage()]);
    }
    exit;
}

// ── CLOSE LOAN (PAID) — force-close with ₱0 balance ─────────────────────────
if ($action === 'complete') {
    requireAdminAction();

    $loanId = (int)($_POST['loan_id'] ?? 0);
    $notes  = trim($_POST['notes'] ?? '');
    if (!$loanId) { echo json_encode(['success'=>false,'message'=>'Invalid ID.']); exit; }

    try {
        $row = $pdo->prepare("SELECT balance_amount FROM employee_loans WHERE loan_id=? AND status IN ('ACTIVE','PAUSED')");
        $row->execute([$loanId]);
        $loan = $row->fetch();
        if (!$loan) {
            echo json_encode(['success'=>false,'message'=>'Loan not found or not active.']); exit;
        }

        $remaining = (float)$loan['balance_amount'];

        $pdo->prepare("UPDATE employee_loans SET balance_amount=0, status='COMPLETED' WHERE loan_id=?")
            ->execute([$loanId]);

        // Log the write-off if there was a remaining balance
        if ($remaining > 0) {
            $pdo->prepare("
                INSERT INTO loan_payment_log (loan_id, payment_date, amount, payment_channel, notes, encoded_by)
                VALUES (?, ?, ?, 'WRITE_OFF', ?, ?)
            ")->execute([$loanId, date('Y-m-d'), $remaining,
                         'Admin closed loan — remaining balance written off'.($notes?" | {$notes}":''),
                         $uid]);
        }

        if ($uid) $pdo->prepare("INSERT INTO audit_logs(user_id,action,table_name,record_id,description) VALUES(?,?,?,?,?)")
            ->execute([$uid,'COMPLETE','employee_loans',$loanId,
                       "Admin closed loan #{$loanId} as paid".($notes?" — {$notes}":'')]);

        echo json_encode(['success'=>true,'message'=>'Loan closed and marked as fully paid.']);
    } catch (PDOException $e) {
        echo json_encode(['success'=>false,'message'=>'DB error: '.$e->getMessage()]);
    }
    exit;
}

// ── ADJUST TERMS ─────────────────────────────────────────────────────────────
if ($action === 'adjust') {
    requireAdminAction();

    $loanId  = (int)($_POST['loan_id']            ?? 0);
    $monthly = max(0, (float)($_POST['monthly_deduction'] ?? 0));
    $endDate = trim($_POST['end_date'] ?? '');
    if (!$loanId || !$monthly) {
        echo json_encode(['success'=>false,'message'=>'Loan ID and monthly amortization are required.']); exit;
    }

    try {
        $pdo->prepare("
            UPDATE employee_loans SET monthly_deduction=?, end_date=?
            WHERE loan_id=? AND status IN ('ACTIVE','PAUSED')
        ")->execute([$monthly, $endDate ?: null, $loanId]);

        if ($uid) $pdo->prepare("INSERT INTO audit_logs(user_id,action,table_name,record_id,description) VALUES(?,?,?,?,?)")
            ->execute([$uid,'ADJUST','employee_loans',$loanId,
                       "Adjusted terms for loan #{$loanId}: monthly amortization=₱{$monthly}"]);

        echo json_encode(['success'=>true,'message'=>'Loan terms updated successfully.']);
    } catch (PDOException $e) {
        echo json_encode(['success'=>false,'message'=>'DB error: '.$e->getMessage()]);
    }
    exit;
}

echo json_encode(['success'=>false,'message'=>'Unknown action.']);
