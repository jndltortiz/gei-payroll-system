<?php
/**
 * modules/employee/loans/index.php
 * Employee Portal — My Loans (read-only)
 */
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../includes/auth.php';
requireEmployee();

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
           el.created_at, el.return_reason
    FROM employee_loans el
    JOIN loan_types lt ON el.loan_type_id = lt.loan_type_id
    WHERE el.employee_id = ? AND el.status IN ('PENDING','RETURNED')
    ORDER BY el.created_at DESC
");
$stmt->execute([$empId]);
$pendingLoans = $stmt->fetchAll(PDO::FETCH_ASSOC);

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

// Totals (only active, not paused)
$activeOnly    = array_filter($activeLoans, fn($l) => $l['status'] === 'ACTIVE');
$totalOutstanding  = array_sum(array_column($activeLoans, 'balance_amount'));
$totalMonthlyAmt   = array_sum(array_column($activeOnly, 'monthly_deduction'));
$autoDeductionTotal = round($totalMonthlyAmt / 2, 2);

$pageTitle = 'My Loans — Employee Portal';
$extraCSS  = [BASE_URL . 'assets/css/employee-portal.css'];
require_once __DIR__ . '/../../../includes/head.php';
?>
<body>
<div class="layout">
<?php include __DIR__ . '/../../../includes/employee-sidebar.php'; ?>

<div class="main">
  <div class="header">
    <div style="display:flex;align-items:center;gap:10px;">
      <i class="fa fa-hand-holding-dollar" style="color:#2563eb;font-size:18px;"></i>
      <div>
        <div style="font-size:15px;font-weight:700;color:#0f172a;">Employee Portal</div>
        <div style="font-size:12px;color:#64748b;">Great Eastern Institute</div>
      </div>
    </div>
    <div style="margin-left:auto;display:flex;align-items:center;gap:12px;">
      <div style="text-align:right;">
        <div style="font-size:14px;font-weight:600;color:#0f172a;">
          <?= htmlspecialchars(($_SESSION['user']['first_name'] ?? '') . ' ' . ($_SESSION['user']['last_name'] ?? '')) ?>
        </div>
        <div style="font-size:11px;color:#64748b;">Employee</div>
      </div>
      <div class="header-avatar">
        <?= strtoupper(substr($_SESSION['user']['first_name'] ?? 'E', 0, 1) . substr($_SESSION['user']['last_name'] ?? 'M', 0, 1)) ?>
      </div>
    </div>
  </div>

  <div class="main-content">
  <div class="emp-page">

    <h1 style="font-size:18px;font-weight:700;color:#0f172a;margin:0 0 4px;">My Loans</h1>
    <p style="font-size:12px;color:#94a3b8;margin:0 0 20px;">View your active loan records and deduction schedule.</p>

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
          $paidAmt = (float)$loan['total_amount'] - (float)$loan['balance_amount'];
          $pctPaid = (float)$loan['total_amount'] > 0
                     ? min(100, round($paidAmt / (float)$loan['total_amount'] * 100)) : 0;
          $autoDeduction = round((float)$loan['monthly_deduction'] / 2, 2);
          $isPaused = $loan['status'] === 'PAUSED';
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
      </div>
      <?php endforeach; ?>

      <?php else: ?>
      <div class="emp-empty">
        <i class="fa fa-hand-holding-dollar"></i>
        <p>No active loans at this time.</p>
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
        $isReturned = $loan['status'] === 'RETURNED';
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
          <?php if ($isReturned && !empty($loan['return_reason'])): ?>
          <div style="margin-top:5px;font-size:11px;padding:5px 8px;background:#fef3c7;border-radius:5px;color:#92400e;">
            <i class="fa fa-rotate-left"></i> Returned: <?= htmlspecialchars($loan['return_reason']) ?>
          </div>
          <?php endif; ?>
        </div>
        <div style="text-align:right;">
          <div style="font-weight:700;color:#374151;">₱<?= number_format((float)$loan['total_amount'], 2) ?></div>
          <?php if ($isReturned): ?>
          <span class="emp-badge" style="display:inline-flex;margin-top:4px;background:#fef3c7;color:#92400e;border-color:#fcd34d;">
            Returned for Correction
          </span>
          <?php else: ?>
          <span class="emp-badge emp-badge--pending" style="display:inline-flex;margin-top:4px;">
            Pending Principal Review
          </span>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Loan History & Audit -->
    <?php if (!empty($historyLoans)): ?>
    <div class="emp-panel" style="padding:0;overflow:hidden;">
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
            $st = strtolower($hl['status']);
            $badgeCls = match($st) {
                'completed'  => 'completed',
                'denied', 'cancelled' => 'rejected',
                'returned'   => 'pending',
                'archived'   => 'pending',
                default      => 'pending',
            };
            $histLabelMap = [
                'completed' => 'Completed', 'denied' => 'Rejected',
                'cancelled' => 'Cancelled', 'archived' => 'Archived',
                'returned'  => 'Returned for Correction',
            ];
            $histLabel = $histLabelMap[$st] ?? ucfirst($st);
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
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>

    <?php if (empty($activeLoans) && empty($pendingLoans) && empty($historyLoans)): ?>
    <div class="emp-panel">
      <div class="emp-empty" style="padding:60px 20px;">
        <i class="fa fa-hand-holding-dollar"></i>
        <p>No loan records found. Contact HR Admin to record a loan.</p>
      </div>
    </div>
    <?php endif; ?>

  </div>
  </div>
</div>
</div>

<?php include __DIR__ . '/../../../includes/footer.php'; ?>
