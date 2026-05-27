<?php
/**
 * modules/principal/leave-approval/index.php
 * Principal Portal – Leave Management (full workflow)
 */
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../includes/auth.php';
requirePrincipal();

$principalId = $_SESSION['user']['employee_id'];
$userId      = $_SESSION['user']['user_id'];

// ── Migration guard ──────────────────────────────────────────────────────────
$hasMig016 = (bool)$pdo->query("SHOW COLUMNS FROM `leave_requests` LIKE 'workflow_status'")->fetch();

// ── Filters / tab ────────────────────────────────────────────────────────────
$tab     = $_GET['tab']    ?? 'pending';
$fDept   = (int)($_GET['dept']   ?? 0);
$fType   = (int)($_GET['type']   ?? 0);
$fMonth  = $_GET['month']  ?? '';
$fSearch = trim($_GET['search'] ?? '');

$validTabs = ['pending','decided','history'];
if (!in_array($tab, $validTabs)) $tab = 'pending';

$today = date('Y-m-d');
$thisM = date('Y-m');
$nextM = date('Y-m', strtotime('+1 month'));

// ── Dashboard stats ───────────────────────────────────────────────────────────
// Pending (forwarded by admin, principal needs to decide)
if ($hasMig016) {
    $sPending = (int)$pdo->query("
        SELECT COUNT(DISTINCT lr.leave_id)
        FROM leave_requests lr
        JOIN leave_request_dates lrd ON lr.leave_id = lrd.leave_id
        WHERE lr.workflow_status = 'FORWARDED' AND lrd.status = 'PENDING'
    ")->fetchColumn();
} else {
    $sPending = (int)$pdo->query("
        SELECT COUNT(DISTINCT lr.leave_id)
        FROM leave_requests lr
        JOIN leave_request_dates lrd ON lr.leave_id = lrd.leave_id
        WHERE lrd.status = 'PENDING'
    ")->fetchColumn();
}

$sOnLeaveToday = (int)$pdo->prepare("
    SELECT COUNT(DISTINCT lr.employee_id)
    FROM leave_requests lr
    JOIN leave_request_dates lrd ON lr.leave_id = lrd.leave_id
    WHERE lrd.leave_date = ? AND lrd.status = 'APPROVED'
")->execute([$today]) ? $pdo->query("
    SELECT COUNT(DISTINCT lr.employee_id)
    FROM leave_requests lr
    JOIN leave_request_dates lrd ON lr.leave_id = lrd.leave_id
    WHERE lrd.leave_date = '{$today}' AND lrd.status = 'APPROVED'
")->fetchColumn() : 0;

// Properly re-query
$sStmt = $pdo->prepare("
    SELECT COUNT(DISTINCT lr.employee_id)
    FROM leave_requests lr
    JOIN leave_request_dates lrd ON lr.leave_id = lrd.leave_id
    WHERE lrd.leave_date = ? AND lrd.status = 'APPROVED'
");
$sStmt->execute([$today]);
$sOnLeaveToday = (int)$sStmt->fetchColumn();

$sOnLeaveThisMonth = (int)$pdo->query("
    SELECT COUNT(DISTINCT lr.employee_id)
    FROM leave_requests lr
    JOIN leave_request_dates lrd ON lr.leave_id = lrd.leave_id
    WHERE lrd.status = 'APPROVED' AND DATE_FORMAT(lrd.leave_date,'%Y-%m') = '{$thisM}'
")->fetchColumn();

$sOnLeaveNextMonth = (int)$pdo->query("
    SELECT COUNT(DISTINCT lr.employee_id)
    FROM leave_requests lr
    JOIN leave_request_dates lrd ON lr.leave_id = lrd.leave_id
    WHERE lrd.status = 'APPROVED' AND DATE_FORMAT(lrd.leave_date,'%Y-%m') = '{$nextM}'
")->fetchColumn();

// Urgent backdated (pending + is_backdated)
$sUrgentBackdated = 0;
if ($hasMig016) {
    $sUrgentBackdated = (int)$pdo->query("
        SELECT COUNT(DISTINCT lr.leave_id)
        FROM leave_requests lr
        JOIN leave_request_dates lrd ON lr.leave_id = lrd.leave_id
        WHERE lr.is_backdated = 1 AND lrd.status = 'PENDING'
          AND lr.workflow_status = 'FORWARDED'
    ")->fetchColumn();
}

// ── Filter helpers ────────────────────────────────────────────────────────────
function buildPrincipalFilters(int $fDept, int $fType, string $fMonth, string $fSearch, array &$params): string {
    $where = [];
    if ($fDept)   { $where[] = 'e.department_id = ?'; $params[] = $fDept; }
    if ($fType)   { $where[] = 'lr.leave_type_id = ?'; $params[] = $fType; }
    if ($fMonth)  { $where[] = "DATE_FORMAT(lr.created_at,'%Y-%m') = ?"; $params[] = $fMonth; }
    if ($fSearch) {
        $where[] = '(CONCAT(e.first_name,\' \',e.last_name) LIKE ? OR e.employee_no LIKE ?)';
        $params[] = '%' . $fSearch . '%';
        $params[] = '%' . $fSearch . '%';
    }
    return $where ? ' AND ' . implode(' AND ', $where) : '';
}

// ── Filter options ────────────────────────────────────────────────────────────
$departments = $pdo->query("SELECT department_id, department_name FROM departments ORDER BY department_name")->fetchAll();
$leaveTypes  = $pdo->query("SELECT leave_type_id, leave_name FROM leave_types ORDER BY leave_name")->fetchAll();

// ── Tab queries ───────────────────────────────────────────────────────────────
$wfSel = $hasMig016
    ? ', lr.workflow_status, lr.is_backdated, lr.backdate_reason'
    : ", 'FORWARDED' AS workflow_status, 0 AS is_backdated, NULL AS backdate_reason";

$baseSelect = "
    SELECT
        lr.leave_id,
        lr.employee_id,
        CONCAT(e.first_name,' ',e.last_name)                  AS employee_name,
        CONCAT(LEFT(e.first_name,1),LEFT(e.last_name,1))      AS initials,
        e.employee_no,
        d.department_name,
        p.position_name,
        lt.leave_name,
        lr.reason,
        lr.start_date, lr.end_date, lr.total_days,
        lr.status, lr.created_at
        {$wfSel},
        COUNT(CASE WHEN lrd.status='PENDING'  THEN 1 END)  AS pending_cnt,
        COUNT(CASE WHEN lrd.status='APPROVED' THEN 1 END)  AS approved_cnt,
        COUNT(CASE WHEN lrd.status='REJECTED' THEN 1 END)  AS rejected_cnt,
        COUNT(lrd.date_id)                                   AS total_dates,
        MIN(lrd.leave_date)                                  AS actual_start,
        MAX(lrd.leave_date)                                  AS actual_end
    FROM leave_requests lr
    JOIN employees  e   ON lr.employee_id   = e.employee_id
    JOIN departments d  ON e.department_id  = d.department_id
    JOIN positions   p  ON e.position_id    = p.position_id
    JOIN leave_types lt ON lr.leave_type_id = lt.leave_type_id
    JOIN leave_request_dates lrd ON lr.leave_id = lrd.leave_id
";

$records = [];
$params  = [];

if ($tab === 'pending') {
    $wfCond = $hasMig016 ? "lr.workflow_status = 'FORWARDED'" : "1=1";
    $extra  = buildPrincipalFilters($fDept, $fType, $fMonth, $fSearch, $params);
    $sql    = $baseSelect . "
        WHERE ({$wfCond}){$extra}
        GROUP BY lr.leave_id
        HAVING pending_cnt > 0
        ORDER BY lr.created_at ASC";

} elseif ($tab === 'decided') {
    $wfCond = $hasMig016 ? "lr.workflow_status = 'FORWARDED'" : "lr.status IN ('APPROVED','REJECTED')";
    $extra  = buildPrincipalFilters($fDept, $fType, $fMonth, $fSearch, $params);
    $sql    = $baseSelect . "
        WHERE ({$wfCond}){$extra}
        GROUP BY lr.leave_id
        HAVING pending_cnt = 0
        ORDER BY lr.created_at DESC";

} else { // history
    $extra = buildPrincipalFilters($fDept, $fType, $fMonth, $fSearch, $params);
    $sql   = $baseSelect . "
        WHERE lr.status IN ('APPROVED','REJECTED','PARTIALLY_APPROVED'){$extra}
        GROUP BY lr.leave_id
        ORDER BY lr.updated_at DESC
        LIMIT 200";
}

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$records = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Individual dates for pending cards ────────────────────────────────────────
$leaveDates = [];
if ($tab === 'pending' && $records) {
    $ids  = implode(',', array_map('intval', array_column($records, 'leave_id')));
    $rows = $pdo->query("
        SELECT lrd.*,
               CONCAT(ae.first_name,' ',ae.last_name) AS actioned_by_name
        FROM leave_request_dates lrd
        LEFT JOIN users     u2  ON lrd.actioned_by = u2.user_id
        LEFT JOIN employees ae  ON u2.employee_id  = ae.employee_id
        WHERE lrd.leave_id IN ({$ids})
        ORDER BY lrd.leave_date ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rows as $r) {
        $leaveDates[$r['leave_id']][] = $r;
    }
}

$display_date = date('M d, Y');
$pageTitle    = 'Leave Management — Principal Portal';
$extraCSS     = [BASE_URL . 'assets/css/principal.css', BASE_URL . 'assets/css/principal-leave.css'];
require_once __DIR__ . '/../../../includes/head.php';
?>
<body>
<div class="layout">
<?php include __DIR__ . '/../../../includes/principal-sidebar.php'; ?>

<div class="main">

  <!-- Top Header -->
  <div class="header">
    <div style="display:flex;align-items:center;gap:10px;">
      <i class="fa fa-calendar-check" style="color:#0f766e;font-size:18px;"></i>
      <div>
        <div style="font-size:15px;font-weight:700;color:#0f172a;">Principal Portal</div>
        <div style="font-size:12px;color:#64748b;">Great Eastern Institute</div>
      </div>
    </div>
    <div style="margin-left:auto;display:flex;align-items:center;gap:12px;">
      <div style="text-align:right;">
        <div style="font-size:14px;font-weight:600;color:#0f172a;">
          <?= htmlspecialchars(($_SESSION['user']['first_name'] ?? '') . ' ' . ($_SESSION['user']['last_name'] ?? '')) ?>
        </div>
        <div style="font-size:11px;color:#64748b;">School Principal</div>
      </div>
      <div class="header-avatar">
        <?= strtoupper(substr($_SESSION['user']['first_name'] ?? 'P', 0, 1) . substr($_SESSION['user']['last_name'] ?? 'R', 0, 1)) ?>
      </div>
    </div>
  </div>

  <div class="main-content">
  <div class="principal-page">

    <!-- Page Header -->
    <div class="principal-page-header" style="margin-bottom:20px;">
      <div>
        <h1 style="font-size:22px;font-weight:700;color:#0f172a;margin:0 0 4px;">Leave Management</h1>
        <p style="font-size:13px;color:#64748b;margin:0;">Approve or reject leave requests per date. Admin forwards requests here for your decision.</p>
      </div>
    </div>

    <!-- Stat Cards -->
    <div class="lv-stats-row" style="grid-template-columns:repeat(5,1fr);gap:14px;margin-bottom:20px;">
      <a href="?tab=pending" class="lv-stat-card lv-stat-amber" style="text-decoration:none;cursor:pointer;<?= $tab==='pending'?'box-shadow:0 0 0 2px #d97706;':'' ?>">
        <div class="lv-stat-label">Pending Decision</div>
        <div class="lv-stat-value"><?= $sPending ?></div>
        <div class="lv-stat-sub">Forwarded by admin</div>
      </a>
      <div class="lv-stat-card" style="background:#fff;border:1px solid #e2e8f0;">
        <div class="lv-stat-label">On Leave Today</div>
        <div class="lv-stat-value" style="color:#0f766e;"><?= $sOnLeaveToday ?></div>
        <div class="lv-stat-sub"><?= $display_date ?></div>
      </div>
      <a href="?tab=history" class="lv-stat-card" style="background:#fff;border:1px solid #e2e8f0;text-decoration:none;cursor:pointer;">
        <div class="lv-stat-label">On Leave This Month</div>
        <div class="lv-stat-value" style="color:#2563eb;"><?= $sOnLeaveThisMonth ?></div>
        <div class="lv-stat-sub"><?= date('F Y') ?></div>
      </a>
      <div class="lv-stat-card" style="background:#fff;border:1px solid #e2e8f0;">
        <div class="lv-stat-label">On Leave Next Month</div>
        <div class="lv-stat-value" style="color:#7c3aed;"><?= $sOnLeaveNextMonth ?></div>
        <div class="lv-stat-sub"><?= date('F Y', strtotime('+1 month')) ?></div>
      </div>
      <?php if ($hasMig016): ?>
      <a href="?tab=pending&type=&search=&backdated=1" class="lv-stat-card" style="background:#fef3c7;border:1px solid #fde68a;text-decoration:none;cursor:pointer;">
        <div class="lv-stat-label" style="color:#b45309;">Urgent Backdated</div>
        <div class="lv-stat-value" style="color:#d97706;"><?= $sUrgentBackdated ?></div>
        <div class="lv-stat-sub" style="color:#92400e;">Sick/Emergency pending</div>
      </a>
      <?php else: ?>
      <div class="lv-stat-card" style="background:#fff;border:1px solid #e2e8f0;">
        <div class="lv-stat-label">Urgent Backdated</div>
        <div class="lv-stat-value" style="color:#d97706;">—</div>
        <div class="lv-stat-sub">Run migration 016</div>
      </div>
      <?php endif; ?>
    </div>

    <!-- Tabs -->
    <div class="lv-tabs-row">
      <a href="?tab=pending<?= $fDept?"&dept={$fDept}":'' ?>" class="lv-tab <?= $tab==='pending'?'lv-tab--active':'' ?>">
        Pending Decision
        <?php if ($sPending > 0): ?><span class="lv-count-badge"><?= $sPending ?></span><?php endif; ?>
      </a>
      <a href="?tab=decided<?= $fDept?"&dept={$fDept}":'' ?>" class="lv-tab <?= $tab==='decided'?'lv-tab--active':'' ?>">
        Decided — Awaiting Record
      </a>
      <a href="?tab=history" class="lv-tab <?= $tab==='history'?'lv-tab--active':'' ?>">
        <i class="fa fa-clock-rotate-left"></i> History
      </a>
    </div>

    <!-- Filters -->
    <form class="lv-filter-bar" method="GET" action="">
      <input type="hidden" name="tab" value="<?= htmlspecialchars($tab) ?>">
      <input type="text" name="search" class="lv-filter-control" placeholder="Search name or employee no."
             value="<?= htmlspecialchars($fSearch) ?>">
      <select name="dept" class="lv-filter-control">
        <option value="">All Departments</option>
        <?php foreach ($departments as $dep): ?>
          <option value="<?= $dep['department_id'] ?>" <?= $fDept==$dep['department_id']?'selected':'' ?>>
            <?= htmlspecialchars($dep['department_name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
      <select name="type" class="lv-filter-control">
        <option value="">All Leave Types</option>
        <?php foreach ($leaveTypes as $lt): ?>
          <option value="<?= $lt['leave_type_id'] ?>" <?= $fType==$lt['leave_type_id']?'selected':'' ?>>
            <?= htmlspecialchars($lt['leave_name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
      <input type="month" name="month" class="lv-filter-control" value="<?= htmlspecialchars($fMonth) ?>">
      <button type="submit" class="lv-btn-filter"><i class="fa fa-filter"></i> Filter</button>
      <a href="?tab=<?= $tab ?>" class="lv-filter-reset">Clear</a>
    </form>

    <!-- ── PENDING TAB: Card-based per-date UI ───────────────────────────── -->
    <?php if ($tab === 'pending'): ?>

    <?php if (empty($records)): ?>
    <div class="lv-empty-state">
      <i class="fa fa-circle-check" style="font-size:32px;color:#10b981;margin-bottom:10px;display:block;"></i>
      <strong>All caught up!</strong>
      <p>No pending leave requests at this time.</p>
    </div>

    <?php else: ?>
    <div class="lv-leave-list" id="leaveList">
    <?php foreach ($records as $leave):
        $dates  = $leaveDates[$leave['leave_id']] ?? [];
        $pCnt   = (int)$leave['pending_cnt'];
        $aCnt   = (int)$leave['approved_cnt'];
        $rCnt   = (int)$leave['rejected_cnt'];
        $isBack = !empty($leave['is_backdated']);

        $overall = 'Pending';
        $oBadge  = 'lv-badge--pending';
        if ($pCnt > 0 && ($aCnt > 0 || $rCnt > 0)) { $overall = 'Partial'; $oBadge = 'lv-badge--partial'; }
        elseif ($pCnt === 0 && $aCnt > 0 && $rCnt === 0) { $overall = 'Approved'; $oBadge = 'lv-badge--approved'; }
        elseif ($pCnt === 0 && $rCnt > 0 && $aCnt === 0) { $overall = 'Rejected'; $oBadge = 'lv-badge--rejected'; }
    ?>
    <div class="lv-leave-card" data-leave-id="<?= $leave['leave_id'] ?>">

      <!-- Card Top -->
      <div class="lv-card-top">
        <div class="lv-card-left">
          <div class="lv-emp-avatar"><?= htmlspecialchars($leave['initials']) ?></div>
          <div class="lv-emp-info">
            <div class="lv-emp-name">
              <?= htmlspecialchars($leave['employee_name']) ?>
              <span class="lv-badge <?= $oBadge ?>"><?= $overall ?></span>
              <?php if ($isBack): ?><span class="lv-badge" style="background:#ede9fe;color:#6d28d9;">Backdated</span><?php endif; ?>
            </div>
            <div class="lv-emp-position">
              <?= htmlspecialchars($leave['position_name']) ?>
              &nbsp;·&nbsp; <?= htmlspecialchars($leave['department_name']) ?>
              <?php if (!empty($leave['employee_no'])): ?>
                &nbsp;·&nbsp; <?= htmlspecialchars($leave['employee_no']) ?>
              <?php endif; ?>
            </div>
            <div class="lv-leave-meta">
              <span><strong>Type:</strong> <?= htmlspecialchars($leave['leave_name']) ?></span>
              <span class="lv-meta-dot">·</span>
              <span><strong>Filed:</strong> <?= date('M d, Y', strtotime($leave['created_at'])) ?></span>
            </div>
            <div class="lv-leave-reason"><strong>Reason:</strong> <?= htmlspecialchars($leave['reason']) ?></div>
            <?php if ($isBack && !empty($leave['backdate_reason'])): ?>
            <div style="margin-top:5px;padding:6px 10px;background:#f5f3ff;border-radius:6px;font-size:12px;color:#6d28d9;">
              <i class="fa fa-triangle-exclamation"></i> <strong>Backdated:</strong> <?= htmlspecialchars($leave['backdate_reason']) ?>
            </div>
            <?php endif; ?>
          </div>
        </div>

        <div class="lv-card-actions">
          <button class="lv-btn-view" onclick="openPrincipalView(<?= $leave['leave_id'] ?>)">
            <i class="fa fa-eye"></i> Full Details
          </button>
          <?php if ($pCnt > 0): ?>
          <button class="lv-btn-approve-all" onclick="bulkAction(<?= $leave['leave_id'] ?>, 'approve')">
            <i class="fa fa-circle-check"></i> Approve All
          </button>
          <button class="lv-btn-reject-all"  onclick="bulkAction(<?= $leave['leave_id'] ?>, 'reject')">
            <i class="fa fa-circle-xmark"></i> Reject All
          </button>
          <?php endif; ?>
        </div>
      </div>

      <!-- Summary pills -->
      <div class="lv-pills-row">
        <span class="lv-pill lv-pill--pending"><?= $pCnt ?> Pending</span>
        <span class="lv-pill lv-pill--approved"><?= $aCnt ?> Approved</span>
        <span class="lv-pill lv-pill--rejected"><?= $rCnt ?> Rejected</span>
      </div>

      <!-- Per-date rows -->
      <div class="lv-dates-wrap">
        <table class="lv-dates-table">
          <thead>
            <tr>
              <th>DATE</th>
              <th>STATUS</th>
              <th>NOTE</th>
              <th class="lv-col-action">ACTION</th>
            </tr>
          </thead>
          <tbody>
          <?php foreach ($dates as $d):
              $ds      = $d['status'];
              $dsBadge = match($ds) {
                  'APPROVED' => 'lv-badge--approved',
                  'REJECTED' => 'lv-badge--rejected',
                  default    => 'lv-badge--pending',
              };
          ?>
          <tr class="lv-date-row" data-date-id="<?= $d['date_id'] ?>">
            <td class="lv-date-cell"><?= date('M d, Y', strtotime($d['leave_date'])) ?></td>
            <td>
              <span class="lv-badge <?= $dsBadge ?>"><?= ucfirst(strtolower($ds)) ?></span>
            </td>
            <td class="lv-note-cell">
              <?php if (!empty($d['remarks'])): ?>
                <span style="font-size:12px;color:#64748b;font-style:italic;"><?= htmlspecialchars($d['remarks']) ?></span>
              <?php else: ?>
                <span style="color:#cbd5e1;font-size:11px;">—</span>
              <?php endif; ?>
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
              <span class="lv-actioned <?= $ds==='APPROVED'?'lv-actioned--approve':'lv-actioned--reject' ?>">
                <?= $ds==='APPROVED' ? '✓ Approved' : '✗ Rejected' ?>
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

    <!-- ── DECIDED / HISTORY TABS: Table UI ──────────────────────────── -->
    <?php else: ?>

    <?php if (empty($records)): ?>
    <div class="lv-empty-state">
      <i class="fa fa-calendar-xmark" style="font-size:28px;display:block;margin-bottom:10px;"></i>
      <p>No records found<?= ($fSearch||$fDept||$fType||$fMonth) ? ' matching the filters' : '' ?>.</p>
    </div>
    <?php else: ?>

    <div class="lv-history-card">
      <table class="lv-history-table">
        <thead>
          <tr>
            <th>Employee</th>
            <th>Leave Type</th>
            <th>Filed</th>
            <th>Date(s)</th>
            <th class="text-center">Days</th>
            <th class="text-center">Approved</th>
            <th class="text-center">Rejected</th>
            <th class="text-center">Status</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($records as $r):
            $dStart  = $r['actual_start'] ?? $r['start_date'];
            $dEnd    = $r['actual_end']   ?? $r['end_date'];
            $dateStr = ($dStart === $dEnd)
                ? date('M d, Y', strtotime($dStart))
                : date('M d', strtotime($dStart)) . '–' . date('M d, Y', strtotime($dEnd));
            $sBadge = match(strtoupper($r['status'])) {
                'APPROVED'           => ['lv-badge--approved','Approved'],
                'REJECTED'           => ['lv-badge--rejected','Rejected'],
                'PARTIALLY_APPROVED' => ['lv-badge--mixed','Mixed'],
                default              => ['lv-badge--pending','Pending'],
            };
        ?>
        <tr>
          <td>
            <div style="display:flex;align-items:center;gap:8px;">
              <div class="lv-emp-avatar" style="width:32px;height:32px;font-size:11px;">
                <?= htmlspecialchars($r['initials']) ?>
              </div>
              <div>
                <div style="font-size:13px;font-weight:600;color:#0f172a;"><?= htmlspecialchars($r['employee_name']) ?></div>
                <div style="font-size:11px;color:#94a3b8;"><?= htmlspecialchars($r['department_name']) ?></div>
              </div>
            </div>
          </td>
          <td style="font-size:13px;">
            <?= htmlspecialchars($r['leave_name']) ?>
            <?php if (!empty($r['is_backdated'])): ?>
              <span class="lv-badge" style="background:#ede9fe;color:#6d28d9;font-size:10px;margin-left:4px;">Backdated</span>
            <?php endif; ?>
          </td>
          <td style="font-size:12px;color:#64748b;white-space:nowrap;"><?= date('M d, Y', strtotime($r['created_at'])) ?></td>
          <td style="font-size:13px;white-space:nowrap;"><?= htmlspecialchars($dateStr) ?></td>
          <td class="text-center" style="font-weight:700;"><?= (int)$r['total_days'] ?></td>
          <td class="text-center" style="color:#059669;font-weight:700;"><?= (int)$r['approved_cnt'] ?></td>
          <td class="text-center" style="color:#dc2626;font-weight:700;"><?= (int)$r['rejected_cnt'] ?></td>
          <td class="text-center"><span class="lv-badge <?= $sBadge[0] ?>"><?= $sBadge[1] ?></span></td>
          <td>
            <button class="lv-btn-sm lv-btn-sm--view" onclick="openPrincipalView(<?= $r['leave_id'] ?>)">
              <i class="fa fa-eye"></i> View
            </button>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <?php endif; ?>
    <?php endif; ?>

  </div><!-- .principal-page -->
  </div><!-- .main-content -->
</div><!-- .main -->
</div><!-- .layout -->

<!-- MODALS -->
<?php include __DIR__ . '/modals/file-leave-modal.php'; ?>
<?php include __DIR__ . '/modals/confirm-action-modal.php'; ?>
<?php include __DIR__ . '/modals/reject-reason-modal.php'; ?>
<?php include __DIR__ . '/modals/view-modal.php'; ?>

<script>
const BASE_URL   = '<?= BASE_URL ?>';
const HAS_MIG016 = <?= $hasMig016 ? 'true' : 'false' ?>;
</script>
<script src="<?= BASE_URL ?>assets/js/principal-leave-approval.js"></script>
<?php include __DIR__ . '/../../../includes/footer.php'; ?>
