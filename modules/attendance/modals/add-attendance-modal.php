<?php /** modal: add-attendance-modal.php — "Log Employee In" */ ?>

<div id="addAttendanceModal" class="att-modal" style="display:none;">
  <div class="att-modal-box att-modal-box--lg">

    <div class="att-modal-header">
      <div>
        <h3><i class="fa fa-arrow-right-to-bracket"></i> Log Employee In</h3>
        <p style="font-size:13px;opacity:0.8;margin-top:2px;">
          Records Time In for selected employees using the current system time
        </p>
      </div>
      <button type="button" class="att-modal-close" onclick="closeAttModal()">
        <i class="fa fa-times"></i>
      </button>
    </div>

    <div id="addAttError" style="display:none;margin:12px 16px 0;padding:10px 14px;
         background:#fef2f2;border:1px solid #fecaca;border-radius:8px;color:#991b1b;font-size:13px;"></div>

    <div class="att-modal-body">

      <!-- Time display -->
      <div class="att-notice-box">
        <strong><i class="fa fa-clock"></i>
          Time In will be recorded as: <span id="loginTimeLive" style="font-size:15px;font-weight:800;">—</span>
        </strong>
        <p>Current system time is used automatically. Use Edit to correct past records.</p>
      </div>

      <!-- Employee filters -->
      <div class="att-field-row" style="gap:8px;">
        <div class="att-field">
          <label>Department</label>
          <select id="addEmpDeptFilter" onchange="loadEmployeesForModal()">
            <option value="">All Departments</option>
            <?php foreach ($depts as $d): ?>
            <option value="<?= $d['department_id'] ?>">
              <?= htmlspecialchars($d['department_name']) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="att-field">
          <label>Position / Role</label>
          <select id="addEmpPosFilter" onchange="loadEmployeesForModal()">
            <option value="">All Positions</option>
            <?php foreach ($positions as $p): ?>
            <option value="<?= $p['position_id'] ?>">
              <?= htmlspecialchars($p['position_name']) ?>
            </option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>

      <!-- Employee search -->
      <div class="att-field">
        <label>Search Employee</label>
        <div class="att-emp-search-wrap">
          <i class="fa fa-magnifying-glass"></i>
          <input type="text" id="addEmpSearch" placeholder="Type name or employee number…"
                 oninput="debounce(loadEmployeesForModal, 300)">
        </div>
      </div>

      <!-- Employee checklist -->
      <div class="att-field">
        <div class="att-emp-list-header">
          <label>Select Employees
            <span id="addEmpSelectedCount" class="att-emp-sel-count" style="display:none;">0 selected</span>
          </label>
          <div style="display:flex;gap:8px;align-items:center;">
            <button type="button" class="att-btn-link" onclick="selectAllAvailableEmployees()">Select All</button>
            <button type="button" class="att-btn-link" onclick="clearEmployeeSelection()">Clear</button>
          </div>
        </div>
        <div id="addEmpList" class="att-emp-checklist">
          <div class="att-emp-loading"><i class="fa fa-spinner fa-spin"></i> Loading employees…</div>
        </div>
      </div>

      <!-- Notes -->
      <div class="att-field">
        <label>Notes (Optional)</label>
        <textarea id="addEmpRemarks" rows="2" placeholder="Add any notes for this batch log-in…"
                  style="padding:9px 12px;border-radius:8px;border:1px solid #d1d5db;font-size:13px;width:100%;resize:vertical;"></textarea>
      </div>

    </div><!-- /modal-body -->

    <div class="att-modal-footer">
      <button type="button" class="att-btn outline" onclick="closeAttModal()">Cancel</button>
      <button type="button" class="att-btn primary" id="addAttSubmitBtn" onclick="submitLogEmployeeIn()">
        <i class="fa fa-arrow-right-to-bracket"></i>
        <span id="addAttBtnLabel">Log Time In</span>
      </button>
    </div>

  </div>
</div>

<script>
// ── Employee list state ───────────────────────────────────────────────────────
let _empListCache = [];

// ── Open / Close ──────────────────────────────────────────────────────────────
window.openAttModal = function() {
    const modal = document.getElementById('addAttendanceModal');
    modal.style.display = 'flex';
    startLoginTimeLive();
    loadEmployeesForModal();
    document.getElementById('addAttError').style.display = 'none';
};

window.closeAttModal = function() {
    document.getElementById('addAttendanceModal').style.display = 'none';
    stopLoginTimeLive();
    document.getElementById('addEmpSearch').value = '';
    document.getElementById('addEmpDeptFilter').value = '';
    document.getElementById('addEmpPosFilter').value  = '';
    document.getElementById('addEmpRemarks').value    = '';
    document.getElementById('addAttError').style.display = 'none';
    _empListCache = [];
    renderEmployeeList([]);
    updateAddSelectionCount();
};

// ── Live clock ────────────────────────────────────────────────────────────────
let _clockInterval = null;
function startLoginTimeLive() {
    function tick() {
        const now = new Date();
        document.getElementById('loginTimeLive').textContent =
            now.toLocaleTimeString('en-US', {hour:'2-digit', minute:'2-digit', second:'2-digit', hour12:true});
    }
    tick();
    _clockInterval = setInterval(tick, 1000);
}
function stopLoginTimeLive() {
    if (_clockInterval) { clearInterval(_clockInterval); _clockInterval = null; }
}

// ── Load employees via AJAX ───────────────────────────────────────────────────
window.loadEmployeesForModal = function() {
    const search = document.getElementById('addEmpSearch').value.trim();
    const dept   = document.getElementById('addEmpDeptFilter').value;
    const pos    = document.getElementById('addEmpPosFilter').value;
    const list   = document.getElementById('addEmpList');
    list.innerHTML = '<div class="att-emp-loading"><i class="fa fa-spinner fa-spin"></i> Loading…</div>';

    const url = `<?= BASE_URL ?>actions/get-active-employees.php?search=${encodeURIComponent(search)}&dept_id=${encodeURIComponent(dept)}&pos_id=${encodeURIComponent(pos)}`;
    fetch(url)
        .then(r => r.json())
        .then(data => {
            _empListCache = data.employees || [];
            renderEmployeeList(_empListCache);
        })
        .catch(() => {
            list.innerHTML = '<div class="att-emp-loading" style="color:#dc2626;">Failed to load employees.</div>';
        });
};

function renderEmployeeList(employees) {
    const list = document.getElementById('addEmpList');
    if (!employees || employees.length === 0) {
        list.innerHTML = '<div class="att-emp-loading">No employees found.</div>';
        updateAddSelectionCount();
        return;
    }

    list.innerHTML = employees.map(emp => {
        const empId  = emp.employee_id;
        const name   = escHtml(emp.full_name);
        const empNo  = escHtml(emp.employee_no || '—');
        const dept   = escHtml(emp.department_name || '');
        const pos    = escHtml(emp.position_name  || '');
        const logged = emp.already_logged;
        const active = emp.needs_timeout;

        let badgeHtml = '';
        let disabled  = '';
        let rowClass  = 'att-emp-item';

        if (emp.on_leave && !logged) {
            // Employee has approved leave today but no attendance record yet
            badgeHtml = '<span class="att-emp-item-badge on-leave">On Leave</span>';
            disabled  = 'disabled';
            rowClass += ' att-emp-item--disabled';
        } else if (active) {
            // Has time_in, no time_out — active check-in, cannot add again
            badgeHtml = '<span class="att-emp-item-badge active">Active</span>';
            disabled  = 'disabled';
            rowClass += ' att-emp-item--disabled';
        } else if (logged) {
            // Already has any record today (ABSENT, LEAVE, etc.)
            const stBadge = emp.current_status
                ? escHtml(emp.current_status.charAt(0) + emp.current_status.slice(1).toLowerCase())
                : 'Recorded';
            badgeHtml = `<span class="att-emp-item-badge recorded">${stBadge}</span>`;
            disabled  = 'disabled';
            rowClass += ' att-emp-item--disabled';
        }

        return `
        <label class="${rowClass}">
          <input type="checkbox" class="add-emp-check" value="${empId}"
                 ${disabled} onchange="updateAddSelectionCount()">
          <div class="att-av" style="flex-shrink:0;">${name.charAt(0)}${(emp.full_name.split(' ')[1]||'').charAt(0)}</div>
          <div class="att-emp-item-info">
            <div class="att-emp-item-name">${name} <span class="att-empno-small">${empNo}</span> ${badgeHtml}</div>
            <div class="att-emp-item-meta">${dept}${dept && pos ? ' · ' : ''}${pos}</div>
          </div>
        </label>`;
    }).join('');

    updateAddSelectionCount();
}

function updateAddSelectionCount() {
    const checked = document.querySelectorAll('.add-emp-check:checked');
    const label   = document.getElementById('addEmpSelectedCount');
    const btn     = document.getElementById('addAttBtnLabel');
    if (checked.length > 0) {
        label.textContent = `${checked.length} selected`;
        label.style.display = 'inline-block';
        btn.textContent = `Log Time In (${checked.length})`;
    } else {
        label.style.display = 'none';
        btn.textContent = 'Log Time In';
    }
}

window.selectAllAvailableEmployees = function() {
    document.querySelectorAll('.add-emp-check:not([disabled])').forEach(cb => cb.checked = true);
    updateAddSelectionCount();
};

window.clearEmployeeSelection = function() {
    document.querySelectorAll('.add-emp-check').forEach(cb => cb.checked = false);
    updateAddSelectionCount();
};

// ── Submit ────────────────────────────────────────────────────────────────────
window.submitLogEmployeeIn = function() {
    const checked = document.querySelectorAll('.add-emp-check:checked');
    const errBox  = document.getElementById('addAttError');
    const btn     = document.getElementById('addAttSubmitBtn');

    errBox.style.display = 'none';

    if (checked.length === 0) {
        errBox.textContent   = 'Please select at least one employee to log in.';
        errBox.style.display = 'block';
        return;
    }

    btn.disabled  = true;
    btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Logging in…';

    const fd = new FormData();
    checked.forEach(cb => fd.append('employee_ids[]', cb.value));
    fd.append('remarks', document.getElementById('addEmpRemarks').value.trim());

    fetch('<?= BASE_URL ?>actions/add-attendance.php', {method:'POST', body:fd})
        .then(r => {
            const ct = r.headers.get('content-type') || '';
            if (!ct.includes('application/json')) return r.text().then(t => { throw new Error(t.substring(0, 200)); });
            return r.json();
        })
        .then(data => {
            if (data.success) {
                closeAttModal();
                location.reload();
            } else {
                errBox.textContent   = data.message || 'Could not log attendance.';
                errBox.style.display = 'block';
                btn.disabled  = false;
                btn.innerHTML = '<i class="fa fa-arrow-right-to-bracket"></i> <span id="addAttBtnLabel">Log Time In</span>';
            }
        })
        .catch(err => {
            errBox.textContent   = 'Error: ' + (err.message || 'Network error.');
            errBox.style.display = 'block';
            btn.disabled  = false;
            btn.innerHTML = '<i class="fa fa-arrow-right-to-bracket"></i> <span id="addAttBtnLabel">Log Time In</span>';
        });
};
</script>
