<?php
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../includes/auth.php';
requireLogin();

// Auto-open a specific record after per-date action redirect
$autoOpenScId = isset($_GET['view_sc']) ? (int)$_GET['view_sc'] : 0;

// ── Pending service credits ────────────────────────────────────────────────
$pending = $pdo->query("
    SELECT sc.*,
           CONCAT(e.first_name,' ',e.last_name)  AS employee_name,
           e.employee_no, p.position_name, d.department_name,
           COALESCE(NULLIF(ec.daily_rate,0), ROUND(ec.monthly_salary/22,2)) AS daily_rate,
           CONCAT(uc.first_name,' ',uc.last_name) AS submitted_by_name,
           tp.period_name       AS target_period_name,
           tp.pay_period_start  AS target_period_start,
           tp.pay_period_end    AS target_period_end,
           tp.pay_date          AS target_pay_date,
           (SELECT MIN(scd.work_date) FROM service_credit_dates scd WHERE scd.service_credit_id = sc.service_credit_id) AS first_date,
           (SELECT MAX(scd.work_date) FROM service_credit_dates scd WHERE scd.service_credit_id = sc.service_credit_id) AS last_date,
           (SELECT COUNT(*)           FROM service_credit_dates scd WHERE scd.service_credit_id = sc.service_credit_id) AS date_count,
           (SELECT COUNT(*)           FROM service_credit_dates scd WHERE scd.service_credit_id = sc.service_credit_id AND scd.status = 'PENDING') AS pending_dates
    FROM service_credits sc
    JOIN employees e   ON e.employee_id   = sc.employee_id
    JOIN positions p   ON p.position_id   = e.position_id
    JOIN departments d ON d.department_id = e.department_id
    LEFT JOIN employee_compensations ec ON ec.employee_id = e.employee_id AND ec.is_active = 1
    LEFT JOIN employees uc ON uc.employee_id = (SELECT employee_id FROM users WHERE user_id = sc.created_by LIMIT 1)
    LEFT JOIN payroll_periods tp ON tp.period_id = sc.target_period_id
    WHERE sc.status = 'PENDING'
    ORDER BY sc.created_at ASC
")->fetchAll(PDO::FETCH_ASSOC);

$pendingTotal = array_sum(array_column($pending, 'equivalent_pay'));

// ── Batch-load child dates (with per-date status) ──────────────────────────
$pendingDates = [];
if (!empty($pending)) {
    $pIds = array_column($pending, 'service_credit_id');
    $ph   = implode(',', array_fill(0, count($pIds), '?'));
    $dSt  = $pdo->prepare("
        SELECT sc_date_id, service_credit_id, work_date,
               CAST(days AS CHAR) AS days,
               CAST(equivalent_pay AS CHAR) AS equivalent_pay,
               status, rejection_reason
        FROM service_credit_dates
        WHERE service_credit_id IN ($ph)
        ORDER BY work_date ASC
    ");
    $dSt->execute($pIds);
    foreach ($dSt->fetchAll(PDO::FETCH_ASSOC) as $d) {
        $pendingDates[$d['service_credit_id']][] = [
            'sc_date_id'       => (int)$d['sc_date_id'],
            'work_date'        => $d['work_date'],
            'days'             => (float)$d['days'],
            'equivalent_pay'   => (float)$d['equivalent_pay'],
            'status'           => $d['status'],
            'rejection_reason' => $d['rejection_reason'],
        ];
    }
}

// ── Batch-load audit logs ──────────────────────────────────────────────────
$pendingAudit = [];
if (!empty($pending)) {
    $pIds = array_column($pending, 'service_credit_id');
    $ph   = implode(',', array_fill(0, count($pIds), '?'));
    $aSt  = $pdo->prepare("
        SELECT al.record_id AS service_credit_id,
               al.action, al.created_at,
               CONCAT(eu.first_name,' ',eu.last_name) AS actor_name
        FROM audit_logs al
        LEFT JOIN users     u  ON u.user_id      = al.user_id
        LEFT JOIN employees eu ON eu.employee_id = u.employee_id
        WHERE al.table_name = 'service_credits' AND al.record_id IN ($ph)
        ORDER BY al.created_at ASC
    ");
    $aSt->execute($pIds);
    foreach ($aSt->fetchAll(PDO::FETCH_ASSOC) as $a) {
        $pendingAudit[$a['service_credit_id']][] = [
            'action'     => $a['action'],
            'actor_name' => $a['actor_name'],
            'created_at' => $a['created_at'],
        ];
    }
}

// Build full JS payload per pending record
$allPendingJs = [];
foreach ($pending as $sc) {
    $allPendingJs[] = array_merge($sc, [
        'dates' => $pendingDates[$sc['service_credit_id']] ?? [],
        'audit' => $pendingAudit[$sc['service_credit_id']] ?? [],
    ]);
}

// ── Recent activity ────────────────────────────────────────────────────────
$history = $pdo->query("
    SELECT sc.*,
           CONCAT(e.first_name,' ',e.last_name)  AS employee_name,
           p.position_name,
           CONCAT(ua.first_name,' ',ua.last_name) AS actioned_by_name,
           (SELECT MIN(scd.work_date) FROM service_credit_dates scd WHERE scd.service_credit_id = sc.service_credit_id) AS first_date,
           (SELECT MAX(scd.work_date) FROM service_credit_dates scd WHERE scd.service_credit_id = sc.service_credit_id) AS last_date,
           (SELECT COUNT(*)           FROM service_credit_dates scd WHERE scd.service_credit_id = sc.service_credit_id) AS date_count
    FROM service_credits sc
    JOIN employees e  ON e.employee_id = sc.employee_id
    JOIN positions p  ON p.position_id = e.position_id
    LEFT JOIN employees ua ON ua.employee_id = (
        SELECT employee_id FROM users WHERE user_id = sc.approved_by LIMIT 1
    )
    WHERE sc.status IN ('APPROVED','PARTIALLY_APPROVED','REJECTED','APPLIED','RELEASED')
    ORDER BY sc.updated_at DESC
    LIMIT 30
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
    <p>Review each work date individually — approved dates are paid as Additional Assignment Pay in the selected payroll.</p>
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

  <!-- Summary stats -->
  <div class="pr-stats-row" style="margin-bottom:24px;">
    <div class="pr-stat">
      <div class="pr-stat-label">Awaiting Your Review</div>
      <div class="pr-stat-value"><?= count($pending) ?></div>
    </div>
    <div class="pr-stat">
      <div class="pr-stat-label">Total Equivalent Pay</div>
      <div class="pr-stat-value pr-stat-value--lg">₱<?= number_format($pendingTotal, 2) ?></div>
    </div>
    <div class="pr-stat">
      <div class="pr-stat-label">Review Per Date</div>
      <div class="pr-stat-value pr-stat-value--teal" style="font-size:13px;font-weight:600;line-height:1.4;">
        Each date reviewed<br>individually
      </div>
    </div>
  </div>

  <!-- ═══ Pending cards ═══ -->
  <?php if (empty($pending)): ?>
  <div class="pr-no-pending">
    <i class="fa fa-circle-check"></i>
    <h3>All clear</h3>
    <p>No service credits are pending your review right now.</p>
  </div>
  <?php else: ?>

  <div style="margin-bottom:8px;font-size:13px;font-weight:600;color:#374151;">
    Pending Review (<?= count($pending) ?>)
  </div>

  <?php foreach ($pending as $sc):
    $dc = (int)($sc['date_count'] ?? 0);
    $pd = (int)($sc['pending_dates'] ?? $dc);
    $fd = $sc['first_date'] ?? null;
    $ld = $sc['last_date']  ?? null;
    $dateDisplay = $fd
        ? ($dc > 1 && $ld
            ? date('M j', strtotime($fd)) . ' – ' . date('M j, Y', strtotime($ld))
            : date('M j, Y', strtotime($fd)))
        : '—';

    $scDatesArr = $pendingDates[$sc['service_credit_id']] ?? [];
    $scAuditArr = $pendingAudit[$sc['service_credit_id']] ?? [];
    $scJson     = htmlspecialchars(json_encode(array_merge($sc, ['dates'=>$scDatesArr,'audit'=>$scAuditArr])), ENT_QUOTES);
  ?>
  <div class="pr-pending-card" style="margin-bottom:14px;">

    <!-- Card header -->
    <div class="pr-pending-header">
      <div>
        <div class="pr-pending-label"><?= htmlspecialchars($sc['employee_name']) ?></div>
        <div class="pr-pending-period">
          <?= htmlspecialchars($sc['position_name']) ?>
          &nbsp;·&nbsp;
          <?= htmlspecialchars($sc['department_name']) ?>
        </div>
      </div>
      <span class="sc-badge sc-badge--pending">
        <i class="fa fa-hourglass-half"></i>
        <?= $pd ?> Date<?= $pd !== 1 ? 's' : '' ?> Pending
      </span>
    </div>

    <!-- Key data grid -->
    <div class="sc-pr-grid">
      <div class="sc-pr-cell">
        <div class="sc-pr-label">Work Date<?= $dc > 1 ? 's' : '' ?></div>
        <div class="sc-pr-val">
          <?= htmlspecialchars($dateDisplay) ?>
          <?php if ($dc > 1): ?><br><small style="color:#94a3b8;"><?= $dc ?> dates</small><?php endif; ?>
        </div>
      </div>
      <div class="sc-pr-cell">
        <div class="sc-pr-label">Total Days</div>
        <div class="sc-pr-val sc-pr-val--bold"><?= number_format((float)$sc['days'], 1) ?></div>
      </div>
      <div class="sc-pr-cell">
        <div class="sc-pr-label">Daily Rate</div>
        <div class="sc-pr-val">₱<?= number_format((float)($sc['daily_rate'] ?? 0), 2) ?></div>
      </div>
      <div class="sc-pr-cell">
        <div class="sc-pr-label">Equiv. Pay</div>
        <div class="sc-pr-val sc-pr-val--pay">₱<?= number_format((float)$sc['equivalent_pay'], 2) ?></div>
      </div>
    </div>

    <!-- Description -->
    <?php if (!empty($sc['remarks'])): ?>
    <div class="sc-pr-desc">
      <i class="fa fa-clipboard-list" style="color:#64748b;font-size:11px;flex-shrink:0;margin-top:1px;"></i>
      <span><?= htmlspecialchars($sc['remarks']) ?></span>
    </div>
    <?php else: ?>
    <div class="sc-pr-desc sc-pr-desc--empty">
      <i class="fa fa-clipboard-list" style="color:#cbd5e1;font-size:11px;"></i>
      <span>No description provided.</span>
    </div>
    <?php endif; ?>

    <!-- Meta -->
    <div class="sc-pr-meta">
      <span><i class="fa fa-user"></i>Submitted by <?= htmlspecialchars($sc['submitted_by_name'] ?: 'HR Admin') ?></span>
      <span><i class="fa fa-clock"></i><?= date('M j, Y g:i A', strtotime($sc['created_at'])) ?></span>
      <?php if ($sc['target_period_id'] && $sc['target_period_name']): ?>
      <span><i class="fa fa-calendar-check" style="color:#2563eb;"></i>
        <span style="color:#2563eb;font-weight:600;"><?= htmlspecialchars($sc['target_period_name']) ?></span>
      </span>
      <?php endif; ?>
    </div>

    <!-- Action footer — View Details only (per-date review in modal) -->
    <div class="sc-pr-actions">
      <button class="sc-pr-btn-details" onclick="openPrViewDetails(<?= $scJson ?>)">
        <i class="fa fa-eye"></i> Review Dates
      </button>
      <span style="font-size:11px;color:#94a3b8;font-style:italic;">
        Review each date individually inside the details panel
      </span>
    </div>
  </div>
  <?php endforeach; ?>
  <?php endif; ?>

  <!-- ═══ Recent Activity ═══ -->
  <?php if (!empty($history)): ?>
  <div class="pr-history-section" style="margin-top:28px;">
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
            'APPROVED'           => 'sc-badge--approved',
            'PARTIALLY_APPROVED' => 'sc-badge--partial',
            'REJECTED'           => 'sc-badge--rejected',
            'APPLIED'            => 'sc-badge--applied',
            'RELEASED'           => 'sc-badge--released',
          ];
          $statusLabels = [
            'APPROVED'           => 'Approved',
            'PARTIALLY_APPROVED' => 'Partial',
            'REJECTED'           => 'Rejected',
            'APPLIED'            => 'In Payroll',
            'RELEASED'           => 'Released',
          ];
          $bc  = $badges[$h['status']]       ?? 'sc-badge--draft';
          $slb = $statusLabels[$h['status']] ?? ucfirst(strtolower($h['status']));

          $hdc = (int)($h['date_count'] ?? 0);
          $hfd = $h['first_date'] ?? null;
          $hld = $h['last_date']  ?? null;
          $hDate = $hfd
            ? ($hdc > 1 && $hld
                ? date('M j', strtotime($hfd)) . ' – ' . date('M j, Y', strtotime($hld))
                : date('M j, Y', strtotime($hfd)))
            : '—';
        ?>
        <tr>
          <td>
            <strong><?= htmlspecialchars($h['employee_name']) ?></strong>
            <br><small style="color:#94a3b8;"><?= htmlspecialchars($h['position_name']) ?></small>
          </td>
          <td>
            <?= htmlspecialchars($hDate) ?>
            <?php if ($hdc > 1): ?><br><small style="color:#94a3b8;"><?= $hdc ?> dates</small><?php endif; ?>
          </td>
          <td><?= number_format((float)$h['days'], 1) ?></td>
          <td style="font-weight:700;color:#0f766e;">₱<?= number_format((float)$h['equivalent_pay'], 2) ?></td>
          <td><span class="sc-badge <?= $bc ?>"><?= $slb ?></span></td>
          <td><?= $h['actioned_by_name'] ? htmlspecialchars($h['actioned_by_name']) : '<span style="color:#d1d5db;">—</span>' ?></td>
        </tr>
        <?php if ($h['status'] === 'REJECTED' && $h['rejection_reason']): ?>
        <tr style="background:#fff5f5;">
          <td colspan="6" style="padding:5px 14px 10px;">
            <div style="display:flex;align-items:flex-start;gap:6px;font-size:12px;color:#991b1b;">
              <i class="fa fa-circle-xmark" style="margin-top:1px;flex-shrink:0;"></i>
              <span><strong>Rejection reason:</strong> <?= htmlspecialchars($h['rejection_reason']) ?></span>
            </div>
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

<!-- ═══ View Details Modal ═══ -->
<div class="sc-modal-overlay" id="prViewOverlay" style="display:none;">
  <div class="sc-modal-box sc-modal-box--lg">
    <div class="sc-modal-header">
      <h3><i class="fa fa-calendar-check" style="color:#0d9488;"></i> Service Credit Review</h3>
      <button onclick="document.getElementById('prViewOverlay').style.display='none'">
        <i class="fa fa-times"></i>
      </button>
    </div>
    <div class="sc-modal-body" id="prViewBody"></div>
    <div class="sc-modal-footer" id="prViewFooter">
      <button type="button" class="sc-btn-ghost"
              onclick="document.getElementById('prViewOverlay').style.display='none'">Close</button>
    </div>
  </div>
</div>

<!-- ═══ Reject Date Modal ═══ -->
<div class="sc-modal-overlay" id="prRejectDateOverlay" style="display:none;">
  <div class="sc-modal-box sc-modal-box--sm">
    <div class="sc-modal-header">
      <h3><i class="fa fa-circle-xmark" style="color:#ef4444;"></i> Reject Work Date</h3>
      <button onclick="closePrRejectDate()"><i class="fa fa-times"></i></button>
    </div>
    <form method="POST" action="<?= BASE_URL ?>actions/service-credits-action.php">
      <input type="hidden" name="action"             value="reject_date">
      <input type="hidden" name="sc_date_id"         id="prRejectDateId"      value="">
      <input type="hidden" name="service_credit_id"  id="prRejectScId"        value="">
      <input type="hidden" name="redirect"           id="prRejectDateRedirect" value="">
      <div class="sc-modal-body">
        <div class="sc-reject-info" style="margin-bottom:14px;">
          <i class="fa fa-triangle-exclamation"></i>
          <div>Rejecting work date: <strong id="prRejectDateLabel"></strong>. The creator will see your reason.</div>
        </div>
        <div class="sc-form-group">
          <label>Rejection Reason <span class="req">*</span></label>
          <textarea name="rejection_reason" id="prRejectDateReason" rows="3" required maxlength="500"
                    placeholder="e.g. Regular school day, Duplicate entry, Insufficient documentation…"></textarea>
          <small id="prRejectDateCount" style="text-align:right;display:block;color:#94a3b8;">0 / 500</small>
        </div>
      </div>
      <div class="sc-modal-footer">
        <button type="button" class="sc-btn-ghost" onclick="closePrRejectDate()">Cancel</button>
        <button type="submit" class="sc-btn-danger">
          <i class="fa fa-circle-xmark"></i> Reject This Date
        </button>
      </div>
    </form>
  </div>
</div>

<script>
const SC_ACTION_URL   = '<?= BASE_URL ?>actions/service-credits-action.php';
const SC_PR_RETURN    = '<?= BASE_URL ?>modules/principal/service-credit-approval/';
const PR_PENDING_DATA = <?= json_encode(array_values($allPendingJs)) ?>;
const AUTO_OPEN_SC    = <?= $autoOpenScId ?>;

// ── Shared badge / format helpers ─────────────────────────────────────────
function escH(s) {
    return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
        .replace(/"/g,'&quot;').replace(/'/g,'&#039;');
}
function fmtDate(d) {
    if (!d) return '—';
    const dt = new Date(d + (d.length===10?'T00:00:00':''));
    return dt.toLocaleDateString('en-PH',{month:'short',day:'numeric',year:'numeric'});
}
function fmtDateTime(d) {
    if (!d) return '—';
    const dt = new Date(d.length===10?d+'T00:00:00':d);
    return dt.toLocaleDateString('en-PH',{month:'short',day:'numeric',year:'numeric'})
        + ' ' + dt.toLocaleTimeString('en-PH',{hour:'numeric',minute:'2-digit'});
}

const DATE_BADGE = {
    PENDING:  {cls:'sc-badge--pending',  lbl:'Pending'},
    APPROVED: {cls:'sc-badge--approved', lbl:'Approved'},
    REJECTED: {cls:'sc-badge--rejected', lbl:'Rejected'},
};

// ── View Details modal (per-date review) ──────────────────────────────────
function openPrViewDetails(r) {
    const dates = (r.dates && r.dates.length > 0) ? r.dates
        : (r.work_date ? [{sc_date_id:0,work_date:r.work_date,days:r.days||0,equivalent_pay:r.equivalent_pay||0,status:'PENDING',rejection_reason:null}] : []);

    // ── Date breakdown table with per-date Approve / Reject ───────────────
    const dRows = dates.map(d => {
        const ds = d.status || 'PENDING';
        const db = DATE_BADGE[ds] || DATE_BADGE.PENDING;
        const rejNote = ds === 'REJECTED' && d.rejection_reason
            ? `<div style="margin-top:3px;font-size:11px;color:#ef4444;font-style:italic;">${escH(d.rejection_reason)}</div>` : '';

        let actionCell = `<span style="color:#d1d5db;font-size:11px;">—</span>`;
        if (ds === 'PENDING' && d.sc_date_id) {
            const wd = escH(fmtDate(d.work_date));
            actionCell = `
                <form method="POST" action="${SC_ACTION_URL}" style="display:inline;margin-right:4px;">
                    <input type="hidden" name="action"            value="approve_date">
                    <input type="hidden" name="sc_date_id"        value="${d.sc_date_id}">
                    <input type="hidden" name="service_credit_id" value="${r.service_credit_id}">
                    <input type="hidden" name="redirect"          value="${SC_PR_RETURN}?view_sc=${r.service_credit_id}">
                    <button type="submit" class="sc-action-btn sc-action-btn--approve" style="font-size:11px;padding:3px 9px;">
                        <i class="fa fa-check"></i> Approve
                    </button>
                </form>
                <button type="button" class="sc-action-btn sc-action-btn--reject" style="font-size:11px;padding:3px 9px;"
                        onclick="openPrRejectDate(${d.sc_date_id},${r.service_credit_id},'${wd}')">
                    <i class="fa fa-times"></i> Reject
                </button>`;
        }

        return `<tr style="border-bottom:1px solid #f8fafc;">
            <td style="padding:8px 10px;">
                <span style="font-weight:600;">${fmtDate(d.work_date)}</span>${rejNote}
            </td>
            <td style="padding:8px 10px;text-align:center;">${parseFloat(d.days).toFixed(1)}d</td>
            <td style="padding:8px 10px;text-align:right;font-weight:600;color:${ds==='REJECTED'?'#d1d5db':'#0f766e'};">
                ₱${parseFloat(d.equivalent_pay).toLocaleString('en-PH',{minimumFractionDigits:2})}
            </td>
            <td style="padding:8px 10px;text-align:center;">
                <span class="sc-badge ${db.cls}" style="font-size:10px;">${db.lbl}</span>
            </td>
            <td style="padding:8px 10px;">${actionCell}</td>
        </tr>`;
    }).join('');

    const pendingCount  = dates.filter(d => (d.status||'PENDING') === 'PENDING').length;
    const approvedCount = dates.filter(d => d.status === 'APPROVED').length;
    const rejectedCount = dates.filter(d => d.status === 'REJECTED').length;

    const dHtml = `<div style="margin-bottom:14px;">
        <div style="font-size:10px;text-transform:uppercase;color:#94a3b8;letter-spacing:.5px;margin-bottom:6px;font-weight:700;">
            Work Dates — Individual Review
        </div>
        <div style="font-size:11px;color:#64748b;margin-bottom:8px;">
            ${pendingCount > 0 ? `<span style="color:#d97706;font-weight:600;">${pendingCount} pending</span>` : ''}
            ${approvedCount > 0 ? `<span style="color:#059669;font-weight:600;margin-left:8px;">${approvedCount} approved</span>` : ''}
            ${rejectedCount > 0 ? `<span style="color:#ef4444;font-weight:600;margin-left:8px;">${rejectedCount} rejected</span>` : ''}
        </div>
        <table style="width:100%;border-collapse:collapse;font-size:13px;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;">
            <thead>
                <tr style="background:#f8fafc;border-bottom:1px solid #e5e7eb;">
                    <th style="padding:8px 10px;text-align:left;font-size:11px;color:#64748b;font-weight:600;">Date</th>
                    <th style="padding:8px 10px;text-align:center;font-size:11px;color:#64748b;font-weight:600;">Day Equiv.</th>
                    <th style="padding:8px 10px;text-align:right;font-size:11px;color:#64748b;font-weight:600;">Amount</th>
                    <th style="padding:8px 10px;text-align:center;font-size:11px;color:#64748b;font-weight:600;">Status</th>
                    <th style="padding:8px 10px;font-size:11px;color:#64748b;font-weight:600;">Action</th>
                </tr>
            </thead>
            <tbody>${dRows}</tbody>
            <tfoot style="border-top:2px solid #e5e7eb;">
                <tr style="background:#f0fdf9;">
                    <td style="padding:8px 10px;font-weight:700;">Total</td>
                    <td style="padding:8px 10px;text-align:center;font-weight:700;">${parseFloat(r.days||0).toFixed(1)}d</td>
                    <td style="padding:8px 10px;text-align:right;font-weight:700;color:#0f766e;">₱${parseFloat(r.equivalent_pay||0).toLocaleString('en-PH',{minimumFractionDigits:2})}</td>
                    <td colspan="2"></td>
                </tr>
            </tfoot>
        </table>
    </div>`;

    // ── Target period ─────────────────────────────────────────────────────
    let tpHtml = '';
    if (r.target_period_id) {
        const pLabel = r.target_period_name
            || (r.target_period_start ? fmtDate(r.target_period_start) + ' – ' + fmtDate(r.target_period_end) : '—');
        const pPay = r.target_pay_date ? ' · Pay: ' + fmtDate(r.target_pay_date) : '';
        tpHtml = `<div class="sc-view-meta-item">
            <div class="sc-view-meta-label">Target Payroll Period</div>
            <div class="sc-view-meta-val" style="color:#2563eb;">${escH(pLabel)}${escH(pPay)}</div>
        </div>`;
    }

    // ── Activity history ──────────────────────────────────────────────────
    const aLabels = {CREATE:'Created',SUBMIT:'Submitted',RESUBMIT:'Resubmitted',UPDATE:'Edited',
                     APPROVE:'Approved (all)',APPROVE_DATE:'Date Approved',
                     REJECT:'Rejected (all)',REJECT_DATE:'Date Rejected',ARCHIVE:'Archived',RESTORE:'Restored'};
    const aColors  = {CREATE:'#64748b',SUBMIT:'#d97706',RESUBMIT:'#d97706',UPDATE:'#2563eb',
                      APPROVE:'#059669',APPROVE_DATE:'#0891b2',REJECT:'#ef4444',
                      REJECT_DATE:'#f97316',ARCHIVE:'#64748b',RESTORE:'#0d9488'};
    const aIcons   = {CREATE:'fa-plus-circle',SUBMIT:'fa-paper-plane',RESUBMIT:'fa-rotate-right',
                      UPDATE:'fa-pen',APPROVE:'fa-circle-check',APPROVE_DATE:'fa-check',
                      REJECT:'fa-circle-xmark',REJECT_DATE:'fa-xmark',
                      ARCHIVE:'fa-box-archive',RESTORE:'fa-rotate-left'};

    let hHtml = '';
    if (r.audit && r.audit.length > 0) {
        hHtml = `<div class="sc-view-history" style="margin-top:14px;">
            <div style="font-size:10px;text-transform:uppercase;color:#94a3b8;letter-spacing:.5px;margin-bottom:10px;font-weight:700;">Activity History</div>
            <div class="sc-history-list">
                ${r.audit.map(a => {
                    const lbl=aLabels[a.action]||a.action, col=aColors[a.action]||'#64748b', ico=aIcons[a.action]||'fa-circle';
                    return `<div class="sc-history-item">
                        <div class="sc-history-dot" style="background:${col};"><i class="fa ${ico}"></i></div>
                        <div class="sc-history-body">
                            <div class="sc-history-action" style="color:${col};">${escH(lbl)}</div>
                            <div class="sc-history-who">${escH(a.actor_name||'System')}</div>
                            <div class="sc-history-when">${fmtDateTime(a.created_at)}</div>
                        </div>
                    </div>`;
                }).join('')}
            </div>
        </div>`;
    }

    document.getElementById('prViewBody').innerHTML = `
        <div class="sc-view-header">
            <div>
                <div class="sc-view-emp">${escH(r.employee_name||'')}</div>
                <div style="font-size:12px;color:#64748b;margin-top:2px;">${escH(r.position_name||'')} &middot; ${escH(r.department_name||'')}</div>
            </div>
            <span class="sc-badge sc-badge--pending">Pending</span>
        </div>

        ${r.remarks ? `<div class="sc-view-remarks"><strong>Description:</strong> ${escH(r.remarks)}</div>` : '<div style="font-size:12px;color:#94a3b8;margin-bottom:12px;font-style:italic;">No description provided.</div>'}

        ${dHtml}

        <div class="sc-view-meta-grid">
            <div class="sc-view-meta-item">
                <div class="sc-view-meta-label">Daily Rate</div>
                <div class="sc-view-meta-val">₱${parseFloat(r.daily_rate||0).toLocaleString('en-PH',{minimumFractionDigits:2})}</div>
            </div>
            <div class="sc-view-meta-item">
                <div class="sc-view-meta-label">Submitted by</div>
                <div class="sc-view-meta-val">${escH(r.submitted_by_name||'HR Admin')}</div>
            </div>
            <div class="sc-view-meta-item">
                <div class="sc-view-meta-label">Submitted on</div>
                <div class="sc-view-meta-val">${fmtDateTime(r.created_at)}</div>
            </div>
            ${tpHtml}
        </div>

        ${hHtml}`;

    document.getElementById('prViewFooter').innerHTML =
        `<button type="button" class="sc-btn-ghost"
                 onclick="document.getElementById('prViewOverlay').style.display='none'">Close</button>`;

    document.getElementById('prViewOverlay').style.display = 'flex';
}

// ── Reject date modal ─────────────────────────────────────────────────────
function openPrRejectDate(dateId, scId, workDateLabel) {
    document.getElementById('prRejectDateId').value         = dateId;
    document.getElementById('prRejectScId').value           = scId;
    document.getElementById('prRejectDateRedirect').value   = SC_PR_RETURN + '?view_sc=' + scId;
    document.getElementById('prRejectDateLabel').textContent = workDateLabel;
    document.getElementById('prRejectDateReason').value     = '';
    document.getElementById('prRejectDateCount').textContent = '0 / 500';
    document.getElementById('prViewOverlay').style.display  = 'none';
    document.getElementById('prRejectDateOverlay').style.display = 'flex';
}
function closePrRejectDate() {
    document.getElementById('prRejectDateOverlay').style.display = 'none';
}
document.getElementById('prRejectDateReason')?.addEventListener('input', function() {
    document.getElementById('prRejectDateCount').textContent = this.value.length + ' / 500';
});

// ── Close modals on backdrop click ────────────────────────────────────────
document.querySelectorAll('.sc-modal-overlay').forEach(el => {
    el.addEventListener('click', e => { if (e.target === el) el.style.display = 'none'; });
});

// ── Auto-open modal after per-date action redirect ────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    if (AUTO_OPEN_SC) {
        const r = PR_PENDING_DATA.find(x => x.service_credit_id == AUTO_OPEN_SC);
        if (r) openPrViewDetails(r);
    }
});
</script>

<?php include __DIR__ . '/../../../includes/footer.php'; ?>
