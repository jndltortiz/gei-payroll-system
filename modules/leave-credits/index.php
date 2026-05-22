<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

// ── Filters ────────────────────────────────────────────────────────────────
$filterYear = (int)($_GET['school_year_id'] ?? 0);
$filterDept = (int)($_GET['department_id']  ?? 0);
$filterType = (int)($_GET['leave_type_id']  ?? 0);

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
            CONCAT(e.first_name, ' ', e.last_name) AS full_name,
            p.position_name,
            d.department_name,
            d.department_id,
            lt.leave_type_id,
            lt.leave_name,
            COALESCE(elc.credit_id,     0)    AS credit_id,
            COALESCE(elc.allocated_days, 0)   AS allocated_days,
            COALESCE(elc.used_days,      0)   AS used_days,
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

// ── Summary stats ──────────────────────────────────────────────────────────
$totalAllocated = array_sum(array_column($creditRows, 'allocated_days'));
$totalUsed      = array_sum(array_column($creditRows, 'used_days'));
$totalRemaining = array_sum(array_column($creditRows, 'remaining_days'));
$allocatedCount = count(array_filter($creditRows, fn($r) => $r['credit_id'] > 0));

// ── Flash ──────────────────────────────────────────────────────────────────
$flashOk  = $_SESSION['lc_success'] ?? '';
$flashErr = $_SESSION['lc_error']   ?? '';
unset($_SESSION['lc_success'], $_SESSION['lc_error']);

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
    <a href="?school_year_id=<?= $filterYear ?>" class="lc-clear-link">
      <i class="fa fa-xmark"></i> Clear filters
    </a>
    <?php endif; ?>
  </form>

  <?php if (!$filterYear): ?>
  <div class="sy-alert sy-alert--warn" style="margin-top:8px;">
    <i class="fa fa-hand-point-up"></i> Select a school year above to view and edit allocations.
  </div>
  <?php else: ?>

  <!-- Summary row -->
  <div class="lc-stats-row">
    <div class="lc-stat">
      <div class="lc-stat-label">School Year</div>
      <div class="lc-stat-value"><?= htmlspecialchars($currentYear['year_name'] ?? '—') ?></div>
    </div>
    <div class="lc-stat">
      <div class="lc-stat-label">Allocated Records</div>
      <div class="lc-stat-value"><?= $allocatedCount ?> / <?= count($creditRows) ?></div>
    </div>
    <div class="lc-stat">
      <div class="lc-stat-label">Total Allocated</div>
      <div class="lc-stat-value"><?= number_format($totalAllocated, 1) ?> days</div>
    </div>
    <div class="lc-stat">
      <div class="lc-stat-label">Total Used</div>
      <div class="lc-stat-value lc-stat-value--red"><?= number_format($totalUsed, 1) ?> days</div>
    </div>
    <div class="lc-stat">
      <div class="lc-stat-label">Total Remaining</div>
      <div class="lc-stat-value lc-stat-value--teal"><?= number_format($totalRemaining, 1) ?> days</div>
    </div>
  </div>

  <!-- Credits table -->
  <?php if (empty($creditRows)): ?>
  <div class="sy-empty">
    <i class="fa fa-calendar-days"></i>
    <h3>No employees found</h3>
    <p>No active employees match the selected filters.</p>
  </div>
  <?php else: ?>
  <div class="sy-table-wrap">
    <table class="sy-table lc-table">
      <thead>
        <tr>
          <th>Employee</th>
          <th>Department</th>
          <th>Leave Type</th>
          <th style="text-align:right;">Allocated</th>
          <th style="text-align:right;">Used</th>
          <th style="text-align:right;">Remaining</th>
          <th>Edit</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($creditRows as $r):
        $remaining = (float)$r['remaining_days'];
        $remClass  = $remaining < 0 ? 'lc-neg' : ($remaining == 0 ? 'lc-zero' : 'lc-pos');
      ?>
      <tr>
        <td>
          <strong><?= htmlspecialchars($r['full_name']) ?></strong><br>
          <small style="color:#94a3b8;"><?= htmlspecialchars($r['position_name']) ?></small>
        </td>
        <td style="color:#64748b;"><?= htmlspecialchars($r['department_name']) ?></td>
        <td><?= htmlspecialchars($r['leave_name']) ?></td>
        <td style="text-align:right;font-weight:600;">
          <?= $r['credit_id'] > 0 ? number_format((float)$r['allocated_days'], 1) : '<span style="color:#94a3b8;">—</span>' ?>
        </td>
        <td style="text-align:right;">
          <?= $r['credit_id'] > 0 ? number_format((float)$r['used_days'], 1) : '<span style="color:#94a3b8;">—</span>' ?>
        </td>
        <td style="text-align:right;" class="<?= $r['credit_id'] > 0 ? $remClass : '' ?>">
          <?= $r['credit_id'] > 0 ? number_format($remaining, 1) : '<span style="color:#94a3b8;">—</span>' ?>
        </td>
        <td>
          <button class="sy-btn-sm sy-btn-sm--outline"
                  onclick="openEditCredit(<?= htmlspecialchars(json_encode([
                      'credit_id'      => (int)$r['credit_id'],
                      'employee_id'    => (int)$r['employee_id'],
                      'school_year_id' => $filterYear,
                      'leave_type_id'  => (int)$r['leave_type_id'],
                      'full_name'      => $r['full_name'],
                      'leave_name'     => $r['leave_name'],
                      'allocated_days' => (float)$r['allocated_days'],
                      'used_days'      => (float)$r['used_days'],
                      'notes'          => $r['notes'] ?? '',
                  ])) ?>)">
            <i class="fa fa-pen"></i>
          </button>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>
  <?php endif; // filterYear ?>
  <?php endif; // schoolYears ?>

</div><!-- .lc-page -->
</div><!-- .main-content -->
</div><!-- .main -->
</div><!-- .layout -->

<!-- Edit Individual Credit Modal -->
<div class="sy-modal-overlay" id="editCreditModal" style="display:none;">
  <div class="sy-modal-box">
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
      <input type="hidden" name="redirect"       value="<?= htmlspecialchars($_SERVER['REQUEST_URI']) ?>">

      <div class="sy-modal-body">
        <div class="lc-edit-info" id="ecInfo">—</div>

        <div class="sy-form-row sy-form-row--2" style="margin-top:14px;">
          <div class="sy-form-group">
            <label>Allocated Days <span class="req">*</span></label>
            <input type="number" name="allocated_days" id="ecAllocated"
                   step="0.5" min="0" max="365" required>
          </div>
          <div class="sy-form-group">
            <label>Used Days (auto-tracked)</label>
            <input type="number" name="used_days" id="ecUsed"
                   step="0.5" min="0" max="365">
            <small>Normally auto-updated by leave approvals.</small>
          </div>
        </div>

        <div class="sy-form-row">
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

<!-- Bulk Allocate Modal -->
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
        <p style="font-size:13px;color:#64748b;margin:0 0 16px;">
          Sets the allocated days for <strong>all active employees</strong> for the selected leave type.
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
            <select name="leave_type_id" required>
              <option value="">— Select —</option>
              <?php foreach ($leaveTypes as $lt): ?>
              <option value="<?= $lt['leave_type_id'] ?>"
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
            <select name="department_id">
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

        <div class="sy-form-row">
          <div class="sy-form-group">
            <label>Notes (applied to all records)</label>
            <input type="text" name="notes" maxlength="255" placeholder="e.g. Annual leave allocation AY 2025-2026">
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
function openEditCredit(r) {
    document.getElementById('ecCreditId').value     = r.credit_id;
    document.getElementById('ecEmployeeId').value   = r.employee_id;
    document.getElementById('ecSchoolYearId').value = r.school_year_id;
    document.getElementById('ecLeaveTypeId').value  = r.leave_type_id;
    document.getElementById('ecAllocated').value    = r.allocated_days;
    document.getElementById('ecUsed').value         = r.used_days;
    document.getElementById('ecNotes').value        = r.notes || '';
    document.getElementById('ecInfo').textContent   =
        r.full_name + ' — ' + r.leave_name;
    document.getElementById('editCreditModal').style.display = 'flex';
}

function openBulkModal() {
    document.getElementById('bulkModal').style.display = 'flex';
}

function confirmBulk(e) {
    e.preventDefault();
    const lt     = document.querySelector('#bulkModal select[name=leave_type_id]');
    const days   = document.querySelector('#bulkModal input[name=allocated_days]');
    const ltText = lt ? (lt.options[lt.selectedIndex]?.text || 'selected type') : 'selected type';
    const daysVal = days ? (days.value || '?') : '?';
    GEI.confirm({
        title:       'Apply Bulk Allocation',
        message:     'Allocate ' + daysVal + ' days of "' + ltText + '" to all active employees?',
        note:        'Existing allocations will be overwritten. Used days are preserved.',
        type:        'warning',
        confirmText: 'Apply Allocation',
    }).then(() => e.target.submit()).catch(() => {});
}

document.querySelectorAll('.sy-modal-overlay').forEach(el => {
    el.addEventListener('click', e => { if (e.target === el) el.style.display = 'none'; });
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
