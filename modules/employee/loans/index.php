<?php
/**
 * modules/employee/loans/index.php
 * Employee Portal — My Loans
 */
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../includes/auth.php';
requireEmployee();

$empId = (int)($_SESSION['user']['employee_id'] ?? 0);

// Active loans
$stmt = $pdo->prepare("
    SELECT el.loan_id, lt.loan_name, el.total_amount, el.balance_amount,
           el.monthly_deduction, el.start_date, el.end_date, el.status,
           el.approved_at, el.created_at
    FROM employee_loans el
    JOIN loan_types lt ON el.loan_type_id = lt.loan_type_id
    WHERE el.employee_id = ? AND el.status = 'ACTIVE'
    ORDER BY el.start_date DESC
");
$stmt->execute([$empId]);
$activeLoans = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Pending loans
$stmt = $pdo->prepare("
    SELECT el.loan_id, lt.loan_name, el.total_amount, el.status, el.created_at
    FROM employee_loans el
    JOIN loan_types lt ON el.loan_type_id = lt.loan_type_id
    WHERE el.employee_id = ? AND el.status = 'PENDING'
    ORDER BY el.created_at DESC
");
$stmt->execute([$empId]);
$pendingLoans = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Completed / denied history
$stmt = $pdo->prepare("
    SELECT el.loan_id, lt.loan_name, el.total_amount, el.status,
           el.start_date, el.end_date, el.created_at
    FROM employee_loans el
    JOIN loan_types lt ON el.loan_type_id = lt.loan_type_id
    WHERE el.employee_id = ? AND el.status NOT IN ('ACTIVE','PENDING')
    ORDER BY el.created_at DESC
    LIMIT 10
");
$stmt->execute([$empId]);
$historyLoans = $stmt->fetchAll(PDO::FETCH_ASSOC);

$totalOutstanding = array_sum(array_column($activeLoans, 'balance_amount'));
$totalMonthlyDed  = array_sum(array_column($activeLoans, 'monthly_deduction'));

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
    <p style="font-size:12px;color:#94a3b8;margin:0 0 20px;">View your active and historical loan records.</p>

    <!-- KPI Cards (only if there are active loans) -->
    <?php if (!empty($activeLoans)): ?>
    <div class="emp-kpi-row" style="grid-template-columns:repeat(3,1fr);margin-bottom:16px;">
      <div class="emp-kpi-card emp-kpi--amber">
        <div class="emp-kpi-icon"><i class="fa fa-coins"></i></div>
        <div class="emp-kpi-body">
          <div class="emp-kpi-value">₱<?= number_format($totalOutstanding, 2) ?></div>
          <div class="emp-kpi-label">Total Outstanding</div>
          <div class="emp-kpi-sub">Across <?= count($activeLoans) ?> active loan<?= count($activeLoans) !== 1 ? 's' : '' ?></div>
        </div>
      </div>
      <div class="emp-kpi-card emp-kpi--red">
        <div class="emp-kpi-icon"><i class="fa fa-calendar-minus"></i></div>
        <div class="emp-kpi-body">
          <div class="emp-kpi-value">₱<?= number_format($totalMonthlyDed, 2) ?></div>
          <div class="emp-kpi-label">Monthly Deduction</div>
          <div class="emp-kpi-sub">Deducted each pay period</div>
        </div>
      </div>
      <div class="emp-kpi-card emp-kpi--blue">
        <div class="emp-kpi-icon"><i class="fa fa-list-check"></i></div>
        <div class="emp-kpi-body">
          <div class="emp-kpi-value"><?= count($activeLoans) ?></div>
          <div class="emp-kpi-label">Active Loans</div>
          <?php if (!empty($pendingLoans)): ?>
          <div class="emp-kpi-sub"><?= count($pendingLoans) ?> pending approval</div>
          <?php endif; ?>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <!-- Active Loans -->
    <div class="emp-panel" style="margin-bottom:16px;">
      <div class="emp-panel-header">
        <div class="emp-panel-title">
          <i class="fa fa-circle-check" style="color:#059669;"></i>
          Active Loans
        </div>
      </div>

      <?php if (!empty($activeLoans)): ?>
      <?php foreach ($activeLoans as $loan):
          $paidAmt   = (float)$loan['total_amount'] - (float)$loan['balance_amount'];
          $pctPaid   = (float)$loan['total_amount'] > 0
                       ? min(100, round($paidAmt / (float)$loan['total_amount'] * 100))
                       : 0;
      ?>
      <div style="border:1px solid var(--border);border-radius:10px;padding:16px;margin-bottom:10px;">
        <div style="display:flex;align-items:flex-start;justify-content:space-between;gap:12px;flex-wrap:wrap;">
          <div>
            <div style="font-size:14px;font-weight:700;color:#0f172a;margin-bottom:3px;">
              <?= htmlspecialchars($loan['loan_name']) ?>
            </div>
            <div style="font-size:12px;color:#64748b;">
              Approved <?= $loan['approved_at'] ? date('M j, Y', strtotime($loan['approved_at'])) : '—' ?>
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

        <div style="margin-top:10px;font-size:12px;color:#374151;">
          <i class="fa fa-calendar-minus" style="color:#dc2626;"></i>
          Monthly deduction: <strong>₱<?= number_format((float)$loan['monthly_deduction'], 2) ?></strong> per pay period
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

    <!-- Pending Loans -->
    <?php if (!empty($pendingLoans)): ?>
    <div class="emp-panel" style="margin-bottom:16px;">
      <div class="emp-panel-header">
        <div class="emp-panel-title">
          <i class="fa fa-hourglass-half" style="color:#d97706;"></i>
          Pending Approval
        </div>
      </div>
      <?php foreach ($pendingLoans as $loan): ?>
      <div style="display:flex;align-items:center;gap:12px;padding:12px 0;border-bottom:1px solid var(--border);">
        <div class="emp-loan-icon"><i class="fa fa-coins"></i></div>
        <div style="flex:1;">
          <div style="font-weight:600;font-size:13px;color:#0f172a;"><?= htmlspecialchars($loan['loan_name']) ?></div>
          <div style="font-size:11px;color:#94a3b8;">Filed <?= date('M j, Y', strtotime($loan['created_at'])) ?></div>
        </div>
        <div>
          <div style="font-weight:700;color:#374151;">₱<?= number_format((float)$loan['total_amount'], 2) ?></div>
          <span class="emp-badge emp-badge--pending" style="display:inline-flex;margin-top:4px;">Pending</span>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- Loan History -->
    <?php if (!empty($historyLoans)): ?>
    <div class="emp-panel" style="padding:0;overflow:hidden;">
      <div style="padding:14px 20px;border-bottom:1px solid var(--border);">
        <span style="font-size:13px;font-weight:700;color:#0f172a;">
          <i class="fa fa-clock-rotate-left" style="color:#94a3b8;"></i>
          Loan History
        </span>
      </div>
      <table style="width:100%;border-collapse:collapse;font-size:13px;">
        <thead>
          <tr style="background:#f8fafc;border-bottom:1px solid var(--border);">
            <th style="padding:10px 16px;text-align:left;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.4px;">Loan Type</th>
            <th style="padding:10px 16px;text-align:right;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.4px;">Amount</th>
            <th style="padding:10px 16px;text-align:center;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.4px;">Status</th>
            <th style="padding:10px 16px;text-align:left;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.4px;">Filed</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($historyLoans as $hl):
            $st = strtolower($hl['status']);
            $badgeCls = match($st) {
                'completed' => 'completed',
                'denied'    => 'rejected',
                default     => 'pending',
            };
        ?>
        <tr style="border-bottom:1px solid var(--border);">
          <td style="padding:12px 16px;font-weight:600;color:#374151;"><?= htmlspecialchars($hl['loan_name']) ?></td>
          <td style="padding:12px 16px;text-align:right;font-weight:600;">₱<?= number_format((float)$hl['total_amount'], 2) ?></td>
          <td style="padding:12px 16px;text-align:center;">
            <span class="emp-badge emp-badge--<?= $badgeCls ?>"><?= ucfirst($st) ?></span>
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
        <p>No loan records found. Contact HR Admin to apply for a loan.</p>
      </div>
    </div>
    <?php endif; ?>

  </div>
  </div>
</div>
</div>

<?php include __DIR__ . '/../../../includes/footer.php'; ?>
