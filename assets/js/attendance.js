// ─── Tab switching ────────────────────────────────────────────────────────────
window.switchTab = function(tab) {
    const url = new URL(window.location.href);
    url.searchParams.set('tab', tab);
    window.location.href = url.toString();
};

// ─── Backdrop click closes any open modal ─────────────────────────────────────
document.addEventListener('click', function(e) {
    const modals = ['addAttendanceModal', 'editAttendanceModal', 'timeoutModal', 'autoAbsentModal'];
    modals.forEach(id => {
        const el = document.getElementById(id);
        if (el && e.target === el) {
            switch (id) {
                case 'addAttendanceModal': closeAttModal();         break;
                case 'editAttendanceModal': closeEditModal();       break;
                case 'timeoutModal':        closeTimeoutModal();    break;
                case 'autoAbsentModal':     closeAutoAbsentModal(); break;
            }
        }
    });
});

// ─── Checkbox / Bulk-Timeout (Today tab) ──────────────────────────────────────

/**
 * Toggle all eligible (non-disabled) row checkboxes in the Today tab.
 * Called by the "select all" checkbox in the thead.
 */
window.toggleSelectAll = function(el) {
    document.querySelectorAll('.row-select:not([disabled])').forEach(cb => {
        cb.checked = el.checked;
    });
    updateBulkBar();
};

/**
 * Sync the bulk-timeout bar visibility and count label.
 * Called on every individual checkbox change.
 */
window.updateBulkBar = function() {
    const selected  = document.querySelectorAll('.row-select:checked');
    const bar       = document.getElementById('bulkTimeoutBar');
    const countEl   = document.getElementById('bulkCount');
    const selectAll = document.getElementById('selectAllRows');
    const eligible  = document.querySelectorAll('.row-select:not([disabled])');

    if (!bar) return;

    if (selected.length > 0) {
        bar.style.display = 'flex';
        countEl.textContent = `${selected.length} employee${selected.length !== 1 ? 's' : ''} selected`;
    } else {
        bar.style.display = 'none';
    }

    // Reflect partial/full selection on the "select all" checkbox
    if (selectAll) {
        selectAll.indeterminate = selected.length > 0 && selected.length < eligible.length;
        selectAll.checked = eligible.length > 0 && selected.length === eligible.length;
    }
};

/** Clear all row checkboxes. */
window.clearRowSelection = function() {
    document.querySelectorAll('.row-select').forEach(cb => cb.checked = false);
    const sa = document.getElementById('selectAllRows');
    if (sa) { sa.checked = false; sa.indeterminate = false; }
    updateBulkBar();
};

/**
 * Gather checked row IDs + names, then open timeout confirmation modal.
 */
window.confirmBulkTimeout = function() {
    const checked = document.querySelectorAll('.row-select:checked');
    if (checked.length === 0) return;
    const ids   = Array.from(checked).map(cb => cb.value);
    const names = Array.from(checked).map(cb => cb.dataset.name || '');
    openTimeoutModal(ids, names);
};

// ─── Debounce helper ──────────────────────────────────────────────────────────
let _debTimer;
window.debounce = function(fn, delay) {
    clearTimeout(_debTimer);
    _debTimer = setTimeout(fn, delay);
};

// ─── HTML escape helper ───────────────────────────────────────────────────────
window.escHtml = function(str) {
    return String(str ?? '').replace(/[&<>"']/g, c => ({
        '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;',
    }[c]));
};
