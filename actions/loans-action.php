<?php
/**
 * actions/loans-action.php
 * Handles all loan CRUD and lifecycle operations.
 * POST: action (add|approve|deny|pause|resume|cancel|archive|
 *                get_review|get_details|record_payment|adjust|complete|edit_returned)
 * Returns JSON.
 *
 * Role guards:
 *   add / record_payment / adjust / complete / pause / resume / cancel / archive → Admin only
 *   edit_returned                                                                 → Admin only
 *   approve / deny                                                                → Principal only
 *   return_for_correction                                                         → Admin OR Principal
 *   get_review / get_details                                                      → Admin OR Principal
 */
require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/notif-utils.php';
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

    // Fetch loan types for the edit form (only needed for RETURNED loans)
    $loanTypesArr = [];
    if ($loan['status'] === 'RETURNED') {
        try {
            $ltStmt = $pdo->query("SELECT loan_type_id, loan_name FROM loan_types WHERE is_active=1 ORDER BY loan_name ASC");
            $loanTypesArr = $ltStmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) { $loanTypesArr = []; }
    }

    echo json_encode([
        'success'      => true,
        'loan'         => $loan,
        'active_loans' => $activeLoansList,
        'schedule'     => $schedule,
        'payment_log'  => $paymentLog,
        'loan_types'   => $loanTypesArr,
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

        // Notify principals (they approve) and the employee (informational)
        try {
            $empNameForLoan = getEmployeeName($pdo, $empId);
            notifPrincipals($pdo,
                "New Loan Record for Review",
                "{$empNameForLoan} has a {$providerName} loan of ₱" . number_format($amount, 2) . " awaiting your approval.",
                'loan',
                BASE_URL . 'modules/loans/index.php',
                (int)$lid
            );
            $loanEmpUid = getEmployeeUserId($pdo, $empId);
            sendNotif($pdo, $loanEmpUid,
                "Loan Record Filed",
                "A {$providerName} loan for ₱" . number_format($amount, 2) . " has been submitted for Principal approval. Payroll deductions will not begin until approved.",
                'loan',
                BASE_URL . 'modules/employee/loans/index.php',
                (int)$lid
            );
        } catch (Exception $ignored) {}

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

        // Notify the employee
        try {
            $liStmt = $pdo->prepare("SELECT el.employee_id, el.provider_name, el.total_amount, el.monthly_deduction, lt.loan_name FROM employee_loans el JOIN loan_types lt ON el.loan_type_id=lt.loan_type_id WHERE el.loan_id=?");
            $liStmt->execute([$loanId]);
            $li = $liStmt->fetch();
            if ($li) {
                $loanEmpUid = getEmployeeUserId($pdo, (int)$li['employee_id']);
                sendNotif($pdo, $loanEmpUid,
                    "Loan Approved",
                    "Your {$li['loan_name']} ({$li['provider_name']}) loan of ₱" . number_format($li['total_amount'], 2) . " has been approved. Monthly deductions of ₱" . number_format($li['monthly_deduction'], 2) . " will begin on the next payroll.",
                    'loan',
                    BASE_URL . 'modules/employee/loans/index.php',
                    $loanId
                );
            }
        } catch (Exception $ignored) {}

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

        // Notify the employee
        try {
            $liStmt2 = $pdo->prepare("SELECT el.employee_id, el.provider_name, lt.loan_name FROM employee_loans el JOIN loan_types lt ON el.loan_type_id=lt.loan_type_id WHERE el.loan_id=?");
            $liStmt2->execute([$loanId]);
            $li2 = $liStmt2->fetch();
            if ($li2) {
                $loanEmpUid2 = getEmployeeUserId($pdo, (int)$li2['employee_id']);
                sendNotif($pdo, $loanEmpUid2,
                    "Loan Rejected",
                    "Your {$li2['loan_name']} ({$li2['provider_name']}) loan request has been rejected." . ($reason ? " Reason: {$reason}" : ''),
                    'loan',
                    BASE_URL . 'modules/employee/loans/index.php',
                    $loanId
                );
            }
        } catch (Exception $ignored) {}

        echo json_encode(['success'=>true,'message'=>'Loan record rejected.']);
    } catch (PDOException $e) {
        echo json_encode(['success'=>false,'message'=>'DB error: '.$e->getMessage()]);
    }
    exit;
}

// ── RETURN FOR CORRECTION — Admin OR Principal ────────────────────────────────
if ($action === 'return_for_correction') {
    requireAdminOrPrincipalAction();

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

        // Notify admins to correct the document
        try {
            $liStmt3 = $pdo->prepare("SELECT el.provider_name, lt.loan_name FROM employee_loans el JOIN loan_types lt ON el.loan_type_id=lt.loan_type_id WHERE el.loan_id=?");
            $liStmt3->execute([$loanId]);
            $li3 = $liStmt3->fetch();
            $loanDesc = $li3 ? "{$li3['loan_name']} ({$li3['provider_name']})" : "Loan #{$loanId}";
            notifAdmins($pdo,
                "Loan Returned for Correction",
                "{$loanDesc} has been returned for correction. Reason: {$reason}",
                'loan',
                BASE_URL . 'modules/loans/index.php',
                $loanId
            );
        } catch (Exception $ignored) {}

        echo json_encode(['success'=>true,'message'=>'Loan document returned for correction. Admin will be notified.']);
    } catch (PDOException $e) {
        echo json_encode(['success'=>false,'message'=>'DB error: '.$e->getMessage()]);
    }
    exit;
}

// ── EDIT & RESUBMIT RETURNED LOAN — Admin or the employee who filed it ────────
if ($action === 'edit_returned') {
    requireLogin();

    $isAdminOrPrincipal = isAdmin() || isPrincipalRole();
    $isEmp              = isEmployee();

    if (!$isAdminOrPrincipal && !$isEmp) {
        http_response_code(403);
        echo json_encode(['success'=>false,'message'=>'Access denied.']); exit;
    }

    $loanId       = (int)($_POST['loan_id']           ?? 0);
    $typeId       = (int)($_POST['loan_type_id']      ?? 0);
    $providerName = trim($_POST['provider_name']      ?? '');
    $ref          = trim($_POST['account_reference']  ?? '');
    $amount       = max(0, (float)($_POST['total_amount']       ?? 0));
    $monthly      = max(0, (float)($_POST['monthly_deduction']  ?? 0));
    $interest     = max(0, (float)($_POST['interest_rate']      ?? 0));
    $payableRaw   = trim($_POST['total_payable']      ?? '');
    $balanceRaw   = trim($_POST['current_balance']    ?? '');
    $startDt      = trim($_POST['start_date']         ?? '');
    $remarks      = trim($_POST['reason']             ?? '');

    if (!$loanId || !$typeId || !$providerName || !$amount || !$monthly || !$startDt) {
        echo json_encode(['success'=>false,'message'=>'Please fill in all required fields (loan type, provider, amount, amortization, start date).']);
        exit;
    }

    // Verify the loan exists, is RETURNED, and the caller is allowed to edit it
    $check = $pdo->prepare("SELECT loan_id, employee_id, filed_by FROM employee_loans WHERE loan_id=? AND status='RETURNED'");
    $check->execute([$loanId]);
    $loanRow = $check->fetch();
    if (!$loanRow) {
        echo json_encode(['success'=>false,'message'=>'Loan not found or is not in RETURNED status — cannot edit.']);
        exit;
    }

    // Employees may only edit loans THEY filed for THEMSELVES
    if ($isEmp && !$isAdminOrPrincipal) {
        $sessionEmpId = (int)($_SESSION['user']['employee_id'] ?? 0);
        if ((int)$loanRow['employee_id'] !== $sessionEmpId || $loanRow['filed_by'] !== 'EMPLOYEE') {
            http_response_code(403);
            echo json_encode(['success'=>false,'message'=>'You can only edit loan requests that you filed yourself.']); exit;
        }
    }

    // Total payable must be >= principal; balance capped at principal
    $payable = ($payableRaw !== '' && (float)$payableRaw > 0)
        ? max($amount, (float)$payableRaw)
        : $amount;
    $currentBalance = ($balanceRaw !== '' && (float)$balanceRaw > 0)
        ? min($amount, max(0, (float)$balanceRaw))
        : $amount;

    $term    = $monthly > 0 ? (int)ceil($payable / $monthly) : 0;
    $endDate = $term > 0 ? date('Y-m-d', strtotime($startDt . " +{$term} months")) : null;

    try {
        $updated = $pdo->prepare("
            UPDATE employee_loans
            SET loan_type_id      = ?,
                provider_name     = ?,
                account_reference = ?,
                total_amount      = ?,
                balance_amount    = ?,
                monthly_deduction = ?,
                interest_rate     = ?,
                total_payable     = ?,
                start_date        = ?,
                end_date          = ?,
                reason            = ?,
                status            = 'PENDING',
                return_reason     = NULL
            WHERE loan_id = ? AND status = 'RETURNED'
        ");
        $updated->execute([
            $typeId, $providerName, $ref ?: null, $amount, $currentBalance,
            $monthly, $interest, $payable, $startDt, $endDate, $remarks,
            $loanId
        ]);

        if ($updated->rowCount() === 0) {
            echo json_encode(['success'=>false,'message'=>'Update failed — loan may have already been processed.']);
            exit;
        }

        $resubmitActor = $isEmp && !$isAdminOrPrincipal ? 'Employee' : 'Admin';
        if ($uid) $pdo->prepare("INSERT INTO audit_logs(user_id,action,table_name,record_id,description) VALUES(?,?,?,?,?)")
            ->execute([$uid, 'RESUBMIT', 'employee_loans', $loanId,
                       "{$resubmitActor} edited and resubmitted returned loan #{$loanId} for Principal review"
                       .($ref ? " (Ref: {$ref})" : '')]);

        // Notify principals that the corrected loan is ready
        try {
            $liStmt4 = $pdo->prepare("SELECT el.employee_id, el.provider_name, el.total_amount, lt.loan_name FROM employee_loans el JOIN loan_types lt ON el.loan_type_id=lt.loan_type_id WHERE el.loan_id=?");
            $liStmt4->execute([$loanId]);
            $li4 = $liStmt4->fetch();
            if ($li4) {
                $empNameForResubmit = getEmployeeName($pdo, (int)$li4['employee_id']);
                notifPrincipals($pdo,
                    "Loan Resubmitted for Review",
                    "{$li4['loan_name']} ({$li4['provider_name']}) for {$empNameForResubmit} — ₱" . number_format($li4['total_amount'], 2) . " — has been corrected and resubmitted.",
                    'loan',
                    BASE_URL . 'modules/loans/index.php',
                    $loanId
                );
            }
        } catch (Exception $ignored) {}

        echo json_encode(['success'=>true,'message'=>'Loan record updated and resubmitted for Principal review.']);
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

// ── EMPLOYEE SELF-SERVICE REQUEST ────────────────────────────────────────────
if ($action === 'request') {
    requireEmployeeAjax();

    $empId   = (int)($_SESSION['user']['employee_id'] ?? 0);
    if (!$empId) { echo json_encode(['success'=>false,'message'=>'Employee profile not linked to your account.']); exit; }

    $typeId       = (int)($_POST['loan_type_id']     ?? 0);
    $providerName = trim($_POST['provider_name']     ?? '');
    $amount       = max(0, (float)($_POST['total_amount']      ?? 0));
    $monthly      = max(0, (float)($_POST['monthly_deduction'] ?? 0));
    $startDt      = trim($_POST['start_date'] ?? date('Y-m-d'));
    $remarks      = trim($_POST['reason']     ?? '');
    // Financial detail fields captured from employee's loan approval letter (all optional)
    $ref            = trim($_POST['account_reference'] ?? '');
    $interest       = max(0, (float)($_POST['interest_rate']  ?? 0));
    $userPayableRaw = trim($_POST['total_payable']     ?? '');
    $userBalanceRaw = trim($_POST['current_balance']   ?? '');

    if (!$typeId || !$amount || !$monthly || !$startDt) {
        echo json_encode(['success'=>false,'message'=>'Please fill in all required fields (loan type, amount, monthly amortization, start date).']);
        exit;
    }
    if (!$providerName) {
        echo json_encode(['success'=>false,'message'=>'Please enter the provider or lending institution name.']);
        exit;
    }

    // Prevent duplicate pending/active loan of same type for same employee
    $dupCheck = $pdo->prepare("
        SELECT loan_id FROM employee_loans
        WHERE employee_id = ? AND loan_type_id = ? AND status IN ('ACTIVE','PENDING','PAUSED','RETURNED')
        LIMIT 1
    ");
    $dupCheck->execute([$empId, $typeId]);
    if ($dupCheck->fetchColumn()) {
        echo json_encode(['success'=>false,'message'=>'You already have an active or pending loan of this type. Please wait for it to be resolved before filing a new request.']);
        exit;
    }

    // Duplicate external reference number (if provided)
    if ($ref !== '') {
        $refCheck = $pdo->prepare("
            SELECT loan_id FROM employee_loans
            WHERE account_reference = ? AND status NOT IN ('CANCELLED','DENIED','ARCHIVED')
            LIMIT 1
        ");
        $refCheck->execute([$ref]);
        if ($refCheck->fetchColumn()) {
            echo json_encode(['success'=>false,'message'=>'A loan with this reference number already exists in the system. Please contact HR Admin if this is an existing loan.']);
            exit;
        }
    }

    // Use employee-supplied total_payable (from their letter); must be >= principal
    $payable = ($userPayableRaw !== '' && (float)$userPayableRaw > 0)
        ? max($amount, (float)$userPayableRaw)
        : $amount;
    // Use employee-supplied current balance; cap at total_amount; default = total_amount (fresh loan)
    $currentBalance = ($userBalanceRaw !== '' && (float)$userBalanceRaw > 0)
        ? min($amount, max(0, (float)$userBalanceRaw))
        : $amount;

    $term    = $monthly > 0 ? (int)ceil($payable / $monthly) : 0;
    $endDate = $term > 0 ? date('Y-m-d', strtotime($startDt . " +{$term} months")) : null;

    try {
        // Try INSERT with filed_by column (migration 019); fall back without it
        try {
            $ins = $pdo->prepare("
                INSERT INTO employee_loans
                    (employee_id, loan_type_id, account_reference, provider_name, total_amount,
                     balance_amount, monthly_deduction, interest_rate, total_payable, start_date,
                     end_date, status, reason, filed_by)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,'PENDING',?,'EMPLOYEE')
            ");
            $ins->execute([$empId, $typeId, $ref ?: null, $providerName, $amount,
                           $currentBalance, $monthly, $interest, $payable, $startDt, $endDate, $remarks]);
        } catch (PDOException $e) {
            // Migration 019 not yet run — insert without filed_by
            $ins = $pdo->prepare("
                INSERT INTO employee_loans
                    (employee_id, loan_type_id, account_reference, provider_name, total_amount,
                     balance_amount, monthly_deduction, interest_rate, total_payable, start_date,
                     end_date, status, reason)
                VALUES (?,?,?,?,?,?,?,?,?,?,?,'PENDING',?)
            ");
            $ins->execute([$empId, $typeId, $ref ?: null, $providerName, $amount,
                           $currentBalance, $monthly, $interest, $payable, $startDt, $endDate, $remarks]);
        }
        $lid = (int)$pdo->lastInsertId();

        // If employee entered a lower current balance (partially-paid existing loan), log prior payments
        $priorPaid = round($amount - $currentBalance, 2);
        if ($priorPaid > 0.00) {
            try {
                $pdo->prepare("
                    INSERT INTO loan_payment_log (loan_id, payment_date, amount, payment_channel, notes, encoded_by)
                    VALUES (?, ?, ?, 'PRIOR_PAYMENTS', 'Balance at time of entry — payments made before system recording', ?)
                ")->execute([$lid, $startDt, $priorPaid, $uid]);
            } catch (PDOException $e) { /* loan_payment_log not yet set up */ }
        }

        $auditNote = "Employee self-service loan request #{$lid} for ₱" . number_format($amount,2) . " — pending Principal review";
        if ($ref) $auditNote .= " (Ref: {$ref})";
        if ($uid) $pdo->prepare("INSERT INTO audit_logs(user_id,action,table_name,record_id,description) VALUES(?,?,?,?,?)")
            ->execute([$uid,'CREATE','employee_loans',$lid,$auditNote]);

        echo json_encode(['success'=>true,'message'=>'Your loan request has been submitted for Principal review.','loan_id'=>$lid]);
    } catch (PDOException $e) {
        echo json_encode(['success'=>false,'message'=>'DB error: '.$e->getMessage()]);
    }
    exit;
}

// ── EMPLOYEE CANCEL OWN PENDING REQUEST ──────────────────────────────────────
if ($action === 'cancel_own') {
    requireEmployeeAjax();

    $empId  = (int)($_SESSION['user']['employee_id'] ?? 0);
    $loanId = (int)($_POST['loan_id'] ?? 0);
    if (!$empId || !$loanId) {
        echo json_encode(['success'=>false,'message'=>'Invalid request.']); exit;
    }

    // Only allow cancelling own PENDING loans filed by the employee themselves
    $row = $pdo->prepare("
        SELECT loan_id, filed_by FROM employee_loans
        WHERE loan_id = ? AND employee_id = ? AND status = 'PENDING'
        LIMIT 1
    ");
    $row->execute([$loanId, $empId]);
    $loan = $row->fetch();

    if (!$loan) {
        echo json_encode(['success'=>false,'message'=>'Loan request not found or cannot be cancelled.']); exit;
    }

    // Check filed_by if column exists; if column doesn't exist, allow cancel for any own pending
    $filedBy = $loan['filed_by'] ?? 'EMPLOYEE';
    if ($filedBy !== 'EMPLOYEE') {
        echo json_encode(['success'=>false,'message'=>'Only loan requests you filed yourself can be cancelled here. Contact HR Admin to cancel admin-filed loans.']); exit;
    }

    try {
        $pdo->prepare("UPDATE employee_loans SET status='CANCELLED' WHERE loan_id=?")
            ->execute([$loanId]);

        if ($uid) $pdo->prepare("INSERT INTO audit_logs(user_id,action,table_name,record_id,description) VALUES(?,?,?,?,?)")
            ->execute([$uid,'CANCEL','employee_loans',$loanId,
                       "Employee cancelled their own pending loan request #{$loanId}"]);

        echo json_encode(['success'=>true,'message'=>'Loan request cancelled.']);
    } catch (PDOException $e) {
        echo json_encode(['success'=>false,'message'=>'DB error: '.$e->getMessage()]);
    }
    exit;
}

// ── SET NEXT-DEDUCTION CONTROL (Admin) ───────────────────────────────────────
if ($action === 'set_deduction_control') {
    requireAdminAction();

    $loanId   = (int)($_POST['loan_id']   ?? 0);
    $skipNext = (int)($_POST['skip_next'] ?? 0); // 1 = skip, 0 = don't skip
    $override = trim($_POST['override_amount'] ?? '');
    if (!$loanId) { echo json_encode(['success'=>false,'message'=>'Invalid loan ID.']); exit; }

    // Validate override amount
    $overrideAmt = null;
    if ($override !== '') {
        $overrideAmt = max(0, (float)$override);
        if ($overrideAmt <= 0) $overrideAmt = null;
    }

    // Can only control ACTIVE loans
    $check = $pdo->prepare("SELECT loan_id, balance_amount FROM employee_loans WHERE loan_id=? AND status='ACTIVE'");
    $check->execute([$loanId]);
    if (!$check->fetch()) {
        echo json_encode(['success'=>false,'message'=>'Loan not found or not active.']); exit;
    }

    try {
        $pdo->prepare("
            UPDATE employee_loans
            SET skip_next_deduction=?, next_deduction_override=?
            WHERE loan_id=?
        ")->execute([$skipNext ? 1 : 0, $overrideAmt, $loanId]);

        if ($uid) {
            $note = $skipNext
                ? "Admin set skip-next-deduction for loan #{$loanId}"
                : ($overrideAmt !== null
                    ? "Admin set next deduction override to ₱" . number_format($overrideAmt,2) . " for loan #{$loanId}"
                    : "Admin cleared deduction controls for loan #{$loanId}");
            $pdo->prepare("INSERT INTO audit_logs(user_id,action,table_name,record_id,description) VALUES(?,?,?,?,?)")
                ->execute([$uid,'ADJUST','employee_loans',$loanId,$note]);
        }

        $msg = $skipNext ? 'Next payroll deduction will be skipped for this loan.'
             : ($overrideAmt !== null ? 'Next deduction override set to ₱' . number_format($overrideAmt,2) . '.'
             : 'Deduction controls cleared — normal deduction will apply.');
        echo json_encode(['success'=>true,'message'=>$msg]);
    } catch (PDOException $e) {
        echo json_encode(['success'=>false,'message'=>'DB error (migration 019 may not be applied): '.$e->getMessage()]);
    }
    exit;
}

echo json_encode(['success'=>false,'message'=>'Unknown action.']);
