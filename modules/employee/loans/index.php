<?php
/**
 * modules/employee/loans/index.php
 * Employee Portal — My Loans
 */
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../includes/auth.php';
requireEmployeeAccess();

$empId = (int)($_SESSION['user']['employee_id'] ?? 0);

// Active and paused loans
$stmt = $pdo->prepare("
    SELECT el.loan_id, lt.loan_name, el.provider_name, el.account_reference,
           el.total_amount, el.balance_amount,
           el.monthly_deduction, el.start_date, el.end_date, el.status,
           el.approved_at, el.created_at
    FROM employee_loans el
    JOIN loan_types lt ON el.loan_type_id = lt.loan_type_id
    WHERE el.employee_id = ? AND el.status IN ('ACTIVE','PAUSED')
    ORDER BY el.start_date DESC
");
$stmt->execute([$empId]);
$activeLoans = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Loans awaiting Principal review (PENDING or RETURNED for correction)
$stmt = $pdo->prepare("
    SELECT el.loan_id, lt.loan_name, el.provider_name, el.total_amount, el.status,
           el.created_at, el.updated_at, el.return_reason
    FROM employee_loans el
    JOIN loan_types lt ON el.loan_type_id = lt.loan_type_id
    WHERE el.employee_id = ? AND el.status IN ('PENDING','RETURNED')
    ORDER BY el.created_at DESC
");
$stmt->execute([$empId]);
$pendingLoans = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Look up who returned each RETURNED loan — for display only, uses audit_logs
$returnedByMap = [];
$returnedSubset = array_filter($pendingLoans, fn($l) => $l['status'] === 'RETURNED');
if (!empty($returnedSubset)) {
    $retIds = array_map('intval', array_column(array_values($returnedSubset), 'loan_id'));
    $rph    = implode(',', array_fill(0, count($retIds), '?'));
    try {
        $rbStmt = $pdo->prepare("
            SELECT al.record_id AS loan_id, u.username AS returned_by
            FROM audit_logs al
            JOIN users u ON al.user_id = u.user_id
            WHERE al.table_name = 'employee_loans'
              AND al.action     = 'RETURN'
              AND al.record_id IN ($rph)
            ORDER BY al.created_at DESC
        ");
        $rbStmt->execute($retIds);
        foreach ($rbStmt->fetchAll(PDO::FETCH_ASSOC) as $rb) {
            // Keep only the most-recent entry per loan
            if (!isset($returnedByMap[(int)$rb['loan_id']])) {
                $returnedByMap[(int)$rb['loan_id']] = $rb['returned_by'];
            }
        }
    } catch (PDOException $e) { /* audit_logs unavailable — returner name skipped */ }
}

// Enriched pending loans: check if filed_by = EMPLOYEE (for cancel button)
// Safe try/catch for migration 019
$pendingFiledBy = [];
try {
    $fbStmt = $pdo->prepare("SELECT loan_id, filed_by FROM employee_loans WHERE employee_id = ? AND status IN ('PENDING','RETURNED')");
    $fbStmt->execute([$empId]);
    foreach ($fbStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $pendingFiledBy[$row['loan_id']] = $row['filed_by'];
    }
} catch (PDOException $e) { /* migration 019 not yet run */ }

// Loan history (completed, cancelled, denied, archived)
$stmt = $pdo->prepare("
    SELECT el.loan_id, lt.loan_name, el.provider_name, el.total_amount, el.balance_amount,
           el.status, el.start_date, el.end_date, el.created_at
    FROM employee_loans el
    JOIN loan_types lt ON el.loan_type_id = lt.loan_type_id
    WHERE el.employee_id = ? AND el.status NOT IN ('ACTIVE','PENDING','PAUSED')
    ORDER BY el.created_at DESC
    LIMIT 20
");
$stmt->execute([$empId]);
$historyLoans = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Deduction payment history from loan_payment_log for all employee loans
// Shows both PAYROLL auto-deductions and MANUAL payments
$allLoanIds = array_unique(array_merge(
    array_column($activeLoans,  'loan_id'),
    array_column($pendingLoans, 'loan_id'),
    array_column($historyLoans, 'loan_id')
));
$deductionHistory = [];
if (!empty($allLoanIds)) {
    $ph   = implode(',', array_fill(0, count($allLoanIds), '?'));
    $dhSt = $pdo->prepare("
        SELECT ppl.loan_id, ppl.payment_date, ppl.amount,
               ppl.payment_channel, ppl.notes, ppl.created_at
        FROM loan_payment_log ppl
        WHERE ppl.loan_id IN ($ph)
        ORDER BY ppl.payment_date DESC, ppl.created_at DESC
        LIMIT 200
    ");
    $dhSt->execute($allLoanIds);
    foreach ($dhSt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $deductionHistory[$row['loan_id']][] = $row;
    }
}

// Available loan types for request modal
$loanTypes = $pdo->query("SELECT * FROM loan_types WHERE is_active=1 ORDER BY loan_name")->fetchAll(PDO::FETCH_ASSOC);

// Totals (only active, not paused)
$activeOnly        = array_filter($activeLoans, fn($l) => $l['status'] === 'ACTIVE');
$totalOutstanding  = array_sum(array_column($activeLoans, 'balance_amount'));
$totalMonthlyAmt   = array_sum(array_column($activeOnly, 'monthly_deduction'));
$autoDeductionTotal = round($totalMonthlyAmt / 2, 2);

$pageTitle = 'My Loans — Employee Portal';
$extraCSS  = [BASE_URL . 'assets/css/employee-portal.css'];
require_once __DIR__ . '/../../../includes/head.php';
?>
<body>
<div class="layout">
<?php
if (isAdmin()):
    include __DIR__ . '/../../../includes/sidebar.php';
elseif (isPrincipalRole()):
    include __DIR__ . '/../../../includes/principal-sidebar.php';
else:
    include __DIR__ . '/../../../includes/employee-sidebar.php';
endif;
?>

<div class="main">
  <?php $empPortalIcon = 'fa-hand-holding-dollar'; include __DIR__ . '/../../../includes/employee-header.php'; ?>

  <div class="main-content">
  <div class="emp-page">

    <!-- Page Heading -->
    <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:20px;flex-wrap:wrap;">
      <div>
        <h1 style="font-size:18px;font-weight:700;color:#0f172a;margin:0 0 4px;">My Loans</h1>
        <p style="font-size:12px;color:#94a3b8;margin:0;">View your loan records, deduction history, and file new requests.</p>
      </div>
      <?php if (!empty($loanTypes)): ?>
      <button onclick="openRequestLoan()"
              style="display:flex;align-items:center;gap:7px;padding:9px 18px;background:#0d9488;
                     color:#fff;border:none;border-radius:9px;font-size:13px;font-weight:700;
                     cursor:pointer;white-space:nowrap;">
        <i class="fa fa-plus"></i> Request a Loan
      </button>
      <?php endif; ?>
    </div>

    <!-- KPI Cards -->
    <?php if (!empty($activeLoans)): ?>
    <div class="emp-kpi-row" style="grid-template-columns:repeat(3,1fr);margin-bottom:16px;">
      <div class="emp-kpi-card emp-kpi--amber">
        <div class="emp-kpi-icon"><i class="fa fa-coins"></i></div>
        <div class="emp-kpi-body">
          <div class="emp-kpi-value">₱<?= number_format($totalOutstanding, 2) ?></div>
          <div class="emp-kpi-label">Total Outstanding</div>
          <div class="emp-kpi-sub">Across <?= count($activeLoans) ?> loan<?= count($activeLoans) !== 1 ? 's' : '' ?></div>
        </div>
      </div>
      <div class="emp-kpi-card emp-kpi--red">
        <div class="emp-kpi-icon"><i class="fa fa-calendar-minus"></i></div>
        <div class="emp-kpi-body">
          <div class="emp-kpi-value">₱<?= number_format($totalMonthlyAmt, 2) ?></div>
          <div class="emp-kpi-label">Monthly Amortization</div>
          <div class="emp-kpi-sub">
            Auto-deduction: ₱<?= number_format($autoDeductionTotal, 2) ?>/payroll
          </div>
        </div>
      </div>
      <div class="emp-kpi-card emp-kpi--blue">
        <div class="emp-kpi-icon"><i class="fa fa-list-check"></i></div>
        <div class="emp-kpi-body">
          <div class="emp-kpi-value"><?= count($activeLoans) ?></div>
          <div class="emp-kpi-label">Active Loans</div>
          <?php if (!empty($pendingLoans)): ?>
          <div class="emp-kpi-sub"><?= count($pendingLoans) ?> pending verification</div>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <!-- Active / Paused Loans -->
    <div class="emp-panel" style="margin-bottom:16px;">
      <div class="emp-panel-header">
        <div class="emp-panel-title">
          <i class="fa fa-circle-check" style="color:#059669;"></i>
          Active Loans
        </div>
      </div>

      <?php if (!empty($activeLoans)): ?>
      <?php foreach ($activeLoans as $loan):
          $loanId    = (int)$loan['loan_id'];
          $paidAmt   = (float)$loan['total_amount'] - (float)$loan['balance_amount'];
          $pctPaid   = (float)$loan['total_amount'] > 0
                       ? min(100, round($paidAmt / (float)$loan['total_amount'] * 100)) : 0;
          $autoDeduction = round((float)$loan['monthly_deduction'] / 2, 2);
          $isPaused  = $loan['status'] === 'PAUSED';
          $loanDeductionRows = $deductionHistory[$loanId] ?? [];
      ?>
      <div style="border:1px solid var(--border);border-radius:10px;padding:16px;margin-bottom:10px;
                  <?= $isPaused ? 'background:#fffbeb;border-color:#fcd34d' : '' ?>">
        <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap;">
          <div>
            <div style="font-size:14px;font-weight:700;color:#0f172a;margin-bottom:2px;">
              <?= htmlspecialchars($loan['loan_name']) ?>
              <?php if ($isPaused): ?>
              <span style="font-size:11px;background:#fef3c7;color:#92400e;padding:2px 6px;border-radius:4px;font-weight:600;margin-left:6px;">
                <i class="fa fa-pause"></i> PAUSED
              </span>
              <?php endif; ?>
            </div>
            <?php if (!empty($loan['provider_name'])): ?>
            <div style="font-size:12px;color:#64748b;margin-bottom:3px;"><?= htmlspecialchars($loan['provider_name']) ?></div>
            <?php endif; ?>
            <div style="font-size:12px;color:#64748b;">
              <?php if ($loan['approved_at']): ?>
              Verified <?= date('M j, Y', strtotime($loan['approved_at'])) ?>
              <?php endif; ?>
              <?php if ($loan['end_date']): ?>
              &nbsp;·&nbsp; Until <?= date('M j, Y', strtotime($loan['end_date'])) ?>
              <?php endif; ?>
            </div>
          </div>
          <div style="text-align:right;">
            <div style="font-size:16px;font-weight:800;color:#d97706;">
              ₱<?= number_format((float)$loan['balance_amount'], 2) ?>
            </div>
            <div style="font-size:11px;color:#94a3b8;">outstanding balance</div>
          </div>
        </div>

        <!-- Progress bar -->
        <div style="margin-top:12px;">
          <div style="display:flex;justify-content:space-between;font-size:11px;color:#64748b;margin-bottom:5px;">
            <span>₱<?= number_format($paidAmt, 2) ?> paid</span>
            <span>₱<?= number_format((float)$loan['total_amount'], 2) ?> total</span>
          </div>
          <div style="height:6px;background:var(--border);border-radius:3px;overflow:hidden;">
            <div style="height:100%;width:<?= $pctPaid ?>%;background:#059669;border-radius:3px;"></div>
          </div>
          <div style="font-size:11px;color:#059669;font-weight:600;margin-top:4px;"><?= $pctPaid ?>% paid off</div>
        </div>

        <div style="margin-top:10px;font-size:12px;color:#374151;display:flex;gap:20px;flex-wrap:wrap;">
          <span>
            <i class="fa fa-calendar" style="color:#0369a1;"></i>
            Monthly amortization: <strong>₱<?= number_format((float)$loan['monthly_deduction'], 2) ?></strong>
          </span>
          <?php if (!$isPaused): ?>
          <span>
            <i class="fa fa-calendar-minus" style="color:#dc2626;"></i>
            Auto-deducted per payroll: <strong>₱<?= number_format($autoDeduction, 2) ?></strong>
          </span>
          <?php else: ?>
          <span style="color:#92400e;">
            <i class="fa fa-pause"></i> Deductions currently paused — contact HR Admin
          </span>
          <?php endif; ?>
        </div>

        <!-- Deduction history toggle -->
        <?php if (!empty($loanDeductionRows)): ?>
        <div style="margin-top:12px;border-top:1px solid var(--border);padding-top:10px;">
          <button onclick="toggleDeductionHistory(<?= $loanId ?>)"
                  style="background:none;border:none;cursor:pointer;font-size:12px;color:#0369a1;
                         font-weight:600;display:flex;align-items:center;gap:5px;padding:0;">
            <i class="fa fa-clock-rotate-left" style="font-size:11px;"></i>
            <span id="dh-toggle-label-<?= $loanId ?>">Show Deduction History (<?= count($loanDeductionRows) ?>)</span>
          </button>
          <div id="dh-<?= $loanId ?>" style="display:none;margin-top:10px;">
            <table style="width:100%;border-collapse:collapse;font-size:12px;">
              <thead>
                <tr style="background:#f8fafc;border-bottom:1px solid var(--border);">
                  <th style="padding:7px 10px;text-align:left;color:#64748b;font-size:11px;font-weight:700;text-transform:uppercase;">Date</th>
                  <th style="padding:7px 10px;text-align:left;color:#64748b;font-size:11px;font-weight:700;text-transform:uppercase;">Channel</th>
                  <th style="padding:7px 10px;text-align:right;color:#64748b;font-size:11px;font-weight:700;text-transform:uppercase;">Amount</th>
                  <th style="padding:7px 10px;text-align:left;color:#64748b;font-size:11px;font-weight:700;text-transform:uppercase;">Notes</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($loanDeductionRows as $dh):
                    $ch = $dh['payment_channel'] ?? 'MANUAL';
                    $chLabel = match($ch) {
                        'PAYROLL'        => '<span style="background:#dbeafe;color:#1d4ed8;padding:2px 6px;border-radius:4px;font-weight:600;">PAYROLL</span>',
                        'MANUAL'         => '<span style="background:#f0fdf4;color:#166534;padding:2px 6px;border-radius:4px;font-weight:600;">MANUAL</span>',
                        'PRIOR_PAYMENTS' => '<span style="background:#f1f5f9;color:#475569;padding:2px 6px;border-radius:4px;font-weight:600;">PRIOR</span>',
                        'WRITE_OFF'      => '<span style="background:#fef2f2;color:#991b1b;padding:2px 6px;border-radius:4px;font-weight:600;">WRITE-OFF</span>',
                        default          => '<span style="background:#f1f5f9;color:#475569;padding:2px 6px;border-radius:4px;font-weight:600;">' . htmlspecialchars($ch) . '</span>',
                    };
                ?>
                <tr style="border-bottom:1px solid var(--border);">
                  <td style="padding:7px 10px;color:#374151;"><?= date('M j, Y', strtotime($dh['payment_date'])) ?></td>
                  <td style="padding:7px 10px;"><?= $chLabel ?></td>
                  <td style="padding:7px 10px;text-align:right;font-weight:700;color:#d97706;">₱<?= number_format((float)$dh['amount'], 2) ?></td>
                  <td style="padding:7px 10px;color:#64748b;"><?= htmlspecialchars($dh['notes'] ?? '—') ?></td>
                </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </div>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>

      <?php else: ?>
      <div class="emp-empty">
        <i class="fa fa-hand-holding-dollar"></i>
        <p>No active loans at this time.</p>
        <?php if (!empty($loanTypes)): ?>
        <button onclick="openRequestLoan()"
                style="margin-top:10px;padding:8px 16px;background:#0d9488;color:#fff;border:none;
                       border-radius:8px;font-size:13px;font-weight:600;cursor:pointer;">
          <i class="fa fa-plus"></i> Request a Loan
        </button>
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>

    <!-- Pending Principal Review / Returned for Correction -->
    <?php if (!empty($pendingLoans)): ?>
    <div class="emp-panel" style="margin-bottom:16px;">
      <div class="emp-panel-header">
        <div class="emp-panel-title">
          <i class="fa fa-hourglass-half" style="color:#d97706;"></i>
          Pending Principal Review
        </div>
      </div>
      <?php foreach ($pendingLoans as $loan):
        $loanId     = (int)$loan['loan_id'];
        $isReturned = $loan['status'] === 'RETURNED';
        $filedByEmp = ($pendingFiledBy[$loanId] ?? 'EMPLOYEE') === 'EMPLOYEE';
      ?>
      <div style="display:flex;align-items:flex-start;gap:12px;padding:12px 0;border-bottom:1px solid var(--border);">
        <div class="emp-loan-icon"
             style="<?= $isReturned ? 'background:#fef3c7;color:#b45309' : '' ?>">
          <i class="fa <?= $isReturned ? 'fa-rotate-left' : 'fa-file-invoice-dollar' ?>"></i>
        </div>
        <div style="flex:1;">
          <div style="font-weight:600;font-size:13px;color:#0f172a;"><?= htmlspecialchars($loan['loan_name']) ?></div>
          <?php if (!empty($loan['provider_name'])): ?>
          <div style="font-size:11px;color:#64748b;"><?= htmlspecialchars($loan['provider_name']) ?></div>
          <?php endif; ?>
          <div style="font-size:11px;color:#94a3b8;">Submitted <?= date('M j, Y', strtotime($loan['created_at'])) ?></div>
          <?php if ($isReturned): ?>
          <div style="margin-top:8px;padding:10px 12px;background:#fef3c7;
                      border:1px solid #fcd34d;border-radius:8px;">
            <div style="font-size:11px;font-weight:700;color:#92400e;margin-bottom:6px;
                        display:flex;align-items:center;gap:5px;">
              <i class="fa fa-rotate-left"></i> Returned for Correction
            </div>
            <?php if (!empty($loan['return_reason'])): ?>
            <div style="font-size:12px;color:#78350f;margin-bottom:4px;line-height:1.5;">
              <strong>Reason:</strong> <?= htmlspecialchars($loan['return_reason']) ?>
            </div>
            <?php endif; ?>
            <?php $retBy = $returnedByMap[$loanId] ?? null; ?>
            <?php if ($retBy): ?>
            <div style="font-size:11px;color:#92400e;margin-bottom:2px;">
              <strong>Returned by:</strong> <?= htmlspecialchars($retBy) ?>
            </div>
            <?php endif; ?>
            <?php if (!empty($loan['updated_at'])): ?>
            <div style="font-size:11px;color:#92400e;margin-bottom:6px;">
              <strong>Date:</strong> <?= date('M j, Y', strtotime($loan['updated_at'])) ?>
            </div>
            <?php endif; ?>
            <div style="font-size:11px;color:#b45309;font-style:italic;">
              Please update your request or contact HR Admin for assistance.
            </div>
          </div>
          <?php endif; ?>
        </div>
        <div style="text-align:right;display:flex;flex-direction:column;align-items:flex-end;gap:6px;">
          <div style="font-weight:700;color:#374151;">₱<?= number_format((float)$loan['total_amount'], 2) ?></div>
          <?php if ($isReturned): ?>
          <span class="emp-badge" style="display:inline-flex;background:#fef3c7;color:#92400e;border-color:#fcd34d;">
            Returned for Correction
          </span>
          <?php else: ?>
          <span class="emp-badge emp-badge--pending" style="display:inline-flex;">
            Pending Principal Review
          </span>
          <?php endif; ?>
          <?php if ($filedByEmp && !$isReturned): ?>
          <button onclick="cancelOwnLoan(<?= $loanId ?>)"
                  style="font-size:11px;color:#dc2626;background:none;border:1px solid #fca5a5;
                         border-radius:5px;padding:3px 8px;cursor:pointer;font-weight:600;">
            <i class="fa fa-xmark"></i> Cancel Request
          </button>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Loan History & Audit -->
    <?php if (!empty($historyLoans)): ?>
    <div class="emp-panel" style="padding:0;overflow:hidden;margin-bottom:16px;">
      <div style="padding:14px 20px;border-bottom:1px solid var(--border);">
        <span style="font-size:13px;font-weight:700;color:#0f172a;">
          <i class="fa fa-clock-rotate-left" style="color:#94a3b8;"></i>
          Loan History &amp; Audit
        </span>
      </div>
      <table style="width:100%;border-collapse:collapse;font-size:13px;">
        <thead>
          <tr style="background:#f8fafc;border-bottom:1px solid var(--border);">
            <th style="padding:10px 16px;text-align:left;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;">Loan Type</th>
            <th style="padding:10px 16px;text-align:left;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;">Provider</th>
            <th style="padding:10px 16px;text-align:right;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;">Amount</th>
            <th style="padding:10px 16px;text-align:center;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;">Status</th>
            <th style="padding:10px 16px;text-align:left;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;">Filed</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($historyLoans as $hl):
            $loanId = (int)$hl['loan_id'];
            $st     = strtolower($hl['status']);
            $badgeCls = match($st) {
                'completed'  => 'completed',
                'denied', 'cancelled' => 'rejected',
                default      => 'pending',
            };
            $histLabelMap = [
                'completed' => 'Completed', 'denied' => 'Rejected',
                'cancelled' => 'Cancelled', 'archived' => 'Archived',
                'returned'  => 'Returned for Correction',
            ];
            $histLabel = $histLabelMap[$st] ?? ucfirst($st);
            $histDeductionRows = $deductionHistory[$loanId] ?? [];
        ?>
        <tr style="border-bottom:1px solid var(--border);">
          <td style="padding:12px 16px;font-weight:600;color:#374151;"><?= htmlspecialchars($hl['loan_name']) ?></td>
          <td style="padding:12px 16px;font-size:12px;color:#64748b;"><?= htmlspecialchars($hl['provider_name'] ?? '—') ?></td>
          <td style="padding:12px 16px;text-align:right;font-weight:600;">₱<?= number_format((float)$hl['total_amount'], 2) ?></td>
          <td style="padding:12px 16px;text-align:center;">
            <span class="emp-badge emp-badge--<?= $badgeCls ?>"><?= $histLabel ?></span>
          </td>
          <td style="padding:12px 16px;color:#64748b;font-size:12px;"><?= date('M j, Y', strtotime($hl['created_at'])) ?></td>
        </tr>
        <?php if (!empty($histDeductionRows)): ?>
        <tr style="border-bottom:1px solid var(--border);">
          <td colspan="5" style="padding:0 16px 12px;">
            <div style="background:#f8fafc;border-radius:8px;padding:10px 12px;">
              <div style="font-size:11px;font-weight:700;color:#64748b;margin-bottom:6px;text-transform:uppercase;">
                <i class="fa fa-clock-rotate-left"></i> Payment History (<?= count($histDeductionRows) ?>)
              </div>
              <?php foreach ($histDeductionRows as $dh):
                  $ch = $dh['payment_channel'] ?? 'MANUAL';
              ?>
              <div style="display:flex;align-items:center;gap:10px;font-size:11px;color:#374151;padding:3px 0;border-bottom:1px solid var(--border);">
                <span style="min-width:70px;color:#64748b;"><?= date('M j, Y', strtotime($dh['payment_date'])) ?></span>
                <span style="font-size:10px;background:<?= $ch==='PAYROLL'?'#dbeafe':'#f0fdf4' ?>;color:<?= $ch==='PAYROLL'?'#1d4ed8':'#166534' ?>;padding:1px 5px;border-radius:3px;font-weight:600;"><?= htmlspecialchars($ch) ?></span>
                <span style="font-weight:700;color:#d97706;margin-left:auto;">₱<?= number_format((float)$dh['amount'], 2) ?></span>
              </div>
              <?php endforeach; ?>
            </div>
          </td>
        </tr>
        <?php endif; ?>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>

    <?php if (empty($activeLoans) && empty($pendingLoans) && empty($historyLoans)): ?>
    <div class="emp-panel">
      <div class="emp-empty" style="padding:60px 20px;">
        <i class="fa fa-hand-holding-dollar"></i>
        <p>No loan records found.</p>
        <?php if (!empty($loanTypes)): ?>
        <button onclick="openRequestLoan()"
                style="margin-top:12px;padding:9px 20px;background:#0d9488;color:#fff;border:none;
                       border-radius:9px;font-size:13px;font-weight:700;cursor:pointer;">
          <i class="fa fa-plus"></i> Request a Loan
        </button>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

  </div>
  </div>
</div>
</div>

<!-- Request Loan Modal -->
<?php include __DIR__ . '/modal-request-loan.php'; ?>

<script>
function toggleDeductionHistory(loanId) {
    var div   = document.getElementById('dh-' + loanId);
    var label = document.getElementById('dh-toggle-label-' + loanId);
    if (!div) return;
    var isHidden = div.style.display === 'none';
    div.style.display   = isHidden ? 'block' : 'none';
    label.textContent   = isHidden ? 'Hide Deduction History' : label.textContent.replace('Hide','Show');
}

function cancelOwnLoan(loanId) {
    if (!confirm('Cancel this loan request? This cannot be undone.')) return;
    var fd = new FormData();
    fd.append('action',  'cancel_own');
    fd.append('loan_id', loanId);
    fetch('<?= BASE_URL ?>actions/loans-action.php', {method:'POST', body:fd})
    .then(function(r){ return r.json(); })
    .then(function(res) {
        if (res.success) {
            location.reload();
        } else {
            alert(res.message || 'Failed to cancel loan request.');
        }
    });
}
</script>

<?php include __DIR__ . '/../../../includes/footer.php'; ?>
