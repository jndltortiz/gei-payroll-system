<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

$tab        = $_GET['tab']    ?? 'documents';
$typeFilter = (int)($_GET['type_id'] ?? 0);
$search     = trim($_GET['search'] ?? '');

// Loan types for dropdowns
$loanTypes = $pdo->query("SELECT * FROM loan_types WHERE is_active=1 ORDER BY loan_name")->fetchAll();

// ── Submitted Loan Documents (PENDING + DENIED) ──────────────────────────────
$statusFilter = $_GET['status'] ?? 'all';
$docWhere     = "el.status IN ('PENDING','RETURNED','DENIED')";
$docParams    = [];
if ($statusFilter !== 'all' && in_array($statusFilter, ['PENDING','RETURNED','DENIED'])) {
    $docWhere = "el.status = :s"; $docParams[':s'] = strtoupper($statusFilter);
}
if ($typeFilter) { $docWhere .= " AND el.loan_type_id = :t"; $docParams[':t'] = $typeFilter; }
if ($search)     { $docWhere .= " AND (e.first_name LIKE :q OR e.last_name LIKE :q OR CONCAT(e.first_name,' ',e.last_name) LIKE :q)"; $docParams[':q'] = "%$search%"; }

$docStmt = $pdo->prepare("
    SELECT el.*, lt.loan_name,
           CONCAT(e.first_name,' ',e.last_name) AS employee_name,
           p.position_name, d.department_name,
           ec.monthly_salary, e.hire_date
    FROM employee_loans el
    JOIN employees e   ON el.employee_id   = e.employee_id
    JOIN loan_types lt ON el.loan_type_id  = lt.loan_type_id
    LEFT JOIN positions   p  ON e.position_id   = p.position_id
    LEFT JOIN departments d  ON e.department_id = d.department_id
    LEFT JOIN employee_compensations ec ON e.employee_id = ec.employee_id AND ec.is_active = 1
    WHERE $docWhere ORDER BY el.created_at DESC
");
$docStmt->execute($docParams);
$documents = $docStmt->fetchAll();

$pendingCount     = $pdo->query("SELECT COUNT(*) FROM employee_loans WHERE status IN ('PENDING','RETURNED')")->fetchColumn();
$verifiedThisMonth = $pdo->query("SELECT COUNT(*) FROM employee_loans WHERE status='ACTIVE' AND MONTH(approved_at)=MONTH(NOW()) AND YEAR(approved_at)=YEAR(NOW())")->fetchColumn();
$pendingAmount    = $pdo->query("SELECT COALESCE(SUM(total_amount),0) FROM employee_loans WHERE status='PENDING'")->fetchColumn();

// ── Active Loans (ACTIVE + PAUSED) ───────────────────────────────────────────
$actWhere  = "el.status IN ('ACTIVE','PAUSED')";
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

$totalActive      = $pdo->query("SELECT COUNT(*) FROM employee_loans WHERE status='ACTIVE'")->fetchColumn();
$totalPaused      = $pdo->query("SELECT COUNT(*) FROM employee_loans WHERE status='PAUSED'")->fetchColumn();
$totalOutstanding = $pdo->query("SELECT COALESCE(SUM(balance_amount),0) FROM employee_loans WHERE status IN ('ACTIVE','PAUSED')")->fetchColumn();
$totalMonthlyAmt  = $pdo->query("SELECT COALESCE(SUM(monthly_deduction),0) FROM employee_loans WHERE status='ACTIVE'")->fetchColumn();
$endingSoon       = $pdo->query("SELECT COUNT(*) FROM employee_loans WHERE status='ACTIVE' AND end_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 3 MONTH)")->fetchColumn();

// ── Loan History & Audit (COMPLETED + CANCELLED + DENIED + ARCHIVED) ─────────
$histStatus  = $_GET['hist_status'] ?? 'all';
$histWhere   = "el.status IN ('COMPLETED','CANCELLED','DENIED','ARCHIVED')";
$histParams  = [];
if ($histStatus !== 'all' && in_array($histStatus, ['COMPLETED','CANCELLED','DENIED','ARCHIVED'])) {
    $histWhere = "el.status = :hs"; $histParams[':hs'] = $histStatus;
}
if ($typeFilter) { $histWhere .= " AND el.loan_type_id = :t"; $histParams[':t'] = $typeFilter; }
if ($search)     { $histWhere .= " AND (e.first_name LIKE :q OR e.last_name LIKE :q OR CONCAT(e.first_name,' ',e.last_name) LIKE :q)"; $histParams[':q'] = "%$search%"; }

$histStmt = $pdo->prepare("
    SELECT el.*, lt.loan_name,
           CONCAT(e.first_name,' ',e.last_name) AS employee_name,
           p.position_name, d.department_name,
           u.username AS actioned_by_name
    FROM employee_loans el
    JOIN employees e   ON el.employee_id   = e.employee_id
    JOIN loan_types lt ON el.loan_type_id  = lt.loan_type_id
    LEFT JOIN positions   p ON e.position_id   = p.position_id
    LEFT JOIN departments d ON e.department_id = d.department_id
    LEFT JOIN users u ON u.user_id = el.approved_by
    WHERE $histWhere
    ORDER BY COALESCE(el.archived_at, el.updated_at, el.created_at) DESC
    LIMIT 200
");
$histStmt->execute($histParams);
$historyLoans = $histStmt->fetchAll();

// Employee list for Add form
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

$pageTitle = 'Loan Records Management';
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
        <h1>Loan Records Management</h1>
        <p>Record, verify, and track employee loan deductions</p>
      </div>
      <button class="btn-primary" onclick="openAddLoan()">
        <i class="fa fa-plus"></i> Add Loan Record
      </button>
    </div>

    <!-- TABS -->
    <div class="loans-tabs">
      <a href="?tab=documents" class="loans-tab <?= $tab==='documents'?'active':'' ?>">
        Submitted Loan Documents
        <?php if ($pendingCount > 0): ?>
        <span class="tab-badge"><?= $pendingCount ?></span>
        <?php endif; ?>
      </a>
      <a href="?tab=active" class="loans-tab <?= $tab==='active'?'active':'' ?>">
        Active Loans
        <span class="tab-badge tab-badge--green"><?= $totalActive ?><?= $totalPaused ? '+' . $totalPaused : '' ?></span>
      </a>
      <a href="?tab=history" class="loans-tab <?= $tab==='history'?'active':'' ?>">
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
      <?php if ($tab === 'documents'): ?>
      <div class="status-pills">
        <?php foreach (['all'=>'All','PENDING'=>'Pending Review','RETURNED'=>'Returned for Correction','DENIED'=>'Rejected'] as $v => $l): ?>
        <a href="?tab=documents&status=<?= $v ?>&type_id=<?= $typeFilter ?>&search=<?= urlencode($search) ?>"
           class="status-pill <?= $statusFilter===$v?'active':'' ?>"><?= $l ?></a>
        <?php endforeach; ?>
      </div>
      <?php elseif ($tab === 'history'): ?>
      <div class="status-pills">
        <?php foreach (['all'=>'All','COMPLETED'=>'Completed','CANCELLED'=>'Cancelled','DENIED'=>'Rejected','ARCHIVED'=>'Archived'] as $v => $l): ?>
        <a href="?tab=history&hist_status=<?= $v ?>&type_id=<?= $typeFilter ?>&search=<?= urlencode($search) ?>"
           class="status-pill <?= $histStatus===$v?'active':'' ?>"><?= $l ?></a>
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

    <!-- ══ SUBMITTED LOAN DOCUMENTS TAB ════════════════════════════════════ -->
    <?php if ($tab === 'documents'): ?>

    <div class="loans-stats">
      <div class="stat-card">
        <div class="stat-num"><?= $pendingCount ?></div>
        <div class="stat-label">Awaiting Verification</div>
        <div class="stat-icon stat-icon--yellow"><i class="fa fa-clock"></i></div>
      </div>
      <div class="stat-card">
        <div class="stat-num"><?= $verifiedThisMonth ?></div>
        <div class="stat-label">Verified This Month</div>
        <div class="stat-icon stat-icon--green"><i class="fa fa-circle-check"></i></div>
      </div>
      <div class="stat-card">
        <div class="stat-num"><?= pesos((float)$pendingAmount) ?></div>
        <div class="stat-label">Total Pending Amount</div>
        <div class="stat-icon stat-icon--blue"><i class="fa fa-file-invoice-dollar"></i></div>
      </div>
    </div>

    <?php if (empty($documents)): ?>
    <div class="loans-empty">
      <i class="fa fa-inbox"></i>
      <p>No submitted loan documents found.</p>
    </div>
    <?php else: ?>
    <div class="app-list">
      <?php foreach ($documents as $app): ?>
      <?php
        $initials = loanInitials($app['employee_name']);
        $termMonths = $app['monthly_deduction'] > 0
            ? (int)ceil($app['total_payable'] / $app['monthly_deduction']) : 0;
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
            <i class="fa fa-file-invoice" style="color:#f59e0b"></i>
            <?= htmlspecialchars($app['loan_name']) ?>
            <?php if (!empty($app['provider_name'])): ?>
            — <span style="color:#64748b"><?= htmlspecialchars($app['provider_name']) ?></span>
            <?php endif; ?>
          </div>
          <div class="app-amount"><?= pesos((float)$app['total_amount']) ?></div>
          <div class="app-terms">
            <?= $termMonths ?> months &bull; <?= pesos((float)$app['monthly_deduction']) ?>/month amortization
          </div>
          <div class="app-submitted">
            Submitted <?= date('M d, Y', strtotime($app['created_at'])) ?>
            <?php if ($app['account_reference']): ?>
            &bull; Ref: <strong><?= htmlspecialchars($app['account_reference']) ?></strong>
            <?php endif; ?>
          </div>
          <?php if ($app['reason']): ?>
          <div class="app-reason"><?= htmlspecialchars($app['reason']) ?></div>
          <?php endif; ?>
        </div>
        <div class="app-card-right">
          <?php
            $statusLabels = [
                'PENDING'  => 'Pending Principal Review',
                'RETURNED' => 'Returned for Correction',
                'DENIED'   => 'Rejected',
            ];
            $appStatus    = $app['status'];
            $appLabel     = $statusLabels[$appStatus] ?? ucfirst(strtolower($appStatus));
          ?>
          <span class="loan-status-badge loan-status--<?= strtolower($appStatus) ?>">
            <?= $appLabel ?>
          </span>
          <button class="btn-outline btn-sm" onclick="openLoanDetails(<?= $app['loan_id'] ?>)">
            <i class="fa fa-eye"></i> View
          </button>
        </div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <!-- ══ ACTIVE LOANS TAB ══════════════════════════════════════════════════ -->
    <?php elseif ($tab === 'active'): ?>

    <div class="loans-stats">
      <div class="stat-card">
        <div class="stat-num"><?= $totalActive ?></div>
        <div class="stat-label">Active Loans</div>
      </div>
      <?php if ($totalPaused > 0): ?>
      <div class="stat-card">
        <div class="stat-num" style="color:#f59e0b"><?= $totalPaused ?></div>
        <div class="stat-label">Paused</div>
      </div>
      <?php endif; ?>
      <div class="stat-card">
        <div class="stat-num" style="color:#ef4444"><?= pesos((float)$totalOutstanding) ?></div>
        <div class="stat-label">Total Outstanding Balance</div>
      </div>
      <div class="stat-card">
        <div class="stat-num"><?= pesos((float)$totalMonthlyAmt) ?></div>
        <div class="stat-label">Total Monthly Amortization</div>
      </div>
      <div class="stat-card">
        <div class="stat-num"><?= $endingSoon ?></div>
        <div class="stat-label">Ending in 3 Months</div>
      </div>
    </div>

    <?php if (empty($activeLoans)): ?>
    <div class="loans-empty">
      <i class="fa fa-inbox"></i>
      <p>No active or paused loans found.</p>
    </div>
    <?php else: ?>
    <div class="active-loans-table-wrap">
      <table class="active-loans-table">
        <thead>
          <tr>
            <th>Employee</th>
            <th>Loan Type / Provider</th>
            <th>Original Amount</th>
            <th>Outstanding</th>
            <th>Monthly Amortization</th>
            <th>Auto-Deduction / Payroll</th>
            <th>Remaining</th>
            <th>Status</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($activeLoans as $loan):
          $pct      = loanProgress((float)$loan['total_amount'], (float)$loan['balance_amount']);
          $rem      = remainingMonths((float)$loan['balance_amount'], (float)$loan['monthly_deduction']);
          $initials = loanInitials($loan['employee_name']);
          $autoDeduction = round((float)$loan['monthly_deduction'] / 2, 2);
        ?>
        <tr>
          <td>
            <div style="display:flex;align-items:center;gap:10px">
              <div class="emp-avatar emp-avatar--sm"><?= $initials ?></div>
              <span><?= htmlspecialchars($loan['employee_name']) ?></span>
            </div>
          </td>
          <td>
            <div style="font-weight:600"><?= htmlspecialchars($loan['loan_name']) ?></div>
            <?php if (!empty($loan['provider_name'])): ?>
            <div style="font-size:11px;color:#64748b"><?= htmlspecialchars($loan['provider_name']) ?></div>
            <?php endif; ?>
          </td>
          <td><?= pesos((float)$loan['total_amount']) ?></td>
          <td>
            <div class="outstanding-cell">
              <span style="color:#ef4444;font-weight:600"><?= pesos((float)$loan['balance_amount']) ?></span>
              <div class="progress-bar-wrap">
                <div class="progress-bar-fill" style="width:<?= $pct ?>%"></div>
              </div>
              <small><?= $pct ?>% paid</small>
            </div>
          </td>
          <td><?= pesos((float)$loan['monthly_deduction']) ?></td>
          <td>
            <span style="color:#0d9488;font-weight:600"><?= pesos($autoDeduction) ?></span>
            <small style="color:#94a3b8;display:block;font-size:10px;">per payroll (semi-monthly)</small>
          </td>
          <td><?= $rem ?> months</td>
          <td>
            <?php if ($loan['status'] === 'PAUSED'): ?>
            <span class="loan-status-badge loan-status--paused">Paused</span>
            <?php else: ?>
            <span class="loan-status-badge loan-status--active">Active</span>
            <?php endif; ?>
          </td>
          <td>
            <button class="btn-icon" title="View / Manage" onclick="openLoanDetails(<?= $loan['loan_id'] ?>)">
              <i class="fa fa-eye"></i>
            </button>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>

    <!-- ══ LOAN HISTORY & AUDIT TAB ════════════════════════════════════════ -->
    <?php else: ?>

    <?php if (empty($historyLoans)): ?>
    <div class="loans-empty">
      <i class="fa fa-clock-rotate-left"></i>
      <p>No historical loan records found.</p>
    </div>
    <?php else: ?>
    <div class="active-loans-table-wrap">
      <table class="active-loans-table">
        <thead>
          <tr>
            <th>Employee</th>
            <th>Loan Type / Provider</th>
            <th>Ref #</th>
            <th>Original Amount</th>
            <th>Remaining Balance</th>
            <th>Start Date</th>
            <th>Status</th>
            <th>Actioned By</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($historyLoans as $loan):
          $initials = loanInitials($loan['employee_name']);
          $stLower  = strtolower($loan['status']);
        ?>
        <tr>
          <td>
            <div style="display:flex;align-items:center;gap:10px">
              <div class="emp-avatar emp-avatar--sm"><?= $initials ?></div>
              <span><?= htmlspecialchars($loan['employee_name']) ?></span>
            </div>
          </td>
          <td>
            <div style="font-weight:600"><?= htmlspecialchars($loan['loan_name']) ?></div>
            <?php if (!empty($loan['provider_name'])): ?>
            <div style="font-size:11px;color:#64748b"><?= htmlspecialchars($loan['provider_name']) ?></div>
            <?php endif; ?>
          </td>
          <td style="font-size:12px;color:#64748b"><?= htmlspecialchars($loan['account_reference'] ?: '—') ?></td>
          <td><?= pesos((float)$loan['total_amount']) ?></td>
          <td><?= pesos((float)$loan['balance_amount']) ?></td>
          <td style="font-size:12px"><?= $loan['start_date'] ? date('M d, Y', strtotime($loan['start_date'])) : '—' ?></td>
          <?php
            $histLabels = [
                'COMPLETED' => 'Completed', 'CANCELLED' => 'Cancelled',
                'DENIED'    => 'Rejected',  'ARCHIVED'  => 'Archived',
                'RETURNED'  => 'Returned for Correction',
            ];
            $histLabel = $histLabels[$loan['status']] ?? ucfirst($stLower);
          ?>
          <td><span class="loan-status-badge loan-status--<?= $stLower ?>"><?= $histLabel ?></span></td>
          <td style="font-size:12px;color:#64748b"><?= htmlspecialchars($loan['actioned_by_name'] ?? '—') ?></td>
          <td>
            <button class="btn-icon" title="View details" onclick="openLoanDetails(<?= $loan['loan_id'] ?>)">
              <i class="fa fa-eye"></i>
            </button>
            <?php if (in_array($loan['status'], ['COMPLETED','CANCELLED','DENIED','RETURNED'])): ?>
            <button class="btn-icon" title="Archive" onclick="archiveLoan(<?= $loan['loan_id'] ?>)"
                    style="color:#94a3b8">
              <i class="fa fa-box-archive"></i>
            </button>
            <?php endif; ?>
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
const BASE_URL   = '<?= BASE_URL ?>';
const LOAN_TYPES = <?= json_encode($loanTypes) ?>;
const EMPLOYEES  = <?= json_encode($empList) ?>;
</script>
<script src="<?= BASE_URL ?>assets/js/loans.js"></script>
<?php include __DIR__ . '/../../includes/footer.php'; ?>
