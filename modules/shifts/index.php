<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

// ── Flash messages ────────────────────────────────────────────────────────────
$flashOk  = $_SESSION['sh_success'] ?? '';
$flashErr = $_SESSION['sh_error']   ?? '';
unset($_SESSION['sh_success'], $_SESSION['sh_error']);

// ── Fetch all shifts with employee count ──────────────────────────────────────
$shifts = $pdo->query("
    SELECT s.*,
           COUNT(e.employee_id) AS employee_count
    FROM shifts s
    LEFT JOIN employees e ON e.shift_id = s.shift_id AND e.employee_status = 'ACTIVE'
    GROUP BY s.shift_id
    ORDER BY s.is_active DESC, s.shift_name ASC
")->fetchAll(PDO::FETCH_ASSOC);

$activeCount   = array_sum(array_column($shifts, 'is_active'));
$inactiveCount = count($shifts) - $activeCount;
$assignedTotal = array_sum(array_column($shifts, 'employee_count'));

// ── Fetch employees without a shift (for the info panel) ─────────────────────
$unassignedCount = (int)$pdo->query("
    SELECT COUNT(*) FROM employees WHERE shift_id IS NULL AND employee_status='ACTIVE'
")->fetchColumn();

$pageTitle = 'Shift Management';
$extraCSS  = [BASE_URL . 'assets/css/shifts.css'];
require_once __DIR__ . '/../../includes/head.php';
?>
<body>
<div class="layout">
<?php include __DIR__ . '/../../includes/sidebar.php'; ?>
<div class="main">
<?php include __DIR__ . '/../../includes/header.php'; ?>
<div class="main-content">

<div class="sh-page">

  <!-- Page Header -->
  <div class="sh-page-header">
    <div>
      <h1><i class="fa fa-clock"></i> Shift Management</h1>
      <p>Define work schedules. Shifts control attendance status logic: PRESENT, LATE, and HALF_DAY thresholds.</p>
    </div>
    <button class="sh-btn-primary" onclick="openCreateModal()">
      <i class="fa fa-plus"></i> Add Shift
    </button>
  </div>

  <!-- Flash alerts -->
  <?php if ($flashOk): ?>
  <div class="sh-alert sh-alert--ok">
    <i class="fa fa-circle-check"></i>
    <?= htmlspecialchars($flashOk) ?>
    <button onclick="this.parentElement.remove()">×</button>
  </div>
  <?php endif; ?>
  <?php if ($flashErr): ?>
  <div class="sh-alert sh-alert--err">
    <i class="fa fa-triangle-exclamation"></i>
    <?= htmlspecialchars($flashErr) ?>
    <button onclick="this.parentElement.remove()">×</button>
  </div>
  <?php endif; ?>

  <!-- Summary Cards -->
  <div class="sh-summary-cards">
    <div class="sh-sum-card">
      <div class="sh-sum-icon blue"><i class="fa fa-list"></i></div>
      <div>
        <div class="sh-sum-val"><?= count($shifts) ?></div>
        <div class="sh-sum-label">Total Shifts</div>
      </div>
    </div>
    <div class="sh-sum-card">
      <div class="sh-sum-icon green"><i class="fa fa-circle-check"></i></div>
      <div>
        <div class="sh-sum-val"><?= $activeCount ?></div>
        <div class="sh-sum-label">Active Shifts</div>
      </div>
    </div>
    <div class="sh-sum-card">
      <div class="sh-sum-icon teal"><i class="fa fa-users"></i></div>
      <div>
        <div class="sh-sum-val"><?= $assignedTotal ?></div>
        <div class="sh-sum-label">Assigned Employees</div>
      </div>
    </div>
    <div class="sh-sum-card">
      <div class="sh-sum-icon gray">
        <i class="fa fa-user-clock"></i>
      </div>
      <div>
        <div class="sh-sum-val"><?= $unassignedCount ?></div>
        <div class="sh-sum-label">Part-Time Employees</div>
      </div>
    </div>
  </div>

  <?php if ($unassignedCount > 0): ?>
  <div class="sh-alert sh-alert--info">
    <i class="fa fa-circle-info"></i>
    <strong><?= $unassignedCount ?> employee<?= $unassignedCount != 1 ? 's are' : ' is' ?> part-time (no shift required).</strong>
    Part-time employees are evaluated as <strong>PRESENT</strong> or <strong>LATE</strong> only — the half-day threshold does not apply to them.
    <a href="<?= BASE_URL ?>modules/employees/index.php" class="sh-link">
      Manage Employees <i class="fa fa-arrow-right fa-xs"></i>
    </a>
  </div>
  <?php endif; ?>

  <!-- Shifts Table -->
  <?php if (empty($shifts)): ?>
  <div class="sh-empty">
    <i class="fa fa-clock"></i>
    <h3>No shifts defined</h3>
    <p>Create a shift to enable automatic attendance status computation.</p>
    <button class="sh-btn-primary" onclick="openCreateModal()">
      <i class="fa fa-plus"></i> Add Shift
    </button>
  </div>
  <?php else: ?>
  <div class="sh-table-wrap">
    <table class="sh-table">
      <thead>
        <tr>
          <th class="sortable" data-col="0" data-sort-type="text">Shift Name</th>
          <th>Start Time</th>
          <th>End Time</th>
          <th>Duration</th>
          <th>Grace Period</th>
          <th>Half-Day At</th>
          <th class="sortable" data-col="6" data-sort-type="number">Employees</th>
          <th class="sortable" data-col="7" data-sort-type="text">Status</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($shifts as $s):
          // Compute duration
          $start = strtotime('1970-01-01 ' . $s['start_time']);
          $end   = strtotime('1970-01-01 ' . $s['end_time']);
          $hrs   = ($end - $start) / 3600;
          $durLabel = ($hrs == floor($hrs)) ? (int)$hrs . 'h' : number_format($hrs, 1) . 'h';

          $halfDayLabel = $s['half_day_time']
              ? date('h:i A', strtotime('1970-01-01 ' . $s['half_day_time']))
              : '<span class="sh-none">default (9:00 AM)</span>';
        ?>
        <tr class="<?= !$s['is_active'] ? 'sh-row--inactive' : '' ?>">
          <td>
            <strong><?= htmlspecialchars($s['shift_name']) ?></strong>
          </td>
          <td class="sh-time">
            <?= date('h:i A', strtotime('1970-01-01 ' . $s['start_time'])) ?>
          </td>
          <td class="sh-time">
            <?= date('h:i A', strtotime('1970-01-01 ' . $s['end_time'])) ?>
          </td>
          <td><span class="sh-dur-tag"><?= $durLabel ?></span></td>
          <td>
            <?= $s['grace_period_minutes'] > 0
                ? '<span class="sh-grace-tag">' . $s['grace_period_minutes'] . ' min</span>'
                : '<span class="sh-none">None</span>' ?>
          </td>
          <td class="sh-time sh-time--small"><?= $halfDayLabel ?></td>
          <td>
            <?php if ($s['employee_count'] > 0): ?>
              <a href="<?= BASE_URL ?>modules/employees/index.php" class="sh-link">
                <?= $s['employee_count'] ?> employee<?= $s['employee_count'] != 1 ? 's' : '' ?>
              </a>
            <?php else: ?>
              <span class="sh-none">—</span>
            <?php endif; ?>
          </td>
          <td>
            <?php if ($s['is_active']): ?>
              <span class="sh-badge sh-badge--active">Active</span>
            <?php else: ?>
              <span class="sh-badge sh-badge--inactive">Inactive</span>
            <?php endif; ?>
          </td>
          <td>
            <div class="sh-action-btns">
              <button class="sh-btn-sm sh-btn-sm--outline"
                      onclick="openEditModal(<?= htmlspecialchars(json_encode($s)) ?>)">
                <i class="fa fa-pen"></i> Edit
              </button>
              <form method="POST" action="<?= BASE_URL ?>actions/shift-action.php" style="display:inline;">
                <input type="hidden" name="action"   value="toggle_active">
                <input type="hidden" name="shift_id" value="<?= $s['shift_id'] ?>">
                <button type="submit" class="sh-btn-sm <?= $s['is_active'] ? 'sh-btn-sm--warn' : 'sh-btn-sm--teal' ?>">
                  <i class="fa <?= $s['is_active'] ? 'fa-ban' : 'fa-circle-check' ?>"></i>
                  <?= $s['is_active'] ? 'Deactivate' : 'Activate' ?>
                </button>
              </form>
              <?php if ($s['employee_count'] == 0): ?>
              <form method="POST" action="<?= BASE_URL ?>actions/shift-action.php" style="display:inline;"
                    data-confirm-title="Delete Shift"
                    data-confirm-message="Delete shift &quot;<?= htmlspecialchars($s['shift_name']) ?>&quot;? This shift has no assigned employees."
                    data-confirm-note="This cannot be undone."
                    data-confirm-type="danger"
                    data-confirm-btn="Delete Shift">
                <input type="hidden" name="action"   value="delete">
                <input type="hidden" name="shift_id" value="<?= $s['shift_id'] ?>">
                <button type="submit" class="sh-btn-sm sh-btn-sm--danger">
                  <i class="fa fa-trash"></i>
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

  <!-- Info guide -->
  <div class="sh-guide">
    <h3><i class="fa fa-circle-info"></i> How Shifts Work</h3>
    <div class="sh-guide-grid">
      <div class="sh-guide-card">
        <div class="sh-guide-label">Attendance Status Logic</div>
        <ul>
          <li>Time-in before shift start → <strong>PRESENT</strong></li>
          <li>Time-in after grace period → <strong>LATE</strong></li>
          <li>Time-in at or after half-day threshold <em>(full-time only)</em> → <strong>HALF_DAY</strong></li>
          <li>No record logged → <strong>ABSENT</strong></li>
        </ul>
      </div>
      <div class="sh-guide-card">
        <div class="sh-guide-label">Payroll Impact</div>
        <ul>
          <li>PRESENT / LATE / HALF_DAY → no salary deduction</li>
          <li>UNDERTIME (early departure) → no salary deduction</li>
          <li>ABSENT (exceeds leave credits) → salary deduction</li>
          <li>HOLIDAY → excluded from absence calculation</li>
        </ul>
      </div>
    </div>
  </div>
  <?php endif; ?>

</div><!-- .sh-page -->
</div><!-- .main-content -->
</div><!-- .main -->
</div><!-- .layout -->

<!-- ── Create / Edit Modal ──────────────────────────────────────────────────── -->
<div class="sh-modal-overlay" id="shModal" style="display:none;">
  <div class="sh-modal-box">
    <div class="sh-modal-header">
      <h3 id="shModalTitle"><i class="fa fa-clock"></i> Add Shift</h3>
      <button onclick="document.getElementById('shModal').style.display='none'">
        <i class="fa fa-times"></i>
      </button>
    </div>
    <form method="POST" action="<?= BASE_URL ?>actions/shift-action.php" id="shForm">
      <input type="hidden" name="action"   id="shFormAction" value="create">
      <input type="hidden" name="shift_id" id="shFormId"     value="">
      <div class="sh-modal-body">

        <div class="sh-form-row">
          <div class="sh-form-group">
            <label>Shift Name <span class="req">*</span></label>
            <input type="text" name="shift_name" id="shName" required
                   placeholder="e.g. Morning Shift, Regular, Split Shift" maxlength="100">
          </div>
        </div>

        <div class="sh-form-row sh-form-row--2">
          <div class="sh-form-group">
            <label>Start Time <span class="req">*</span></label>
            <input type="time" name="start_time" id="shStart" required>
            <small>Official start of the shift</small>
          </div>
          <div class="sh-form-group">
            <label>End Time <span class="req">*</span></label>
            <input type="time" name="end_time" id="shEnd" required>
            <small>Official end of the shift</small>
          </div>
        </div>

        <div class="sh-form-row sh-form-row--2">
          <div class="sh-form-group">
            <label>Grace Period (minutes)</label>
            <input type="number" name="grace_period_minutes" id="shGrace"
                   min="0" max="120" value="0" placeholder="0">
            <small>Minutes after start time before attendance is marked LATE</small>
          </div>
          <div class="sh-form-group">
            <label>Half-Day Threshold <span class="sh-field-note">(full-time only)</span></label>
            <input type="time" name="half_day_time" id="shHalfDay">
            <small>Full-time employees arriving at or after this time are marked <strong>HALF_DAY</strong>. Leave blank to use 9:00 AM default. Part-time employees are always PRESENT or LATE — this threshold does not apply to them.</small>
          </div>
        </div>

        <div class="sh-form-row">
          <label class="sh-check-label">
            <input type="checkbox" name="is_active" id="shIsActive" value="1" checked>
            <span>Active — available for employee assignment</span>
          </label>
        </div>

      </div>
      <div class="sh-modal-footer">
        <button type="button" class="sh-btn-ghost"
                onclick="document.getElementById('shModal').style.display='none'">Cancel</button>
        <button type="submit" class="sh-btn-primary">
          <i class="fa fa-floppy-disk"></i>
          <span id="shSubmitLabel">Save Shift</span>
        </button>
      </div>
    </form>
  </div>
</div>

<script>
function openCreateModal() {
    document.getElementById('shModalTitle').innerHTML = '<i class="fa fa-clock"></i> Add Shift';
    document.getElementById('shFormAction').value = 'create';
    document.getElementById('shFormId').value     = '';
    document.getElementById('shSubmitLabel').textContent = 'Save Shift';
    document.getElementById('shForm').reset();
    document.getElementById('shIsActive').checked = true;
    document.getElementById('shModal').style.display = 'flex';
    document.getElementById('shName').focus();
}

function openEditModal(s) {
    document.getElementById('shModalTitle').innerHTML = '<i class="fa fa-pen"></i> Edit Shift';
    document.getElementById('shFormAction').value = 'update';
    document.getElementById('shFormId').value     = s.shift_id;
    document.getElementById('shName').value       = s.shift_name;
    document.getElementById('shStart').value      = s.start_time  ? s.start_time.substring(0,5)  : '';
    document.getElementById('shEnd').value        = s.end_time    ? s.end_time.substring(0,5)    : '';
    document.getElementById('shGrace').value      = s.grace_period_minutes || 0;
    document.getElementById('shHalfDay').value    = s.half_day_time ? s.half_day_time.substring(0,5) : '';
    document.getElementById('shIsActive').checked = !!parseInt(s.is_active);
    document.getElementById('shSubmitLabel').textContent = 'Update Shift';
    document.getElementById('shModal').style.display = 'flex';
}

document.getElementById('shModal').addEventListener('click', function(e) {
    if (e.target === this) this.style.display = 'none';
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
