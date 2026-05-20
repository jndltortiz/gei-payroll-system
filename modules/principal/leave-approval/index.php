<?php
/**
 * modules/principal/leave-approval/index.php
 * Principal Portal – Leave Management
 */
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../includes/auth.php';
requirePrincipal();

$principal_id = $_SESSION['user']['employee_id'];
$user_id      = $_SESSION['user']['user_id'];

// ── Stats ─────────────────────────────────────────────────────────────────────

// Principal's own leave balance
$stmt = $pdo->prepare("
    SELECT COALESCE(SUM(
        CASE WHEN tt.type_name IN ('CREDIT','ADJUSTMENT') THEN lt.days ELSE -lt.days END
    ), 0) AS balance
    FROM leave_transactions lt
    JOIN leave_transaction_types tt ON lt.transaction_type_id = tt.transaction_type_id
    WHERE lt.employee_id = ?
");
$stmt->execute([$principal_id]);
$leave_balance = (int) $stmt->fetchColumn();

// Pending dates count + how many distinct requests
$pending_stats = $pdo->query("
    SELECT COUNT(*) AS cnt,
           COUNT(DISTINCT lr.leave_id) AS requests
    FROM leave_request_dates lrd
    JOIN leave_requests lr ON lrd.leave_id = lr.leave_id
    WHERE lrd.status = 'PENDING'
")->fetch(PDO::FETCH_ASSOC);
$pending_dates    = (int)$pending_stats['cnt'];
$pending_requests = (int)$pending_stats['requests'];

// Staff on leave today
$today = date('Y-m-d');
$staff_on_leave = (int)$pdo->prepare("
    SELECT COUNT(DISTINCT lrd.leave_id)
    FROM leave_request_dates lrd
    WHERE lrd.leave_date = ? AND lrd.status = 'APPROVED'
")->execute([$today]) ? $pdo->query("
    SELECT COUNT(DISTINCT lrd.leave_id)
    FROM leave_request_dates lrd
    WHERE lrd.leave_date = '$today' AND lrd.status = 'APPROVED'
")->fetchColumn() : 0;

// Properly requery staff on leave
$s = $pdo->prepare("
    SELECT COUNT(DISTINCT lrd.leave_id) AS cnt
    FROM leave_request_dates lrd
    WHERE lrd.leave_date = ? AND lrd.status = 'APPROVED'
");
$s->execute([$today]);
$staff_on_leave = (int)$s->fetchColumn();

// ── Pending leave cards (have at least 1 PENDING date) ──────────────────────
$pending_leaves = $pdo->query("
    SELECT
        lr.leave_id,
        lr.employee_id,
        CONCAT(e.first_name, ' ', e.last_name)         AS employee_name,
        CONCAT(LEFT(e.first_name,1), LEFT(e.last_name,1)) AS initials,
        p.position_name,
        lt.leave_name,
        lr.reason,
        lr.created_at AS filed_at,
        COUNT(CASE WHEN lrd.status = 'PENDING'  THEN 1 END) AS pending_count,
        COUNT(CASE WHEN lrd.status = 'APPROVED' THEN 1 END) AS approved_count,
        COUNT(CASE WHEN lrd.status = 'REJECTED' THEN 1 END) AS rejected_count,
        CASE
            WHEN COUNT(CASE WHEN lrd.status = 'PENDING' THEN 1 END) > 0
                 AND COUNT(CASE WHEN lrd.status IN ('APPROVED','REJECTED') THEN 1 END) > 0 THEN 'Partial'
            WHEN COUNT(CASE WHEN lrd.status = 'PENDING' THEN 1 END) > 0 THEN 'Pending'
            WHEN COUNT(CASE WHEN lrd.status = 'APPROVED' THEN 1 END) = COUNT(*) THEN 'Approved'
            ELSE 'Rejected'
        END AS overall_status
    FROM leave_requests lr
    JOIN employees e   ON lr.employee_id  = e.employee_id
    JOIN positions  p  ON e.position_id   = p.position_id
    JOIN leave_types lt ON lr.leave_type_id = lt.leave_type_id
    JOIN leave_request_dates lrd ON lr.leave_id = lrd.leave_id
    WHERE lr.status IN ('PENDING','APPROVED')
    GROUP BY lr.leave_id
    HAVING pending_count > 0
    ORDER BY lr.created_at ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Fetch all individual dates for the pending leaves
$leave_dates = [];
if ($pending_leaves) {
    $ids  = implode(',', array_map('intval', array_column($pending_leaves, 'leave_id')));
    $rows = $pdo->query("
        SELECT lrd.*,
               e2.first_name AS actioned_first,
               e2.last_name  AS actioned_last
        FROM leave_request_dates lrd
        LEFT JOIN users u      ON lrd.actioned_by = u.user_id
        LEFT JOIN employees e2 ON u.employee_id   = e2.employee_id
        WHERE lrd.leave_id IN ($ids)
        ORDER BY lrd.leave_date ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $leave_dates[$r['leave_id']][] = $r;
    }
}

// ── Recently actioned leaves ─────────────────────────────────────────────────
$recent_actioned = $pdo->query("
    SELECT
        CONCAT(e.first_name, ' ', e.last_name) AS employee_name,
        lt.leave_name,
        lr.start_date, lr.end_date, lr.total_days,
        lr.status,
        lr.updated_at
    FROM leave_requests lr
    JOIN employees  e  ON lr.employee_id   = e.employee_id
    JOIN leave_types lt ON lr.leave_type_id = lt.leave_type_id
    WHERE lr.status IN ('APPROVED','REJECTED')
    ORDER BY lr.updated_at DESC
    LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);

// ── Leave types for File modal ────────────────────────────────────────────────
$leave_types = $pdo->query("
    SELECT leave_type_id, leave_name FROM leave_types ORDER BY leave_name
")->fetchAll(PDO::FETCH_ASSOC);

$display_date = date('M d, Y');

$pageTitle = 'Leave Management — Principal Portal';
$extraCSS  = [BASE_URL . 'assets/css/principal.css', BASE_URL . 'assets/css/principal-leave.css'];
require_once __DIR__ . '/../../../includes/head.php';
?>
<body>
<div class="layout">
<?php include __DIR__ . '/../../../includes/principal-sidebar.php'; ?>

<div class="main">

  <!-- ── Top Header ── -->
  <div class="header">
    <div style="display:flex;align-items:center;gap:10px;">
      <i class="fa fa-calendar-check" style="color:#0f766e;font-size:18px;"></i>
      <div>
        <div style="font-size:15px;font-weight:700;color:#0f172a;">Principal Portal</div>
        <div style="font-size:12px;color:#64748b;">Great Eastern Institute</div>
      </div>
    </div>
    <div style="margin-left:auto;display:flex;align-items:center;gap:12px;">
      <button style="background:none;border:none;cursor:pointer;">
        <i class="fa fa-bell" style="font-size:16px;color:#64748b;"></i>
      </button>
      <div style="text-align:right;">
        <div style="font-size:14px;font-weight:600;color:#0f172a;">
          <?= htmlspecialchars(($_SESSION['user']['first_name'] ?? '') . ' ' . ($_SESSION['user']['last_name'] ?? '')) ?>
        </div>
        <div style="font-size:11px;color:#64748b;">School Principal</div>
      </div>
      <div class="header-avatar">
        <?= strtoupper(
            substr($_SESSION['user']['first_name'] ?? 'P', 0, 1) .
            substr($_SESSION['user']['last_name']  ?? 'R', 0, 1)
        ) ?>
      </div>
    </div>
  </div>

  <div class="main-content">
  <div class="principal-page">

    <!-- ── Page Header ── -->
    <div class="principal-page-header" style="display:flex;align-items:flex-start;justify-content:space-between;flex-wrap:wrap;gap:12px;">
      <div>
        <h1>Leave Management</h1>
        <p>Review employee leave requests and file your own leave.</p>
      </div>
      <button class="lv-btn-primary" onclick="openModal('fileLeaveModal')">
        <i class="fa fa-plus"></i> File a Leave
      </button>
    </div>

    <!-- ── Stats Row ── -->
    <div class="lv-stats-row">
      <div class="lv-stat-card lv-stat-blue">
        <div class="lv-stat-label">My Leave Balance</div>
        <div class="lv-stat-value"><?= $leave_balance ?> Days</div>
        <div class="lv-stat-sub">Available this year</div>
      </div>
      <div class="lv-stat-card lv-stat-amber">
        <div class="lv-stat-label">Pending Date Reviews</div>
        <div class="lv-stat-value"><?= $pending_dates ?> <?= $pending_dates == 1 ? 'Date' : 'Dates' ?></div>
        <div class="lv-stat-sub">Across <?= $pending_requests ?> <?= $pending_requests == 1 ? 'request' : 'requests' ?></div>
      </div>
      <div class="lv-stat-card lv-stat-teal">
        <div class="lv-stat-label">Staff On Leave Today</div>
        <div class="lv-stat-value"><?= $staff_on_leave ?> Staff</div>
        <div class="lv-stat-sub">As of today, <?= $display_date ?></div>
      </div>
    </div>

    <!-- ── Pending Review ── -->
    <div class="lv-section">
      <div class="lv-section-header">
        <h2 class="lv-section-title">
          Pending Review
          <span class="lv-count-badge" id="pendingCountBadge">
            <?= count($pending_leaves) ?> <?= count($pending_leaves) == 1 ? 'request' : 'requests' ?>
          </span>
        </h2>
        <span style="font-size:12px;color:#94a3b8;">Approve or reject each date individually</span>
      </div>

      <?php if (empty($pending_leaves)): ?>
      <div class="lv-empty-state">
        <i class="fa fa-circle-check" style="font-size:32px;color:#10b981;margin-bottom:10px;display:block;"></i>
        <strong>All caught up!</strong>
        <p>No pending leave requests at this time.</p>
      </div>

      <?php else: ?>
      <div class="lv-leave-list" id="leaveList">
      <?php foreach ($pending_leaves as $leave):
          $dates  = $leave_dates[$leave['leave_id']] ?? [];
          $status = $leave['overall_status'];
          $badge_class = match($status) {
              'Pending'  => 'lv-badge--pending',
              'Partial'  => 'lv-badge--partial',
              'Approved' => 'lv-badge--approved',
              default    => 'lv-badge--rejected',
          };
      ?>
      <div class="lv-leave-card" data-leave-id="<?= $leave['leave_id'] ?>">

        <!-- Card Top -->
        <div class="lv-card-top">
          <div class="lv-card-left">
            <div class="lv-emp-avatar"><?= htmlspecialchars($leave['initials']) ?></div>
            <div class="lv-emp-info">
              <div class="lv-emp-name">
                <?= htmlspecialchars($leave['employee_name']) ?>
                <span class="lv-badge <?= $badge_class ?>"><?= $status ?></span>
              </div>
              <div class="lv-emp-position"><?= htmlspecialchars($leave['position_name']) ?></div>
              <div class="lv-leave-meta">
                <span><strong>Type:</strong> <?= htmlspecialchars($leave['leave_name']) ?></span>
                <span class="lv-meta-dot">·</span>
                <span><strong>Filed:</strong> <?= date('M d, Y', strtotime($leave['filed_at'])) ?></span>
              </div>
              <div class="lv-leave-reason">
                <strong>Reason:</strong> <?= htmlspecialchars($leave['reason']) ?>
              </div>
            </div>
          </div>

          <?php if ($leave['pending_count'] > 0): ?>
          <div class="lv-card-actions">
            <button class="lv-btn-approve-all"
                    onclick="bulkAction(<?= $leave['leave_id'] ?>, 'approve')">
              <i class="fa fa-circle-check"></i> Approve All
            </button>
            <button class="lv-btn-reject-all"
                    onclick="bulkAction(<?= $leave['leave_id'] ?>, 'reject')">
              <i class="fa fa-circle-xmark"></i> Reject All
            </button>
          </div>
          <?php endif; ?>
        </div>

        <!-- Date summary pills -->
        <div class="lv-pills-row">
          <span class="lv-pill lv-pill--pending"><?= $leave['pending_count'] ?> Pending</span>
          <span class="lv-pill lv-pill--approved"><?= $leave['approved_count'] ?> Approved</span>
          <span class="lv-pill lv-pill--rejected"><?= $leave['rejected_count'] ?> Rejected</span>
        </div>

        <!-- Individual dates table -->
        <div class="lv-dates-wrap">
          <table class="lv-dates-table">
            <thead>
              <tr>
                <th>DATE</th>
                <th>STATUS</th>
                <th class="lv-col-action">ACTION</th>
              </tr>
            </thead>
            <tbody>
            <?php foreach ($dates as $d):
                $ds = $d['status'];
                $ds_badge = match($ds) {
                    'APPROVED' => 'lv-badge--approved',
                    'REJECTED' => 'lv-badge--rejected',
                    default    => 'lv-badge--pending',
                };
            ?>
            <tr class="lv-date-row" data-date-id="<?= $d['date_id'] ?>">
              <td class="lv-date-cell"><?= date('M d, Y', strtotime($d['leave_date'])) ?></td>
              <td>
                <span class="lv-badge <?= $ds_badge ?>"><?= ucfirst(strtolower($ds)) ?></span>
              </td>
              <td class="lv-action-cell">
                <?php if ($ds === 'PENDING'): ?>
                <button class="lv-btn-sm lv-btn-sm--approve"
                        onclick="dateAction(<?= $d['date_id'] ?>, <?= $leave['leave_id'] ?>, 'approve')">
                  <i class="fa fa-check"></i> Approve
                </button>
                <button class="lv-btn-sm lv-btn-sm--reject"
                        onclick="dateAction(<?= $d['date_id'] ?>, <?= $leave['leave_id'] ?>, 'reject')">
                  <i class="fa fa-times"></i> Reject
                </button>
                <?php else: ?>
                <span class="lv-actioned <?= $ds === 'APPROVED' ? 'lv-actioned--approve' : 'lv-actioned--reject' ?>">
                  <?= $ds === 'APPROVED' ? '✓ Approved' : '✗ Rejected' ?>
                </span>
                <?php endif; ?>
              </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>
      <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>

    <!-- ── Recent Actioned Leaves ── -->
    <div class="lv-section">
      <div class="lv-section-header">
        <h2 class="lv-section-title">Recent Actioned Leaves</h2>
      </div>

      <div class="pr-history-section" style="padding:0;">
        <div class="pr-history-table-wrap">
          <table class="pr-history-table">
            <thead>
              <tr>
                <th>Employee</th>
                <th>Leave Type</th>
                <th>Date(s)</th>
                <th>Total Days</th>
                <th>Status</th>
              </tr>
            </thead>
            <tbody>
            <?php if (empty($recent_actioned)): ?>
            <tr><td colspan="5" style="text-align:center;color:#94a3b8;padding:24px;">No recent activity.</td></tr>
            <?php else: ?>
            <?php foreach ($recent_actioned as $r):
                $rdate = ($r['start_date'] === $r['end_date'])
                    ? date('M d, Y', strtotime($r['start_date']))
                    : date('M d', strtotime($r['start_date'])) . ' – ' . date('M d, Y', strtotime($r['end_date']));
                $rs_badge = match(strtoupper($r['status'])) {
                    'APPROVED' => 'pr-badge--approved',
                    'REJECTED' => 'pr-badge--open',
                    default    => 'pr-badge--awaiting',
                };
                $rs_label = ucfirst(strtolower($r['status']));
            ?>
            <tr>
              <td><strong><?= htmlspecialchars($r['employee_name']) ?></strong></td>
              <td style="color:#64748b;"><?= htmlspecialchars($r['leave_name']) ?></td>
              <td style="color:#64748b;"><?= $rdate ?></td>
              <td style="text-align:center;font-weight:600;"><?= $r['total_days'] ?></td>
              <td>
                <span class="pr-badge <?= $rs_badge ?>"><?= $rs_label ?></span>
              </td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
            </tbody>
          </table>
        </div>
      </div>
    </div>

  </div><!-- .principal-page -->
  </div><!-- .main-content -->
</div><!-- .main -->
</div><!-- .layout -->

<!-- ══ MODALS ════════════════════════════════════════════════════════════════ -->
<?php include __DIR__ . '/modals/file-leave-modal.php'; ?>
<?php include __DIR__ . '/modals/confirm-action-modal.php'; ?>
<?php include __DIR__ . '/modals/reject-reason-modal.php'; ?>

<script>
const BASE_URL = '<?= BASE_URL ?>';
</script>
<script src="<?= BASE_URL ?>assets/js/principal-leave-approval.js"></script>
<?php include __DIR__ . '/../../../includes/footer.php'; ?>