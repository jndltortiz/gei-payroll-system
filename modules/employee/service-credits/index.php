<?php
/**
 * modules/employee/service-credits/index.php
 * Employee Portal — My Service Credits
 */
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../includes/auth.php';
requireEmployee();

$empId = (int)($_SESSION['user']['employee_id'] ?? 0);

// All service credits for this employee
$stmt = $pdo->prepare("
    SELECT sc.service_credit_id, sc.status, sc.equivalent_pay, sc.created_at,
           sc.remarks,
           (SELECT COUNT(*) FROM service_credit_dates scd
            WHERE scd.service_credit_id = sc.service_credit_id) AS date_count,
           (SELECT MIN(work_date) FROM service_credit_dates scd2
            WHERE scd2.service_credit_id = sc.service_credit_id) AS first_date,
           (SELECT MAX(work_date) FROM service_credit_dates scd3
            WHERE scd3.service_credit_id = sc.service_credit_id) AS last_date
    FROM service_credits sc
    WHERE sc.employee_id = ?
    ORDER BY sc.created_at DESC
");
$stmt->execute([$empId]);
$credits = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Summary stats
$totalApproved = 0;
$totalPay      = 0;
$pending       = 0;
foreach ($credits as $c) {
    if (strtolower($c['status']) === 'approved') {
        $totalApproved++;
        $totalPay += (float)$c['equivalent_pay'];
    }
    if (strtolower($c['status']) === 'pending') $pending++;
}

$pageTitle = 'My Service Credits — Employee Portal';
$extraCSS  = [BASE_URL . 'assets/css/employee-portal.css'];
require_once __DIR__ . '/../../../includes/head.php';
?>
<body>
<div class="layout">
<?php include __DIR__ . '/../../../includes/employee-sidebar.php'; ?>

<div class="main">
  <div class="header">
    <div style="display:flex;align-items:center;gap:10px;">
      <i class="fa fa-medal" style="color:#2563eb;font-size:18px;"></i>
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

    <h1 style="font-size:18px;font-weight:700;color:#0f172a;margin:0 0 4px;">My Service Credits</h1>
    <p style="font-size:12px;color:#94a3b8;margin:0 0 20px;">
      Extra work rendered beyond regular hours — credited as Additional Assignment Pay in payroll.
    </p>

    <!-- Stats -->
    <?php if (!empty($credits)): ?>
    <div class="emp-kpi-row" style="grid-template-columns:repeat(3,1fr);margin-bottom:16px;">
      <div class="emp-kpi-card emp-kpi--purple">
        <div class="emp-kpi-icon"><i class="fa fa-medal"></i></div>
        <div class="emp-kpi-body">
          <div class="emp-kpi-value"><?= count($credits) ?></div>
          <div class="emp-kpi-label">Total Submissions</div>
        </div>
      </div>
      <div class="emp-kpi-card emp-kpi--green">
        <div class="emp-kpi-icon"><i class="fa fa-circle-check"></i></div>
        <div class="emp-kpi-body">
          <div class="emp-kpi-value">₱<?= number_format($totalPay, 2) ?></div>
          <div class="emp-kpi-label">Total Approved Pay</div>
          <div class="emp-kpi-sub"><?= $totalApproved ?> approved submission<?= $totalApproved !== 1 ? 's' : '' ?></div>
        </div>
      </div>
      <div class="emp-kpi-card emp-kpi--amber">
        <div class="emp-kpi-icon"><i class="fa fa-hourglass-half"></i></div>
        <div class="emp-kpi-body">
          <div class="emp-kpi-value"><?= $pending ?></div>
          <div class="emp-kpi-label">Pending Approval</div>
        </div>
      </div>
    </div>
    <?php endif; ?>

    <!-- Service Credit List -->
    <div class="emp-panel" style="padding:0;overflow:hidden;">

      <?php if (!empty($credits)): ?>
      <table style="width:100%;border-collapse:collapse;font-size:13px;">
        <thead>
          <tr style="background:#f8fafc;border-bottom:1px solid var(--border);">
            <th style="padding:11px 16px;text-align:left;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.4px;">Work Date(s)</th>
            <th style="padding:11px 16px;text-align:center;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.4px;">Days</th>
            <th style="padding:11px 16px;text-align:right;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.4px;">Equivalent Pay</th>
            <th style="padding:11px 16px;text-align:center;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.4px;">Status</th>
            <th style="padding:11px 16px;text-align:left;font-size:11px;font-weight:700;color:#64748b;text-transform:uppercase;letter-spacing:0.4px;">Submitted</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($credits as $sc):
            $status   = strtolower($sc['status']);
            $badgeCls = match($status) {
                'approved' => 'approved',
                'rejected' => 'rejected',
                default    => 'pending',
            };
            $statusIcon = match($status) {
                'approved' => 'circle-check',
                'rejected' => 'circle-xmark',
                default    => 'hourglass-half',
            };

            $dateRange = $sc['first_date'] && $sc['last_date']
                ? ($sc['first_date'] === $sc['last_date']
                    ? date('M j, Y', strtotime($sc['first_date']))
                    : date('M j', strtotime($sc['first_date'])) . ' – ' . date('M j, Y', strtotime($sc['last_date'])))
                : '—';
        ?>
        <tr style="border-bottom:1px solid var(--border);">
          <td style="padding:13px 16px;font-weight:600;color:#0f172a;"><?= htmlspecialchars($dateRange) ?></td>
          <td style="padding:13px 16px;text-align:center;font-weight:700;color:#374151;">
            <?= (int)$sc['date_count'] ?>
          </td>
          <td style="padding:13px 16px;text-align:right;font-weight:700;color:<?= $status === 'approved' ? '#059669' : '#374151' ?>;">
            ₱<?= number_format((float)$sc['equivalent_pay'], 2) ?>
          </td>
          <td style="padding:13px 16px;text-align:center;">
            <span class="emp-badge emp-badge--<?= $badgeCls ?>">
              <i class="fa fa-<?= $statusIcon ?>"></i>
              <?= ucfirst($status) ?>
            </span>
          </td>
          <td style="padding:13px 16px;color:#64748b;font-size:12px;">
            <?= date('M j, Y', strtotime($sc['created_at'])) ?>
            <?php if (!empty($sc['remarks']) && $status === 'rejected'): ?>
            <div style="font-size:11px;color:#dc2626;margin-top:3px;">
              <i class="fa fa-circle-info"></i>
              <?= htmlspecialchars($sc['remarks']) ?>
            </div>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>

      <?php else: ?>
      <div class="emp-empty" style="padding:60px 20px;">
        <i class="fa fa-medal"></i>
        <p>No service credit submissions yet.<br>Ask HR Admin to record extra work you've rendered.</p>
      </div>
      <?php endif; ?>

    </div>

    <!-- Info note -->
    <div style="margin-top:14px;padding:12px 16px;background:#fffbeb;border:1px solid #fde68a;border-radius:10px;font-size:12px;color:#92400e;display:flex;align-items:flex-start;gap:8px;">
      <i class="fa fa-circle-info" style="margin-top:2px;flex-shrink:0;"></i>
      <div>
        Approved service credits are included as <strong>Additional Assignment Pay</strong> in your next payroll period.
        Contact HR Admin to submit extra work dates for approval.
      </div>
    </div>

  </div>
  </div>
</div>
</div>

<?php include __DIR__ . '/../../../includes/footer.php'; ?>
