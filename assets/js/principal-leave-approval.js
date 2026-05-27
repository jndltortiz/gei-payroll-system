/**
 * assets/js/principal-leave-approval.js
 * GEI Principal Portal – Leave Management
 */
'use strict';

/* ── Modal helpers ──────────────────────────────────────────── */
function openModal(id)  { const el = document.getElementById(id); if (el) el.style.display = 'flex'; }
function closeModal(id) { const el = document.getElementById(id); if (el) el.style.display = 'none'; }

document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.pr-modal-overlay').forEach(overlay => {
        overlay.addEventListener('click', e => { if (e.target === overlay) closeModal(overlay.id); });
    });
});
document.addEventListener('keydown', e => {
    if (e.key === 'Escape') {
        const open = [...document.querySelectorAll('.pr-modal-overlay')]
            .filter(el => el.style.display === 'flex');
        if (!open.length) return;
        // Close only the topmost (highest z-index) visible modal
        open.sort((a, b) =>
            (parseInt(getComputedStyle(b).zIndex) || 0) -
            (parseInt(getComputedStyle(a).zIndex) || 0)
        );
        closeModal(open[0].id);
    }
});

/* ── Utilities ──────────────────────────────────────────────── */
function escHtml(str) {
    if (!str) return '';
    return String(str)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;')
        .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

/* ── PRINCIPAL VIEW MODAL ───────────────────────────────────── */
async function openPrincipalView(leaveId) {
    openModal('principalViewModal');
    const body = document.getElementById('principalViewBody');
    body.innerHTML = '<div style="text-align:center;padding:50px;color:#94a3b8;"><i class="fa fa-spinner fa-spin fa-lg"></i><br>Loading…</div>';

    try {
        const res  = await fetch(BASE_URL + 'actions/leave-get-details.php?leave_id=' + leaveId);
        const data = await res.json();
        if (data.success) {
            body.innerHTML = buildPrincipalViewHtml(data);
        } else {
            body.innerHTML = `<div style="padding:20px;color:#ef4444;">${escHtml(data.message)}</div>`;
        }
    } catch {
        body.innerHTML = '<div style="padding:20px;color:#ef4444;">Network error loading details.</div>';
    }
}

function buildPrincipalViewHtml(data) {
    const r    = data.record;
    const dates = data.dates || [];
    const bal  = data.balance;
    const atts = data.attachments || [];

    const initials = ((r.employee_name || '').split(' ').map(w => w[0]).join('').slice(0,2)).toUpperCase();

    const empRow = `
        <div class="pvm-emp-row">
            <div class="pvm-avatar">${escHtml(initials)}</div>
            <div>
                <div class="pvm-name">${escHtml(r.employee_name)}</div>
                <div class="pvm-meta">
                    ${escHtml(r.employee_no || '—')} &nbsp;·&nbsp;
                    ${escHtml(r.department_name || '—')} &nbsp;·&nbsp;
                    ${escHtml(r.position_name || '—')}
                </div>
            </div>
        </div>`;

    const fmLabels = { SINGLE: 'Single Date', MULTIPLE: 'Multiple Dates', RANGE: 'Date Range' };
    const fmLabel  = fmLabels[r.filing_mode || 'MULTIPLE'] || (r.filing_mode || 'Multiple Dates');
    const infoGrid = `
        <div class="pvm-info-grid">
            <div class="pvm-info-cell"><label>Leave Type</label><span>${escHtml(r.leave_name)}</span></div>
            <div class="pvm-info-cell"><label>Filing Mode</label><span>${escHtml(fmLabel)}</span></div>
            <div class="pvm-info-cell"><label>Filed Date</label><span>${escHtml(r.applied_date)}</span></div>
            <div class="pvm-info-cell"><label>Total Days</label><span>${escHtml(String(r.total_days))}</span></div>
            <div class="pvm-info-cell"><label>Status</label>
                <span class="lv-badge lv-badge--${(r.status||'pending').toLowerCase()}">${escHtml(r.status||'Pending')}</span>
            </div>
        </div>`;

    let backdatedHtml = '';
    if (parseInt(r.is_backdated)) {
        backdatedHtml = `
            <div class="pvm-backdated-alert">
                <i class="fa fa-triangle-exclamation"></i>
                <div><strong>Backdated Filing</strong><br>${escHtml(r.backdate_reason || 'No explanation provided.')}</div>
            </div>`;
    }

    const reasonBox = `
        <div class="pvm-section-label">Reason</div>
        <div class="pvm-reason-box">${escHtml(r.reason)}</div>`;

    // Per-date table with inline decision controls for PENDING dates
    const dateRows = dates.map(d => {
        const sc   = `lv-status-badge--${(d.status||'pending').toLowerCase()}`;
        const note = d.principal_note
            ? `<em style="font-size:12px;color:#64748b;">${escHtml(d.principal_note)}</em>`
            : '<span style="color:#cbd5e1;font-size:11px;">—</span>';

        let actionHtml = '<span style="color:#94a3b8;font-size:12px;">—</span>';
        if (d.status === 'PENDING' && d.date_id) {
            actionHtml = `
                <div class="pvm-decide-row">
                    <button class="lv-btn-sm lv-btn-sm--approve"
                            onclick="dateAction(${d.date_id}, 0, 'approve')">
                        <i class="fa fa-check"></i> Approve
                    </button>
                    <button class="lv-btn-sm lv-btn-sm--reject"
                            onclick="dateAction(${d.date_id}, 0, 'reject')">
                        <i class="fa fa-times"></i> Reject
                    </button>
                </div>`;
        } else if (d.status === 'APPROVED') {
            actionHtml = '<span class="lv-actioned lv-actioned--approve">✓ Approved</span>';
        } else if (d.status === 'REJECTED') {
            actionHtml = '<span class="lv-actioned lv-actioned--reject">✗ Rejected</span>';
        }

        return `<tr>
            <td style="font-weight:600;">${escHtml(d.date_formatted)}</td>
            <td><span class="lv-status-badge ${sc}">${(d.status||'').charAt(0).toUpperCase()+(d.status||'').slice(1).toLowerCase()}</span></td>
            <td>${note}</td>
            <td style="text-align:right;">${actionHtml}</td>
        </tr>`;
    }).join('');

    const datesTable = `
        <div class="pvm-section-label">Date Breakdown — ${dates.length} Date${dates.length!==1?'s':''}</div>
        <table class="pvm-dates-table">
            <thead><tr>
                <th>Date</th><th>Status</th><th>Note</th><th style="text-align:right;">Action</th>
            </tr></thead>
            <tbody>${dateRows}</tbody>
        </table>`;

    let balHtml = '';
    if (bal) {
        balHtml = `
            <div class="pvm-section-label">Leave Balance (${escHtml(r.leave_name)})</div>
            <div class="pvm-balance-strip">
                <div class="pvm-balance-item"><label>Allocated</label><span>${escHtml(bal.allocated)}</span></div>
                <div class="pvm-balance-item"><label>Used</label><span>${escHtml(bal.used)}</span></div>
                <div class="pvm-balance-item"><label>Remaining</label><span>${escHtml(bal.remaining)}</span></div>
            </div>`;
    }

    let attHtml = '';
    if (atts.length) {
        const items = atts.map(a => {
            const icon = (a.file_type||'').includes('pdf') ? 'fa-file-pdf' : 'fa-file-image';
            return `<a class="pvm-attach-item" href="${BASE_URL}${escHtml(a.file_path)}" target="_blank">
                <i class="fa ${icon}"></i>
                <span>${escHtml(a.file_name)}</span>
                <i class="fa fa-arrow-up-right-from-square" style="margin-left:auto;font-size:10px;color:#94a3b8;"></i>
            </a>`;
        }).join('');
        attHtml = `
            <div class="pvm-section-label">Supporting Documents</div>
            <div class="pvm-attach-list">${items}</div>`;
    }

    let adminNoteHtml = '';
    if (r.admin_note) {
        adminNoteHtml = `
            <div style="background:#fffbeb;border:1px solid #fde68a;border-radius:8px;padding:10px 14px;font-size:12px;color:#92400e;margin-bottom:12px;">
                <strong style="display:block;font-size:11px;text-transform:uppercase;letter-spacing:.04em;margin-bottom:3px;">Admin Note</strong>
                ${escHtml(r.admin_note)}
            </div>`;
    }

    return empRow + `<div class="pvm-body">` + infoGrid + backdatedHtml + reasonBox + adminNoteHtml + datesTable + balHtml + attHtml + `</div>`;
}

/* ── File Leave Form ────────────────────────────────────────── */
document.getElementById('fileLeaveForm')?.addEventListener('submit', async (e) => {
    e.preventDefault();
    const form  = e.target;
    const start = form.start_date.value;
    const end   = form.end_date.value;
    const reason = form.reason.value.trim();

    if (!form.leave_type_id.value || !start || !end || !reason) {
        showToast('Please fill in all required fields.', 'error'); return;
    }
    if (new Date(end) < new Date(start)) {
        showToast('End date cannot be before start date.', 'error'); return;
    }

    const btn = document.getElementById('submitLeaveBtn');
    btn.disabled = true;
    btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Submitting…';

    const fd = new FormData(form);
    fd.delete('start_date'); fd.delete('end_date');
    const d1 = new Date(start + 'T00:00:00');
    const d2 = new Date(end   + 'T00:00:00');
    for (let d = new Date(d1); d <= d2; d.setDate(d.getDate() + 1)) {
        fd.append('dates[]', d.toISOString().split('T')[0]);
    }

    try {
        const res  = await fetch(BASE_URL + 'actions/leave-file.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) {
            showToast('Leave request submitted!', 'success');
            closeModal('fileLeaveModal');
            form.reset();
            const fnd = document.getElementById('fileNameDisplay');
            if (fnd) fnd.style.display = 'none';
            setTimeout(() => location.reload(), 1200);
        } else {
            showToast(data.message || 'Failed to submit.', 'error');
        }
    } catch {
        showToast('Network error.', 'error');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="fa fa-paper-plane"></i> Submit Leave';
    }
});

document.getElementById('leaveAttachment')?.addEventListener('change', function () {
    const d = document.getElementById('fileNameDisplay');
    if (this.files[0]) { d.textContent = '📎 ' + this.files[0].name; d.style.display = 'block'; }
    else d.style.display = 'none';
});

/* ── Individual date action ─────────────────────────────────── */
let pendingAction = null;

function dateAction(dateId, leaveId, action) {
    pendingAction = { type: 'date', action, dateId, leaveId };
    const dateLabel = document.querySelector(`.lv-date-row[data-date-id="${dateId}"] .lv-date-cell`)?.textContent?.trim() || '';

    if (action === 'approve') {
        document.getElementById('confirmTitle').textContent = 'Confirm Approval';
        document.getElementById('confirmMsg').textContent   = `Approve leave date ${dateLabel}?`;
        document.getElementById('confirmOkBtn').innerHTML   = '<i class="fa fa-circle-check"></i> Approve';
        openModal('confirmModal');
    } else {
        const msgEl = document.getElementById('rejectMsg');
        if (msgEl) msgEl.textContent = `Rejecting leave date: ${dateLabel}. Add a note explaining your decision.`;
        const remarksEl = document.getElementById('rejectRemarks');
        if (remarksEl) remarksEl.value = '';
        openModal('rejectModal');
    }
}

document.getElementById('confirmOkBtn')?.addEventListener('click', async () => {
    if (!pendingAction) return;
    closeModal('confirmModal');
    await executeAction(pendingAction.action, pendingAction);
});

document.getElementById('rejectOkBtn')?.addEventListener('click', async () => {
    if (!pendingAction) return;
    pendingAction.remarks = (document.getElementById('rejectRemarks')?.value || '').trim();
    closeModal('rejectModal');
    await executeAction('reject', pendingAction);
});

/* ── Bulk action ────────────────────────────────────────────── */
function bulkAction(leaveId, action) {
    pendingAction = { type: 'bulk', action, leaveId };
    if (action === 'approve') {
        document.getElementById('confirmTitle').textContent = 'Approve All Dates';
        document.getElementById('confirmMsg').textContent   = 'Approve ALL pending dates for this leave request?';
        document.getElementById('confirmOkBtn').innerHTML   = '<i class="fa fa-circle-check"></i> Approve All';
        openModal('confirmModal');
    } else {
        const msgEl = document.getElementById('rejectMsg');
        if (msgEl) msgEl.textContent = 'Reject ALL pending dates for this leave request?';
        const remarksEl = document.getElementById('rejectRemarks');
        if (remarksEl) remarksEl.value = '';
        openModal('rejectModal');
    }
}

/* ── Execute AJAX ───────────────────────────────────────────── */
async function executeAction(action, ctx) {
    const fd = new FormData();
    fd.append('action', action);
    if (ctx.type === 'date') {
        fd.append('level',   'date');
        fd.append('date_id', ctx.dateId);
        if (ctx.leaveId) fd.append('leave_id', ctx.leaveId);
    } else {
        fd.append('level',    'all');
        fd.append('leave_id', ctx.leaveId);
    }
    if (ctx.remarks) fd.append('notes', ctx.remarks);

    try {
        const res  = await fetch(BASE_URL + 'actions/leave-action.php', { method: 'POST', body: fd });
        const data = await res.json();

        if (data.success) {
            const label = action === 'approve' ? 'approved' : 'rejected';
            showToast(`Leave date(s) ${label} successfully.`, 'success');

            if (ctx.type === 'date') {
                updateDateRow(ctx.dateId, action);
                if (ctx.leaveId) updateCardSummary(ctx.leaveId);
                // Refresh view modal if open
                refreshViewModalDate(ctx.dateId, action, ctx.remarks);
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

/* ── Optimistic DOM updates (card UI) ──────────────────────── */
function updateDateRow(dateId, action) {
    const row = document.querySelector(`.lv-date-row[data-date-id="${dateId}"]`);
    if (!row) return;
    const isApprove    = action === 'approve';
    const badgeClass   = isApprove ? 'lv-badge--approved' : 'lv-badge--rejected';
    const actionedClass = isApprove ? 'lv-actioned--approve' : 'lv-actioned--reject';
    const label         = isApprove ? 'Approved' : 'Rejected';
    row.querySelector('td:nth-child(2)').innerHTML = `<span class="lv-badge ${badgeClass}">${label}</span>`;
    row.querySelector('.lv-action-cell').innerHTML = `<span class="lv-actioned ${actionedClass}">${isApprove ? '✓' : '✗'} ${label}</span>`;
}

function refreshViewModalDate(dateId, action, note) {
    const body = document.getElementById('principalViewBody');
    if (!body) return;
    // Find row in view modal table
    const rows = body.querySelectorAll('table tbody tr');
    rows.forEach(row => {
        const btn = row.querySelector(`[onclick*="dateAction(${dateId},"]`);
        if (!btn) return;
        const isApprove = action === 'approve';
        const sc = isApprove ? 'lv-status-badge--approved' : 'lv-status-badge--rejected';
        const label = isApprove ? 'Approved' : 'Rejected';
        const cells = row.querySelectorAll('td');
        if (cells[1]) cells[1].innerHTML = `<span class="lv-status-badge ${sc}">${label}</span>`;
        if (cells[2] && note) cells[2].innerHTML = `<em style="font-size:12px;color:#64748b;">${escHtml(note)}</em>`;
        if (cells[3]) cells[3].innerHTML = `<span class="lv-actioned ${isApprove?'lv-actioned--approve':'lv-actioned--reject'}">${isApprove?'✓':'✗'} ${label}</span>`;
    });
}

function updateCardSummary(leaveId) {
    const card = document.querySelector(`.lv-leave-card[data-leave-id="${leaveId}"]`);
    if (!card) return;

    let pending = 0, approved = 0, rejected = 0;
    card.querySelectorAll('.lv-date-row').forEach(row => {
        const badge = row.querySelector('.lv-badge');
        if (!badge) return;
        const txt = badge.textContent.trim().toLowerCase();
        if (txt === 'pending') pending++;
        else if (txt === 'approved') approved++;
        else rejected++;
    });

    card.querySelector('.lv-pill--pending').textContent  = `${pending} Pending`;
    card.querySelector('.lv-pill--approved').textContent = `${approved} Approved`;
    card.querySelector('.lv-pill--rejected').textContent = `${rejected} Rejected`;

    let badgeClass, badgeText;
    if (pending > 0 && (approved > 0 || rejected > 0)) { badgeClass = 'lv-badge--partial'; badgeText = 'Partial'; }
    else if (pending > 0) { badgeClass = 'lv-badge--pending'; badgeText = 'Pending'; }
    else if (approved > 0 && rejected === 0) { badgeClass = 'lv-badge--approved'; badgeText = 'Approved'; }
    else { badgeClass = 'lv-badge--rejected'; badgeText = 'Rejected'; }

    const empBadge = card.querySelector('.lv-emp-name .lv-badge');
    if (empBadge) { empBadge.className = `lv-badge ${badgeClass}`; empBadge.textContent = badgeText; }

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
