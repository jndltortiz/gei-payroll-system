<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

// ── School year helpers ─────────────────────────────────────────────────────
function currentSchoolYear(): string {
    $m = (int)date('n');
    $y = (int)date('Y');
    return $m >= 6 ? "$y-" . ($y + 1) : ($y - 1) . "-$y";
}
function syDates(string $sy): array {
    [$y1, $y2] = explode('-', $sy);
    return ["{$y1}-06-01", "{$y2}-05-31 23:59:59"];
}

$currentSY = currentSchoolYear();

// ── Active tab & filters ────────────────────────────────────────────────────
$tab       = $_GET['tab']    ?? 'all';
$search    = trim($_GET['search'] ?? '');
$syFilter  = $_GET['sy'] ?? $currentSY;
$validTabs = ['all','draft','pending','approved','applied','rejected','archived'];
if (!in_array($tab, $validTabs)) $tab = 'all';

// ── Available school years (from data) ─────────────────────────────────────
$syRows = $pdo->query("
    SELECT DISTINCT
        CASE WHEN MONTH(created_at)>=6 THEN YEAR(created_at)   ELSE YEAR(created_at)-1 END AS y1,
        CASE WHEN MONTH(created_at)>=6 THEN YEAR(created_at)+1 ELSE YEAR(created_at)   END AS y2
    FROM service_credits
    ORDER BY y1 DESC
")->fetchAll(PDO::FETCH_ASSOC);
$allSYs = array_map(fn($r) => $r['y1'] . '-' . $r['y2'], $syRows);
if (!in_array($currentSY, $allSYs)) array_unshift($allSYs, $currentSY);

// ── SY WHERE fragment ───────────────────────────────────────────────────────
$syWhere  = '';
$syParams = [];
if ($syFilter !== 'all' && in_array($syFilter, $allSYs)) {
    [$syStart, $syEnd] = syDates($syFilter);
    $syWhere = " AND sc.created_at BETWEEN :sy_start AND :sy_end";
    $syParams[':sy_start'] = $syStart;
    $syParams[':sy_end']   = $syEnd;
}

// ── Tab counts (filtered by SY) ─────────────────────────────────────────────
$cntStmtRaw = $pdo->prepare("
    SELECT sc.status, COUNT(*) AS cnt
    FROM service_credits sc
    WHERE 1=1 $syWhere
    GROUP BY sc.status
");
$cntStmtRaw->execute($syParams);
$counts        = $cntStmtRaw->fetchAll(PDO::FETCH_KEY_PAIR);
$allCount      = array_sum($counts);
$draftCount    = $counts['DRAFT']    ?? 0;
$pendingCount  = $counts['PENDING']            ?? 0;
$approvedCount = ($counts['APPROVED'] ?? 0) + ($counts['PARTIALLY_APPROVED'] ?? 0);
$appliedCount  = ($counts['APPLIED']  ?? 0) + ($counts['RELEASED'] ?? 0);
$rejectedCount = $counts['REJECTED']          ?? 0;
$archivedCount = $counts['ARCHIVED']          ?? 0;

// ── Stats (all-time, no SY filter — give complete picture) ──────────────────
$pendingPay  = (float)$pdo->query("SELECT COALESCE(SUM(equivalent_pay),0) FROM service_credits WHERE status='PENDING'")->fetchColumn();
$approvedPay = (float)$pdo->query("SELECT COALESCE(SUM(equivalent_pay),0) FROM service_credits WHERE status IN('APPROVED','PARTIALLY_APPROVED')")->fetchColumn();
$appliedPay  = (float)$pdo->query("SELECT COALESCE(SUM(equivalent_pay),0) FROM service_credits WHERE status IN('APPLIED','RELEASED')")->fetchColumn();

// ── WHERE clause ───────────────────────────────────────────────────────────
$where  = "WHERE 1=1" . $syWhere;
$params = $syParams;

if ($tab !== 'all') {
    if ($tab === 'approved') {
        $where .= " AND sc.status IN ('APPROVED','PARTIALLY_APPROVED')";
    } elseif ($tab === 'applied') {
        $where .= " AND sc.status IN ('APPLIED','RELEASED')";
    } elseif ($tab === 'archived') {
        $where .= " AND sc.status = 'ARCHIVED'";
    } else {
        $where .= " AND sc.status = :tab";
        $params[':tab'] = strtoupper($tab);
    }
}
if ($search !== '') {
    $where .= " AND (e.first_name LIKE :s OR e.last_name LIKE :s OR e.employee_no LIKE :s)";
    $params[':s'] = "%$search%";
}

// ── Pagination ─────────────────────────────────────────────────────────────
$perPage = 15;
$page    = max(1, (int)($_GET['page'] ?? 1));
$offset  = ($page - 1) * $perPage;

$cntStmt = $pdo->prepare("SELECT COUNT(*) FROM service_credits sc
    JOIN employees e ON e.employee_id=sc.employee_id $where");
$cntStmt->execute($params);
$total    = (int)$cntStmt->fetchColumn();
$pages    = max(1, (int)ceil($total / $perPage));

// ── Records ────────────────────────────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT sc.*,
           CONCAT(e.first_name,' ',e.last_name)  AS employee_name, e.employee_no,
           p.position_name, d.department_name,
           CONCAT(ua.first_name,' ',ua.last_name) AS approved_by_name,
           CONCAT(uc.first_name,' ',uc.last_name) AS created_by_name,
           tp.period_name AS target_period_name,
           tp.pay_period_start AS target_period_start,
           tp.pay_period_end   AS target_period_end,
           tp.pay_date         AS target_pay_date,
           (SELECT MIN(scd.work_date) FROM service_credit_dates scd WHERE scd.service_credit_id = sc.service_credit_id) AS first_date,
           (SELECT MAX(scd.work_date) FROM service_credit_dates scd WHERE scd.service_credit_id = sc.service_credit_id) AS last_date,
           (SELECT COUNT(*)           FROM service_credit_dates scd WHERE scd.service_credit_id = sc.service_credit_id) AS date_count
    FROM service_credits sc
    JOIN employees e    ON e.employee_id   = sc.employee_id
    JOIN positions p    ON p.position_id   = e.position_id
    JOIN departments d  ON d.department_id = e.department_id
    LEFT JOIN employees ua      ON ua.employee_id = (SELECT employee_id FROM users WHERE user_id = sc.approved_by   LIMIT 1)
    LEFT JOIN employees uc      ON uc.employee_id = (SELECT employee_id FROM users WHERE user_id = sc.created_by    LIMIT 1)
    LEFT JOIN payroll_periods tp ON tp.period_id  = sc.target_period_id
    $where
    ORDER BY sc.updated_at DESC, sc.created_at DESC
    LIMIT :lim OFFSET :off
");
foreach ($params as $k => $v) $stmt->bindValue($k, $v);
$stmt->bindValue(':lim',  $perPage, PDO::PARAM_INT);
$stmt->bindValue(':off',  $offset,  PDO::PARAM_INT);
$stmt->execute();
$records = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Batch-load child date rows ─────────────────────────────────────────────
$scDates = [];
if (!empty($records)) {
    $scIds = array_column($records, 'service_credit_id');
    $ph    = implode(',', array_fill(0, count($scIds), '?'));
    $dSt   = $pdo->prepare("
        SELECT sc_date_id, service_credit_id, work_date,
               CAST(days AS CHAR) AS days,
               CAST(equivalent_pay AS CHAR) AS equivalent_pay,
               status, rejection_reason
        FROM service_credit_dates
        WHERE service_credit_id IN ($ph)
        ORDER BY work_date ASC
    ");
    $dSt->execute($scIds);
    foreach ($dSt->fetchAll(PDO::FETCH_ASSOC) as $d) {
        $scDates[$d['service_credit_id']][] = [
            'sc_date_id'       => (int)$d['sc_date_id'],
            'work_date'        => $d['work_date'],
            'days'             => (float)$d['days'],
            'equivalent_pay'   => (float)$d['equivalent_pay'],
            'status'           => $d['status'],
            'rejection_reason' => $d['rejection_reason'],
        ];
    }
}

// ── Batch-load audit logs for activity history ─────────────────────────────
$scAudit = [];
if (!empty($records)) {
    $scIds = array_column($records, 'service_credit_id');
    $ph    = implode(',', array_fill(0, count($scIds), '?'));
    $aSt   = $pdo->prepare("
        SELECT al.record_id AS service_credit_id,
               al.action, al.description, al.created_at,
               CONCAT(eu.first_name,' ',eu.last_name) AS actor_name
        FROM audit_logs al
        LEFT JOIN users    u  ON u.user_id     = al.user_id
        LEFT JOIN employees eu ON eu.employee_id = u.employee_id
        WHERE al.table_name = 'service_credits'
          AND al.record_id IN ($ph)
        ORDER BY al.created_at ASC
    ");
    $aSt->execute($scIds);
    foreach ($aSt->fetchAll(PDO::FETCH_ASSOC) as $a) {
        $scAudit[$a['service_credit_id']][] = [
            'action'      => $a['action'],
            'description' => $a['description'],
            'created_at'  => $a['created_at'],
            'actor_name'  => $a['actor_name'],
        ];
    }
}

// ── Open ACCRUED_PAY periods (backward-compat: fall back to all OPEN if column missing) ──
$openPeriods = [];
$hasPeriodTypeCol = false;
try {
    $hasPeriodTypeCol = (bool)$pdo->query("
        SELECT COUNT(*) FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME   = 'payroll_periods'
          AND COLUMN_NAME  = 'period_type'
    ")->fetchColumn();
} catch (PDOException $_e) {}

if ($hasPeriodTypeCol) {
    $openPeriods = $pdo->query("
        SELECT period_id, period_name, pay_period_start, pay_period_end, pay_date
        FROM payroll_periods
        WHERE status = 'OPEN' AND period_type = 'ACCRUED_PAY'
        ORDER BY pay_period_start ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
} else {
    $openPeriods = $pdo->query("
        SELECT period_id, period_name, pay_period_start, pay_period_end, pay_date
        FROM payroll_periods
        WHERE status = 'OPEN'
        ORDER BY pay_period_start ASC
    ")->fetchAll(PDO::FETCH_ASSOC);
}

// ── Employee dropdown (for create/edit modal) ───────────────────────────────
$employees = $pdo->query("
    SELECT e.employee_id, CONCAT(e.first_name,' ',e.last_name) AS full_name, p.position_name,
           COALESCE(NULLIF(ec.daily_rate,0), ROUND(ec.monthly_salary/22,2)) AS daily_rate
    FROM employees e
    JOIN positions p ON p.position_id=e.position_id
    LEFT JOIN employee_compensations ec ON ec.employee_id=e.employee_id AND ec.is_active=1
    WHERE e.employee_status='ACTIVE'
    ORDER BY e.last_name, e.first_name
")->fetchAll(PDO::FETCH_ASSOC);

// ── Flash ──────────────────────────────────────────────────────────────────
$flashOk  = $_SESSION['sc_success'] ?? '';
$flashErr = $_SESSION['sc_error']   ?? '';
unset($_SESSION['sc_success'], $_SESSION['sc_error']);

$pageTitle = 'Service Credits';
$extraCSS  = [BASE_URL . 'assets/css/service-credits.css'];
require_once __DIR__ . '/../../includes/head.php';
?>
<body>
<div class="layout">
<?php include __DIR__ . '/../../includes/sidebar.php'; ?>
<div class="main">
<?php include __DIR__ . '/../../includes/header.php'; ?>
<div class="main-content">

  <!-- Page header -->
  <div class="sc-page-header">
    <div class="sc-page-header-left">
      <div>
        <h1>Service Credits</h1>
        <p>Track extra work rendered beyond school days — approved credits become Additional Assignment Payment.</p>
      </div>
    </div>
    <button class="sc-btn-primary" id="btnCreate">
      <i class="fa fa-plus"></i> Create Service Credit
    </button>
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

  <!-- Stat cards -->
  <div class="sc-stats">
    <div class="sc-stat-card">
      <div class="sc-stat-icon sc-stat-icon--amber"><i class="fa fa-clock"></i></div>
      <div>
        <div class="sc-stat-num"><?= $pendingCount ?></div>
        <div class="sc-stat-label">Pending Approval</div>
        <div class="sc-stat-sub">₱<?= number_format($pendingPay, 2) ?> total</div>
      </div>
    </div>
    <div class="sc-stat-card">
      <div class="sc-stat-icon sc-stat-icon--green"><i class="fa fa-circle-check"></i></div>
      <div>
        <div class="sc-stat-num">₱<?= number_format($approvedPay, 2) ?></div>
        <div class="sc-stat-label">Approved — Awaiting Payroll</div>
        <div class="sc-stat-sub"><?= $approvedCount ?> credit<?= $approvedCount!==1?'s':'' ?></div>
      </div>
    </div>
    <div class="sc-stat-card">
      <div class="sc-stat-icon sc-stat-icon--blue"><i class="fa fa-file-invoice-dollar"></i></div>
      <div>
        <div class="sc-stat-num">₱<?= number_format($appliedPay, 2) ?></div>
        <div class="sc-stat-label">Applied to Payroll</div>
        <div class="sc-stat-sub"><?= $appliedCount ?> credit<?= $appliedCount!==1?'s':'' ?></div>
      </div>
    </div>
  </div>

  <!-- Tabs -->
  <div class="sc-tabs-bar">
    <?php
    $tabs = [
      'all'      => ['All',       $allCount,      ''],
      'draft'    => ['Draft',     $draftCount,    'gray'],
      'pending'  => ['Pending',   $pendingCount,  'amber'],
      'approved' => ['Approved',  $approvedCount, 'green'],
      'applied'  => ['In Payroll',$appliedCount,  'blue'],
      'rejected' => ['Rejected',  $rejectedCount, 'red'],
      'archived' => ['Archived',  $archivedCount, 'slate'],
    ];
    foreach ($tabs as $key => [$label, $cnt, $color]):
      $active = $tab === $key ? 'active' : '';
      $url = '?' . http_build_query(['tab'=>$key,'search'=>$search,'sy'=>$syFilter]);
    ?>
    <a href="<?= $url ?>" class="sc-tab <?= $active ?>">
      <?= $label ?>
      <?php if ($cnt > 0): ?>
        <span class="sc-tab-badge sc-tab-badge--<?= $color ?>"><?= $cnt ?></span>
      <?php endif; ?>
    </a>
    <?php endforeach; ?>
  </div>

  <!-- Filter / search bar -->
  <form method="GET" class="sc-filter-bar" id="filterForm">
    <input type="hidden" name="tab" value="<?= htmlspecialchars($tab) ?>">
    <div class="sc-filter-bar-left">
      <div class="sc-search-wrap">
        <i class="fa fa-magnifying-glass"></i>
        <input type="text" name="search" placeholder="Search employee…"
               value="<?= htmlspecialchars($search) ?>" class="sc-search-input" id="scSearch">
      </div>
      <!-- School year filter -->
      <select name="sy" class="sc-select" onchange="this.form.submit()">
        <option value="all" <?= $syFilter === 'all' ? 'selected' : '' ?>>All School Years</option>
        <?php foreach ($allSYs as $sy): ?>
        <option value="<?= htmlspecialchars($sy) ?>" <?= $syFilter === $sy ? 'selected' : '' ?>>
          SY <?= htmlspecialchars($sy) ?><?= $sy === $currentSY ? ' (Current)' : '' ?>
        </option>
        <?php endforeach; ?>
      </select>
    </div>
  </form>

  <!-- Table -->
  <div class="sc-card">
    <?php if (empty($records)): ?>
    <div class="sc-empty">
      <i class="fa fa-medal"></i>
      <p>No service credits found</p>
      <small><?= $tab==='all' ? 'Click "Create Service Credit" to get started.' : "No $tab records for the selected period." ?></small>
    </div>
    <?php else: ?>
    <div class="sc-table-wrap">
      <table class="sc-table">
        <thead>
          <tr>
            <th>Employee</th>
            <th>Work Dates</th>
            <th>Total Days</th>
            <th>Amount</th>
            <th>Status</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($records as $r):
          $badges = [
            'DRAFT'              => ['sc-badge--draft',    'Draft'],
            'PENDING'            => ['sc-badge--pending',  'Pending'],
            'APPROVED'           => ['sc-badge--approved', 'Approved'],
            'PARTIALLY_APPROVED' => ['sc-badge--approved', 'Approved'],  // treated same as Approved
            'APPLIED'            => ['sc-badge--applied',  'In Payroll'],
            'RELEASED'           => ['sc-badge--released', 'Released'],
            'REJECTED'           => ['sc-badge--rejected', 'Rejected'],
            'ARCHIVED'           => ['sc-badge--archived', 'Archived'],
          ];
          [$bdgClass, $bdgLabel] = $badges[$r['status']] ?? ['sc-badge--draft', $r['status']];
          $canEdit     = in_array($r['status'], ['DRAFT','REJECTED']);
          $canDelete   = $r['status'] === 'DRAFT';
          $canSubmit   = $r['status'] === 'DRAFT';
          $canResubmit = $r['status'] === 'REJECTED';
          $canArchive  = in_array($r['status'], ['DRAFT','REJECTED','PARTIALLY_APPROVED','APPLIED','RELEASED']);
          $canRestore  = $r['status'] === 'ARCHIVED';

          $rDates = $scDates[$r['service_credit_id']] ?? [];
          $rAudit = $scAudit[$r['service_credit_id']] ?? [];
          $rData  = array_merge($r, ['dates' => $rDates, 'audit' => $rAudit]);
          $rJson  = htmlspecialchars(json_encode($rData), ENT_QUOTES);

          $dc = (int)($r['date_count'] ?? 0);
          $fd = $r['first_date'] ?? $r['work_date'] ?? null;
          $ld = $r['last_date']  ?? $r['work_date'] ?? null;
        ?>
        <tr>
          <td>
            <div class="sc-emp-cell">
              <div class="sc-emp-avatar"><?= strtoupper(substr($r['employee_name'],0,1) . substr(strrchr($r['employee_name'],' ')??'',1,1)) ?></div>
              <div>
                <span class="sc-emp-name"><?= htmlspecialchars($r['employee_name']) ?></span>
                <span class="sc-emp-pos"><?= htmlspecialchars($r['position_name']) ?></span>
              </div>
            </div>
          </td>
          <td>
            <?php if ($fd): ?>
              <?php if ($dc > 1 && $ld): ?>
                <?= date('M j', strtotime($fd)) ?> – <?= date('M j, Y', strtotime($ld)) ?>
                <br><small style="color:#94a3b8;"><?= $dc ?> dates</small>
              <?php else: ?>
                <?= date('M j, Y', strtotime($fd)) ?>
              <?php endif; ?>
            <?php else: ?>
              <span class="sc-na">—</span>
            <?php endif; ?>
          </td>
          <td><?= number_format((float)$r['days'],1) ?> day<?= $r['days']!=1?'s':'' ?></td>
          <td class="sc-pay-cell">₱<?= number_format((float)$r['equivalent_pay'],2) ?></td>
          <td><span class="sc-badge <?= $bdgClass ?>"><?= $bdgLabel ?></span></td>
          <td>
            <div class="sc-action-group">
              <button class="sc-icon-btn sc-icon-btn--view" title="View details"
                onclick="openViewModal(<?= $rJson ?>)">
                <i class="fa fa-eye"></i>
              </button>
              <?php if ($canEdit): ?>
              <button class="sc-icon-btn sc-icon-btn--edit" title="Edit"
                onclick="openEditModal(<?= $rJson ?>)">
                <i class="fa fa-pen"></i>
              </button>
              <?php endif; ?>
              <?php if ($canSubmit): ?>
              <form method="POST" action="<?= BASE_URL ?>actions/service-credits-action.php" style="display:inline">
                <input type="hidden" name="action" value="resubmit">
                <input type="hidden" name="service_credit_id" value="<?= $r['service_credit_id'] ?>">
                <button type="submit" class="sc-action-btn sc-action-btn--approve" title="Submit for approval">
                  <i class="fa fa-paper-plane"></i> Submit
                </button>
              </form>
              <?php endif; ?>
              <?php if ($canDelete): ?>
              <form method="POST" action="<?= BASE_URL ?>actions/service-credits-action.php" style="display:inline"
                    onsubmit="return confirm('Delete this draft? This cannot be undone.')">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="service_credit_id" value="<?= $r['service_credit_id'] ?>">
                <button type="submit" class="sc-icon-btn" title="Delete draft" style="color:#ef4444;border-color:#fca5a5;">
                  <i class="fa fa-trash"></i>
                </button>
              </form>
              <?php endif; ?>
              <?php if ($canResubmit): ?>
              <form method="POST" action="<?= BASE_URL ?>actions/service-credits-action.php" style="display:inline">
                <input type="hidden" name="action" value="resubmit">
                <input type="hidden" name="service_credit_id" value="<?= $r['service_credit_id'] ?>">
                <button type="submit" class="sc-icon-btn sc-icon-btn--resubmit" title="Resubmit for approval">
                  <i class="fa fa-rotate-right"></i>
                </button>
              </form>
              <?php endif; ?>
              <?php if ($canArchive): ?>
              <form method="POST" action="<?= BASE_URL ?>actions/service-credits-action.php" style="display:inline">
                <input type="hidden" name="action" value="archive">
                <input type="hidden" name="service_credit_id" value="<?= $r['service_credit_id'] ?>">
                <button type="submit" class="sc-icon-btn sc-icon-btn--archive" title="Move to archive">
                  <i class="fa fa-box-archive"></i>
                </button>
              </form>
              <?php endif; ?>
              <?php if ($canRestore): ?>
              <form method="POST" action="<?= BASE_URL ?>actions/service-credits-action.php" style="display:inline">
                <input type="hidden" name="action" value="restore">
                <input type="hidden" name="service_credit_id" value="<?= $r['service_credit_id'] ?>">
                <button type="submit" class="sc-icon-btn sc-icon-btn--restore" title="Restore to draft">
                  <i class="fa fa-rotate-left"></i>
                </button>
              </form>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>

    <!-- Pagination -->
    <?php if ($pages > 1): ?>
    <div class="sc-pagination">
      <span>Showing <?= $offset+1 ?>–<?= min($offset+$perPage,$total) ?> of <?= $total ?></span>
      <div class="sc-pagination-btns">
        <?php if ($page>1): ?>
        <a href="?<?= http_build_query(['tab'=>$tab,'search'=>$search,'sy'=>$syFilter,'page'=>$page-1]) ?>" class="sc-page-btn"><i class="fa fa-chevron-left"></i></a>
        <?php endif; ?>
        <?php for ($pg=max(1,$page-2);$pg<=min($pages,$page+2);$pg++): ?>
        <a href="?<?= http_build_query(['tab'=>$tab,'search'=>$search,'sy'=>$syFilter,'page'=>$pg]) ?>"
           class="sc-page-btn <?= $pg===$page?'active':'' ?>"><?= $pg ?></a>
        <?php endfor; ?>
        <?php if ($page<$pages): ?>
        <a href="?<?= http_build_query(['tab'=>$tab,'search'=>$search,'sy'=>$syFilter,'page'=>$page+1]) ?>" class="sc-page-btn"><i class="fa fa-chevron-right"></i></a>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>
    <?php endif; ?>
  </div>

</div><!-- .main-content -->
</div><!-- .main -->
</div><!-- .layout -->

<!-- ══ Modals ══ -->
<?php include __DIR__ . '/modals/modal-create.php'; ?>
<?php include __DIR__ . '/modals/modal-view.php'; ?>

<script>
const BASE_URL      = '<?= BASE_URL ?>';
const SC_OPEN_PERIODS = <?= json_encode($openPeriods) ?>;
</script>
<script src="<?= BASE_URL ?>assets/js/service-credits.js"></script>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
