<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

// ── Tab ─────────────────────────────────────────────────────────────────────
$tab = $_GET['tab'] ?? 'applications';

// ── Filters ─────────────────────────────────────────────────────────────────
$statusFilter = $_GET['status']  ?? 'all';
$typeFilter   = (int)($_GET['type_id'] ?? 0);
$search       = trim($_GET['search'] ?? '');

// ── Loan types for dropdown ──────────────────────────────────────────────────
$loanTypes = $pdo->query("SELECT * FROM loan_types WHERE is_active=1 ORDER BY loan_name")->fetchAll();

// ── Applications (PENDING) ───────────────────────────────────────────────────
$appWhere  = "el.status = 'PENDING'";
$appParams = [];
if ($statusFilter !== 'all' && in_array($statusFilter, ['PENDING','DENIED'])) {
    $appWhere = "el.status = :s"; $appParams[':s'] = strtoupper($statusFilter);
}
if ($typeFilter) { $appWhere .= " AND el.loan_type_id = :t"; $appParams[':t'] = $typeFilter; }
if ($search)     { $appWhere .= " AND (e.first_name LIKE :q OR e.last_name LIKE :q OR CONCAT(e.first_name,' ',e.last_name) LIKE :q)"; $appParams[':q'] = "%$search%"; }

$appStmt = $pdo->prepare("
    SELECT el.*, lt.loan_name,
           CONCAT(e.first_name,' ',e.last_name) AS employee_name,
           p.position_name, d.department_name,
           ec.monthly_salary, e.hire_date
    FROM employee_loans el
    JOIN employees e   ON el.employee_id   = e.employee_id
    JOIN loan_types lt ON el.loan_type_id  = lt.loan_type_id
    LEFT JOIN positions   p ON e.position_id   = p.position_id
    LEFT JOIN departments d ON e.department_id = d.department_id
    LEFT JOIN employee_compensations ec ON e.employee_id = ec.employee_id AND ec.is_active = 1
    WHERE $appWhere ORDER BY el.created_at DESC
");
$appStmt->execute($appParams);
$applications = $appStmt->fetchAll();

// Application summary stats
$awaitingCount    = $pdo->query("SELECT COUNT(*) FROM employee_loans WHERE status='PENDING'")->fetchColumn();
$approvedThisMonth = $pdo->query("SELECT COUNT(*) FROM employee_loans WHERE status='ACTIVE' AND MONTH(approved_at)=MONTH(NOW()) AND YEAR(approved_at)=YEAR(NOW())")->fetchColumn();
$pendingAmount    = $pdo->query("SELECT COALESCE(SUM(total_amount),0) FROM employee_loans WHERE status='PENDING'")->fetchColumn();

// ── Active loans ─────────────────────────────────────────────────────────────
$actWhere  = "el.status = 'ACTIVE'";
$actParams = [];
if ($typeFilter) { $actWhere .= " AND el.loan_type_id = :t"; $actParams[':t'] = $typeFilter; }
if ($search)     { $actWhere .= " AND (e.first_name LIKE :q OR e.last_name LIKE :q OR CONCAT(e.first_name,' ',e.last_name) LIKE :q)"; $actParams[':q'] = "%$search%"; }

$actStmt = $pdo->prepare("
    SELECT el.*, lt.loan_name,
           CONCAT(e.first_name,' ',e.last_name) AS employee_name,
           p.position_name, d.department_name
    FROM employee_loans el
    JOIN employees e   ON el.employee_id   = e.employee_id
    JOIN loan_types lt ON el.loan_type_id  = lt.loan_type_id
    LEFT JOIN positions   p ON e.position_id   = p.position_id
    LEFT JOIN departments d ON e.department_id = d.department_id
    WHERE $actWhere ORDER BY el.start_date DESC
");
$actStmt->execute($actParams);
$activeLoans = $actStmt->fetchAll();

// Active summary stats
$totalActive      = $pdo->query("SELECT COUNT(*) FROM employee_loans WHERE status='ACTIVE'")->fetchColumn();
$totalOutstanding = $pdo->query("SELECT COALESCE(SUM(balance_amount),0) FROM employee_loans WHERE status='ACTIVE'")->fetchColumn();
$totalMonthlyDed  = $pdo->query("SELECT COALESCE(SUM(monthly_deduction),0) FROM employee_loans WHERE status='ACTIVE'")->fetchColumn();
$endingSoon       = $pdo->query("SELECT COUNT(*) FROM employee_loans WHERE status='ACTIVE' AND end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 3 MONTH)")->fetchColumn();

// ── Active employees for Add Loan form ────────────────────────────────────────
$empList = $pdo->query("
    SELECT e.employee_id, CONCAT(e.first_name,' ',e.last_name) AS full_name,
           p.position_name, d.department_name, ec.monthly_salary, e.hire_date, e.employment_type
    FROM employees e
    LEFT JOIN positions p ON e.position_id=p.position_id
    LEFT JOIN departments d ON e.department_id=d.department_id
    LEFT JOIN employee_compensations ec ON e.employee_id=ec.employee_id AND ec.is_active=1
    WHERE e.employee_status='ACTIVE'
    ORDER BY e.last_name, e.first_name
")->fetchAll();

$pageTitle = 'Loans Management';
$extraCSS  = [BASE_URL . 'assets/css/loans.css'];
require_once __DIR__ . '/../../includes/head.php';

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
?>
<body>
<div class="layout">
<?php include __DIR__ . '/../../includes/sidebar.php'; ?>
<div class="main">
<?php include __DIR__ . '/../../includes/header.php'; ?>
<div class="main-content">

  <div class="loans-page">

    <!-- PAGE HEADER -->
    <div class="loans-header">
      <div>
        <h1>Loans Management</h1>
        <p>Review loan applications and track active loans</p>
      </div>
      <button class="btn-primary" onclick="openAddLoan()">
        <i class="fa fa-plus"></i> Add Loan
      </button>
    </div>

    <!-- TABS -->
    <div class="loans-tabs">
      <a href="?tab=applications" class="loans-tab <?= $tab==='applications'?'active':'' ?>">
        Applications
        <?php if ($awaitingCount > 0): ?>
        <span class="tab-badge"><?= $awaitingCount ?></span>
        <?php endif; ?>
      </a>
      <a href="?tab=active" class="loans-tab <?= $tab==='active'?'active':'' ?>">
        Active
        <span class="tab-badge tab-badge--green"><?= $totalActive ?></span>
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
      <?php if ($tab === 'applications'): ?>
      <div class="status-pills">
        <?php foreach (['all'=>'All','PENDING'=>'Pending','DENIED'=>'Denied'] as $v => $l): ?>
        <a href="?tab=applications&status=<?= $v ?>&type_id=<?= $typeFilter ?>&search=<?= urlencode($search) ?>"
           class="status-pill <?= $statusFilter===$v?'active':'' ?>"><?= $l ?></a>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
      <button type="submit" class="btn-outline btn-sm" title="Apply filter"
              style="padding:7px 12px;">
        <i class="fa fa-filter"></i>
      </button>
      <?php if ($search || $typeFilter): ?>
      <a href="?tab=<?= $tab ?>" class="btn-outline btn-sm" title="Clear filters"
         style="padding:7px 12px;">
        <i class="fa fa-times"></i>
      </a>
      <?php endif; ?>
    </form>

    <!-- ══ APPLICATIONS TAB ══════════════════════════════════════════════ -->
    <?php if ($tab === 'applications'): ?>

    <!-- Stats -->
    <div class="loans-stats">
      <div class="stat-card">
        <div class="stat-num"><?= $awaitingCount ?></div>
        <div class="stat-label">Awaiting Review</div>
        <div class="stat-icon stat-icon--yellow"><i class="fa fa-clock"></i></div>
      </div>
      <div class="stat-card">
        <div class="stat-num"><?= $approvedThisMonth ?></div>
        <div class="stat-label">Approved This Month</div>
        <div class="stat-icon stat-icon--green"><i class="fa fa-circle-check"></i></div>
      </div>
      <div class="stat-card">
        <div class="stat-num"><?= pesos((float)$pendingAmount) ?></div>
        <div class="stat-label">Pending Amount</div>
        <div class="stat-icon stat-icon--blue"><i class="fa fa-dollar-sign"></i></div>
      </div>
    </div>

    <!-- Applications list -->
    <?php if (empty($applications)): ?>
    <div class="loans-empty">
      <i class="fa fa-inbox"></i>
      <p>No loan applications found.</p>
    </div>
    <?php else: ?>
    <div class="app-list">
      <?php foreach ($applications as $app): ?>
      <?php
        $initials = loanInitials($app['employee_name']);
        $pct = $app['monthly_salary'] > 0
            ? round(($app['monthly_deduction'] / $app['monthly_salary']) * 100, 1)
            : 0;
      ?>
      <div class="app-card">
        <div class="app-card-left">
          <div class="emp-avatar"><?= $initials ?></div>
          <div class="app-emp-info">
            <strong><?= htmlspecialchars($app['employee_name']) ?></strong>
            <span><?= htmlspecialchars($app['department_name'] ?? '') ?></span>
            <span><?= htmlspecialchars($app['position_name'] ?? '') ?></span>
          </div>
        </div>
        <div class="app-card-center">
          <div class="app-loan-type">
            <i class="fa fa-fire" style="color:#f59e0b"></i>
            <?= htmlspecialchars($app['loan_name']) ?>
          </div>
          <div class="app-amount"><?= pesos((float)$app['total_amount']) ?></div>
          <div class="app-terms">
            <?php
              $termMonths = $app['monthly_deduction'] > 0
                ? (int)ceil($app['total_payable'] / $app['monthly_deduction'])
                : 0;
            ?>
            <?= $termMonths ?> months
            &bull;
            <?= pesos((float)$app['monthly_deduction']) ?>/month
          </div>
          <div class="app-submitted">
            Submitted <?= date('M d, Y', strtotime($app['created_at'])) ?>
          </div>
          <?php if ($app['reason']): ?>
          <div class="app-reason"><?= htmlspecialchars($app['reason']) ?></div>
          <?php endif; ?>
        </div>
        <div class="app-card-right">
          <span class="loan-status-badge loan-status--<?= strtolower($app['status']) ?>">
            <?= ucfirst(strtolower($app['status'])) ?>
          </span>
          <?php if ($app['status'] === 'PENDING'): ?>
          <button class="btn-primary btn-sm" onclick="openReview(<?= $app['loan_id'] ?>)">
            Review
          </button>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- ══ ACTIVE LOANS TAB ══════════════════════════════════════════════ -->
    <?php else: ?>

    <!-- Stats -->
    <div class="loans-stats">
      <div class="stat-card">
        <div class="stat-num"><?= $totalActive ?></div>
        <div class="stat-label">Total Active Loans</div>
      </div>
      <div class="stat-card">
        <div class="stat-num" style="color:#ef4444"><?= pesos((float)$totalOutstanding) ?></div>
        <div class="stat-label">Total Outstanding Balance</div>
      </div>
      <div class="stat-card">
        <div class="stat-num"><?= pesos((float)$totalMonthlyDed) ?></div>
        <div class="stat-label">Total Monthly Deductions</div>
      </div>
      <div class="stat-card">
        <div class="stat-num"><?= $endingSoon ?></div>
        <div class="stat-label">Ending in 3 Months</div>
      </div>
    </div>

    <!-- Active loans table -->
    <?php if (empty($activeLoans)): ?>
    <div class="loans-empty">
      <i class="fa fa-inbox"></i>
      <p>No active loans found.</p>
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
            <th>Monthly Payment</th>
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
          // Next payment = next 15th of current or next month
          $nextPay  = $loan['start_date'] ? date('M d, Y', strtotime($loan['start_date'] . ' +' . max(0, (int)ceil(($loan['total_amount'] - $loan['balance_amount']) / $loan['monthly_deduction'])) . ' months')) : '—';
        ?>
        <tr>
          <td>
            <div style="display:flex;align-items:center;gap:10px">
              <div class="emp-avatar emp-avatar--sm"><?= $initials ?></div>
              <span><?= htmlspecialchars($loan['employee_name']) ?></span>
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
            <button class="btn-icon" title="View details" onclick="openLoanDetails(<?= $loan['loan_id'] ?>)">
              <i class="fa fa-eye"></i>
            </button>
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

<?php include __DIR__ . '/modals/modal-add-loan.php'; ?>
<?php include __DIR__ . '/modals/modal-review.php'; ?>
<?php include __DIR__ . '/modals/modal-details.php'; ?>

<script>
const BASE_URL = '<?= BASE_URL ?>';
const LOAN_TYPES  = <?= json_encode($loanTypes) ?>;
const EMPLOYEES   = <?= json_encode($empList) ?>;
</script>
<script src="<?= BASE_URL ?>assets/js/loans.js"></script>
<?php include __DIR__ . '/../../includes/footer.php'; ?>