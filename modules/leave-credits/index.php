<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

// ── Filters ────────────────────────────────────────────────────────────────
$filterYear = (int)($_GET['school_year_id'] ?? 0);
$filterDept = (int)($_GET['department_id']  ?? 0);
$filterType = (int)($_GET['leave_type_id']  ?? 0);
$search     = trim($_GET['q'] ?? '');
$currentPage = max(1, (int)($_GET['page'] ?? 1));
$perPage    = 12;

// ── Reference data ─────────────────────────────────────────────────────────
$schoolYears = $pdo->query("SELECT * FROM school_years ORDER BY start_date DESC")->fetchAll(PDO::FETCH_ASSOC);
$activeYear  = null;
foreach ($schoolYears as $sy) {
    if ($sy['is_active']) { $activeYear = $sy; break; }
}
if (!$filterYear && $activeYear) {
    $filterYear = (int)$activeYear['school_year_id'];
}

$departments = $pdo->query("SELECT department_id, department_name FROM departments ORDER BY department_name")->fetchAll(PDO::FETCH_ASSOC);
$leaveTypes  = $pdo->query("SELECT leave_type_id, leave_name FROM leave_types ORDER BY leave_name")->fetchAll(PDO::FETCH_ASSOC);

// ── Current school year row ────────────────────────────────────────────────
$currentYear = null;
if ($filterYear) {
    $stmt = $pdo->prepare("SELECT * FROM school_years WHERE school_year_id = ?");
    $stmt->execute([$filterYear]);
    $currentYear = $stmt->fetch(PDO::FETCH_ASSOC);
}

// ── Employee leave credit rows for this year ───────────────────────────────
$creditRows = [];
if ($filterYear) {
    $sql = "
        SELECT
            e.employee_id,
            e.employee_no,
            CONCAT(e.first_name, ' ', e.last_name) AS full_name,
            p.position_name,
            d.department_name,
            d.department_id,
            COALESCE(e.sex, '') AS sex,
            lt.leave_type_id,
            lt.leave_name,
            COALESCE(elc.credit_id,      0)  AS credit_id,
            COALESCE(elc.allocated_days, 0)  AS allocated_days,
            COALESCE(elc.used_days,      0)  AS used_days,
            COALESCE(elc.allocated_days - elc.used_days, 0) AS remaining_days,
            elc.notes
        FROM employees e
        JOIN positions   p ON p.position_id   = e.position_id
        JOIN departments d ON d.department_id  = e.department_id
        CROSS JOIN leave_types lt
        LEFT JOIN employee_leave_credits elc
               ON elc.employee_id    = e.employee_id
              AND elc.school_year_id = ?
              AND elc.leave_type_id  = lt.leave_type_id
        WHERE e.employee_status = 'ACTIVE'
    ";
    $params = [$filterYear];

    if ($filterDept) { $sql .= " AND d.department_id = ?"; $params[] = $filterDept; }
    if ($filterType) { $sql .= " AND lt.leave_type_id = ?"; $params[] = $filterType; }

    $sql .= " ORDER BY d.department_name, e.last_name, e.first_name, lt.leave_name";

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $creditRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// ── Gender-based leave type visibility ────────────────────────────────────
function leaveVisibleForSex(string $sex, string $leaveName): bool {
    $lower = strtolower($leaveName);
    if ($sex === 'MALE'   && strpos($lower, 'matern') !== false) return false;
    if ($sex === 'FEMALE' && strpos($lower, 'patern') !== false) return false;
    return true;
}

// ── Group rows by employee ─────────────────────────────────────────────────
$employees = [];
foreach ($creditRows as $r) {
    $eid = (int)$r['employee_id'];
    if (!isset($employees[$eid])) {
        $empNo = $r['employee_no'] ?: ('EMP-' . str_pad($eid, 3, '0', STR_PAD_LEFT));
        $employees[$eid] = [
            'employee_id'     => $eid,
            'employee_no'     => $empNo,
            'full_name'       => $r['full_name'],
            'position_name'   => $r['position_name'],
            'department_name' => $r['department_name'],
            'department_id'   => (int)$r['department_id'],
            'sex'             => $r['sex'],
            'credits'         => [],
        ];
    }
    if (leaveVisibleForSex($r['sex'], $r['leave_name'])) {
        $employees[$eid]['credits'][] = $r;
    }
}
// Drop employees with no visible credits after gender filter
$employees = array_values(array_filter($employees, function($e) { return !empty($e['credits']); }));

// ── Summary stats (all visible employees, before search) ──────────────────
$totalAllocated  = 0;
$totalUsed       = 0;
$totalRemaining  = 0;
$allocatedCount  = 0;
$totalCreditRows = 0;
foreach ($employees as $emp) {
    foreach ($emp['credits'] as $r) {
        $totalAllocated  += (float)$r['allocated_days'];
        $totalUsed       += (float)$r['used_days'];
        $totalRemaining  += (float)$r['remaining_days'];
        if ((int)$r['credit_id'] > 0) $allocatedCount++;
        $totalCreditRows++;
    }
}
$totalVisibleEmp = count($employees);

// ── Search filter ──────────────────────────────────────────────────────────
if ($search !== '') {
    $s = strtolower($search);
    $employees = array_values(array_filter($employees, function($emp) use ($s) {
        return strpos(strtolower($emp['full_name']), $s) !== false
            || strpos(strtolower($emp['employee_no']), $s) !== false
            || strpos(strtolower($emp['position_name']), $s) !== false;
    }));
}
$totalEmpCount = count($employees);

// ── Pagination ────────────────────────────────────────────────────────────
$totalPages  = max(1, (int)ceil($totalEmpCount / $perPage));
$currentPage = min($currentPage, $totalPages);
$pageOffset  = ($currentPage - 1) * $perPage;
$pageEmployees = array_slice($employees, $pageOffset, $perPage);

// ── All active employees for bulk preview ─────────────────────────────────
$allEmpForBulk = [];
if ($filterYear) {
    $allEmpForBulk = $pdo->query(
        "SELECT employee_id, department_id, COALESCE(sex, '') AS sex
         FROM employees WHERE employee_status = 'ACTIVE'"
    )->fetchAll(PDO::FETCH_ASSOC);
}

// ── Flash ──────────────────────────────────────────────────────────────────
$flashOk  = $_SESSION['lc_success'] ?? '';
$flashErr = $_SESSION['lc_error']   ?? '';
unset($_SESSION['lc_success'], $_SESSION['lc_error']);

// ── URL helper ────────────────────────────────────────────────────────────
function lcUrl(array $overrides = []): string {
    global $filterYear, $filterDept, $filterType, $search, $currentPage;
    $base = [
        'school_year_id' => $filterYear ?: '',
        'department_id'  => $filterDept ?: '',
        'leave_type_id'  => $filterType ?: '',
        'q'              => $search,
        'page'           => $currentPage,
    ];
    $merged = array_merge($base, $overrides);
    $out    = array_filter($merged, function($v) { return $v !== '' && $v !== 0 && $v !== null; });
    return '?' . http_build_query($out);
}

$pageTitle = 'Leave Credit Allocation';
$extraCSS  = [BASE_URL . 'assets/css/school-years.css', BASE_URL . 'assets/css/leave-credits.css'];
require_once __DIR__ . '/../../includes/head.php';
?>
<body>
<div class="layout">
<?php include __DIR__ . '/../../includes/sidebar.php'; ?>
<div class="main">
<?php include __DIR__ . '/../../includes/header.php'; ?>
<div class="main-content">

<div class="lc-page">

  <!-- Page Header -->
  <div class="sy-page-header">
    <div>
      <h1>Leave Credit Allocation</h1>
      <p>Allocate paid leave days to employees per school year and leave type.</p>
    </div>
    <?php if ($filterYear): ?>
    <button class="sy-btn-primary" onclick="openBulkModal()">
      <i class="fa fa-layer-group"></i> Bulk Allocate
    </button>
    <?php endif; ?>
  </div>

  <!-- Flash alerts -->
  <?php if ($flashOk): ?>
    <div class="sy-alert sy-alert--ok"><i class="fa fa-circle-check"></i>
      <?= htmlspecialchars($flashOk) ?>
      <button onclick="this.parentElement.remove()">×</button>
    </div>
  <?php endif; ?>
  <?php if ($flashErr): ?>
    <div class="sy-alert sy-alert--err"><i class="fa fa-triangle-exclamation"></i>
      <?= htmlspecialchars($flashErr) ?>
      <button onclick="this.parentElement.remove()">×</button>
    </div>
  <?php endif; ?>

  <?php if (empty($schoolYears)): ?>
  <div class="sy-alert sy-alert--warn">
    <i class="fa fa-triangle-exclamation"></i>
    No school years defined. <a href="<?= BASE_URL ?>modules/school-years/index.php">Create one first.</a>
  </div>
  <?php else: ?>

  <!-- Filter bar -->
  <form method="GET" class="lc-filter-bar" id="filterForm">
    <input type="hidden" name="q" value="<?= htmlspecialchars($search) ?>">
    <div class="lc-filter-group">
      <label>School Year</label>
      <select name="school_year_id" onchange="this.form.submit()">
        <option value="">— Select —</option>
        <?php foreach ($schoolYears as $sy): ?>
        <option value="<?= $sy['school_year_id'] ?>"
                <?= $sy['school_year_id'] == $filterYear ? 'selected' : '' ?>>
          <?= htmlspecialchars($sy['year_name']) ?>
          <?= $sy['is_active'] ? ' ★ Active' : '' ?>
        </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="lc-filter-group">
      <label>Department</label>
      <select name="department_id" onchange="this.form.submit()">
        <option value="">All Departments</option>
        <?php foreach ($departments as $d): ?>
        <option value="<?= $d['department_id'] ?>"
                <?= $d['department_id'] == $filterDept ? 'selected' : '' ?>>
          <?= htmlspecialchars($d['department_name']) ?>
        </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="lc-filter-group">
      <label>Leave Type</label>
      <select name="leave_type_id" onchange="this.form.submit()">
        <option value="">All Types</option>
        <?php foreach ($leaveTypes as $lt): ?>
        <option value="<?= $lt['leave_type_id'] ?>"
                <?= $lt['leave_type_id'] == $filterType ? 'selected' : '' ?>>
          <?= htmlspecialchars($lt['leave_name']) ?>
        </option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php if ($filterDept || $filterType): ?>
    <a href="<?= lcUrl(['department_id'=>'','leave_type_id'=>'','page'=>1]) ?>" class="lc-clear-link">
      <i class="fa fa-xmark"></i> Clear filters
    </a>
    <?php endif; ?>
  </form>

  <?php if (!$filterYear): ?>
  <div class="sy-alert sy-alert--warn" style="margin-top:8px;">
    <i class="fa fa-hand-point-up"></i> Select a school year above to view and edit allocations.
  </div>
  <?php else: ?>

  <!-- Summary cards -->
  <div class="lc-stats-row">
    <div class="lc-stat">
      <div class="lc-stat-label">School Year</div>
      <div class="lc-stat-value" style="font-size:16px;"><?= htmlspecialchars($currentYear['year_name'] ?? '—') ?></div>
    </div>
    <div class="lc-stat">
      <div class="lc-stat-label">Employees</div>
      <div class="lc-stat-value"><?= $totalVisibleEmp ?></div>
    </div>
    <div class="lc-stat">
      <div class="lc-stat-label">Allocated Records</div>
      <div class="lc-stat-value"><?= $allocatedCount ?> / <?= $totalCreditRows ?></div>
    </div>
    <div class="lc-stat">
      <div class="lc-stat-label">Total Allocated</div>
      <div class="lc-stat-value"><?= number_format($totalAllocated, 1) ?> <small style="font-size:13px;font-weight:400;">days</small></div>
    </div>
    <div class="lc-stat">
      <div class="lc-stat-label">Total Used</div>
      <div class="lc-stat-value lc-stat-value--red"><?= number_format($totalUsed, 1) ?> <small style="font-size:13px;font-weight:400;">days</small></div>
    </div>
    <div class="lc-stat">
      <div class="lc-stat-label">Total Remaining</div>
      <div class="lc-stat-value lc-stat-value--teal"><?= number_format($totalRemaining, 1) ?> <small style="font-size:13px;font-weight:400;">days</small></div>
    </div>
  </div>

  <!-- Search bar -->
  <form method="GET" class="lc-search-bar" id="searchForm">
    <input type="hidden" name="school_year_id" value="<?= $filterYear ?>">
    <?php if ($filterDept): ?><input type="hidden" name="department_id" value="<?= $filterDept ?>"><?php endif; ?>
    <?php if ($filterType): ?><input type="hidden" name="leave_type_id" value="<?= $filterType ?>"><?php endif; ?>
    <input type="hidden" name="page" value="1">
    <div class="lc-search-wrap">
      <i class="fa fa-magnifying-glass lc-search-icon"></i>
      <input type="text" name="q" id="searchInput"
             value="<?= htmlspecialchars($search) ?>"
             placeholder="Search by name, ID, or position…"
             class="lc-search-input"
             autocomplete="off">
      <?php if ($search): ?>
      <a href="<?= lcUrl(['q'=>'','page'=>1]) ?>" class="lc-search-clear" title="Clear search">
        <i class="fa fa-xmark"></i>
      </a>
      <?php endif; ?>
    </div>
    <button type="submit" class="sy-btn-sm sy-btn-sm--outline" style="height:38px;">Search</button>
  </form>

  <!-- Result count -->
  <?php if ($totalEmpCount === 0 && $filterYear): ?>
  <div class="sy-empty">
    <i class="fa fa-calendar-days"></i>
    <h3><?= $search ? 'No employees match your search' : 'No employees found' ?></h3>
    <p><?= $search ? 'Try a different name, ID, or position.' : 'No active employees match the selected filters.' ?></p>
    <?php if ($search): ?>
    <a href="<?= lcUrl(['q'=>'','page'=>1]) ?>" class="sy-btn-primary" style="text-decoration:none;">Clear Search</a>
    <?php endif; ?>
  </div>
  <?php else: ?>

  <!-- Result info row -->
  <div class="lc-result-info">
    <span>
      Showing <strong><?= count($pageEmployees) ?></strong>
      of <strong><?= $totalEmpCount ?></strong> employee<?= $totalEmpCount !== 1 ? 's' : '' ?>
      <?= $search ? ' matching <em>' . htmlspecialchars($search) . '</em>' : '' ?>
    </span>
    <?php if ($totalPages > 1): ?>
    <span class="lc-result-page">Page <?= $currentPage ?> of <?= $totalPages ?></span>
    <?php endif; ?>
  </div>

  <!-- Employee accordion list -->
  <div class="lc-accordion" id="empAccordion">
  <?php foreach ($pageEmployees as $emp):
    $empSex = $emp['sex'];
    $nameParts = explode(' ', trim($emp['full_name']));
    $initials  = strtoupper(
        substr($nameParts[0], 0, 1) .
        substr(end($nameParts), 0, 1)
    );
    $empAllocated  = array_sum(array_column($emp['credits'], 'allocated_days'));
    $empUsed       = array_sum(array_column($emp['credits'], 'used_days'));
    $empRemaining  = $empAllocated - $empUsed;
    $empHasCredits = count(array_filter($emp['credits'], function($c) { return (int)$c['credit_id'] > 0; }));
    $remClass = $empRemaining < 0 ? 'lc-neg' : ($empRemaining == 0 && $empAllocated > 0 ? 'lc-zero' : '');
    $sexLabel = ['MALE' => 'Male', 'FEMALE' => 'Female', 'OTHER' => 'Other'][$empSex] ?? 'N/A';
    $sexClass = ['MALE' => 'lc-sex--male', 'FEMALE' => 'lc-sex--female', 'OTHER' => 'lc-sex--other'][$empSex] ?? 'lc-sex--other';
  ?>
  <div class="lc-emp-card" id="emp-card-<?= $emp['employee_id'] ?>">
    <div class="lc-emp-header" onclick="toggleEmp(<?= $emp['employee_id'] ?>)" role="button" tabindex="0"
         onkeydown="if(event.key==='Enter'||event.key===' ')toggleEmp(<?= $emp['employee_id'] ?>)">
      <div class="lc-emp-avatar"><?= $initials ?></div>
      <div class="lc-emp-info">
        <div class="lc-emp-name">
          <?= htmlspecialchars($emp['full_name']) ?>
        </div>
        <div class="lc-emp-meta">
          <span><?= htmlspecialchars($emp['employee_no']) ?></span>
          <span class="lc-meta-dot">·</span>
          <span><?= htmlspecialchars($emp['position_name']) ?></span>
          <span class="lc-meta-dot">·</span>
          <span><?= htmlspecialchars($emp['department_name']) ?></span>
          <?php if ($sexLabel !== 'N/A'): ?>
          <span class="lc-meta-dot">·</span>
          <span class="lc-sex-badge"><?= $sexLabel ?></span>
          <?php endif; ?>
        </div>
      </div>
      <div class="lc-emp-summary">
        <div class="lc-emp-sum-item">
          <span class="lc-emp-sum-label"><?= count($emp['credits']) ?> type<?= count($emp['credits']) !== 1 ? 's' : '' ?></span>
        </div>
        <?php if ($empAllocated > 0): ?>
        <div class="lc-emp-sum-item">
          <span class="lc-emp-sum-label">Rem:</span>
          <span class="lc-emp-sum-val <?= $remClass ?>"><?= number_format($empRemaining, 1) ?></span>
        </div>
        <?php elseif ($empHasCredits == 0): ?>
        <div class="lc-emp-sum-item">
          <span class="lc-badge-unset">Not set</span>
        </div>
        <?php endif; ?>
      </div>
      <div class="lc-emp-toggle-icon"><i class="fa fa-chevron-down"></i></div>
    </div>

    <div class="lc-emp-body" id="emp-body-<?= $emp['employee_id'] ?>">
      <table class="lc-credits-inner">
        <thead>
          <tr>
            <th>Leave Type</th>
            <th class="lc-th-num">Allocated</th>
            <th class="lc-th-num">Used</th>
            <th class="lc-th-num">Remaining</th>
            <th class="lc-th-action">Edit</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach ($emp['credits'] as $r):
          $rem      = (float)$r['remaining_days'];
          $hasRec   = (int)$r['credit_id'] > 0;
          $rCls     = $rem < 0 ? 'lc-neg' : ($rem == 0 && $hasRec ? 'lc-zero' : ($hasRec ? 'lc-pos' : ''));
          $payload  = json_encode([
              'credit_id'       => (int)$r['credit_id'],
              'employee_id'     => (int)$r['employee_id'],
              'school_year_id'  => $filterYear,
              'leave_type_id'   => (int)$r['leave_type_id'],
              'full_name'       => $r['full_name'],
              'department_name' => $emp['department_name'],
              'sex_label'       => $sexLabel,
              'leave_name'      => $r['leave_name'],
              'allocated_days'  => (float)$r['allocated_days'],
              'used_days'       => (float)$r['used_days'],
              'notes'           => $r['notes'] ?? '',
          ]);
        ?>
        <tr>
          <td class="lc-leave-type-cell"><?= htmlspecialchars($r['leave_name']) ?></td>
          <td class="lc-num-cell">
            <?= $hasRec ? number_format((float)$r['allocated_days'], 1) : '<span class="lc-dash">—</span>' ?>
          </td>
          <td class="lc-num-cell">
            <?= $hasRec ? number_format((float)$r['used_days'], 1) : '<span class="lc-dash">—</span>' ?>
          </td>
          <td class="lc-num-cell <?= $rCls ?>">
            <?= $hasRec ? number_format($rem, 1) : '<span class="lc-dash">—</span>' ?>
          </td>
          <td class="lc-action-cell">
            <button class="sy-btn-sm sy-btn-sm--outline lc-edit-btn"
                    onclick="openEditCredit(<?= htmlspecialchars($payload, ENT_QUOTES) ?>)"
                    title="Edit <?= htmlspecialchars($r['leave_name']) ?> for <?= htmlspecialchars($r['full_name']) ?>">
              <i class="fa fa-pen"></i>
            </button>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
  <?php endforeach; ?>
  </div><!-- .lc-accordion -->

  <!-- Pagination -->
  <?php if ($totalPages > 1): ?>
  <div class="lc-pagination">
    <?php if ($currentPage > 1): ?>
    <a href="<?= lcUrl(['page' => $currentPage - 1]) ?>" class="lc-page-btn">
      <i class="fa fa-chevron-left"></i> Prev
    </a>
    <?php else: ?>
    <span class="lc-page-btn lc-page-btn--disabled"><i class="fa fa-chevron-left"></i> Prev</span>
    <?php endif; ?>

    <?php
    $windowStart = max(1, $currentPage - 2);
    $windowEnd   = min($totalPages, $currentPage + 2);
    if ($windowStart > 1): ?>
    <a href="<?= lcUrl(['page' => 1]) ?>" class="lc-page-num">1</a>
    <?php if ($windowStart > 2): ?><span class="lc-page-ellipsis">…</span><?php endif; ?>
    <?php endif; ?>

    <?php for ($p = $windowStart; $p <= $windowEnd; $p++): ?>
    <?php if ($p == $currentPage): ?>
    <span class="lc-page-num lc-page-num--active"><?= $p ?></span>
    <?php else: ?>
    <a href="<?= lcUrl(['page' => $p]) ?>" class="lc-page-num"><?= $p ?></a>
    <?php endif; ?>
    <?php endfor; ?>

    <?php if ($windowEnd < $totalPages): ?>
    <?php if ($windowEnd < $totalPages - 1): ?><span class="lc-page-ellipsis">…</span><?php endif; ?>
    <a href="<?= lcUrl(['page' => $totalPages]) ?>" class="lc-page-num"><?= $totalPages ?></a>
    <?php endif; ?>

    <?php if ($currentPage < $totalPages): ?>
    <a href="<?= lcUrl(['page' => $currentPage + 1]) ?>" class="lc-page-btn">
      Next <i class="fa fa-chevron-right"></i>
    </a>
    <?php else: ?>
    <span class="lc-page-btn lc-page-btn--disabled">Next <i class="fa fa-chevron-right"></i></span>
    <?php endif; ?>
  </div>
  <?php endif; ?>

  <?php endif; // totalEmpCount ?>
  <?php endif; // filterYear ?>
  <?php endif; // schoolYears ?>

</div><!-- .lc-page -->
</div><!-- .main-content -->
</div><!-- .main -->
</div><!-- .layout -->

<!-- ── Edit Individual Credit Modal ───────────────────────────────────────── -->
<div class="sy-modal-overlay" id="editCreditModal" style="display:none;">
  <div class="sy-modal-box" style="max-width:500px;">
    <div class="sy-modal-header">
      <h3><i class="fa fa-pen"></i> Edit Leave Credit</h3>
      <button onclick="document.getElementById('editCreditModal').style.display='none'">
        <i class="fa fa-times"></i>
      </button>
    </div>
    <form method="POST" action="<?= BASE_URL ?>actions/leave-credits-action.php">
      <input type="hidden" name="action"         value="save">
      <input type="hidden" name="credit_id"      id="ecCreditId"     value="">
      <input type="hidden" name="employee_id"    id="ecEmployeeId"   value="">
      <input type="hidden" name="school_year_id" id="ecSchoolYearId" value="">
      <input type="hidden" name="leave_type_id"  id="ecLeaveTypeId"  value="">
      <input type="hidden" name="used_days"      id="ecUsedHidden"   value="0">
      <input type="hidden" name="redirect"       value="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">

      <div class="sy-modal-body">

        <!-- Read-only info grid -->
        <div class="lc-edit-info-grid">
          <div class="lc-info-field">
            <span class="lc-info-label">Employee</span>
            <span class="lc-info-val" id="ecEmployeeName">—</span>
          </div>
          <div class="lc-info-field">
            <span class="lc-info-label">Department</span>
            <span class="lc-info-val" id="ecDepartment">—</span>
          </div>
          <div class="lc-info-field">
            <span class="lc-info-label">Gender</span>
            <span class="lc-info-val" id="ecGender">—</span>
          </div>
          <div class="lc-info-field">
            <span class="lc-info-label">Leave Type</span>
            <span class="lc-info-val" id="ecLeaveTypeName">—</span>
          </div>
        </div>

        <!-- Editable fields -->
        <div class="sy-form-row sy-form-row--2" style="margin-top:16px;">
          <div class="sy-form-group">
            <label>Allocated Days <span class="req">*</span></label>
            <input type="number" name="allocated_days" id="ecAllocated"
                   step="0.5" min="0" max="365" required
                   oninput="updateEcRemaining()">
          </div>
          <div class="sy-form-group">
            <label>Used Days</label>
            <div class="lc-readonly-field" id="ecUsedDisplay">0</div>
            <small>Auto-tracked by leave approvals.</small>
          </div>
        </div>

        <!-- Live remaining preview -->
        <div class="lc-remaining-preview">
          <span class="lc-remaining-label">Remaining Days Preview</span>
          <span class="lc-remaining-val" id="ecRemainingPreview">—</span>
        </div>

        <div class="sy-form-row" style="margin-top:12px;">
          <div class="sy-form-group">
            <label>Notes</label>
            <input type="text" name="notes" id="ecNotes" maxlength="255"
                   placeholder="Optional note about this allocation…">
          </div>
        </div>
      </div>

      <div class="sy-modal-footer">
        <button type="button" class="sy-btn-ghost"
                onclick="document.getElementById('editCreditModal').style.display='none'">Cancel</button>
        <button type="submit" class="sy-btn-primary">
          <i class="fa fa-floppy-disk"></i> Save
        </button>
      </div>
    </form>
  </div>
</div>

<!-- ── Bulk Allocate Modal ─────────────────────────────────────────────────── -->
<div class="sy-modal-overlay" id="bulkModal" style="display:none;">
  <div class="sy-modal-box" style="max-width:520px;">
    <div class="sy-modal-header">
      <h3><i class="fa fa-layer-group"></i> Bulk Allocate Leave Credits</h3>
      <button onclick="document.getElementById('bulkModal').style.display='none'">
        <i class="fa fa-times"></i>
      </button>
    </div>
    <form method="POST" action="<?= BASE_URL ?>actions/leave-credits-action.php"
          onsubmit="confirmBulk(event)">
      <input type="hidden" name="action"         value="bulk_allocate">
      <input type="hidden" name="school_year_id" value="<?= $filterYear ?>">
      <input type="hidden" name="redirect"       value="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">

      <div class="sy-modal-body">
        <p class="lc-bulk-desc">
          Sets the allocated days for the selected leave type and department.
          Existing records are updated; employees without a record get one created.
          <strong>Used days are not reset.</strong>
        </p>

        <div class="sy-form-row">
          <div class="sy-form-group">
            <label>School Year</label>
            <input type="text" value="<?= htmlspecialchars($currentYear['year_name'] ?? '—') ?>" disabled>
          </div>
        </div>

        <div class="sy-form-row sy-form-row--2">
          <div class="sy-form-group">
            <label>Leave Type <span class="req">*</span></label>
            <select name="leave_type_id" id="bulkLeaveType" required onchange="updateBulkPreview()">
              <option value="">— Select —</option>
              <?php foreach ($leaveTypes as $lt): ?>
              <option value="<?= $lt['leave_type_id'] ?>"
                      data-name="<?= htmlspecialchars(strtolower($lt['leave_name'])) ?>"
                      <?= $lt['leave_type_id'] == $filterType ? 'selected' : '' ?>>
                <?= htmlspecialchars($lt['leave_name']) ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="sy-form-group">
            <label>Allocated Days <span class="req">*</span></label>
            <input type="number" name="allocated_days" step="0.5" min="0" max="365" required
                   placeholder="e.g. 30">
          </div>
        </div>

        <div class="sy-form-row">
          <div class="sy-form-group">
            <label>Department (optional)</label>
            <select name="department_id" id="bulkDept" onchange="updateBulkPreview()">
              <option value="">All Departments</option>
              <?php foreach ($departments as $d): ?>
              <option value="<?= $d['department_id'] ?>"
                      <?= $d['department_id'] == $filterDept ? 'selected' : '' ?>>
                <?= htmlspecialchars($d['department_name']) ?>
              </option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <!-- Gender restriction notice -->
        <div class="lc-gender-notice" id="bulkGenderNotice" style="display:none;">
          <i class="fa fa-circle-info"></i>
          <span id="bulkGenderNoticeText"></span>
        </div>

        <!-- Preview count -->
        <div class="lc-bulk-preview">
          <i class="fa fa-users"></i>
          This will update <strong id="bulkPreviewCount">—</strong> employee(s).
        </div>

        <div class="sy-form-row" style="margin-top:4px;">
          <div class="sy-form-group">
            <label>Notes (applied to all records)</label>
            <input type="text" name="notes" maxlength="255"
                   placeholder="e.g. Annual leave allocation AY 2025-2026">
          </div>
        </div>
      </div>

      <div class="sy-modal-footer">
        <button type="button" class="sy-btn-ghost"
                onclick="document.getElementById('bulkModal').style.display='none'">Cancel</button>
        <button type="submit" class="sy-btn-primary">
          <i class="fa fa-layer-group"></i> Apply Bulk Allocation
        </button>
      </div>
    </form>
  </div>
</div>

<script>
// ── Employee accordion ──────────────────────────────────────────────────────
function toggleEmp(id) {
    var body = document.getElementById('emp-body-' + id);
    var card = document.getElementById('emp-card-' + id);
    var open = body.style.display !== 'none' && body.style.display !== '';
    body.style.display = open ? 'none' : 'block';
    card.classList.toggle('lc-emp-card--open', !open);
}

// ── Edit credit modal ───────────────────────────────────────────────────────
function openEditCredit(r) {
    document.getElementById('ecCreditId').value     = r.credit_id;
    document.getElementById('ecEmployeeId').value   = r.employee_id;
    document.getElementById('ecSchoolYearId').value = r.school_year_id;
    document.getElementById('ecLeaveTypeId').value  = r.leave_type_id;
    document.getElementById('ecUsedHidden').value   = r.used_days;
    document.getElementById('ecAllocated').value    = r.allocated_days;
    document.getElementById('ecNotes').value        = r.notes || '';

    document.getElementById('ecEmployeeName').textContent  = r.full_name;
    document.getElementById('ecDepartment').textContent    = r.department_name;
    document.getElementById('ecGender').textContent        = r.sex_label;
    document.getElementById('ecLeaveTypeName').textContent = r.leave_name;
    document.getElementById('ecUsedDisplay').textContent   = parseFloat(r.used_days).toFixed(1);

    updateEcRemaining();
    document.getElementById('editCreditModal').style.display = 'flex';
}

function updateEcRemaining() {
    var allocated = parseFloat(document.getElementById('ecAllocated').value) || 0;
    var used      = parseFloat(document.getElementById('ecUsedHidden').value) || 0;
    var remaining = allocated - used;
    var el = document.getElementById('ecRemainingPreview');
    el.textContent = remaining.toFixed(1) + ' days';
    el.className = 'lc-remaining-val ' + (remaining < 0 ? 'lc-neg' : remaining === 0 ? 'lc-zero' : 'lc-pos');
}

// ── Bulk modal ─────────────────────────────────────────────────────────────
var allEmpForBulk = <?= json_encode(array_values($allEmpForBulk)) ?>;

function openBulkModal() {
    document.getElementById('bulkModal').style.display = 'flex';
    updateBulkPreview();
}

function updateBulkPreview() {
    var ltSel  = document.getElementById('bulkLeaveType');
    var deptSel = document.getElementById('bulkDept');
    var ltId   = parseInt(ltSel ? ltSel.value : 0) || 0;
    var deptId = parseInt(deptSel ? deptSel.value : 0) || 0;
    var notice = document.getElementById('bulkGenderNotice');
    var noticeText = document.getElementById('bulkGenderNoticeText');
    var countEl = document.getElementById('bulkPreviewCount');

    if (!ltId) {
        countEl.textContent = '—';
        notice.style.display = 'none';
        return;
    }

    var ltName = (ltSel.options[ltSel.selectedIndex].getAttribute('data-name') || '').toLowerCase();
    var isMaternity = ltName.indexOf('matern') !== -1;
    var isPaternity = ltName.indexOf('patern') !== -1;

    var eligible = allEmpForBulk.slice();
    if (deptId) {
        eligible = eligible.filter(function(e) { return parseInt(e.department_id) === deptId; });
    }
    if (isMaternity) {
        eligible = eligible.filter(function(e) { return e.sex === 'FEMALE' || e.sex === ''; });
        notice.style.display = 'flex';
        noticeText.textContent = 'Maternity leave will only be applied to female employees.';
    } else if (isPaternity) {
        eligible = eligible.filter(function(e) { return e.sex === 'MALE' || e.sex === ''; });
        notice.style.display = 'flex';
        noticeText.textContent = 'Paternity leave will only be applied to male employees.';
    } else {
        notice.style.display = 'none';
    }

    countEl.textContent = eligible.length;
}

function confirmBulk(e) {
    e.preventDefault();
    var lt      = document.getElementById('bulkLeaveType');
    var days    = document.querySelector('#bulkModal input[name=allocated_days]');
    var ltText  = lt ? (lt.options[lt.selectedIndex] && lt.options[lt.selectedIndex].value ? lt.options[lt.selectedIndex].text : 'selected type') : 'selected type';
    var daysVal = days ? (days.value || '?') : '?';
    var count   = document.getElementById('bulkPreviewCount').textContent;
    GEI.confirm({
        title:       'Apply Bulk Allocation',
        message:     'Allocate ' + daysVal + ' day(s) of "' + ltText + '" to ' + count + ' employee(s)?',
        note:        'Existing allocations will be overwritten. Used days are preserved.',
        type:        'warning',
        confirmText: 'Apply Allocation',
    }).then(function() { e.target.submit(); }).catch(function() {});
}

// Close modals on overlay click
document.querySelectorAll('.sy-modal-overlay').forEach(function(el) {
    el.addEventListener('click', function(e) { if (e.target === el) el.style.display = 'none'; });
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
