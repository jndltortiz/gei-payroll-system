/**
 * assets/js/principal-leave-approval.js
 * GEI Principal Portal – Leave Management
 * Uses: Font Awesome icons, pr-modal-overlay classes, showToast from head.php
 */

/* ── Modal helpers ───────────────────────────────────────────────────────── */
function openModal(id)  {
    const el = document.getElementById(id);
    if (el) el.style.display = 'flex';
}
function closeModal(id) {
    const el = document.getElementById(id);
    if (el) el.style.display = 'none';
}

// Close on backdrop click
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.pr-modal-overlay').forEach(overlay => {
        overlay.addEventListener('click', e => {
            if (e.target === overlay) closeModal(overlay.id);
        });
    });
});

// ESC key closes open modals
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        document.querySelectorAll('.pr-modal-overlay').forEach(el => {
            if (el.style.display === 'flex') closeModal(el.id);
        });
    }
});

/* ── File Leave Form ─────────────────────────────────────────────────────── */
document.getElementById('fileLeaveForm')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const form    = e.target;
    const typeVal = form.leave_type_id.value;
    const start   = form.start_date.value;
    const end     = form.end_date.value;
    const reason  = form.reason.value.trim();

    if (!typeVal || !start || !end || !reason) {
        showToast('Please fill in all required fields.', 'error');
        return;
    }
    if (new Date(end) < new Date(start)) {
        showToast('End date cannot be before start date.', 'error');
        return;
    }

    const btn = document.getElementById('submitLeaveBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Submitting…';

    // Build dates[] array from start→end range
    const fd = new FormData(form);
    fd.delete('start_date');
    fd.delete('end_date');

    const d1 = new Date(start + 'T00:00:00');
    const d2 = new Date(end   + 'T00:00:00');
    for (let d = new Date(d1); d <= d2; d.setDate(d.getDate() + 1)) {
        const iso = d.toISOString().split('T')[0];
        fd.append('dates[]', iso);
    }

    try {
        const res  = await fetch(BASE_URL + 'actions/leave-file.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) {
            showToast('Leave request submitted successfully!', 'success');
            closeModal('fileLeaveModal');
            form.reset();
            document.getElementById('fileNameDisplay').style.display = 'none';
            setTimeout(() => location.reload(), 1200);
        } else {
            showToast(data.message || 'Failed to submit leave.', 'error');
        }
    } catch {
        showToast('Network error. Please try again.', 'error');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa fa-paper-plane"></i> Submit Leave';
    }
});

// File upload display
document.getElementById('leaveAttachment')?.addEventListener('change', function () {
    const display = document.getElementById('fileNameDisplay');
    if (this.files[0]) {
        display.textContent = '📎 ' + this.files[0].name;
        display.style.display = 'block';
    } else {
        display.style.display = 'none';
    }
});

/* ── Individual date action ──────────────────────────────────────────────── */
let pendingAction = null;

function dateAction(dateId, leaveId, action) {
    pendingAction = { type: 'date', action, dateId, leaveId };

    if (action === 'approve') {
        const dateLabel = getDateFromRow(dateId);
        document.getElementById('confirmTitle').textContent = 'Confirm Approval';
        document.getElementById('confirmMsg').textContent =
            `Approve leave date ${dateLabel}?`;
        document.getElementById('confirmOkBtn').innerHTML =
            '<i class="fa fa-circle-check"></i> Approve';
        openModal('confirmModal');
    } else {
        const dateLabel = getDateFromRow(dateId);
        document.getElementById('rejectMsg').textContent =
            `You are rejecting the leave date: ${dateLabel}.`;
        document.getElementById('rejectRemarks').value = '';
        openModal('rejectModal');
    }
}

function getDateFromRow(dateId) {
    const row = document.querySelector(`.lv-date-row[data-date-id="${dateId}"] .lv-date-cell`);
    return row ? row.textContent.trim() : '';
}

// Confirm approve
document.getElementById('confirmOkBtn')?.addEventListener('click', async () => {
    if (!pendingAction) return;
    closeModal('confirmModal');
    await executeAction(pendingAction.action, pendingAction);
});

// Confirm reject
document.getElementById('rejectOkBtn')?.addEventListener('click', async () => {
    if (!pendingAction) return;
    pendingAction.remarks = document.getElementById('rejectRemarks').value.trim();
    closeModal('rejectModal');
    await executeAction('reject', pendingAction);
});

/* ── Bulk action ─────────────────────────────────────────────────────────── */
function bulkAction(leaveId, action) {
    pendingAction = { type: 'bulk', action, leaveId };

    if (action === 'approve') {
        document.getElementById('confirmTitle').textContent = 'Approve All Dates';
        document.getElementById('confirmMsg').textContent =
            'Approve ALL pending dates for this leave request?';
        document.getElementById('confirmOkBtn').innerHTML =
            '<i class="fa fa-circle-check"></i> Approve All';
        openModal('confirmModal');
    } else {
        document.getElementById('rejectMsg').textContent =
            'Reject ALL pending dates for this leave request?';
        document.getElementById('rejectRemarks').value = '';
        openModal('rejectModal');
    }
}

/* ── Execute AJAX ────────────────────────────────────────────────────────── */
async function executeAction(action, ctx) {
    const payload = new FormData();
    payload.append('action', action);

    // leave-action.php expects level=date|all  (NOT type=date|bulk)
    if (ctx.type === 'date') {
        payload.append('level',    'date');
        payload.append('date_id',  ctx.dateId);
        payload.append('leave_id', ctx.leaveId);
    } else {
        payload.append('level',    'all');
        payload.append('leave_id', ctx.leaveId);
    }
    if (ctx.remarks) payload.append('notes', ctx.remarks);

    try {
        const res  = await fetch(BASE_URL + 'actions/leave-action.php', {
            method: 'POST', body: payload
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

/* ── Optimistic DOM updates ──────────────────────────────────────────────── */
function updateDateRow(dateId, action) {
    const row = document.querySelector(`.lv-date-row[data-date-id="${dateId}"]`);
    if (!row) return;

    const isApprove    = action === 'approve';
    const badgeClass   = isApprove ? 'lv-badge--approved' : 'lv-badge--rejected';
    const actionedClass = isApprove ? 'lv-actioned--approve' : 'lv-actioned--reject';
    const label         = isApprove ? 'Approved' : 'Rejected';

    row.querySelector('td:nth-child(2)').innerHTML =
        `<span class="lv-badge ${badgeClass}">${label}</span>`;
    row.querySelector('.lv-action-cell').innerHTML =
        `<span class="lv-actioned ${actionedClass}">${isApprove ? '✓' : '✗'} ${label}</span>`;
}

function updateCardSummary(leaveId) {
    const card = document.querySelector(`.lv-leave-card[data-leave-id="${leaveId}"]`);
    if (!card) return;

    let pending = 0, approved = 0, rejected = 0;
    card.querySelectorAll('.lv-date-row').forEach(row => {
        const badge = row.querySelector('.lv-badge');
        if (!badge) return;
        const txt = badge.textContent.trim().toLowerCase();
        if (txt === 'pending')  pending++;
        else if (txt === 'approved') approved++;
        else rejected++;
    });

    card.querySelector('.lv-pill--pending').textContent  = `${pending} Pending`;
    card.querySelector('.lv-pill--approved').textContent = `${approved} Approved`;
    card.querySelector('.lv-pill--rejected').textContent = `${rejected} Rejected`;

    // Update overall badge
    let badgeClass, badgeText;
    if (pending > 0 && (approved > 0 || rejected > 0)) {
        badgeClass = 'lv-badge--partial';  badgeText = 'Partial';
    } else if (pending > 0) {
        badgeClass = 'lv-badge--pending';  badgeText = 'Pending';
    } else if (approved > 0 && rejected === 0) {
        badgeClass = 'lv-badge--approved'; badgeText = 'Approved';
    } else {
        badgeClass = 'lv-badge--rejected'; badgeText = 'Rejected';
    }
    const empBadge = card.querySelector('.lv-emp-name .lv-badge');
    if (empBadge) {
        empBadge.className = `lv-badge ${badgeClass}`;
        empBadge.textContent = badgeText;
    }

    // Remove bulk buttons & fade out card when fully actioned
    if (pending === 0) {
        card.querySelector('.lv-btn-approve-all')?.remove();
        card.querySelector('.lv-btn-reject-all')?.remove();

        setTimeout(() => {
            card.style.transition = 'opacity 400ms ease, transform 400ms ease';
            card.style.opacity    = '0';
            card.style.transform  = 'translateY(-6px)';
            setTimeout(() => { card.remove(); updatePendingCount(); }, 420);
        }, 900);
    }
}

function refreshCard(leaveId, action) {
    const card = document.querySelector(`.lv-leave-card[data-leave-id="${leaveId}"]`);
    if (!card) return;
    card.querySelectorAll('.lv-date-row').forEach(row => {
        const badge = row.querySelector('.lv-badge');
        if (badge && badge.textContent.trim().toLowerCase() === 'pending') {
            updateDateRow(row.dataset.dateId, action);
        }
    });
    updateCardSummary(leaveId);
}

function updatePendingCount() {
    const remaining = document.querySelectorAll('.lv-leave-card').length;
    const badge = document.getElementById('pendingCountBadge');
    if (badge) badge.textContent = `${remaining} ${remaining === 1 ? 'request' : 'requests'}`;

    if (remaining === 0) {
        const list = document.getElementById('leaveList');
        if (list) {
            list.outerHTML = `
            <div class="lv-empty-state">
                <i class="fa fa-circle-check" style="font-size:32px;color:#10b981;margin-bottom:10px;display:block;"></i>
                <strong>All caught up!</strong>
                <p>No pending leave requests at this time.</p>
            </div>`;
        }
    }
}