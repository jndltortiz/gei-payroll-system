<?php
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../includes/auth.php';
requireLogin();

// ── Pending service credits ────────────────────────────────────────────────
$pending = $pdo->query("
    SELECT sc.*,
           CONCAT(e.first_name,' ',e.last_name) AS employee_name,
           e.employee_no, p.position_name, d.department_name,
           COALESCE(NULLIF(ec.daily_rate,0), ROUND(ec.monthly_salary/22,2)) AS daily_rate,
           (SELECT MIN(scd.work_date) FROM service_credit_dates scd WHERE scd.service_credit_id = sc.service_credit_id) AS first_date,
           (SELECT MAX(scd.work_date) FROM service_credit_dates scd WHERE scd.service_credit_id = sc.service_credit_id) AS last_date,
           (SELECT COUNT(*)           FROM service_credit_dates scd WHERE scd.service_credit_id = sc.service_credit_id) AS date_count
    FROM service_credits sc
    JOIN employees e   ON e.employee_id   = sc.employee_id
    JOIN positions p   ON p.position_id   = e.position_id
    JOIN departments d ON d.department_id = e.department_id
    LEFT JOIN employee_compensations ec ON ec.employee_id=e.employee_id AND ec.is_active=1
    WHERE sc.status = 'PENDING'
    ORDER BY sc.created_at ASC
")->fetchAll(PDO::FETCH_ASSOC);

$pendingTotal = array_sum(array_column($pending, 'equivalent_pay'));

// ── History (recently approved/rejected) ──────────────────────────────────
$history = $pdo->query("
    SELECT sc.*,
           CONCAT(e.first_name,' ',e.last_name) AS employee_name,
           p.position_name,
           CONCAT(u.first_name,' ',u.last_name) AS actioned_by_name,
           (SELECT MIN(scd.work_date) FROM service_credit_dates scd WHERE scd.service_credit_id = sc.service_credit_id) AS first_date,
           (SELECT MAX(scd.work_date) FROM service_credit_dates scd WHERE scd.service_credit_id = sc.service_credit_id) AS last_date,
           (SELECT COUNT(*)           FROM service_credit_dates scd WHERE scd.service_credit_id = sc.service_credit_id) AS date_count
    FROM service_credits sc
    JOIN employees e ON e.employee_id = sc.employee_id
    JOIN positions p ON p.position_id = e.position_id
    LEFT JOIN employees u ON u.employee_id = (
        SELECT employee_id FROM users WHERE user_id = sc.approved_by LIMIT 1
    )
    WHERE sc.status IN ('APPROVED','REJECTED','APPLIED','RELEASED')
    ORDER BY sc.updated_at DESC
    LIMIT 20
")->fetchAll(PDO::FETCH_ASSOC);

// ── Flash ──────────────────────────────────────────────────────────────────
$flashOk  = $_SESSION['sc_success'] ?? '';
$flashErr = $_SESSION['sc_error']   ?? '';
unset($_SESSION['sc_success'], $_SESSION['sc_error']);

$pageTitle = 'Service Credit Approvals — Principal Portal';
$extraCSS  = [
    BASE_URL . 'assets/css/principal.css',
    BASE_URL . 'assets/css/service-credits.css',
];
require_once __DIR__ . '/../../../includes/head.php';
?>
<body>
<div class="layout">
<?php include __DIR__ . '/../../../includes/principal-sidebar.php'; ?>
<div class="main">
<?php include __DIR__ . '/../../../includes/header.php'; ?>
<div class="main-content">

  <div class="principal-page">
    <div class="principal-page-header">
      <h1>Service Credit Approvals</h1>
      <p>Review and approve extra work rendered by employees — approved credits become Additional Assignment Payment.</p>
    </div>

    <!-- Alerts -->
    <?php if ($flashOk): ?>
      <div class="sc-alert sc-alert--ok"><i class="fa fa-circle-check"></i><?= htmlspecialchars($flashOk) ?>
        <button onclick="this.parentElement.remove()">×</button></div>
    <?php endif; ?>
    <?php if ($flashErr): ?>
      <div class="sc-alert sc-alert--err"><i class="fa fa-triangle-exclamation"></i><?= htmlspecialchars($flashErr) ?>
        <button onclick="this.parentElement.remove()">×</button></div>
    <?php endif; ?>

    <!-- Summary -->
    <div class="pr-stats-row" style="margin-bottom:24px;">
      <div class="pr-stat">
        <div class="pr-stat-label">Awaiting Your Approval</div>
        <div class="pr-stat-value"><?= count($pending) ?></div>
      </div>
      <div class="pr-stat">
        <div class="pr-stat-label">Total Equivalent Pay</div>
        <div class="pr-stat-value pr-stat-value--lg">₱<?= number_format($pendingTotal,2) ?></div>
      </div>
      <div class="pr-stat">
        <div class="pr-stat-label">Impact</div>
        <div class="pr-stat-value pr-stat-value--teal" style="font-size:14px;font-weight:600;">
          Merged into Additional<br>Assignment Payment
        </div>
      </div>
    </div>

    <!-- Pending cards -->
    <?php if (empty($pending)): ?>
    <div class="pr-no-pending">
      <i class="fa fa-circle-check"></i>
      <h3>All clear</h3>
      <p>No service credits are pending your approval.</p>
    </div>
    <?php else: ?>

    <?php foreach ($pending as $sc): ?>
    <div class="pr-pending-card" style="margin-bottom:14px;">
      <div class="pr-pending-header">
        <div>
          <div class="pr-pending-label"><?= htmlspecialchars($sc['employee_name']) ?></div>
          <div class="pr-pending-period">
            <?= htmlspecialchars($sc['position_name']) ?> ·
            <?= htmlspecialchars($sc['department_name']) ?>
          </div>
        </div>
        <span class="sc-badge sc-badge--pending">Pending</span>
      </div>

      <?php
        $dc = (int)($sc['date_count'] ?? 0);
        $fd = $sc['first_date'] ?? $sc['work_date'] ?? null;
        $ld = $sc['last_date']  ?? $sc['work_date'] ?? null;
        $dateDisplay = $fd
            ? ($dc > 1 && $ld
                ? date('M j', strtotime($fd)) . ' – ' . date('M j, Y', strtotime($ld)) . ' (' . $dc . ' dates)'
                : date('M j, Y', strtotime($fd)))
            : '—';
      ?>
      <div class="sc-view-grid" style="margin-bottom:12px;">
        <div class="sc-view-item">
          <small>Work Date<?= $dc > 1 ? 's' : '' ?></small>
          <strong><?= htmlspecialchars($dateDisplay) ?></strong>
        </div>
        <div class="sc-view-item">
          <small>Total Days</small>
          <strong><?= number_format((float)$sc['days'],1) ?> day<?= $sc['days']!=1?'s':'' ?></strong>
        </div>
        <div class="sc-view-item">
          <small>Equivalent Pay</small>
          <strong style="color:#0f766e;font-size:18px;">₱<?= number_format((float)$sc['equivalent_pay'],2) ?></strong>
        </div>
        <div class="sc-view-item">
          <small>Daily Rate</small>
          <strong>₱<?= number_format((float)($sc['daily_rate']??0),2) ?></strong>
        </div>
      </div>

      <?php if ($sc['remarks']): ?>
      <div class="sc-view-remarks" style="margin-bottom:12px;">
        <strong>Description:</strong> <?= htmlspecialchars($sc['remarks']) ?>
      </div>
      <?php endif; ?>

      <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:10px;">
        <div style="font-size:12px;color:#94a3b8;">
          <i class="fa fa-clock"></i>
          Submitted <?= date('M j, Y', strtotime($sc['created_at'] ?? $sc['work_date'])) ?>
        </div>
        <div style="display:flex;gap:8px;">
          <button class="pr-btn-reject"
                  onclick="openScReject(<?= $sc['service_credit_id'] ?>,'<?= htmlspecialchars($sc['employee_name']) ?>')">
            <i class="fa fa-circle-xmark"></i> Reject
          </button>
          <form method="POST" action="<?= BASE_URL ?>actions/service-credits-action.php"
                style="display:inline"
                data-confirm-title="Approve Service Credit"
                data-confirm-message="Approve this service credit for ₱<?= number_format((float)$sc['equivalent_pay'],2) ?>?"
                data-confirm-note="This will be included in the next payroll as Additional Assignment Pay."
                data-confirm-type="info"
                data-confirm-btn="Approve">
            <input type="hidden" name="action" value="approve">
            <input type="hidden" name="service_credit_id" value="<?= $sc['service_credit_id'] ?>">
            <input type="hidden" name="redirect" value="<?= BASE_URL ?>modules/principal/service-credit-approval/index.php">
            <button type="submit" class="pr-btn-approve">
              <i class="fa fa-circle-check"></i> Approve
            </button>
          </form>
        </div>
      </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>

    <!-- History -->
    <?php if (!empty($history)): ?>
    <div class="pr-history-section">
      <h2>Recent Activity</h2>
      <div class="pr-history-table-wrap">
        <table class="pr-history-table">
          <thead>
            <tr>
              <th>Employee</th>
              <th>Work Period</th>
              <th>Days</th>
              <th>Equiv. Pay</th>
              <th>Status</th>
              <th>Actioned By</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($history as $h):
            $badges = [
              'APPROVED' => 'sc-badge--approved', 'REJECTED' => 'sc-badge--rejected',
              'APPLIED'  => 'sc-badge--applied',  'RELEASED' => 'sc-badge--released',
            ];
            $bc = $badges[$h['status']] ?? 'sc-badge--draft';
          ?>
          <?php
            $hdc = (int)($h['date_count'] ?? 0);
            $hfd = $h['first_date'] ?? $h['work_date'] ?? null;
            $hld = $h['last_date']  ?? $h['work_date'] ?? null;
            $hDateDisp = $hfd
                ? ($hdc > 1 && $hld
                    ? date('M j', strtotime($hfd)) . ' – ' . date('M j, Y', strtotime($hld))
                    : date('M j, Y', strtotime($hfd)))
                : '—';
          ?>
          <tr>
            <td><strong><?= htmlspecialchars($h['employee_name']) ?></strong><br>
                <small style="color:#94a3b8;"><?= htmlspecialchars($h['position_name']) ?></small></td>
            <td><?= htmlspecialchars($hDateDisp) ?><?= $hdc > 1 ? '<br><small style="color:#94a3b8;">' . $hdc . ' dates</small>' : '' ?></td>
            <td><?= number_format((float)$h['days'],1) ?></td>
            <td style="font-weight:700;color:#0f766e;">₱<?= number_format((float)$h['equivalent_pay'],2) ?></td>
            <td><span class="sc-badge <?= $bc ?>"><?= ucfirst(strtolower($h['status'])) ?></span></td>
            <td><?= $h['actioned_by_name'] ? htmlspecialchars($h['actioned_by_name']) : '<span style="color:#d1d5db;">—</span>' ?></td>
          </tr>
          <?php if ($h['status']==='REJECTED' && $h['rejection_reason']): ?>
          <tr style="background:#fff5f5;">
            <td colspan="6" style="padding:6px 14px;font-size:12px;color:#991b1b;">
              <i class="fa fa-circle-xmark"></i> Reason: <?= htmlspecialchars($h['rejection_reason']) ?>
            </td>
          </tr>
          <?php endif; ?>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
    <?php endif; ?>
  </div><!-- .principal-page -->
</div><!-- .main-content -->
</div><!-- .main -->
</div><!-- .layout -->

<!-- Reject modal -->
<div class="sc-modal-overlay" id="scRejectOverlay" style="display:none;">
  <div class="sc-modal-box sc-modal-box--sm">
    <div class="sc-modal-header">
      <h3><i class="fa fa-circle-xmark" style="color:#ef4444"></i> Reject Service Credit</h3>
      <button onclick="this.closest('.sc-modal-overlay').style.display='none'">
        <i class="fa fa-times"></i>
      </button>
    </div>
    <form method="POST" action="<?= BASE_URL ?>actions/service-credits-action.php">
      <input type="hidden" name="action" value="reject">
      <input type="hidden" name="service_credit_id" id="scRejectId" value="">
      <input type="hidden" name="redirect" value="<?= BASE_URL ?>modules/principal/service-credit-approval/index.php">
      <div class="sc-modal-body">
        <div class="sc-reject-info">
          <i class="fa fa-triangle-exclamation"></i>
          Rejecting credit for <strong id="scRejectEmp"></strong>.
          Please state your reason.
        </div>
        <div class="sc-form-group" style="margin-top:12px;">
          <label>Reason <span class="req">*</span></label>
          <textarea name="rejection_reason" rows="3" required maxlength="500"
                    placeholder="State why this is being rejected…"></textarea>
        </div>
      </div>
      <div class="sc-modal-footer">
        <button type="button" class="sc-btn-ghost"
                onclick="this.closest('.sc-modal-overlay').style.display='none'">Cancel</button>
        <button type="submit" class="sc-btn-danger">
          <i class="fa fa-circle-xmark"></i> Submit Rejection
        </button>
      </div>
    </form>
  </div>
</div>

<script>
function openScReject(id, name) {
    document.getElementById('scRejectId').value = id;
    document.getElementById('scRejectEmp').textContent = name;
    document.getElementById('scRejectOverlay').style.display = 'flex';
}
</script>

<?php include __DIR__ . '/../../../includes/footer.php'; ?>