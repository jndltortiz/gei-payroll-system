/**
 * assets/js/principal-leave.js
 * GEI Principal Portal – Leave Management
 */

/* ── Sidebar toggle ──────────────────────────────────────────────────────── */
const sidebar      = document.getElementById('sidebar');
const sidebarToggle = document.getElementById('sidebarToggle');
const toggleIcon   = document.getElementById('toggleIcon');

sidebarToggle?.addEventListener('click', () => {
    sidebar.classList.toggle('collapsed');
    document.body.classList.toggle('sidebar-collapsed');
    lucide.createIcons();
});

/* ── Modal helpers ───────────────────────────────────────────────────────── */
function openModal(id)  { document.getElementById(id)?.classList.add('open'); }
function closeModal(id) { document.getElementById(id)?.classList.remove('open'); }

// Close via data-close attribute
document.querySelectorAll('[data-close]').forEach(btn => {
    btn.addEventListener('click', () => closeModal(btn.dataset.close));
});
// Close on overlay click
document.querySelectorAll('.modal-overlay').forEach(overlay => {
    overlay.addEventListener('click', e => {
        if (e.target === overlay) closeModal(overlay.id);
    });
});
// ESC key
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal-overlay.open')
            .forEach(m => closeModal(m.id));
    }
});

/* ── Toast ───────────────────────────────────────────────────────────────── */
function showToast(msg, type = 'success', duration = 3500) {
    const icons = { success: 'check-circle', error: 'x-circle', warn: 'alert-triangle' };
    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;
    toast.innerHTML = `<i data-lucide="${icons[type] || 'info'}"></i><span>${msg}</span>`;
    document.getElementById('toastContainer').appendChild(toast);
    lucide.createIcons();
    setTimeout(() => toast.remove(), duration);
}

/* ── File Leave modal ────────────────────────────────────────────────────── */
document.getElementById('fileLeaveBtn')?.addEventListener('click', () => {
    openModal('fileLeaveModal');
    lucide.createIcons();
});

// File upload display
const fileInput = document.getElementById('leaveAttachment');
const fileDisplay = document.getElementById('fileNameDisplay');
fileInput?.addEventListener('change', () => {
    const file = fileInput.files[0];
    if (file) {
        fileDisplay.textContent = '📎 ' + file.name;
        fileDisplay.classList.remove('hidden');
    } else {
        fileDisplay.classList.add('hidden');
    }
});

// Submit leave form
document.getElementById('fileLeaveForm')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const form = e.target;

    // Basic validation
    const type   = form.leave_type_id.value;
    const start  = form.start_date.value;
    const end    = form.end_date.value;
    const reason = form.reason.value.trim();

    if (!type || !start || !end || !reason) {
        showToast('Please fill in all required fields.', 'error');
        return;
    }
    if (new Date(end) < new Date(start)) {
        showToast('End date cannot be before start date.', 'error');
        return;
    }

    const btn = document.getElementById('submitLeaveBtn');
    btn.classList.add('btn-loading');
    btn.textContent = 'Submitting...';

    const fd = new FormData(form);
    try {
        const res = await fetch('../../../actions/leave-file.php', {
            method: 'POST',
            body: fd
        });
        const data = await res.json();
        if (data.success) {
            showToast('Leave request submitted successfully.', 'success');
            closeModal('fileLeaveModal');
            form.reset();
            fileDisplay.classList.add('hidden');
            setTimeout(() => location.reload(), 1000);
        } else {
            showToast(data.message || 'Failed to submit leave.', 'error');
        }
    } catch {
        showToast('Network error. Please try again.', 'error');
    } finally {
        btn.classList.remove('btn-loading');
        btn.innerHTML = '<i data-lucide="send"></i> Submit Leave';
        lucide.createIcons();
    }
});

/* ── Individual date action ──────────────────────────────────────────────── */
let pendingAction = null; // { type, dateId, leaveId, remarks }

/**
 * Called from inline onclick on Approve/Reject buttons.
 * @param {number} dateId
 * @param {number} leaveId
 * @param {'approve'|'reject'} action
 */
function dateAction(dateId, leaveId, action) {
    pendingAction = { type: 'date', action, dateId, leaveId };

    if (action === 'approve') {
        document.getElementById('confirmMsg').textContent =
            `Approve leave date for ${formatDate(getDateFromRow(dateId))}?`;
        document.getElementById('confirmOkBtn').textContent = 'Approve';
        document.getElementById('confirmOkBtn').className = 'btn-primary';
        openModal('confirmModal');
        lucide.createIcons();
    } else {
        const dateLabel = formatDate(getDateFromRow(dateId));
        document.getElementById('rejectMsg').textContent =
            `You are rejecting the leave date: ${dateLabel}.`;
        document.getElementById('rejectRemarks').value = '';
        openModal('rejectModal');
        lucide.createIcons();
    }
}

function getDateFromRow(dateId) {
    const row = document.querySelector(`[data-date-id="${dateId}"] .date-cell`);
    return row ? row.textContent.trim() : '';
}

function formatDate(str) { return str || 'selected date'; }

// Confirm approve
document.getElementById('confirmOkBtn')?.addEventListener('click', async () => {
    if (!pendingAction) return;
    closeModal('confirmModal');
    await executeAction(pendingAction.action, pendingAction);
});

// Confirm reject
document.getElementById('rejectOkBtn')?.addEventListener('click', async () => {
    if (!pendingAction) return;
    const remarks = document.getElementById('rejectRemarks').value.trim();
    pendingAction.remarks = remarks;
    closeModal('rejectModal');
    await executeAction('reject', pendingAction);
});

/* ── Bulk action (Approve All / Reject All) ──────────────────────────────── */
function bulkAction(leaveId, action) {
    pendingAction = { type: 'bulk', action, leaveId };

    if (action === 'approve') {
        document.getElementById('confirmMsg').textContent =
            'Approve ALL pending dates for this leave request?';
        document.getElementById('confirmOkBtn').textContent = 'Approve All';
        document.getElementById('confirmOkBtn').className = 'btn-primary';
        openModal('confirmModal');
        lucide.createIcons();
    } else {
        document.getElementById('rejectMsg').textContent =
            'Reject ALL pending dates for this leave request?';
        document.getElementById('rejectRemarks').value = '';
        openModal('rejectModal');
        lucide.createIcons();
    }
}

/* ── Execute AJAX action ─────────────────────────────────────────────────── */
async function executeAction(action, ctx) {
    const payload = new FormData();
    payload.append('action', action);
    payload.append('type', ctx.type);

    if (ctx.type === 'date') {
        payload.append('date_id', ctx.dateId);
        payload.append('leave_id', ctx.leaveId);
    } else {
        payload.append('leave_id', ctx.leaveId);
    }
    if (ctx.remarks) payload.append('remarks', ctx.remarks);

    try {
        const res = await fetch('../../../actions/leave-action.php', {
            method: 'POST',
            body: payload
        });
        const data = await res.json();

        if (data.success) {
            const label = action === 'approve' ? 'approved' : 'rejected';
            showToast(`Leave date(s) ${label} successfully.`, 'success');

            if (ctx.type === 'date') {
                updateDateRow(ctx.dateId, action);
                updateCardSummary(ctx.leaveId);
            } else {
                refreshCard(ctx.leaveId, action);
            }
        } else {
            showToast(data.message || 'Action failed.', 'error');
        }
    } catch {
        showToast('Network error. Please try again.', 'error');
    }
    pendingAction = null;
}

/* ── DOM update helpers (optimistic UI) ─────────────────────────────────── */
function updateDateRow(dateId, action) {
    const row = document.querySelector(`.date-row[data-date-id="${dateId}"]`);
    if (!row) return;

    const statusCell = row.querySelector('td:nth-child(2)');
    const actionCell = row.querySelector('.action-cell');

    const isApprove = action === 'approve';
    statusCell.innerHTML = `<span class="badge ${isApprove ? 'badge-approved' : 'badge-rejected'}">
        ${isApprove ? 'Approved' : 'Rejected'}
    </span>`;
    actionCell.innerHTML = `<span class="actioned-label ${isApprove ? 'actioned-approve' : 'actioned-reject'}">
        ${isApprove ? '✓ Approved' : '✗ Rejected'}
    </span>`;
}

function updateCardSummary(leaveId) {
    const card = document.querySelector(`.leave-card[data-leave-id="${leaveId}"]`);
    if (!card) return;

    const rows = card.querySelectorAll('.date-row');
    let pending = 0, approved = 0, rejected = 0;

    rows.forEach(r => {
        const badge = r.querySelector('.badge');
        if (!badge) return;
        const txt = badge.textContent.trim().toLowerCase();
        if (txt === 'pending')  pending++;
        else if (txt === 'approved') approved++;
        else rejected++;
    });

    card.querySelector('.pill-pending').textContent  = `${pending} Pending`;
    card.querySelector('.pill-approved').textContent = `${approved} Approved`;
    card.querySelector('.pill-rejected').textContent = `${rejected} Rejected`;

    // Update overall badge
    let overallStatus, overallClass;
    if (pending > 0 && (approved > 0 || rejected > 0)) {
        overallStatus = 'Partial'; overallClass = 'badge-partial';
    } else if (pending > 0) {
        overallStatus = 'Pending'; overallClass = 'badge-pending';
    } else if (rejected === rows.length) {
        overallStatus = 'Rejected'; overallClass = 'badge-rejected';
    } else {
        overallStatus = 'Approved'; overallClass = 'badge-approved';
    }

    const empNameBadge = card.querySelector('.emp-name .badge');
    if (empNameBadge) {
        empNameBadge.className = `badge ${overallClass}`;
        empNameBadge.textContent = overallStatus;
    }

    // Hide Approve All / Reject All if no pending left
    if (pending === 0) {
        card.querySelector('.btn-approve-all')?.remove();
        card.querySelector('.btn-reject-all')?.remove();

        // If fully actioned, remove from pending list after a moment
        setTimeout(() => {
            card.style.transition = 'opacity 400ms ease, transform 400ms ease';
            card.style.opacity = '0';
            card.style.transform = 'translateY(-4px)';
            setTimeout(() => {
                card.remove();
                updatePendingCount();
            }, 400);
        }, 800);
    }
}

function refreshCard(leaveId, action) {
    const card = document.querySelector(`.leave-card[data-leave-id="${leaveId}"]`);
    if (!card) return;

    // Mark all pending rows
    card.querySelectorAll('.date-row').forEach(row => {
        const badge = row.querySelector('.badge');
        if (badge && badge.textContent.trim().toLowerCase() === 'pending') {
            updateDateRow(row.dataset.dateId, action);
        }
    });
    updateCardSummary(leaveId);
}

function updatePendingCount() {
    const remaining = document.querySelectorAll('.leave-card').length;
    const badge = document.querySelector('.count-badge');
    if (badge) badge.textContent = `${remaining} ${remaining === 1 ? 'request' : 'requests'}`;

    if (remaining === 0) {
        const leaveList = document.getElementById('leaveList');
        if (leaveList) {
            leaveList.outerHTML = `
            <div class="empty-state">
                <i data-lucide="check-circle-2"></i>
                <p>All caught up! No pending leave requests.</p>
            </div>`;
            lucide.createIcons();
        }
    }
}

/* ── Init ────────────────────────────────────────────────────────────────── */
document.addEventListener('DOMContentLoaded', () => {
    lucide.createIcons();
});