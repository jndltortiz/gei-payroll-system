<?php
/**
 * modules/principal/loan-approval/index.php
 * Principal Portal — Loan Approval page
 *
 * Mirrors the admin loans portal (modules/loans/index.php) in structure,
 * but scoped to the principal's approval workflow:
 *   - PENDING loans awaiting principal sign-off
 *   - ACTIVE loans overview (read-only, no add/edit)
 *   - History tab (DENIED / COMPLETED)
 *
 * Auth: requirePrincipal() — principal and special assistant only.
 */
require_once __DIR__ . '/../../../config/config.php';
require_once __DIR__ . '/../../../includes/auth.php';
requirePrincipal();

// ── Tab & filters ────────────────────────────────────────────────
$tab          = $_GET['tab']    ?? 'pending';
$typeFilter   = (int)($_GET['type_id'] ?? 0);
$search       = trim($_GET['search']   ?? '');
$histStatus   = $_GET['hist_status']   ?? 'all'; // for history tab

$uid = $_SESSION['user']['user_id'] ?? null;

// ── Loan types for dropdown ──────────────────────────────────────
$loanTypes = $pdo->query("SELECT * FROM loan_types WHERE is_active=1 ORDER BY loan_name")->fetchAll();

// ── Build WHERE helpers ──────────────────────────────────────────
function buildSearchWhere(string $base, int $typeFilter, string $search, array &$params): string
{
    $w = $base;
    if ($typeFilter) { $w .= " AND el.loan_type_id = :t"; $params[':t'] = $typeFilter; }
    if ($search)     { $w .= " AND (e.first_name LIKE :q OR e.last_name LIKE :q OR CONCAT(e.first_name,' ',e.last_name) LIKE :q)";
                       $params[':q'] = "%$search%"; }
    return $w;
}

// ── PENDING loans (awaiting principal approval) ──────────────────
$pendingParams = [];
$pendingWhere  = buildSearchWhere("el.status = 'PENDING'", $typeFilter, $search, $pendingParams);
$pendingStmt   = $pdo->prepare("
    SELECT el.*, lt.loan_name,
           CONCAT(e.first_name,' ',e.last_name) AS employee_name,
           p.position_name, d.department_name,
           ec.monthly_salary, e.hire_date
    FROM employee_loans el
    JOIN employees e   ON el.employee_id  = e.employee_id
    JOIN loan_types lt ON el.loan_type_id = lt.loan_type_id
    LEFT JOIN positions   p ON e.position_id   = p.position_id
    LEFT JOIN departments d ON e.department_id = d.department_id
    LEFT JOIN employee_compensations ec ON e.employee_id = ec.employee_id AND ec.is_active = 1
    WHERE $pendingWhere
    ORDER BY el.created_at ASC
");
$pendingStmt->execute($pendingParams);
$pendingLoans = $pendingStmt->fetchAll();

// ── Summary stats ────────────────────────────────────────────────
$awaitingCount    = $pdo->query("SELECT COUNT(*) FROM employee_loans WHERE status='PENDING'")->fetchColumn();
$pendingAmount    = $pdo->query("SELECT COALESCE(SUM(total_amount),0) FROM employee_loans WHERE status='PENDING'")->fetchColumn();
$approvedByMe     = $pdo->prepare("SELECT COUNT(*) FROM employee_loans WHERE approved_by=? AND status IN ('ACTIVE','COMPLETED') AND MONTH(approved_at)=MONTH(NOW()) AND YEAR(approved_at)=YEAR(NOW())");
$approvedByMe->execute([$uid]);
$approvedThisMonth = $approvedByMe->fetchColumn();

// ── ACTIVE loans ─────────────────────────────────────────────────
$actParams = [];
$actWhere  = buildSearchWhere("el.status = 'ACTIVE'", $typeFilter, $search, $actParams);
$actStmt   = $pdo->prepare("
    SELECT el.*, lt.loan_name,
           CONCAT(e.first_name,' ',e.last_name) AS employee_name,
           p.position_name, d.department_name
    FROM employee_loans el
    JOIN employees e   ON el.employee_id  = e.employee_id
    JOIN loan_types lt ON el.loan_type_id = lt.loan_type_id
    LEFT JOIN positions   p ON e.position_id   = p.position_id
    LEFT JOIN departments d ON e.department_id = d.department_id
    WHERE $actWhere
    ORDER BY el.start_date DESC
");
$actStmt->execute($actParams);
$activeLoans = $actStmt->fetchAll();

$totalActive      = $pdo->query("SELECT COUNT(*) FROM employee_loans WHERE status='ACTIVE'")->fetchColumn();
$totalOutstanding = $pdo->query("SELECT COALESCE(SUM(balance_amount),0) FROM employee_loans WHERE status='ACTIVE'")->fetchColumn();
$totalMonthlyDed  = $pdo->query("SELECT COALESCE(SUM(monthly_deduction),0) FROM employee_loans WHERE status='ACTIVE'")->fetchColumn();

// ── HISTORY tab ──────────────────────────────────────────────────
$histParams = [];
$histBase   = "el.status IN ('DENIED','COMPLETED')";
if ($histStatus !== 'all' && in_array($histStatus, ['DENIED','COMPLETED'])) {
    $histBase = "el.status = :hs"; $histParams[':hs'] = $histStatus;
}
$histWhere = buildSearchWhere($histBase, $typeFilter, $search, $histParams);
$histStmt  = $pdo->prepare("
    SELECT el.*, lt.loan_name,
           CONCAT(e.first_name,' ',e.last_name) AS employee_name,
           p.position_name, d.department_name,
           u.username AS actioned_by_name
    FROM employee_loans el
    JOIN employees e   ON el.employee_id  = e.employee_id
    JOIN loan_types lt ON el.loan_type_id = lt.loan_type_id
    LEFT JOIN positions   p ON e.position_id   = p.position_id
    LEFT JOIN departments d ON e.department_id = d.department_id
    LEFT JOIN users u ON u.user_id = el.approved_by
    WHERE $histWhere
    ORDER BY el.updated_at DESC
    LIMIT 100
");
$histStmt->execute($histParams);
$historyLoans = $histStmt->fetchAll();

// ── Helpers ──────────────────────────────────────────────────────
function loanInitials(string $name): string {
    $parts = explode(' ', trim($name));
    return strtoupper(substr($parts[0],0,1) . substr(end($parts),0,1));
}
function pesos(float $v): string {
    return '₱' . number_format($v, 2);
}
function loanProgress(float $total, float $balance): int {
    return $total > 0 ? (int)round(($total - $balance) / $total * 100) : 0;
}
function remainingMonths(float $balance, float $monthly): int {
    return ($monthly > 0 && $balance > 0) ? (int)ceil($balance / $monthly) : 0;
}

$pageTitle = 'Loan Approvals';
$extraCSS  = [BASE_URL . 'assets/css/loans.css', BASE_URL . 'assets/css/principal-loan-approval.css'];
require_once __DIR__ . '/../../../includes/head.php';

?>
<body>
<div class="layout">
<?php include __DIR__ . '/../../../includes/principal-sidebar.php'; ?>
<div class="main">
  <!-- ── Page Header ── -->
  <div class="header">
    <div style="display:flex;align-items:center;gap:10px;">
      <i class="fa fa-file-invoice-dollar" style="color:#0f766e;font-size:18px;"></i>
      <div>
        <div style="font-size:15px;font-weight:700;color:#0f172a;">Principal Portal</div>
        <div style="font-size:12px;color:#64748b;">Great Eastern Institute</div>
      </div>
    </div>
    <div style="margin-left:auto;display:flex;align-items:center;gap:12px;">
      <button style="background:none;border:none;cursor:pointer;position:relative;">
        <i class="fa fa-bell" style="font-size:16px;color:#64748b;"></i>
      </button>
      <div style="text-align:right;">
        <div style="font-size:14px;font-weight:600;color:#0f172a;">
          <?= htmlspecialchars(($_SESSION['user']['first_name'] ?? '') . ' ' . ($_SESSION['user']['last_name'] ?? '')) ?>
        </div>
        <div style="font-size:11px;color:#64748b;">School Principal</div>
      </div>
      <div class="header-avatar">
        <?= strtoupper(substr($_SESSION['user']['first_name'] ?? 'P', 0, 1) .
                       substr($_SESSION['user']['last_name']  ?? 'R', 0, 1)) ?>
      </div>
    </div>
  </div>
<div class="main-content">

<div class="loans-page">

  <!-- PAGE HEADER -->
  <div class="loans-header">
    <div>
      <h1>Loan Verification</h1>
      <p>Review and verify submitted employee loan documents</p>
    </div>
  </div>

  <!-- SUMMARY CARDS -->
  <div class="loans-stats pla-stats">
    <div class="stat-card">
      <div class="stat-num <?= $awaitingCount > 0 ? 'text-warning' : '' ?>"><?= $awaitingCount ?></div>
      <div class="stat-label">Awaiting Verification</div>
      <div class="stat-icon stat-icon--yellow"><i class="fa fa-clock"></i></div>
    </div>
    <div class="stat-card">
      <div class="stat-num text-success"><?= $approvedThisMonth ?></div>
      <div class="stat-label">Verified This Month</div>
      <div class="stat-icon stat-icon--green"><i class="fa fa-circle-check"></i></div>
    </div>
    <div class="stat-card">
      <div class="stat-num text-danger"><?= pesos((float)$totalOutstanding) ?></div>
      <div class="stat-label">Total Outstanding</div>
      <div class="stat-icon stat-icon--red"><i class="fa fa-triangle-exclamation"></i></div>
    </div>
    <div class="stat-card">
      <div class="stat-num text-teal"><?= $totalActive ?></div>
      <div class="stat-label">Active Loans</div>
      <div class="stat-icon stat-icon--teal"><i class="fa fa-credit-card"></i></div>
    </div>
  </div>

  <!-- ALERT BANNER -->
  <?php if ($awaitingCount > 0): ?>
  <div class="pla-alert-banner">
    <i class="fa fa-circle-info"></i>
    <div>
      <strong><?= $awaitingCount ?> submitted loan document<?= $awaitingCount > 1 ? 's' : '' ?> awaiting verification</strong>
      <span>Total pending amount: <strong><?= pesos((float)$pendingAmount) ?></strong> &bull; Please review and verify or reject the submitted documents</span>
    </div>
  </div>
  <?php endif; ?>

  <!-- TABS -->
  <div class="loans-tabs">
    <a href="?tab=pending&type_id=<?= $typeFilter ?>&search=<?= urlencode($search) ?>"
       class="loans-tab <?= $tab==='pending'?'active':'' ?>">
      Pending Verification
      <?php if ($awaitingCount > 0): ?>
      <span class="tab-badge"><?= $awaitingCount ?></span>
      <?php endif; ?>
    </a>
    <a href="?tab=active&type_id=<?= $typeFilter ?>&search=<?= urlencode($search) ?>"
       class="loans-tab <?= $tab==='active'?'active':'' ?>">
      Active Loans
      <span class="tab-badge tab-badge--green"><?= $totalActive ?></span>
    </a>
    <a href="?tab=history&type_id=<?= $typeFilter ?>&search=<?= urlencode($search) ?>"
       class="loans-tab <?= $tab==='history'?'active':'' ?>">
      Loan History &amp; Audit
    </a>
  </div>

  <!-- FILTER BAR -->
  <form class="loans-filter-bar" method="GET" action="">
    <input type="hidden" name="tab" value="<?= $tab ?>">
    <div class="filter-search">
      <i class="fa fa-search"></i>
      <input type="text" name="search" placeholder="Search employee…"
             value="<?= htmlspecialchars($search) ?>">
    </div>
    <select name="type_id">
      <option value="">All Types</option>
      <?php foreach ($loanTypes as $lt): ?>
      <option value="<?= $lt['loan_type_id'] ?>" <?= $typeFilter==$lt['loan_type_id']?'selected':'' ?>>
        <?= htmlspecialchars($lt['loan_name']) ?>
      </option>
      <?php endforeach; ?>
    </select>
    <?php if ($tab === 'history'): ?>
    <div class="status-pills">
      <?php foreach (['all'=>'All','COMPLETED'=>'Completed','DENIED'=>'Rejected','CANCELLED'=>'Cancelled','ARCHIVED'=>'Archived'] as $v => $l): ?>
      <a href="?tab=history&hist_status=<?= $v ?>&type_id=<?= $typeFilter ?>&search=<?= urlencode($search) ?>"
         class="status-pill <?= $histStatus===$v?'active':'' ?>"><?= $l ?></a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
    <button type="submit" class="btn-outline btn-sm" title="Apply filter" style="padding:7px 12px;">
      <i class="fa fa-filter"></i>
    </button>
    <?php if ($search || $typeFilter): ?>
    <a href="?tab=<?= $tab ?>" class="btn-outline btn-sm" title="Clear" style="padding:7px 12px;">
      <i class="fa fa-times"></i>
    </a>
    <?php endif; ?>
  </form>

  <!-- ══ PENDING TAB ══════════════════════════════════════════════ -->
  <?php if ($tab === 'pending'): ?>

  <?php if (empty($pendingLoans)): ?>
  <div class="loans-empty">
    <i class="fa fa-inbox"></i>
    <p>No submitted loan documents pending verification. All caught up!</p>
  </div>
  <?php else: ?>
  <div class="app-list">
    <?php foreach ($pendingLoans as $loan):
      $initials = loanInitials($loan['employee_name']);
      $termMonths = $loan['monthly_deduction'] > 0
        ? (int)ceil($loan['total_payable'] / $loan['monthly_deduction']) : 0;
      $pctSal = $loan['monthly_salary'] > 0
        ? round(($loan['monthly_deduction'] / $loan['monthly_salary']) * 100, 1) : 0;
    ?>
    <div class="app-card pla-app-card">
      <div class="app-card-left">
        <div class="emp-avatar"><?= $initials ?></div>
        <div class="app-emp-info">
          <strong><?= htmlspecialchars($loan['employee_name']) ?></strong>
          <span><?= htmlspecialchars($loan['department_name'] ?? '') ?></span>
          <span><?= htmlspecialchars($loan['position_name'] ?? '') ?></span>
        </div>
      </div>
      <div class="app-card-center">
        <div class="app-loan-type">
          <i class="fa fa-fire" style="color:#f59e0b"></i>
          <?= htmlspecialchars($loan['loan_name']) ?>
        </div>
        <div class="app-amount"><?= pesos((float)$loan['total_amount']) ?></div>
        <div class="app-terms">
          <?= $termMonths ?> months &bull; <?= pesos((float)$loan['monthly_deduction']) ?>/month amortization
        </div>
        <div class="app-submitted">
          Submitted <?= date('M d, Y', strtotime($loan['created_at'])) ?>
        </div>
        <?php if ($loan['reason']): ?>
        <div class="app-reason"><?= htmlspecialchars($loan['reason']) ?></div>
        <?php endif; ?>
      </div>
      <div class="app-card-right">
        <span class="pla-badge pla-badge--pending">Awaiting Verification</span>
        <button class="btn-primary btn-sm" onclick="openPrincipalReview(<?= $loan['loan_id'] ?>)">
          <i class="fa fa-eye"></i> Review &amp; Verify
        </button>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- ══ ACTIVE LOANS TAB ══════════════════════════════════════════ -->
  <?php elseif ($tab === 'active'): ?>

  <!-- Active sub-summary -->
  <div class="pla-active-summary">
    <div class="pla-as-card">
      <span class="pla-as-label">Total Outstanding Balance</span>
      <span class="pla-as-value text-danger"><?= pesos((float)$totalOutstanding) ?></span>
      <span class="pla-as-sub">Across <?= $totalActive ?> active loans</span>
    </div>
    <div class="pla-as-card">
      <span class="pla-as-label">Monthly Amortization</span>
      <span class="pla-as-value text-teal"><?= pesos((float)$totalMonthlyDed) ?></span>
      <span class="pla-as-sub">Total monthly amortization</span>
    </div>
    <div class="pla-as-card">
      <span class="pla-as-label">Active Loans</span>
      <span class="pla-as-value"><?= $totalActive ?></span>
      <span class="pla-as-sub">Being repaid</span>
    </div>
  </div>

  <?php if (empty($activeLoans)): ?>
  <div class="loans-empty">
    <i class="fa fa-inbox"></i><p>No active loans found.</p>
  </div>
  <?php else: ?>
  <div class="active-loans-table-wrap">
    <table class="active-loans-table">
      <thead>
        <tr>
          <th>Employee</th>
          <th>Loan Type</th>
          <th>Original Amount</th>
          <th>Outstanding</th>
          <th>Monthly Amortization</th>
          <th>Remaining</th>
          <th>Next Payment</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($activeLoans as $loan):
        $pct      = loanProgress((float)$loan['total_amount'], (float)$loan['balance_amount']);
        $rem      = remainingMonths((float)$loan['balance_amount'], (float)$loan['monthly_deduction']);
        $initials = loanInitials($loan['employee_name']);
        $paysMade = $loan['monthly_deduction'] > 0
            ? (int)floor(($loan['total_amount'] - $loan['balance_amount']) / $loan['monthly_deduction']) : 0;
        $nextPay  = $loan['start_date']
            ? date('M d, Y', strtotime($loan['start_date'] . " +{$paysMade} months")) : '—';
      ?>
      <tr>
        <td>
          <div style="display:flex;align-items:center;gap:10px">
            <div class="emp-avatar emp-avatar--sm"><?= $initials ?></div>
            <div>
              <span style="font-weight:600"><?= htmlspecialchars($loan['employee_name']) ?></span>
              <small style="display:block;color:#64748b"><?= htmlspecialchars($loan['department_name']??'') ?></small>
            </div>
          </div>
        </td>
        <td><?= htmlspecialchars($loan['loan_name']) ?></td>
        <td><?= pesos((float)$loan['total_amount']) ?></td>
        <td>
          <div class="outstanding-cell">
            <span style="color:#ef4444;font-weight:600"><?= pesos((float)$loan['balance_amount']) ?></span>
            <div class="progress-bar-wrap">
              <div class="progress-bar-fill" style="width:<?= $pct ?>%"></div>
            </div>
            <small><?= $pct ?>%</small>
          </div>
        </td>
        <td><?= pesos((float)$loan['monthly_deduction']) ?></td>
        <td><?= $rem ?> months</td>
        <td><?= $nextPay ?></td>
        <td>
          <button class="btn-icon" title="View details"
                  onclick="openPrincipalDetails(<?= $loan['loan_id'] ?>)">
            <i class="fa fa-eye"></i>
          </button>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <!-- ══ HISTORY TAB ══════════════════════════════════════════════ -->
  <?php else: ?>

  <?php if (empty($historyLoans)): ?>
  <div class="loans-empty">
    <i class="fa fa-file-lines"></i>
    <p>No loan history found.</p>
  </div>
  <?php else: ?>
  <div class="active-loans-table-wrap">
    <table class="active-loans-table">
      <thead>
        <tr>
          <th>Employee</th>
          <th>Loan Type</th>
          <th>Amount</th>
          <th>Status</th>
          <th>Date Actioned</th>
          <th>Notes</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($historyLoans as $hl):
        $initials = loanInitials($hl['employee_name']);
        $actDate  = $hl['approved_at'] ? date('M d, Y', strtotime($hl['approved_at'])) : '—';
      ?>
      <tr>
        <td>
          <div style="display:flex;align-items:center;gap:10px">
            <div class="emp-avatar emp-avatar--sm"><?= $initials ?></div>
            <div>
              <span style="font-weight:600"><?= htmlspecialchars($hl['employee_name']) ?></span>
              <small style="display:block;color:#64748b"><?= htmlspecialchars($hl['department_name']??'') ?></small>
            </div>
          </div>
        </td>
        <td><?= htmlspecialchars($hl['loan_name']) ?></td>
        <td><?= pesos((float)$hl['total_amount']) ?></td>
        <td>
          <?php if ($hl['status'] === 'DENIED'): ?>
            <span class="pla-badge pla-badge--denied">Denied</span>
          <?php elseif ($hl['status'] === 'COMPLETED'): ?>
            <span class="pla-badge pla-badge--completed">Completed</span>
          <?php else: ?>
            <span class="pla-badge pla-badge--muted"><?= $hl['status'] ?></span>
          <?php endif; ?>
        </td>
        <td><?= $actDate ?></td>
        <td style="color:#64748b;font-size:12px">
          <?= $hl['denied_reason'] ? htmlspecialchars($hl['denied_reason']) : '—' ?>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
  <?php endif; ?>

</div><!-- .loans-page -->
</div><!-- .main-content -->
</div><!-- .main -->
</div><!-- .layout -->

<?php include __DIR__ . '/modals/modal-principal-review.php'; ?>
<?php include __DIR__ . '/modals/modal-principal-details.php'; ?>

<script>
const BASE_URL   = '<?= BASE_URL ?>';
const LOAN_TYPES = <?= json_encode($loanTypes) ?>;
</script>
<script src="<?= BASE_URL ?>assets/js/loans.js"></script>
<script src="<?= BASE_URL ?>assets/js/principal-loan-approval.js"></script>
<?php include __DIR__ . '/../../../includes/footer.php'; ?>