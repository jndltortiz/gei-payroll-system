<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

// ── Active tab ─────────────────────────────────────────────────────────────
$tab    = $_GET['tab']    ?? 'all';
$search = trim($_GET['search'] ?? '');
$period = trim($_GET['period'] ?? '');
$validTabs = ['all','draft','pending','approved','applied','rejected'];
if (!in_array($tab, $validTabs)) $tab = 'all';

// ── Tab counts ─────────────────────────────────────────────────────────────
$counts = $pdo->query("
    SELECT status, COUNT(*) AS cnt
    FROM service_credits GROUP BY status
")->fetchAll(PDO::FETCH_KEY_PAIR);
$allCount      = array_sum($counts);
$draftCount    = $counts['DRAFT']    ?? 0;
$pendingCount  = $counts['PENDING']  ?? 0;
$approvedCount = $counts['APPROVED'] ?? 0;
$appliedCount  = ($counts['APPLIED'] ?? 0) + ($counts['RELEASED'] ?? 0);
$rejectedCount = $counts['REJECTED'] ?? 0;

// ── Stats ──────────────────────────────────────────────────────────────────
$pendingPay  = (float)$pdo->query("SELECT COALESCE(SUM(equivalent_pay),0) FROM service_credits WHERE status='PENDING'")->fetchColumn();
$approvedPay = (float)$pdo->query("SELECT COALESCE(SUM(equivalent_pay),0) FROM service_credits WHERE status='APPROVED'")->fetchColumn();
$appliedPay  = (float)$pdo->query("SELECT COALESCE(SUM(equivalent_pay),0) FROM service_credits WHERE status IN('APPLIED','RELEASED')")->fetchColumn();

// ── WHERE clause ───────────────────────────────────────────────────────────
$where  = "WHERE 1=1";
$params = [];

if ($tab !== 'all') {
    if ($tab === 'applied') {
        $where .= " AND sc.status IN ('APPLIED','RELEASED')";
    } else {
        $where .= " AND sc.status = :tab";
        $params[':tab'] = strtoupper($tab);
    }
}
if ($search !== '') {
    $where .= " AND (e.first_name LIKE :s OR e.last_name LIKE :s OR e.employee_no LIKE :s)";
    $params[':s'] = "%$search%";
}
if ($period !== '') {
    $where .= " AND sc.work_date LIKE :period";
    $params[':period'] = $period . '%';
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
           CONCAT(e.first_name,' ',e.last_name) AS employee_name, e.employee_no,
           p.position_name, d.department_name,
           CONCAT(u.first_name,' ',u.last_name) AS approved_by_name,
           (SELECT MIN(scd.work_date) FROM service_credit_dates scd WHERE scd.service_credit_id = sc.service_credit_id) AS first_date,
           (SELECT MAX(scd.work_date) FROM service_credit_dates scd WHERE scd.service_credit_id = sc.service_credit_id) AS last_date,
           (SELECT COUNT(*)           FROM service_credit_dates scd WHERE scd.service_credit_id = sc.service_credit_id) AS date_count
    FROM service_credits sc
    JOIN employees e   ON e.employee_id = sc.employee_id
    JOIN positions p   ON p.position_id = e.position_id
    JOIN departments d ON d.department_id = e.department_id
    LEFT JOIN employees u ON u.employee_id = (
        SELECT employee_id FROM users WHERE user_id = sc.approved_by LIMIT 1
    )
    $where
    ORDER BY sc.updated_at DESC, sc.created_at DESC
    LIMIT :lim OFFSET :off
");
foreach ($params as $k => $v) $stmt->bindValue($k, $v);
$stmt->bindValue(':lim',  $perPage, PDO::PARAM_INT);
$stmt->bindValue(':off',  $offset,  PDO::PARAM_INT);
$stmt->execute();
$records = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Batch-load child date rows for all records on this page ────────────────
$scDates = [];
if (!empty($records)) {
    $scIds = array_column($records, 'service_credit_id');
    $ph    = implode(',', array_fill(0, count($scIds), '?'));
    $dSt   = $pdo->prepare("
        SELECT service_credit_id, work_date,
               CAST(days AS CHAR) AS days,
               CAST(equivalent_pay AS CHAR) AS equivalent_pay
        FROM service_credit_dates
        WHERE service_credit_id IN ($ph)
        ORDER BY work_date ASC
    ");
    $dSt->execute($scIds);
    foreach ($dSt->fetchAll(PDO::FETCH_ASSOC) as $d) {
        $scDates[$d['service_credit_id']][] = [
            'work_date'      => $d['work_date'],
            'days'           => (float)$d['days'],
            'equivalent_pay' => (float)$d['equivalent_pay'],
        ];
    }
}

// ── Dropdowns ──────────────────────────────────────────────────────────────
$employees = $pdo->query("
    SELECT e.employee_id, CONCAT(e.first_name,' ',e.last_name) AS full_name, p.position_name,
           COALESCE(NULLIF(ec.daily_rate,0), ROUND(ec.monthly_salary/22,2)) AS daily_rate
    FROM employees e
    JOIN positions p ON p.position_id=e.position_id
    LEFT JOIN employee_compensations ec ON ec.employee_id=e.employee_id AND ec.is_active=1
    WHERE e.employee_status='ACTIVE'
    ORDER BY e.last_name, e.first_name
")->fetchAll(PDO::FETCH_ASSOC);

// ── Payroll periods for filter ──────────────────────────────────────────────
$periods = $pdo->query("SELECT period_id, period_name, pay_period_start, pay_period_end
    FROM payroll_periods ORDER BY pay_period_start DESC LIMIT 12")->fetchAll();

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
      <div class="sc-page-icon"><i class="fa fa-medal"></i></div>
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
      'all'      => ['All', $allCount,      ''],
      'draft'    => ['Draft', $draftCount,  'gray'],
      'pending'  => ['Pending', $pendingCount, 'amber'],
      'approved' => ['Approved', $approvedCount, 'green'],
      'applied'  => ['In Payroll', $appliedCount, 'blue'],
      'rejected' => ['Rejected', $rejectedCount, 'red'],
    ];
    foreach ($tabs as $key => [$label, $cnt, $color]):
      $active = $tab === $key ? 'active' : '';
      $url = '?' . http_build_query(['tab'=>$key,'search'=>$search,'period'=>$period]);
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
      <select name="period" class="sc-select" onchange="this.form.submit()">
        <option value="">All Periods</option>
        <?php foreach ($periods as $pp): ?>
        <option value="<?= substr($pp['pay_period_start'],0,7) ?>"
                <?= $period===substr($pp['pay_period_start'],0,7)?'selected':'' ?>>
          <?= htmlspecialchars($pp['period_name']) ?>
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
      <small><?= $tab==='all' ? 'Click "Create Service Credit" to get started.' : "No $tab records." ?></small>
    </div>
    <?php else: ?>
    <div class="sc-table-wrap">
      <table class="sc-table">
        <thead>
          <tr>
            <th>Employee</th>
            <th>Work Period</th>
            <th>Days</th>
            <th>Equiv. Pay</th>
            <th>Status</th>
            <th>Payroll</th>
            <th>Approved By</th>
            <th>Created</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($records as $r):
          $badges = [
            'DRAFT'    => ['sc-badge--draft',    'Draft'],
            'PENDING'  => ['sc-badge--pending',  'Pending'],
            'APPROVED' => ['sc-badge--approved', 'Approved'],
            'APPLIED'  => ['sc-badge--applied',  'In Payroll'],
            'RELEASED' => ['sc-badge--released', 'Released'],
            'REJECTED' => ['sc-badge--rejected', 'Rejected'],
          ];
          [$bdgClass, $bdgLabel] = $badges[$r['status']] ?? ['sc-badge--draft', $r['status']];
          $canEdit     = in_array($r['status'], ['DRAFT','REJECTED']);
          $canDelete   = $r['status'] === 'DRAFT';
          $canResubmit = in_array($r['status'], ['DRAFT','REJECTED']);
          $canApprove  = $r['status'] === 'PENDING';

          // Build data payload including child dates for JS modals
          $rDates = $scDates[$r['service_credit_id']] ?? [];
          $rData  = array_merge($r, ['dates' => $rDates]);
          $rJson  = htmlspecialchars(json_encode($rData), ENT_QUOTES);

          // Work period display
          $dc = (int)($r['date_count'] ?? 0);
          $fd = $r['first_date'] ?? $r['work_date'] ?? null;
          $ld = $r['last_date']  ?? $r['work_date'] ?? null;
        ?>
        <tr>
          <td>
            <div class="sc-emp-cell">
              <div class="sc-emp-avatar"><?= strtoupper(substr($r['employee_name'],0,1).substr(strrchr($r['employee_name'],' ')??'',1,1)) ?></div>
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
            <?php if ($r['payroll_id']): ?>
              <a href="<?= BASE_URL ?>modules/payroll/index.php"
                 class="sc-payroll-link" title="View payroll">#<?= $r['payroll_id'] ?></a>
            <?php else: ?>
              <span class="sc-na">—</span>
            <?php endif; ?>
          </td>
          <td><?= $r['approved_by_name'] ? htmlspecialchars($r['approved_by_name']) : '<span class="sc-na">—</span>' ?></td>
          <td><?= date('M j', strtotime($r['created_at'] ?? $r['work_date'])) ?></td>
          <td>
            <div class="sc-action-group">
              <!-- View details -->
              <button class="sc-icon-btn sc-icon-btn--view" title="View details"
                onclick="openViewModal(<?= $rJson ?>)">
                <i class="fa fa-eye"></i>
              </button>
              <?php if ($canApprove): ?>
              <!-- Approve inline -->
              <form method="POST" action="<?= BASE_URL ?>actions/service-credits-action.php"
                    style="display:inline"
                    onsubmit="return confirm('Approve this service credit for ₱<?= number_format((float)$r['equivalent_pay'],2) ?>?\nIt will be included in the next payroll as Additional Assignment Payment.')">
                <input type="hidden" name="action" value="approve">
                <input type="hidden" name="service_credit_id" value="<?= $r['service_credit_id'] ?>">
                <button type="submit" class="sc-icon-btn sc-icon-btn--approve" title="Approve">
                  <i class="fa fa-circle-check"></i>
                </button>
              </form>
              <!-- Reject -->
              <button class="sc-icon-btn sc-icon-btn--reject" title="Reject"
                onclick="openRejectModal(<?= $r['service_credit_id'] ?>,'<?= htmlspecialchars($r['employee_name']) ?>')">
                <i class="fa fa-circle-xmark"></i>
              </button>
              <?php endif; ?>
              <?php if ($canEdit): ?>
              <button class="sc-icon-btn sc-icon-btn--edit" title="Edit"
                onclick="openEditModal(<?= $rJson ?>)">
                <i class="fa fa-pen"></i>
              </button>
              <?php endif; ?>
              <?php if ($canResubmit && $r['status']==='REJECTED'): ?>
              <form method="POST" action="<?= BASE_URL ?>actions/service-credits-action.php"
                    style="display:inline"
                    onsubmit="return confirm('Resubmit this for approval?')">
                <input type="hidden" name="action" value="resubmit">
                <input type="hidden" name="service_credit_id" value="<?= $r['service_credit_id'] ?>">
                <button type="submit" class="sc-icon-btn sc-icon-btn--resubmit" title="Resubmit">
                  <i class="fa fa-rotate-right"></i>
                </button>
              </form>
              <?php endif; ?>
              <?php if ($canDelete): ?>
              <button class="sc-icon-btn sc-icon-btn--delete" title="Delete"
                onclick="openDeleteModal(<?= $r['service_credit_id'] ?>,'<?= htmlspecialchars($r['employee_name']) ?>')">
                <i class="fa fa-trash"></i>
              </button>
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
        <a href="?<?= http_build_query(['tab'=>$tab,'search'=>$search,'period'=>$period,'page'=>$page-1]) ?>" class="sc-page-btn"><i class="fa fa-chevron-left"></i></a>
        <?php endif; ?>
        <?php for ($pg=max(1,$page-2);$pg<=min($pages,$page+2);$pg++): ?>
        <a href="?<?= http_build_query(['tab'=>$tab,'search'=>$search,'period'=>$period,'page'=>$pg]) ?>"
           class="sc-page-btn <?= $pg===$page?'active':'' ?>"><?= $pg ?></a>
        <?php endfor; ?>
        <?php if ($page<$pages): ?>
        <a href="?<?= http_build_query(['tab'=>$tab,'search'=>$search,'period'=>$period,'page'=>$page+1]) ?>" class="sc-page-btn"><i class="fa fa-chevron-right"></i></a>
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
<?php include __DIR__ . '/modals/modal-reject.php'; ?>
<?php include __DIR__ . '/modals/modal-view.php'; ?>
<?php include __DIR__ . '/modals/modal-delete.php'; ?>

<script>const BASE_URL='<?= BASE_URL ?>';</script>
<script src="<?= BASE_URL ?>assets/js/service-credits.js"></script>
<?php include __DIR__ . '/../../includes/footer.php'; ?>