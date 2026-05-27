<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../includes/auth.php';
requireAdminPage();

// ── Flash ──────────────────────────────────────────────────────────────────
$flashOk  = $_SESSION['sy_success'] ?? '';
$flashErr = $_SESSION['sy_error']   ?? '';
unset($_SESSION['sy_success'], $_SESSION['sy_error']);

// ── Fetch all school years ─────────────────────────────────────────────────
$years = $pdo->query("
    SELECT sy.*,
           (SELECT COUNT(*) FROM employee_leave_credits elc WHERE elc.school_year_id = sy.school_year_id) AS credit_count,
           (SELECT COUNT(*) FROM leave_requests lr          WHERE lr.school_year_id  = sy.school_year_id) AS leave_count
    FROM school_years sy
    ORDER BY sy.start_date DESC
")->fetchAll(PDO::FETCH_ASSOC);

$activeYear = null;
foreach ($years as $y) {
    if ($y['is_active']) { $activeYear = $y; break; }
}

$pageTitle = 'School Year Management';
$extraCSS  = [BASE_URL . 'assets/css/school-years.css'];
require_once __DIR__ . '/../../includes/head.php';
?>
<body>
<div class="layout">
<?php include __DIR__ . '/../../includes/sidebar.php'; ?>
<div class="main">
<?php include __DIR__ . '/../../includes/header.php'; ?>
<div class="main-content">

<div class="sy-page">

  <a href="<?= BASE_URL ?>modules/settings/index.php" class="back-link">
    <i class="fa fa-arrow-left"></i> Back to Settings
  </a>

  <!-- Page Header -->
  <div class="sy-page-header">
    <div>
      <h1>School Year Management</h1>
      <p>Define academic years and set the active school year. Leave credits reset each school year.</p>
    </div>
    <button class="sy-btn-primary" onclick="openCreateModal()">
      <i class="fa fa-plus"></i> Add School Year
    </button>
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

  <!-- Active school year banner -->
  <?php if ($activeYear): ?>
  <div class="sy-active-banner">
    <i class="fa fa-circle-check"></i>
    <div>
      <strong>Active School Year: <?= htmlspecialchars($activeYear['year_name']) ?></strong>
      <span><?= date('M j, Y', strtotime($activeYear['start_date'])) ?> — <?= date('M j, Y', strtotime($activeYear['end_date'])) ?></span>
    </div>
  </div>
  <?php else: ?>
  <div class="sy-alert sy-alert--warn">
    <i class="fa fa-triangle-exclamation"></i>
    No active school year is set. Leave filings will not be linked to a school year until you activate one.
  </div>
  <?php endif; ?>

  <!-- School years table -->
  <?php if (empty($years)): ?>
  <div class="sy-empty">
    <i class="fa fa-calendar-xmark"></i>
    <h3>No school years defined</h3>
    <p>Add your first school year to start allocating leave credits.</p>
    <button class="sy-btn-primary" onclick="openCreateModal()">
      <i class="fa fa-plus"></i> Add School Year
    </button>
  </div>
  <?php else: ?>
  <div class="sy-table-wrap">
    <table class="sy-table">
      <thead>
        <tr>
          <th>School Year</th>
          <th>Start Date</th>
          <th>End Date</th>
          <th>Duration</th>
          <th>Leave Requests</th>
          <th>Credit Records</th>
          <th>Status</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php
        $today = date('Y-m-d');
        foreach ($years as $y):
          $start  = new DateTime($y['start_date']);
          $end    = new DateTime($y['end_date']);
          $months = round($start->diff($end)->days / 30.44, 1);

          if ($y['is_active']) {
              $badge = '<span class="sy-badge sy-badge--active"><i class="fa fa-circle-check"></i> Active</span>';
          } elseif ($y['end_date'] < $today) {
              $badge = '<span class="sy-badge sy-badge--archived">Archived</span>';
          } elseif ($y['start_date'] > $today) {
              $badge = '<span class="sy-badge sy-badge--upcoming">Upcoming</span>';
          } else {
              $badge = '<span class="sy-badge sy-badge--inactive">Inactive</span>';
          }
      ?>
      <tr>
        <td><strong><?= htmlspecialchars($y['year_name']) ?></strong></td>
        <td><?= date('M j, Y', strtotime($y['start_date'])) ?></td>
        <td><?= date('M j, Y', strtotime($y['end_date'])) ?></td>
        <td style="color:#64748b;"><?= $months ?> mo.</td>
        <td>
          <?php if ($y['leave_count'] > 0): ?>
            <a href="<?= BASE_URL ?>modules/leave/index.php?sy=<?= $y['school_year_id'] ?>" class="sy-count-link">
              <?= $y['leave_count'] ?> request<?= $y['leave_count']!=1?'s':'' ?>
            </a>
          <?php else: ?>
            <span style="color:#94a3b8;">—</span>
          <?php endif; ?>
        </td>
        <td>
          <?php if ($y['credit_count'] > 0): ?>
            <a href="<?= BASE_URL ?>modules/leave-credits/index.php?school_year_id=<?= $y['school_year_id'] ?>" class="sy-count-link">
              <?= $y['credit_count'] ?> record<?= $y['credit_count']!=1?'s':'' ?>
            </a>
          <?php else: ?>
            <a href="<?= BASE_URL ?>modules/leave-credits/index.php?school_year_id=<?= $y['school_year_id'] ?>" class="sy-count-link sy-count-link--muted">Allocate credits</a>
          <?php endif; ?>
        </td>
        <td><?= $badge ?></td>
        <td>
          <div style="display:flex;gap:6px;flex-wrap:wrap;">
            <?php if (!$y['is_active']): ?>
            <form method="POST" action="<?= BASE_URL ?>actions/school-year-action.php" style="display:inline;"
                  data-confirm-title="Set Active School Year"
                  data-confirm-message="Set &quot;<?= htmlspecialchars($y['year_name']) ?>&quot; as the active school year?"
                  data-confirm-note="This will deactivate the current active school year."
                  data-confirm-type="warning"
                  data-confirm-btn="Set Active">
              <input type="hidden" name="action" value="set_active">
              <input type="hidden" name="school_year_id" value="<?= $y['school_year_id'] ?>">
              <button type="submit" class="sy-btn-sm sy-btn-sm--teal">
                <i class="fa fa-circle-check"></i> Set Active
              </button>
            </form>
            <?php endif; ?>
            <button class="sy-btn-sm sy-btn-sm--outline"
                    onclick="openEditModal(<?= htmlspecialchars(json_encode($y)) ?>)">
              <i class="fa fa-pen"></i> Edit
            </button>
            <?php if (!$y['is_active'] && $y['leave_count'] == 0 && $y['credit_count'] == 0): ?>
            <form method="POST" action="<?= BASE_URL ?>actions/school-year-action.php" style="display:inline;"
                  data-confirm-title="Delete School Year"
                  data-confirm-message="Delete school year &quot;<?= htmlspecialchars($y['year_name']) ?>&quot;? It has no leave requests or credit allocations."
                  data-confirm-note="This cannot be undone."
                  data-confirm-type="danger"
                  data-confirm-btn="Delete School Year">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="school_year_id" value="<?= $y['school_year_id'] ?>">
              <button type="submit" class="sy-btn-sm sy-btn-sm--danger">
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
  <?php endif; ?>

  <!-- BOSY / EOSY Guide -->
  <div class="sy-guide">
    <h3><i class="fa fa-circle-info"></i> School Year Workflow</h3>
    <div class="sy-guide-grid">
      <div class="sy-guide-card">
        <div class="sy-guide-label">BOSY — Beginning of School Year</div>
        <ol>
          <li>Create the new school year (e.g. 2026-2027).</li>
          <li>Set it as <em>Active</em>.</li>
          <li>Go to <a href="<?= BASE_URL ?>modules/leave-credits/index.php">Leave Credits</a> and bulk-allocate days to all employees.</li>
          <li>New leave filings will automatically link to the active year.</li>
        </ol>
      </div>
      <div class="sy-guide-card">
        <div class="sy-guide-label">EOSY — End of School Year</div>
        <ol>
          <li>Ensure all pending leave requests for the year are actioned.</li>
          <li>Create and activate the next school year.</li>
          <li>Previous year credits are preserved as history.</li>
          <li>Remaining credits do <strong>not</strong> carry over (per DepEd policy).</li>
        </ol>
      </div>
    </div>
  </div>

</div><!-- .sy-page -->
</div><!-- .main-content -->
</div><!-- .main -->
</div><!-- .layout -->

<!-- Create / Edit Modal -->
<div class="sy-modal-overlay" id="syModal" style="display:none;">
  <div class="sy-modal-box">
    <div class="sy-modal-header">
      <h3 id="syModalTitle"><i class="fa fa-calendar-plus"></i> Add School Year</h3>
      <button onclick="document.getElementById('syModal').style.display='none'">
        <i class="fa fa-times"></i>
      </button>
    </div>
    <form method="POST" action="<?= BASE_URL ?>actions/school-year-action.php" id="syForm">
      <input type="hidden" name="action" id="syFormAction" value="create">
      <input type="hidden" name="school_year_id" id="syFormId" value="">
      <div class="sy-modal-body">

        <div class="sy-form-row">
          <div class="sy-form-group">
            <label>School Year Name <span class="req">*</span></label>
            <input type="text" name="year_name" id="syYearName" required
                   placeholder="e.g. 2026-2027" maxlength="20">
            <small>Format: YYYY-YYYY (e.g. 2026-2027)</small>
            <span class="sy-inline-error" id="errYearName"></span>
          </div>
        </div>

        <div class="sy-form-row sy-form-row--2">
          <div class="sy-form-group">
            <label>Start Date <span class="req">*</span></label>
            <input type="date" name="start_date" id="syStartDate" required
                   onchange="clearFieldError('errStartDate')">
          </div>
          <div class="sy-form-group">
            <label>End Date <span class="req">*</span></label>
            <input type="date" name="end_date" id="syEndDate" required
                   onchange="clearFieldError('errEndDate')">
            <span class="sy-inline-error" id="errEndDate"></span>
          </div>
        </div>

        <div class="sy-form-group">
          <label class="sy-check-label">
            <input type="checkbox" name="is_active" id="syIsActive" value="1">
            <span>Set as active school year</span>
          </label>
          <small class="sy-active-warn" id="syActiveWarning">
            <i class="fa fa-triangle-exclamation"></i>
            Setting this active will deactivate the current active school year.
          </small>
        </div>

      </div>
      <div class="sy-modal-footer">
        <button type="button" class="sy-btn-ghost"
                onclick="document.getElementById('syModal').style.display='none'">Cancel</button>
        <button type="submit" class="sy-btn-primary" id="sySubmitBtn">
          <i class="fa fa-floppy-disk"></i> Save School Year
        </button>
      </div>
    </form>
  </div>
</div>

<script>
const _hasActive  = <?= $activeYear ? 'true' : 'false' ?>;
const _activeId   = <?= $activeYear ? (int)$activeYear['school_year_id'] : 'null' ?>;
const _activeName = <?= $activeYear ? json_encode($activeYear['year_name']) : 'null' ?>;

// ── Modal open/close ───────────────────────────────────────────────────────
function openCreateModal() {
    document.getElementById('syModalTitle').innerHTML = '<i class="fa fa-calendar-plus"></i> Add School Year';
    document.getElementById('syFormAction').value = 'create';
    document.getElementById('syFormId').value     = '';
    document.getElementById('syForm').reset();
    clearAllErrors();
    document.getElementById('syModal').style.display = 'flex';
    document.getElementById('syYearName').focus();
}

function openEditModal(y) {
    document.getElementById('syModalTitle').innerHTML = '<i class="fa fa-pen"></i> Edit School Year';
    document.getElementById('syFormAction').value = 'update';
    document.getElementById('syFormId').value     = y.school_year_id;
    document.getElementById('syYearName').value   = y.year_name;
    document.getElementById('syStartDate').value  = y.start_date;
    document.getElementById('syEndDate').value    = y.end_date;
    document.getElementById('syIsActive').checked = !!parseInt(y.is_active);
    clearAllErrors();
    updateActiveWarning();
    document.getElementById('syModal').style.display = 'flex';
}

document.getElementById('syModal').addEventListener('click', function(e) {
    if (e.target === this) this.style.display = 'none';
});

// ── Active-year warning label ──────────────────────────────────────────────
function updateActiveWarning() {
    const chk    = document.getElementById('syIsActive');
    const warn   = document.getElementById('syActiveWarning');
    const editId = parseInt(document.getElementById('syFormId').value) || 0;
    const displacesActive = chk.checked && _hasActive && editId !== _activeId;
    warn.style.display = displacesActive ? 'flex' : 'none';
}
document.getElementById('syIsActive').addEventListener('change', updateActiveWarning);

// ── Client-side validation ─────────────────────────────────────────────────
function showFieldError(id, msg) {
    var el = document.getElementById(id);
    if (el) { el.textContent = msg; el.style.display = 'inline'; }
}
function clearFieldError(id) {
    var el = document.getElementById(id);
    if (el) { el.textContent = ''; el.style.display = 'none'; }
}
function clearAllErrors() {
    ['errYearName', 'errEndDate'].forEach(clearFieldError);
}

// ── Form submit: validate then confirm if activating ──────────────────────
document.getElementById('syForm').addEventListener('submit', function(e) {
    clearAllErrors();
    var valid = true;

    var name  = document.getElementById('syYearName').value.trim();
    var start = document.getElementById('syStartDate').value;
    var end   = document.getElementById('syEndDate').value;
    var isActivating = document.getElementById('syIsActive').checked;
    var editId = parseInt(document.getElementById('syFormId').value) || 0;

    // Year name format: YYYY-YYYY or YYYY–YYYY
    if (name && !/^\d{4}[-–]\d{4}$/.test(name)) {
        showFieldError('errYearName', 'Format must be YYYY–YYYY (e.g. 2026-2027)');
        valid = false;
    }

    // End date after start date
    if (start && end && end <= start) {
        showFieldError('errEndDate', 'End date must be after start date.');
        valid = false;
    }

    if (!valid) { e.preventDefault(); return; }

    // Confirmation when activating a year that displaces an existing active year
    var displacesActive = isActivating && _hasActive && editId !== _activeId;
    if (displacesActive) {
        e.preventDefault();
        var form = this;
        GEI.confirm({
            title:       'Change Active School Year',
            message:     'Set “' + (name || 'this school year') + '” as the active school year?',
            note:        'This will deactivate “' + _activeName + '”. New leave requests and credit allocations will link to the new active year. Previous records are preserved.',
            type:        'warning',
            confirmText: 'Confirm',
        }).then(function() { form.submit(); }).catch(function() {});
    }
});

// Auto-format year name: normalise en-dash to hyphen on blur for consistency
document.getElementById('syYearName').addEventListener('blur', function() {
    this.value = this.value.replace('–', '-');
    if (this.value) clearFieldError('errYearName');
});
</script>

<?php include __DIR__ . '/../../includes/footer.php'; ?>
