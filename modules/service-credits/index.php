<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';

requireLogin();

$pageTitle = 'Service Credits';

// ── Summary stats ─────────────────────────────────────────────────────────────
$stmtEarned = $pdo->query("SELECT COALESCE(SUM(days), 0) FROM service_credits WHERE is_approved = 1");
$totalCreditsEarned = (float)$stmtEarned->fetchColumn();
$totalCreditsUsed   = 0; // Extend with leave_transactions if tracked
$totalCreditsAvail  = $totalCreditsEarned - $totalCreditsUsed;

// ── Filters ───────────────────────────────────────────────────────────────────
$search      = trim($_GET['search'] ?? '');
$monthFilter = trim($_GET['month']  ?? '');

// ── Pagination ────────────────────────────────────────────────────────────────
$perPage     = 20;
$currentPage = max(1, (int)($_GET['page'] ?? 1));
$offset      = ($currentPage - 1) * $perPage;

// ── WHERE clause ──────────────────────────────────────────────────────────────
$where  = "WHERE 1=1";
$params = [];

if ($search !== '') {
    $where .= " AND (e.first_name LIKE :search OR e.last_name LIKE :search OR e.employee_no LIKE :search)";
    $params[':search'] = "%$search%";
}

if ($monthFilter !== '' && $monthFilter !== 'all') {
    [$filterYear, $filterMonth] = explode('-', $monthFilter);
    $where .= " AND YEAR(sc.work_date) = :fyear AND MONTH(sc.work_date) = :fmonth";
    $params[':fyear']  = $filterYear;
    $params[':fmonth'] = $filterMonth;
}

// ── Total count ───────────────────────────────────────────────────────────────
$stmtCount = $pdo->prepare("
    SELECT COUNT(*)
    FROM service_credits sc
    JOIN employees e ON e.employee_id = sc.employee_id
    $where
");
$stmtCount->execute($params);
$totalRecords = (int)$stmtCount->fetchColumn();
$totalPages   = max(1, (int)ceil($totalRecords / $perPage));

// ── History rows ──────────────────────────────────────────────────────────────
$stmt = $pdo->prepare("
    SELECT
        sc.service_credit_id,
        sc.work_date,
        sc.days,
        sc.remarks,
        e.employee_id,
        e.first_name,
        e.last_name,
        e.photo_path,
        p.position_name
    FROM service_credits sc
    JOIN employees   e ON e.employee_id = sc.employee_id
    JOIN positions   p ON p.position_id = e.position_id
    $where
    ORDER BY sc.work_date DESC, sc.service_credit_id DESC
    LIMIT :limit OFFSET :offset
");
foreach ($params as $k => $v) $stmt->bindValue($k, $v);
$stmt->bindValue(':limit',  $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset,  PDO::PARAM_INT);
$stmt->execute();
$credits = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Employee summary sidebar ──────────────────────────────────────────────────
$employeeSummary = $pdo->query("
    SELECT
        e.employee_id,
        e.first_name,
        e.last_name,
        p.position_name,
        SUM(sc.days) AS earned
    FROM service_credits sc
    JOIN employees e ON e.employee_id = sc.employee_id
    JOIN positions p ON p.position_id = e.position_id
    WHERE sc.is_approved = 1
    GROUP BY e.employee_id, e.first_name, e.last_name, p.position_name
    ORDER BY earned DESC
    LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);

// ── Dropdowns ─────────────────────────────────────────────────────────────────
$allEmployees = $pdo->query("
    SELECT e.employee_id, e.first_name, e.last_name, p.position_name
    FROM employees e
    JOIN positions p ON p.position_id = e.position_id
    WHERE e.employee_status = 'ACTIVE'
    ORDER BY e.last_name, e.first_name
")->fetchAll(PDO::FETCH_ASSOC);

$approverOptions = ['Principal', 'Assistant Principal', 'HR Manager'];

// ── Flash messages ────────────────────────────────────────────────────────────
$successMsg = $_SESSION['sc_success'] ?? '';
$errorMsg   = $_SESSION['sc_error']   ?? '';
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
  <!-- Page Header -->
  <div class="page-header">
    <div class="page-header-icon">
      <i class="fa fa-medal"></i>
    </div>
    <div>
      <h1 class="page-title">Service Credits Management</h1>
      <p class="page-subtitle">Track extra work and accrued leave days earned by employees</p>
    </div>
  </div>

  <!-- Alerts -->
  <?php if ($successMsg): ?>
  <div class="alert alert-success">
    <i class="ph ph-check-circle"></i> <?= htmlspecialchars($successMsg) ?>
    <button class="alert-close" onclick="this.parentElement.remove()">×</button>
  </div>
  <?php endif; ?>
  <?php if ($errorMsg): ?>
  <div class="alert alert-error">
    <i class="ph ph-warning-circle"></i> <?= htmlspecialchars($errorMsg) ?>
    <button class="alert-close" onclick="this.parentElement.remove()">×</button>
  </div>
  <?php endif; ?>

  <!-- Stat Cards -->
  <div class="stats-row">
    <div class="stat-card">
      <div class="stat-card-body">
        <div>
          <p class="stat-label">Total Credits Earned</p>
          <p class="stat-value"><?= number_format($totalCreditsEarned, 1) ?></p>
        </div>
        <div class="stat-icon stat-icon--teal"><i class="ph ph-trend-up"></i></div>
      </div>
    </div>
    <div class="stat-card">
      <div class="stat-card-body">
        <div>
          <p class="stat-label">Credits Used</p>
          <p class="stat-value"><?= number_format($totalCreditsUsed, 1) ?></p>
        </div>
        <div class="stat-icon stat-icon--blue"><i class="ph ph-calendar-check"></i></div>
      </div>
    </div>
    <div class="stat-card">
      <div class="stat-card-body">
        <div>
          <p class="stat-label">Credits Available</p>
          <p class="stat-value"><?= number_format($totalCreditsAvail, 1) ?></p>
        </div>
        <div class="stat-icon stat-icon--amber"><i class="ph ph-medal"></i></div>
      </div>
    </div>
  </div>

  <!-- Filter Bar -->
  <div class="filter-bar">
    <form method="GET" class="filter-bar-form" id="filterForm">
      <div class="filter-bar-left">
        <div class="search-wrap">
          <i class="ph ph-magnifying-glass search-icon"></i>
          <input
            type="text"
            name="search"
            id="searchInput"
            class="form-control search-input"
            placeholder="Search employees..."
            value="<?= htmlspecialchars($search) ?>"
          >
        </div>
        <i class="ph ph-funnel filter-icon"></i>
        <select name="month" class="form-select month-select" onchange="this.form.submit()">
          <option value="all" <?= ($monthFilter === '' || $monthFilter === 'all') ? 'selected' : '' ?>>All Months</option>
          <?php for ($i = 0; $i < 12; $i++):
            $ts  = strtotime("-$i months");
            $val = date('Y-m', $ts);
            $lbl = date('F Y', $ts);
          ?>
          <option value="<?= $val ?>" <?= $monthFilter === $val ? 'selected' : '' ?>><?= $lbl ?></option>
          <?php endfor; ?>
        </select>
      </div>
      <button type="button" class="btn btn-primary" id="btnAddCredit">
        <i class="ph ph-plus"></i> Add Service Credit
      </button>
    </form>
  </div>

  <!-- Main Layout -->
  <div class="sc-layout">

    <!-- History Card -->
    <div class="card sc-history-card">
      <h2 class="card-title">Service Credit History</h2>

      <?php if (empty($credits)): ?>
      <div class="empty-state">
        <i class="ph ph-medal empty-icon"></i>
        <p class="empty-title">No service credits found</p>
        <p class="empty-sub">Click "Add Service Credit" to get started</p>
        <button class="btn btn-primary" id="btnAddCreditEmpty">
          <i class="ph ph-plus"></i> Add Your First Credit
        </button>
      </div>
      <?php else: ?>
      <div class="table-responsive">
        <table class="sc-table">
          <thead>
            <tr>
              <th>Date</th>
              <th>Employee</th>
              <th>Reason</th>
              <th>Earned</th>
              <th>Used</th>
              <th>Available</th>
              <th></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($credits as $cr):
              $earned    = (float)$cr['days'];
              $used      = 0;
              $available = $earned - $used;
            ?>
            <tr>
              <td class="sc-date"><?= date('M j, Y', strtotime($cr['work_date'])) ?></td>
              <td>
                <div class="employee-cell">
                  <?php if (!empty($cr['photo_path']) && file_exists('../../' . $cr['photo_path'])): ?>
                    <img src="../../<?= htmlspecialchars($cr['photo_path']) ?>" class="emp-avatar" alt="">
                  <?php else: ?>
                    <div class="emp-avatar-placeholder">
                      <?= strtoupper(substr($cr['first_name'], 0, 1) . substr($cr['last_name'], 0, 1)) ?>
                    </div>
                  <?php endif; ?>
                  <div>
                    <span class="emp-name"><?= htmlspecialchars($cr['first_name'] . ' ' . $cr['last_name']) ?></span>
                    <span class="emp-pos"><?= htmlspecialchars($cr['position_name']) ?></span>
                  </div>
                </div>
              </td>
              <td><?= htmlspecialchars($cr['remarks'] ?? '—') ?></td>
              <td><span class="badge-earned">+<?= number_format($earned, 1) ?></span></td>
              <td><?= number_format($used, 1) ?></td>
              <td class="<?= $available > 0 ? 'text-teal' : 'text-gray' ?>"><?= number_format($available, 1) ?></td>
              <td class="sc-actions">
                <button class="icon-btn icon-btn--edit" title="Edit"
                  onclick="openEditModal(<?= htmlspecialchars(json_encode($cr)) ?>)">
                  <i class="ph ph-pencil-simple"></i>
                </button>
                <button class="icon-btn icon-btn--delete" title="Delete"
                  onclick="openDeleteModal(<?= (int)$cr['service_credit_id'] ?>, '<?= htmlspecialchars($cr['first_name'] . ' ' . $cr['last_name']) ?>', <?= $earned ?>)">
                  <i class="ph ph-trash"></i>
                </button>
              </td>
            </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <?php if ($totalPages > 1): ?>
      <div class="pagination-bar">
        <span class="pagination-info">
          Showing <?= $offset + 1 ?>–<?= min($offset + $perPage, $totalRecords) ?> of <?= $totalRecords ?> records
        </span>
        <div class="pagination-controls">
          <?php if ($currentPage > 1): ?>
          <a href="?page=<?= $currentPage - 1 ?>&search=<?= urlencode($search) ?>&month=<?= urlencode($monthFilter) ?>" class="page-btn"><i class="ph ph-caret-left"></i></a>
          <?php endif; ?>
          <?php for ($p = max(1, $currentPage - 2); $p <= min($totalPages, $currentPage + 2); $p++): ?>
          <a href="?page=<?= $p ?>&search=<?= urlencode($search) ?>&month=<?= urlencode($monthFilter) ?>"
             class="page-btn <?= $p === $currentPage ? 'active' : '' ?>"><?= $p ?></a>
          <?php endfor; ?>
          <?php if ($currentPage < $totalPages): ?>
          <a href="?page=<?= $currentPage + 1 ?>&search=<?= urlencode($search) ?>&month=<?= urlencode($monthFilter) ?>" class="page-btn"><i class="ph ph-caret-right"></i></a>
          <?php endif; ?>
        </div>
      </div>
      <?php endif; ?>
      <?php endif; ?>
    </div>

    <!-- Employee Summary Sidebar -->
    <div class="card sc-summary-card">
      <h2 class="card-title">Employee Summary</h2>
      <div class="summary-list">
        <?php if (empty($employeeSummary)): ?>
          <p class="no-summary">No credits awarded yet.</p>
        <?php else: ?>
          <?php foreach ($employeeSummary as $emp):
            $empEarned    = (float)$emp['earned'];
            $empUsed      = 0;
            $empAvailable = $empEarned - $empUsed;
          ?>
          <div class="summary-item">
            <div>
              <span class="summary-emp-name"><?= htmlspecialchars($emp['first_name'] . ' ' . $emp['last_name']) ?></span>
              <span class="summary-emp-pos"><?= htmlspecialchars($emp['position_name']) ?></span>
              <div class="summary-meta">
                <span class="meta-earned"><i class="ph ph-arrow-up"></i> <?= number_format($empEarned, 1) ?> earned</span>
                <span class="meta-dot">·</span>
                <span class="meta-used"><i class="ph ph-arrow-down"></i> <?= number_format($empUsed, 1) ?> used</span>
              </div>
            </div>
            <span class="summary-credits"><?= number_format($empAvailable, 1) ?> credits</span>
          </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
      <div class="info-box">
        <span class="info-box-label">Service Credits:</span>
        1 credit = 1 additional leave day. Credits are accrued and added to the employee's leave balance.
        Employees can use them as vacation or sick leave.
      </div>
    </div>

  </div><!-- /.sc-layout -->
</div><!-- /.main-content -->


<!-- Modal: Add / Edit -->
<div class="modal-overlay" id="creditModal" style="display:none;">
  <div class="modal-box" role="dialog" aria-modal="true" aria-labelledby="modalTitle">
    <div class="modal-header">
      <h3 class="modal-title" id="modalTitle">Add Service Credit</h3>
      <button class="modal-close" onclick="closeModal('creditModal')">×</button>
    </div>
    <form id="creditForm" method="POST" action="../../actions/service-credits-action.php">
      <input type="hidden" name="action"            id="formAction"   value="add">
      <input type="hidden" name="service_credit_id" id="formCreditId" value="">
      <div class="modal-body">

        <div class="form-group">
          <label class="form-label" for="formEmployee">Employee</label>
          <select name="employee_id" id="formEmployee" class="form-select" required>
            <option value="">Select employee...</option>
            <?php foreach ($allEmployees as $emp): ?>
            <option value="<?= $emp['employee_id'] ?>">
              <?= htmlspecialchars($emp['first_name'] . ' ' . $emp['last_name'] . ' — ' . $emp['position_name']) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-row-2">
          <div class="form-group">
            <label class="form-label" for="formDays">Credits Earned</label>
            <input type="number" name="days" id="formDays" class="form-control"
                   value="1.0" step="0.5" min="0.5" max="30" required>
          </div>
          <div class="form-group">
            <label class="form-label" for="formWorkDate">Date Earned</label>
            <input type="date" name="work_date" id="formWorkDate" class="form-control"
                   value="<?= date('Y-m-d') ?>" max="<?= date('Y-m-d') ?>" required>
          </div>
        </div>

        <div class="form-group">
          <label class="form-label" for="formRemarks">Reason / Description</label>
          <textarea name="remarks" id="formRemarks" class="form-control" rows="3"
                    placeholder="e.g., Saturday tutorial session, weekend seminar..." maxlength="255"></textarea>
          <span class="char-count" id="charCount">0/255</span>
        </div>

        <div class="form-group">
          <label class="form-label" for="formApprover">Approved By</label>
          <select name="approved_by_role" id="formApprover" class="form-select">
            <?php foreach ($approverOptions as $opt): ?>
            <option value="<?= htmlspecialchars($opt) ?>"><?= htmlspecialchars($opt) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="note-box">
          <strong>Note:</strong> Service credits are accrued as additional leave days.
          1 credit = 1 extra day of leave that can be used throughout the year.
        </div>

      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('creditModal')">Cancel</button>
        <button type="submit" class="btn btn-primary" id="submitBtn">Add Credit</button>
      </div>
    </form>
  </div>
</div>


<!-- Modal: Delete Confirmation -->
<div class="modal-overlay" id="deleteModal" style="display:none;">
  <div class="modal-box modal-box--sm" role="dialog" aria-modal="true">
    <div class="modal-header">
      <h3 class="modal-title">Delete Service Credit?</h3>
      <button class="modal-close" onclick="closeModal('deleteModal')">×</button>
    </div>
    <div class="modal-body modal-body--center">
      <div class="delete-warning-icon"><i class="ph ph-warning"></i></div>
      <p class="modal-desc" id="deleteDesc"></p>
    </div>
    <form method="POST" action="../../actions/service-credits-action.php">
      <input type="hidden" name="action"            value="delete">
      <input type="hidden" name="service_credit_id" id="deleteCreditId" value="">
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" onclick="closeModal('deleteModal')">Cancel</button>
        <button type="submit" class="btn btn-danger">Delete</button>
      </div>
    </form>
  </div>
</div><!-- end .main-content -->
</div><!-- end .main -->
</div><!-- end .layout -->

<script src="<?= BASE_URL ?>assets/js/service-credits.js"></script>
<?php include __DIR__ . '/../../includes/footer.php'; ?>