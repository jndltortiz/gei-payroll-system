// ================================
// MODAL OPEN / CLOSE
// ================================
window.openModal = function(id) {
    const modal = document.getElementById(id);
    if (modal) modal.classList.add('active');
};

window.closeModal = function(id) {
    const modal = document.getElementById(id);
    if (modal) modal.classList.remove('active');
};

// Close on backdrop click
document.addEventListener('click', function(e) {
    if (e.target.classList.contains('modal')) {
        e.target.classList.remove('active');
    }
});

// ================================
// VIEW EMPLOYEE
// ================================
window.viewEmployee = function(id) {
    fetch(BASE_URL + 'actions/get-employee.php?id=' + id)
        .then(res => res.json())
        .then(d => {
            // Avatar initials
            const initials = (d.first_name?.[0] ?? '') + (d.last_name?.[0] ?? '');
            document.getElementById('viewAvatar').textContent = initials.toUpperCase();

            // Name
            document.getElementById('viewName').textContent = d.first_name + ' ' + d.last_name;

            // Position · Department
            const pos  = d.position_name   ?? '—';
            const dept = d.department_name ?? '—';
            document.getElementById('viewPositionDept').textContent = pos + ' · ' + dept;

            // Status badge
            const badge = document.getElementById('viewStatusBadge');
            const status = (d.employee_status ?? 'unknown').toLowerCase();
            badge.textContent = d.employee_status ? ucFirst(status) : '—';
            badge.className = 'badge ' + status;

            // Detail fields
            document.getElementById('viewEmpId').textContent     = d.employee_no ?? '—';
            document.getElementById('viewEmail').textContent     = d.email ?? '—';
            document.getElementById('viewEmployment').textContent = d.employment_type
                ? (d.employment_type === 'FULL_TIME' ? 'Full-time' : 'Part-time')
                : '—';
            document.getElementById('viewShift').textContent     = d.shift_name ?? '—';
            document.getElementById('viewSalary').textContent    = d.monthly_salary
                ? '₱' + parseFloat(d.monthly_salary).toLocaleString('en-PH', {minimumFractionDigits: 2})
                : '—';
            document.getElementById('viewRole').textContent      = d.role_name ?? '—';
            document.getElementById('viewDateHired').textContent = d.hire_date
                ? formatDate(d.hire_date) : '—';
            document.getElementById('viewPhone').textContent     = d.contact_no ?? '—';

            // Store id for "Edit" button inside view modal
            window._viewingEmployeeId = id;

            openModal('viewEmployeeModal');
        })
        .catch(err => console.error('viewEmployee error:', err));
};

// Edit button inside view modal
window.openEditFromView = function() {
    closeModal('viewEmployeeModal');
    if (window._viewingEmployeeId) {
        editEmployee(window._viewingEmployeeId);
    }
};

// ================================
// EDIT EMPLOYEE
// ================================
window.editEmployee = function(id) {
    fetch(BASE_URL + 'actions/get-employee.php?id=' + id)
        .then(res => res.json())
        .then(d => {
            // Helper: set value on element by id
            const setId = (elId, value) => {
                const el = document.getElementById(elId);
                if (el) el.value = value ?? '';
            };

            // Hidden field
            setId('editEmployeeId', d.employee_id);

            // Personal
            setId('editFirstName',   d.first_name);
            setId('editMiddleName',  d.middle_name);
            setId('editLastName',    d.last_name);
            setId('editSuffix',      d.suffix);
            setId('editCivilStatus', d.civil_status);
            setId('editSex',         d.sex);
            setId('editBirthDate',   d.birth_date);
            setId('editContactNo',   d.contact_no);
            setId('editEmployeeNo',  d.employee_no);
            setId('editAddress',     d.address);

            // Job
            setId('editDeptSelect',      d.department_id);
            setId('editEmploymentType',  d.employment_type);
            setId('editHireDate',        d.hire_date);
            setId('editEmployeeStatus',  d.employee_status);

            // Load positions for this department, then set position
            loadPositions('editDeptSelect', 'editPosSelect', d.position_id);

            // Salary
            setId('editBasicSalary', d.monthly_salary);

            // Shift
            setId('editShiftSelect', d.shift_id);
            previewShift('editShiftSelect', 'editShiftPreview', 'editShiftEmpty');

            // Credentials
            setId('editEmail',    d.email);
            setId('editUsername', d.username);
            setId('editRole',     d.role_name);
            // Password intentionally left blank — only update if filled

            // Subtitle
            const subtitle = document.getElementById('editModalSubtitle');
            if (subtitle) subtitle.textContent = 'Editing: ' + d.first_name + ' ' + d.last_name;

            openModal('editEmployeeModal');
        })
        .catch(err => console.error('editEmployee error:', err));
};

// ================================
// DEACTIVATE
// ================================
window.setDeactivate = function(id, name) {
    document.getElementById('deact_id').value = id;
    const msg = document.getElementById('deactMsg');
    if (msg) {
        msg.textContent = 'Are you sure you want to deactivate ' + name + '? They will lose system access.';
    }
    openModal('deactEmployeeModal');
};

// ================================
// SAVE CONFIRMATION (2-step)
// ================================
let _pendingFormId   = null;
let _pendingSaveType = null;

window.prepareSave = function(formId, type) {
    // Basic HTML5 validation first
    const form = document.getElementById(formId);
    if (!form) return;
    if (!form.checkValidity()) {
        form.reportValidity();
        return;
    }

    _pendingFormId   = formId;
    _pendingSaveType = type ?? 'add';

    if (_pendingSaveType === 'edit') {
        openModal('saveEditModal');
    } else {
        // Update confirm message with employee name
        const firstName = form.querySelector('[name="first_name"]')?.value ?? '';
        const lastName  = form.querySelector('[name="last_name"]')?.value  ?? '';
        const msg = document.getElementById('saveAddMsg');
        if (msg && firstName) {
            msg.textContent = 'Add ' + firstName + ' ' + lastName + ' as a new employee? Their account credentials will be sent via email.';
        }
        openModal('saveAddModal');
    }
};

window.confirmSave = async function(type) {
    if (!_pendingFormId) return;
    const form = document.getElementById(_pendingFormId);
    if (!form) return;

    if (type === 'add') {
        // Close confirm modal, submit via fetch for JSON response + toast
        closeModal('saveAddModal');
        const btn = form.querySelector('button[type="button"].btn-save');
        if (btn) { btn.disabled = true; btn.textContent = 'Saving…'; }

        try {
            const res  = await fetch(BASE_URL + 'actions/employee-save.php',
                                     { method: 'POST', body: new FormData(form) });
            const data = await res.json();
            if (data.success) {
                if (typeof showToast === 'function') showToast(data.message + ' Username: ' + data.username, 'success', 5000);
                setTimeout(() => location.reload(), 1200);
            } else {
                if (typeof showToast === 'function') showToast(data.message || 'Failed to save employee.', 'error');
                if (btn) { btn.disabled = false; btn.textContent = 'Add Employee'; }
            }
        } catch {
            if (typeof showToast === 'function') showToast('Network error. Please try again.', 'error');
            if (btn) { btn.disabled = false; btn.textContent = 'Add Employee'; }
        }
    } else {
        // Edit — keep existing form submit behaviour
        if (form) form.submit();
    }
};

// ================================
// DEPARTMENT → POSITION CASCADE
// ================================
window.loadPositions = function(deptSelectId, posSelectId, preselectId) {
    const deptEl = document.getElementById(deptSelectId);
    const posEl  = document.getElementById(posSelectId);
    if (!deptEl || !posEl) return;

    const deptId = parseInt(deptEl.value);
    const options = posEl.querySelectorAll('option');

    let firstVisible = null;

    options.forEach(opt => {
        if (!opt.value) return; // skip placeholder
        const optDept = parseInt(opt.getAttribute('data-department'));
        const show = (!deptId || optDept === deptId);
        opt.style.display = show ? '' : 'none';
        if (show && !firstVisible) firstVisible = opt.value;
    });

    // Update placeholder text
    const placeholder = posEl.querySelector('option[value=""]');
    if (placeholder) {
        placeholder.textContent = deptId ? 'Select position...' : 'Select department first...';
    }

    // Set preselected value or reset
    if (preselectId) {
        posEl.value = preselectId;
    } else {
        posEl.value = '';
    }
};

// ================================
// SHIFT PREVIEW
// ================================
window.previewShift = function(selectId, previewId, emptyId) {
    const sel     = document.getElementById(selectId);
    const preview = document.getElementById(previewId);
    const empty   = document.getElementById(emptyId);
    if (!sel || !preview || !empty) return;

    const opt = sel.options[sel.selectedIndex];
    if (!opt || !opt.value) {
        preview.style.display = 'none';
        empty.style.display   = '';
        return;
    }

    const name  = opt.getAttribute('data-name')  ?? '';
    const start = opt.getAttribute('data-start') ?? '—';
    const end   = opt.getAttribute('data-end')   ?? '—';
    const grace = opt.getAttribute('data-grace') ?? '—';

    // Title
    const titleEl = document.getElementById(selectId.replace('Select', 'PreviewTitle')
                                                     .replace('ShiftSelect', 'ShiftPreviewTitle'));
    if (titleEl) titleEl.textContent = 'SHIFT DETAILS — ' + name.toUpperCase();

    // Values — derive IDs by replacing "Select" with Start/End/Grace
    const prefix = selectId.replace('ShiftSelect', 'Shift');
    const setEl = (suffix, val) => {
        const el = document.getElementById(prefix + suffix);
        if (el) el.textContent = val;
    };
    setEl('Start', start);
    setEl('End',   end);
    setEl('Grace', grace);

    preview.style.display = '';
    empty.style.display   = 'none';
};

// ================================
// AUTO-FILL USERNAME FROM EMAIL
// ================================
window.autoFillUsername = function(emailId, usernameId) {
    const email    = document.getElementById(emailId);
    const username = document.getElementById(usernameId);
    if (!email || !username) return;

    let val = email.value;

    // Auto-enforce @gei.edu.ph: if user typed something without @, append domain
    // If they typed @ already, leave it; if no @, we'll append on blur
    const prefix = val.split('@')[0];
    username.value = prefix.toLowerCase().replace(/[^a-z0-9.]/g, '');
};

// On blur: enforce @gei.edu.ph if user didn't type a domain
document.addEventListener('DOMContentLoaded', () => {
    ['addEmail', 'editEmail'].forEach(id => {
        const el = document.getElementById(id);
        if (!el) return;
        el.addEventListener('blur', function() {
            if (this.value && !this.value.includes('@')) {
                this.value = this.value.toLowerCase() + '@gei.edu.ph';
                // re-trigger username fill
                const usernameId = id === 'addEmail' ? 'addUsername' : 'editUsername';
                autoFillUsername(id, usernameId);
            } else if (this.value.includes('@') && !this.value.endsWith('@gei.edu.ph')) {
                // Wrong domain — correct it
                const prefix = this.value.split('@')[0];
                this.value = prefix.toLowerCase() + '@gei.edu.ph';
            }
        });
        // Show the domain hint while typing
        el.addEventListener('focus', function() {
            if (!this.value) {
                this.placeholder = 'firstname.lastname';
            }
        });
        el.addEventListener('input', function() {
            if (!this.value.includes('@')) {
                this.style.backgroundImage = '';
            }
        });
    });
});

// ================================
// PASSWORD TOGGLE
// ================================
window.togglePassword = function(inputId, iconEl) {
    const input = document.getElementById(inputId);
    if (!input) return;
    if (input.type === 'password') {
        input.type = 'text';
        iconEl.querySelector('i').className = 'fa fa-eye-slash';
    } else {
        input.type = 'password';
        iconEl.querySelector('i').className = 'fa fa-eye';
    }
};

// ================================
// GENERATE STRONG PASSWORD
// ================================
window.generatePassword = function(inputId) {
    const chars  = 'ABCDEFGHJKMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789!@#$%';
    let password = '';
    for (let i = 0; i < 12; i++) {
        password += chars[Math.floor(Math.random() * chars.length)];
    }
    const input = document.getElementById(inputId);
    if (input) {
        input.type  = 'text';
        input.value = password;
    }
};

// ================================
// TOGGLE SWITCH (force password change)
// ================================
window.toggleSwitch = function(el, hiddenId) {
    el.classList.toggle('on');
    const hidden = document.getElementById(hiddenId);
    if (hidden) hidden.value = el.classList.contains('on') ? '1' : '0';
};

// ================================
// SEARCH TABLE (client-side)
// ================================
window.searchTable = function() {
    const input   = document.getElementById('searchInput').value.toLowerCase();
    const rows    = document.querySelectorAll('#empTable tbody tr:not(#empNoResults)');
    let   visible = 0;

    rows.forEach(row => {
        const show = row.innerText.toLowerCase().includes(input);
        row.style.display = show ? '' : 'none';
        if (show) visible++;
    });

    // Show/hide no-results row
    let noRes = document.getElementById('empNoResults');
    if (input && visible === 0) {
        if (!noRes) {
            const tbody = document.querySelector('#empTable tbody');
            const tr = document.createElement('tr');
            tr.id = 'empNoResults';
            tr.innerHTML = '<td colspan="8"><div class="empty-state" style="padding:40px 24px;"><i class="fa fa-magnifying-glass"></i><p>No results for “' + input + '”</p><small>Try a different name or employee number</small></div></td>';
            tbody.appendChild(tr);
        } else {
            noRes.style.display = '';
            const small = noRes.querySelector('p');
            if (small) small.textContent = 'No results for “' + input + '”';
        }
    } else if (noRes) {
        noRes.style.display = 'none';
    }
};

// ================================
// DEPARTMENT FILTER (page reload)
// ================================
window.filterDept = function() {
    const dept   = document.getElementById('deptFilterSelect').value;
    const search = document.getElementById('searchInput').value;
    window.location.href = '?dept=' + dept + '&search=' + encodeURIComponent(search);
};

// ================================
// HELPERS
// ================================
function ucFirst(str) {
    return str.charAt(0).toUpperCase() + str.slice(1);
}

function formatDate(dateStr) {
    if (!dateStr) return '—';
    const d = new Date(dateStr);
    return d.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' });
}
// ── Leave file attachment helpers ─────────────────────────────────────────────
function onLeaveFileSelected(input) {
    const file = input.files[0];
    if (!file) return;
    if (file.size > 5 * 1024 * 1024) {
        if (typeof showToast === 'function') showToast('File too large — maximum 5 MB.', 'error');
        input.value = ''; return;
    }
    document.getElementById('leaveFileName').textContent    = file.name + ' (' + (file.size/1024).toFixed(0) + ' KB)';
    document.getElementById('leaveFilePreview').style.display = 'flex';
    document.getElementById('leaveFileArea').style.borderColor = '#0f766e';
}
function handleLeaveFileDrop(e) {
    e.preventDefault();
    document.getElementById('leaveFileArea').classList.remove('drag-over');
    const dt = e.dataTransfer; if (!dt?.files?.length) return;
    document.getElementById('leaveFileInput').files = dt.files;
    onLeaveFileSelected(document.getElementById('leaveFileInput'));
}
function clearLeaveFile() {
    document.getElementById('leaveFileInput').value = '';
    document.getElementById('leaveFilePreview').style.display = 'none';
    document.getElementById('leaveFileArea').style.borderColor = '';
}

// ── Dynamic education entries ─────────────────────────────────────────────────
let _eduCount = 1;
window.addEduEntry = function() {
    _eduCount++;
    const container = document.getElementById('eduEntriesContainer');
    if (!container) return;
    const div = document.createElement('div');
    div.className = 'edu-entry';
    div.dataset.index = _eduCount;
    div.innerHTML = `
      <div class="edu-entry-header">
        <span class="edu-entry-label">Entry ${_eduCount}</span>
        <button type="button" class="edu-remove-btn" onclick="removeEduEntry(this)">
          <i class="fa fa-times"></i> Remove
        </button>
      </div>
      <div class="form-grid">
        <div class="form-group">
          <label>Level / Degree</label>
          <select name="edu_degree[]">
            <option value="">— Select —</option>
            <option>Bachelor's Degree</option><option>Master's Degree</option>
            <option>Doctorate</option><option>Senior High School</option>
            <option>High School</option><option>Vocational / Tech</option>
            <option>Elementary</option>
          </select>
        </div>
        <div class="form-group">
          <label>Course / Major</label>
          <input type="text" name="edu_course[]" placeholder="e.g. Bachelor of Education">
        </div>
        <div class="form-group">
          <label>School / University</label>
          <input type="text" name="edu_school[]" placeholder="e.g. Tarlac State University">
        </div>
        <div class="form-group">
          <label>Year Graduated</label>
          <input type="number" name="edu_year[]" min="1950" max="2030" placeholder="e.g. 2018">
        </div>
      </div>`;
    container.appendChild(div);
    // Show remove buttons when more than 1 entry
    container.querySelectorAll('.edu-remove-btn').forEach(b => b.style.display = 'inline-flex');
};
window.removeEduEntry = function(btn) {
    const entry = btn.closest('.edu-entry');
    if (entry) entry.remove();
    // Hide remove btn if only 1 left
    const container = document.getElementById('eduEntriesContainer');
    const entries = container?.querySelectorAll('.edu-entry') ?? [];
    if (entries.length === 1) entries[0].querySelector('.edu-remove-btn').style.display = 'none';
};

// ── Dynamic document rows ─────────────────────────────────────────────────────
let _docCount = 1;
window.addDocRow = function() {
    _docCount++;
    const list = document.getElementById('docUploadList');
    if (!list) return;
    const div = document.createElement('div');
    div.className = 'doc-upload-row';
    div.dataset.index = _docCount;
    div.innerHTML = `
      <div class="form-grid" style="align-items:end;">
        <div class="form-group">
          <label>Document Type</label>
          <select name="doc_type[]">
            <option value="">— Select type —</option>
            <option>Diploma / Transcript</option><option>Employment Contract</option>
            <option>SSS Card / Number</option><option>PhilHealth Card</option>
            <option>Pag-IBIG Card</option><option>TIN Card</option>
            <option>Government ID</option><option>NBI Clearance</option>
            <option>Medical Certificate</option><option>Certificate of Employment</option>
            <option>Other</option>
          </select>
        </div>
        <div class="form-group">
          <label>File</label>
          <input type="file" name="doc_file[]" accept=".pdf,.jpg,.jpeg,.png" class="doc-file-input">
          <small style="font-size:11px;color:#9ca3af;">PDF, JPG, PNG — max 5MB</small>
        </div>
        <div class="form-group" style="flex:0;min-width:80px;">
          <button type="button" class="edu-remove-btn" onclick="removeDocRow(this)" style="margin-top:24px;">
            <i class="fa fa-trash"></i>
          </button>
        </div>
      </div>`;
    list.appendChild(div);
};
window.removeDocRow = function(btn) {
    const row = btn.closest('.doc-upload-row');
    if (row) row.remove();
};