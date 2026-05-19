<?php
// All data available from parent's $pdo
$openPeriods = $pdo->query("
    SELECT * FROM payroll_periods WHERE status='OPEN'
    ORDER BY pay_period_start DESC
")->fetchAll();

$departments = $pdo->query("
    SELECT department_id, department_name FROM departments ORDER BY department_name
")->fetchAll();

$positions = $pdo->query("
    SELECT pos.position_id, pos.position_name, d.department_name
    FROM positions pos
    LEFT JOIN departments d ON pos.department_id = d.department_id
    ORDER BY d.department_name, pos.position_name
")->fetchAll();

$activeEmployees = $pdo->query("
    SELECT e.employee_id, e.first_name, e.last_name, d.department_name, p.position_name
    FROM employees e
    LEFT JOIN departments d ON e.department_id = d.department_id
    LEFT JOIN positions   p ON e.position_id   = p.position_id
    JOIN employee_compensations ec ON e.employee_id = ec.employee_id AND ec.is_active = 1
    WHERE e.employee_status = 'ACTIVE'
    ORDER BY e.last_name, e.first_name
")->fetchAll();
?>

<div id="generateModal" class="modal">
  <div class="modal-box gen-modal-box">

    <div class="modal-header">
      <h3>Generate Payroll</h3>
      <span class="close" onclick="closeGenerateModal()">&times;</span>
    </div>

    <?php if (empty($openPeriods)): ?>
      <div class="gen-notice gen-notice--warn">
        <i class="fa fa-triangle-exclamation"></i>
        No open pay periods. Create one in Payroll Settings first.
      </div>
    <?php endif; ?>

    <form method="POST" action="<?= BASE_URL ?>actions/generate-payroll.php" id="generateForm">

      <!-- Pay Period -->
      <div class="gen-field">
        <label>Pay Period <span class="req">*</span></label>
        <?php if (empty($openPeriods)): ?>
          <select disabled><option>No open periods</option></select>
          <input type="hidden" name="period_id" value="">
        <?php else: ?>
          <select name="period_id" required>
            <?php foreach ($openPeriods as $p): ?>
            <option value="<?= $p['period_id'] ?>"
              <?= $p['period_id'] == $selectedId ? 'selected' : '' ?>>
              <?= htmlspecialchars($p['period_name']) ?>
            </option>
            <?php endforeach; ?>
          </select>
        <?php endif; ?>
      </div>

      <!-- Scope selector -->
      <div class="gen-field">
        <label>Generate For</label>
        <div class="scope-grid">

          <label class="scope-card">
            <input type="radio" name="scope" value="all" checked>
            <span class="scope-card-inner">
              <i class="fa fa-users"></i>
              <strong>All Employees</strong>
              <small>Every active employee</small>
            </span>
          </label>

          <label class="scope-card">
            <input type="radio" name="scope" value="department">
            <span class="scope-card-inner">
              <i class="fa fa-building"></i>
              <strong>By Department</strong>
              <small>One department at a time</small>
            </span>
          </label>

          <label class="scope-card">
            <input type="radio" name="scope" value="position">
            <span class="scope-card-inner">
              <i class="fa fa-id-badge"></i>
              <strong>By Position</strong>
              <small>One role/position</small>
            </span>
          </label>

          <label class="scope-card">
            <input type="radio" name="scope" value="specific">
            <span class="scope-card-inner">
              <i class="fa fa-user-check"></i>
              <strong>Select Employees</strong>
              <small>Pick one or more</small>
            </span>
          </label>

        </div>
      </div>

      <!-- Department sub-field -->
      <div class="gen-sub-field" id="scopeDeptGroup" style="display:none">
        <label>Department <span class="req">*</span></label>
        <select name="department_id" id="deptSelect">
          <option value="">— Select department —</option>
          <?php foreach ($departments as $d): ?>
          <option value="<?= $d['department_id'] ?>">
            <?= htmlspecialchars($d['department_name']) ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- Position sub-field -->
      <div class="gen-sub-field" id="scopePosGroup" style="display:none">
        <label>Position <span class="req">*</span></label>
        <select name="position_id" id="posSelect">
          <option value="">— Select position —</option>
          <?php foreach ($positions as $p): ?>
          <option value="<?= $p['position_id'] ?>">
            <?= htmlspecialchars($p['position_name']) ?>
            <?php if ($p['department_name']): ?>
              — <?= htmlspecialchars($p['department_name']) ?>
            <?php endif; ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>

      <!-- Specific employees checklist -->
      <div class="gen-sub-field" id="scopeSpecificGroup" style="display:none">
        <label>Employees <span class="req">*</span></label>

        <div class="emp-checklist-toolbar">
          <input type="text" id="empSearch" placeholder="Search by name…"
                 oninput="filterEmpList(this.value)">
          <label class="select-all-label">
            <input type="checkbox" id="selectAllEmp" onchange="toggleSelectAll(this.checked)">
            Select all
          </label>
        </div>

        <div class="emp-checklist" id="empChecklist">
          <?php foreach ($activeEmployees as $emp): ?>
          <label class="emp-check-item"
                 data-name="<?= strtolower(htmlspecialchars($emp['first_name'].' '.$emp['last_name'])) ?>"
                 data-dept="<?= strtolower(htmlspecialchars($emp['department_name'] ?? '')) ?>">
            <input type="checkbox" name="employee_ids[]" value="<?= $emp['employee_id'] ?>">
            <span class="emp-check-info">
              <strong><?= htmlspecialchars($emp['first_name'].' '.$emp['last_name']) ?></strong>
              <small><?= htmlspecialchars($emp['position_name'] ?? '') ?>
                     <?= $emp['department_name'] ? '— '.$emp['department_name'] : '' ?></small>
            </span>
          </label>
          <?php endforeach; ?>
          <?php if (empty($activeEmployees)): ?>
          <p style="color:#9ca3af;font-size:13px;padding:8px 0;">No active employees found.</p>
          <?php endif; ?>
        </div>

        <div class="emp-checklist-count">
          <span id="selectedCount">0</span> employee(s) selected
        </div>
      </div>

      <!-- Force re-generate -->
      <div class="gen-field gen-field--checkbox">
        <label>
          <input type="checkbox" name="force_regenerate" value="1">
          <span>
            Re-generate existing records
            <small>Deletes and rebuilds records that were already generated for the selected period and scope.</small>
          </span>
        </label>
      </div>

      <div class="modal-actions">
        <button type="button" class="btn-outline" onclick="closeGenerateModal()">Cancel</button>
        <button type="submit" class="btn-primary" <?= empty($openPeriods) ? 'disabled' : '' ?>>
          <i class="fa fa-play"></i> Generate
        </button>
      </div>

    </form>
  </div>
</div>

<script>
// ── Scope switcher ────────────────────────────────────────────────────────────
document.querySelectorAll('input[name="scope"]').forEach(radio => {
    radio.addEventListener('change', function () {
        document.getElementById('scopeDeptGroup').style.display     = 'none';
        document.getElementById('scopePosGroup').style.display      = 'none';
        document.getElementById('scopeSpecificGroup').style.display = 'none';

        if (this.value === 'department') document.getElementById('scopeDeptGroup').style.display     = 'block';
        if (this.value === 'position')   document.getElementById('scopePosGroup').style.display      = 'block';
        if (this.value === 'specific')   document.getElementById('scopeSpecificGroup').style.display = 'block';
    });
});

// ── Employee list search ──────────────────────────────────────────────────────
function filterEmpList(q) {
    const term = q.toLowerCase().trim();
    document.querySelectorAll('#empChecklist .emp-check-item').forEach(item => {
        const match = !term
            || item.dataset.name.includes(term)
            || item.dataset.dept.includes(term);
        item.style.display = match ? '' : 'none';
    });
}

// ── Select all ────────────────────────────────────────────────────────────────
function toggleSelectAll(checked) {
    document.querySelectorAll('#empChecklist .emp-check-item')
        .forEach(item => {
            if (item.style.display !== 'none') {
                item.querySelector('input[type="checkbox"]').checked = checked;
            }
        });
    updateSelectedCount();
}

function updateSelectedCount() {
    const n = document.querySelectorAll('#empChecklist input[type="checkbox"]:checked').length;
    const el = document.getElementById('selectedCount');
    if (el) el.textContent = n;
}

document.getElementById('empChecklist')?.addEventListener('change', updateSelectedCount);

// ── Prevent submit when scope requires sub-selection ─────────────────────────
document.getElementById('generateForm')?.addEventListener('submit', function (e) {
    const scope = document.querySelector('input[name="scope"]:checked')?.value;
    if (scope === 'department' && !document.getElementById('deptSelect')?.value) {
        e.preventDefault();
        alert('Please select a department.');
        return;
    }
    if (scope === 'position' && !document.getElementById('posSelect')?.value) {
        e.preventDefault();
        alert('Please select a position.');
        return;
    }
    if (scope === 'specific') {
        const checked = document.querySelectorAll('#empChecklist input[type="checkbox"]:checked').length;
        if (!checked) {
            e.preventDefault();
            alert('Please select at least one employee.');
            return;
        }
    }
});
</script>