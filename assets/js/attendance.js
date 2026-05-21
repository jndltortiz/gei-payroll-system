// TAB SWITCHING
window.switchTab = function(tab) {
    const url = new URL(window.location.href);
    url.searchParams.set('tab', tab);
    window.location.href = url.toString();
};

// ADD MODAL — opens the manual attendance modal
window.openAttModal = function() {
    const modal = document.getElementById('addAttendanceModal');
    if (!modal) return;
    modal.classList.add('open');
    modal.style.display = 'flex';
    // Focus the employee dropdown (select-based modal), not a search input
    const empSel = document.getElementById('add_employee_id');
    if (empSel) empSel.focus();
};

window.closeAttModal = function() {
    const modal = document.getElementById('addAttendanceModal');
    if (!modal) return;
    modal.classList.remove('open');
    // closeAddModal() is defined inside the modal PHP file
    if (typeof closeAddModal === 'function') closeAddModal();
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
    const editModal = document.getElementById('editAttendanceModal');
    editModal.classList.add('open');
    editModal.style.display = 'flex';
};

window.closeEditModal = function() {
    const editModal = document.getElementById('editAttendanceModal');
    editModal.classList.remove('open');
    editModal.style.display = 'none';
};

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