// TAB SWITCHING
window.switchTab = function(tab) {
    const url = new URL(window.location.href);
    url.searchParams.set('tab', tab);
    window.location.href = url.toString();
};

// ADD MODAL
window.openAttModal = function() {
    document.getElementById('addAttendanceModal').classList.add('open');
    document.getElementById('addAttendanceModal').style.display = 'flex';
    document.getElementById('empSearchInput').focus();
};

window.closeAttModal = function() {
    document.getElementById('addAttendanceModal').classList.remove('open');
    clearEmployeeSelection();
    document.getElementById('addAttForm').reset();
};

// Close on backdrop click
document.addEventListener('click', function(e) {
    const addModal  = document.getElementById('addAttendanceModal');
    const editModal = document.getElementById('editAttendanceModal');
    if (e.target === addModal)  closeAttModal();
    if (e.target === editModal) closeEditModal();
});

// EDIT MODAL
window.openEditModal = function(id, name, date, timeIn, timeOut, status, remarks) {
    document.getElementById('edit_id').value       = id;
    document.getElementById('edit_employee').value = name;
    document.getElementById('edit_date').value     = date;
    document.getElementById('edit_time_in').value  = timeIn  || '';
    document.getElementById('edit_time_out').value = timeOut || '';
    document.getElementById('edit_status').value   = status;
    document.getElementById('edit_remarks').value  = remarks || '';
    document.getElementById('editModalSubtitle').textContent = 'Editing record for ' + name;
    document.getElementById('editAttendanceModal').classList.add('open');
};

window.closeEditModal = function() {
    document.getElementById('editAttendanceModal').classList.remove('open');
};

// LIVE EMPLOYEE SEARCH
// Fetches from a lightweight JSON endpoint
let empSearchTimer = null;

window.searchEmployees = function(query) {
    const dropdown = document.getElementById('empDropdown');

    if (query.trim().length < 1) {
        dropdown.classList.add('hidden');
        return;
    }

    clearTimeout(empSearchTimer);
    empSearchTimer = setTimeout(() => {
        fetch('../../actions/search-employees.php?q=' + encodeURIComponent(query.trim()))
            .then(r => {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.json();
            })
            .then(data => {
                renderEmployeeOptions(query.trim(), data);
            })
            .catch(err => {
                console.error('Employee search failed:', err);
                dropdown.innerHTML = '<div class="att-emp-option" style="color:#9ca3af;text-align:center;">Error loading employees.</div>';
                dropdown.classList.remove('hidden');
            });
    }, 250);
};

function renderEmployeeOptions(query, employees) {
    const dropdown = document.getElementById('empDropdown');
    const q = query.toLowerCase();
    const filtered = employees.filter(e =>
        (e.first_name + ' ' + e.last_name).toLowerCase().includes(q) ||
        (e.employee_no || '').toLowerCase().includes(q)
    );

    if (filtered.length === 0) {
        dropdown.innerHTML = '<div class="att-emp-option" style="color:#9ca3af;text-align:center;">No employees found</div>';
    } else {
        dropdown.innerHTML = filtered.slice(0, 20).map(e => `
            <div class="att-emp-option" onclick="selectEmployee(${e.employee_id}, '${escHtml(e.first_name + ' ' + e.last_name)}', '${escHtml(e.department_name || '')}')">
                <div>${escHtml(e.first_name + ' ' + e.last_name)}</div>
                <div class="opt-dept">${escHtml(e.department_name || '')} ${e.employee_no ? '· ' + e.employee_no : ''}</div>
            </div>
        `).join('');
    }

    dropdown.classList.remove('hidden');
}

window.selectEmployee = function(id, name, dept) {
    document.getElementById('selectedEmployeeId').value = id;
    document.getElementById('empSearchInput').value = '';
    document.getElementById('empDropdown').classList.add('hidden');

    const badge = document.getElementById('selectedEmpBadge');
    document.getElementById('selectedEmpName').textContent = name + (dept ? ' · ' + dept : '');
    badge.classList.remove('hidden');
};

window.clearEmployeeSelection = function() {
    document.getElementById('selectedEmployeeId').value = '';
    document.getElementById('empSearchInput').value = '';
    document.getElementById('empDropdown').classList.add('hidden');
    document.getElementById('selectedEmpBadge').classList.add('hidden');
};

// Hide dropdown when clicking outside
document.addEventListener('click', function(e) {
    const wrap = document.getElementById('addAttendanceModal');
    if (!wrap) return;
    const dd = document.getElementById('empDropdown');
    const inp = document.getElementById('empSearchInput');
    if (dd && inp && !dd.contains(e.target) && e.target !== inp) {
        dd.classList.add('hidden');
    }
});

// Validate employee selected on form submit
const addAttForm = document.getElementById('addAttForm');
if (addAttForm) {
    addAttForm.addEventListener('submit', function(e) {
        const empId = document.getElementById('selectedEmployeeId').value;
        if (!empId) {
            e.preventDefault();
            alert('Please select an employee.');
            document.getElementById('empSearchInput').focus();
        }
    });
}

// DEBOUNCE HELPER
let _debTimer;
window.debounce = function(fn, delay) {
    clearTimeout(_debTimer);
    _debTimer = setTimeout(fn, delay);
};

// HTML ESCAPE HELPER
function escHtml(str) {
    return String(str).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}