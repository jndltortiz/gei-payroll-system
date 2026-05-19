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
    fetch('../../actions/get-employee.php?id=' + id)
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
    fetch('../../actions/get-employee.php?id=' + id)
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

window.confirmSave = function(type) {
    if (_pendingFormId) {
        const form = document.getElementById(_pendingFormId);
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
    const input = document.getElementById('searchInput').value.toLowerCase();
    const rows  = document.querySelectorAll('#empTable tbody tr');
    rows.forEach(row => {
        row.style.display = row.innerText.toLowerCase().includes(input) ? '' : 'none';
    });
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