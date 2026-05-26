/* ============================================================
   leave.js – GEI HR System | Admin Leave Management
   ============================================================ */
'use strict';

/* ── HELPERS ─────────────────────────────────────────────── */
function escHtml(str) {
    if (!str) return '';
    return String(str)
        .replace(/&/g, '&amp;').replace(/</g, '&lt;')
        .replace(/>/g, '&gt;').replace(/"/g, '&quot;');
}

function showFlash(type, message) {
    const existing = document.querySelector('.alert-flash');
    if (existing) existing.remove();
    const el = document.createElement('div');
    el.className = `alert-flash alert-flash--${type}`;
    el.innerHTML = `${escHtml(message)} <button class="alert-flash__close" onclick="this.parentElement.remove()">×</button>`;
    const mc = document.querySelector('.main-content');
    if (mc) mc.prepend(el);
    setTimeout(() => { if (el.parentElement) el.remove(); }, 5000);
}

/* ── VIEW MODAL ──────────────────────────────────────────── */
async function openViewModal(leaveId) {
    document.getElementById('viewLeaveOverlay').style.display = 'flex';
    const body = document.getElementById('viewLeaveBody');
    body.innerHTML = '<div class="modal-loading"><i class="fa fa-spinner fa-spin"></i> Loading...</div>';

    try {
        const res  = await fetch(`${BASE_URL}actions/leave-get-details.php?leave_id=${leaveId}`);
        const data = await res.json();
        if (data.success) {
            body.innerHTML = buildViewHtml(data);
        } else {
            body.innerHTML = `<p style="color:#ef4444;">${escHtml(data.message)}</p>`;
        }
    } catch {
        body.innerHTML = '<p style="color:#ef4444;">Network error loading details.</p>';
    }
}

function buildViewHtml(data) {
    const r    = data.record;
    const dates = data.dates || [];
    const bal  = data.balance;
    const atts = data.attachments || [];
    const wf   = r.workflow_status || 'PENDING_REVIEW';

    // Employee row
    const initials = ((r.employee_name || '').split(' ').map(w => w[0]).join('').slice(0,2)).toUpperCase();
    const empRow = `
        <div class="vm-emp-row">
            <div class="vm-emp-avatar">${escHtml(initials)}</div>
            <div>
                <div class="vm-emp-name">${escHtml(r.employee_name)}</div>
                <div class="vm-emp-detail">
                    ${escHtml(r.employee_no || '—')} &nbsp;·&nbsp;
                    ${escHtml(r.department_name || '—')} &nbsp;·&nbsp;
                    ${escHtml(r.position_name || '—')}
                </div>
            </div>
        </div>`;

    // Info grid
    const fmLabels = { SINGLE: 'Single Date', MULTIPLE: 'Multiple Dates', RANGE: 'Date Range' };
    const fmLabel  = fmLabels[r.filing_mode || 'MULTIPLE'] || (r.filing_mode || 'Multiple Dates');
    const infoGrid = `
        <div class="vm-info-grid">
            <div class="vm-info-cell"><label>Leave Type</label><span>${escHtml(r.leave_name)}</span></div>
            <div class="vm-info-cell"><label>Filing Mode</label><span>${escHtml(fmLabel)}</span></div>
            <div class="vm-info-cell"><label>Filed</label><span>${escHtml(r.applied_date)}</span></div>
            <div class="vm-info-cell"><label>Total Days</label><span>${escHtml(String(r.total_days))}</span></div>
            <div class="vm-info-cell"><label>Status</label>
                <span class="lm-badge lm-badge--${(r.status||'pending').toLowerCase()}">${escHtml(r.status||'Pending')}</span>
            </div>
        </div>`;

    // Backdated alert
    let backdatedHtml = '';
    if (parseInt(r.is_backdated)) {
        backdatedHtml = `
            <div class="vm-backdated-alert">
                <i class="fa fa-triangle-exclamation"></i>
                <div>
                    <strong>Backdated Filing</strong><br>
                    ${escHtml(r.backdate_reason || 'No explanation provided.')}
                </div>
            </div>`;
    }

    // Reason
    const reasonBox = `
        <div class="vm-section-label">Reason for Leave</div>
        <div class="vm-reason-box">${escHtml(r.reason)}</div>`;

    // Per-date table
    const dateRows = dates.map(d => {
        const sc = `status-badge--${(d.status||'pending').toLowerCase()}`;
        const noteHtml = d.principal_note
            ? `<span class="vm-note-cell">${escHtml(d.principal_note)}</span>`
            : `<span style="color:#cbd5e1;font-size:11px;">—</span>`;
        const byHtml  = d.actioned_by_name
            ? `<span style="font-size:11px;color:#94a3b8;">${escHtml(d.actioned_by_name)}</span>`
            : '';
        return `<tr>
            <td style="font-weight:600;">${escHtml(d.date_formatted)}</td>
            <td><span class="status-badge ${sc}">${(d.status||'').charAt(0).toUpperCase() + (d.status||'').slice(1).toLowerCase()}</span></td>
            <td>${noteHtml}</td>
            <td>${byHtml}</td>
        </tr>`;
    }).join('');

    const datesTable = `
        <div class="vm-section-label">Date Breakdown</div>
        <table class="vm-dates-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Status</th>
                    <th>Principal Note</th>
                    <th>Actioned By</th>
                </tr>
            </thead>
            <tbody>${dateRows}</tbody>
        </table>`;

    // Balance strip
    let balHtml = '';
    if (bal) {
        balHtml = `
            <div class="vm-section-label">Leave Balance (${escHtml(r.leave_name)})</div>
            <div class="vm-balance-strip">
                <div class="vm-balance-item"><label>Allocated</label><span>${escHtml(bal.allocated)}</span></div>
                <div class="vm-balance-item"><label>Used</label><span>${escHtml(bal.used)}</span></div>
                <div class="vm-balance-item"><label>Remaining</label><span>${escHtml(bal.remaining)}</span></div>
            </div>`;
    }

    // Attachments
    let attHtml = '';
    if (atts.length) {
        const attItems = atts.map(a => {
            const icon = (a.file_type||'').includes('pdf') ? 'fa-file-pdf' : 'fa-file-image';
            return `<a class="vm-attach-item" href="${BASE_URL}${escHtml(a.file_path)}" target="_blank">
                <i class="fa ${icon}"></i>
                <span>${escHtml(a.file_name)}</span>
                <i class="fa fa-arrow-up-right-from-square" style="margin-left:auto;font-size:11px;color:#94a3b8;"></i>
            </a>`;
        }).join('');
        attHtml = `
            <div class="vm-section-label">Supporting Documents</div>
            <div class="vm-attach-list">${attItems}</div>`;
    }

    // Admin note display
    let adminNoteHtml = '';
    if (r.admin_note) {
        adminNoteHtml = `
            <div class="vm-admin-note">
                <strong>Admin Note</strong>
                ${escHtml(r.admin_note)}
            </div>`;
    }

    // Workflow controls area
    let wfHtml = '';
    if (HAS_MIG016) {
        if (wf === 'PENDING_REVIEW') {
            wfHtml = `
                <div class="vm-workflow-bar">
                    <span class="vm-workflow-label">Actions:</span>
                    <button class="btn btn--primary" data-lid="${r.leave_id}" data-name="${escHtml(r.employee_name)}" onclick="handleForwardBtn(this)">
                        <i class="fa fa-paper-plane"></i> Forward to Principal
                    </button>
                    <button class="btn btn--ghost" onclick="saveAdminNote(${r.leave_id})">
                        <i class="fa fa-note-sticky"></i> Save Note
                    </button>
                </div>`;
        } else if (wf === 'FORWARDED') {
            const pendingLeft = dates.filter(d => d.status === 'PENDING').length;
            if (pendingLeft === 0) {
                wfHtml = `
                    <div class="vm-workflow-bar">
                        <span class="vm-workflow-label">Principal has decided all dates.</span>
                        <button class="btn btn--teal" onclick="doRecord(${r.leave_id});closeViewLeaveModal();">
                            <i class="fa fa-check-double"></i> Record Result &amp; Sync Attendance
                        </button>
                    </div>`;
            } else {
                wfHtml = `
                    <div class="vm-workflow-bar" style="background:#fffbeb;">
                        <i class="fa fa-clock" style="color:#d97706;"></i>
                        <span class="vm-workflow-label">Awaiting principal decision on ${pendingLeft} date(s).</span>
                    </div>`;
            }
        } else if (wf === 'RECORDED') {
            wfHtml = `
                <div class="vm-workflow-bar" style="background:#ecfdf5;">
                    <i class="fa fa-circle-check" style="color:#059669;"></i>
                    <span class="vm-workflow-label">Result recorded. Leave history is finalised.</span>
                </div>`;
        }
    }

    // Note textarea for admin note saving
    const noteArea = (HAS_MIG016 && wf === 'PENDING_REVIEW') ? `
        <div class="vm-section-label">Admin Internal Note</div>
        <div class="form-group">
            <textarea class="form-textarea" id="vmAdminNote" rows="2"
                placeholder="Add internal note (visible to admin only)...">${escHtml(r.admin_note || '')}</textarea>
        </div>` : '';

    return empRow + infoGrid + backdatedHtml + reasonBox + noteArea + datesTable + balHtml + attHtml + adminNoteHtml + wfHtml;
}

function closeViewLeaveModal() {
    document.getElementById('viewLeaveOverlay').style.display = 'none';
}
function closeViewModal(e) {
    if (e.target === document.getElementById('viewLeaveOverlay')) closeViewLeaveModal();
}

/* ── SAVE ADMIN NOTE (from view modal) ──────────────────── */
async function saveAdminNote(leaveId) {
    const note = (document.getElementById('vmAdminNote')?.value || '').trim();
    const fd   = new FormData();
    fd.append('action', 'mark_reviewed');
    fd.append('leave_id', leaveId);
    fd.append('note', note);

    try {
        const res  = await fetch(`${BASE_URL}actions/leave-admin-action.php`, { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) {
            showFlash('success', data.message || 'Note saved.');
        } else {
            showFlash('error', data.message || 'Failed to save note.');
        }
    } catch {
        showFlash('error', 'Network error.');
    }
}

/* ── FORWARD TO PRINCIPAL ────────────────────────────────── */
function handleForwardBtn(btn) {
    closeViewLeaveModal();
    openForwardModal(parseInt(btn.dataset.lid, 10), btn.dataset.name || '');
}

let _fwdLeaveId = null;

function openForwardModal(leaveId, employeeName) {
    _fwdLeaveId = leaveId;
    const el = document.getElementById('forwardEmpName');
    if (el) el.textContent = employeeName || '';
    const noteEl = document.getElementById('forwardNote');
    if (noteEl) noteEl.value = '';
    document.getElementById('forwardOverlay').style.display = 'flex';
}

function closeForwardModalDirect() {
    document.getElementById('forwardOverlay').style.display = 'none';
    _fwdLeaveId = null;
}

function closeForwardModal(e) {
    if (e.target === document.getElementById('forwardOverlay')) closeForwardModalDirect();
}

async function confirmForward() {
    if (!_fwdLeaveId) return;

    const note = (document.getElementById('forwardNote')?.value || '').trim();
    const btn  = document.getElementById('btnConfirmForward');
    if (btn) { btn.disabled = true; btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Forwarding...'; }

    const fd = new FormData();
    fd.append('action', 'forward');
    fd.append('leave_id', _fwdLeaveId);
    fd.append('note', note);

    try {
        const res  = await fetch(`${BASE_URL}actions/leave-admin-action.php`, { method: 'POST', body: fd });
        const data = await res.json();

        if (data.success) {
            closeForwardModalDirect();
            showFlash('success', data.message || 'Leave forwarded to principal.');
            setTimeout(() => location.reload(), 1000);
        } else {
            showFlash('error', data.message || 'Failed to forward.');
        }
    } catch {
        showFlash('error', 'Network error.');
    } finally {
        if (btn) { btn.disabled = false; btn.innerHTML = '<i class="fa fa-paper-plane"></i> Forward to Principal'; }
    }
}

/* ── RECORD RESULT ───────────────────────────────────────── */
let _recordConfirmCallback = null;

async function doRecord(leaveId) {
    _recordConfirmCallback = async () => {
        closeConfirmModal();
        const fd = new FormData();
        fd.append('action', 'record');
        fd.append('leave_id', leaveId);

        try {
            const res  = await fetch(`${BASE_URL}actions/leave-admin-action.php`, { method: 'POST', body: fd });
            const data = await res.json();

            if (data.success) {
                showFlash('success', data.message || 'Result recorded.');
                setTimeout(() => location.reload(), 1200);
            } else {
                showFlash('error', data.message || 'Failed to record result.');
            }
        } catch {
            showFlash('error', 'Network error.');
        }
    };

    showConfirm(
        'Record Result',
        'Mark this leave as recorded? Attendance records will be updated for all approved dates.',
        'Yes, Record',
        'teal',
        _recordConfirmCallback
    );
}

/* ── CONFIRM MODAL ───────────────────────────────────────── */
let confirmCallback = null;

function showConfirm(title, desc, btnLabel, btnType, callback) {
    document.getElementById('confirmTitle').textContent = title;
    document.getElementById('confirmDesc').textContent  = desc;

    const btn = document.getElementById('confirmBtn');
    btn.textContent = btnLabel;
    btn.className = `btn btn--${btnType}`;

    const icon = document.getElementById('confirmIcon');
    const iconMap = { teal: ['fa-circle-check','#ecfdf5','#059669'], danger: ['fa-circle-xmark','#fef2f2','#dc2626'], primary: ['fa-paper-plane','#eff6ff','#2563eb'] };
    const [iconCls, bg, color] = iconMap[btnType] || iconMap.primary;
    icon.className = `fa ${iconCls}`;
    const iconWrap = icon.closest('.confirm-icon');
    if (iconWrap) { iconWrap.style.background = bg; icon.style.color = color; }

    confirmCallback = callback;
    document.getElementById('confirmOverlay').style.display = 'flex';
}

function executeConfirm() {
    if (confirmCallback) confirmCallback();
}

function closeConfirmModal(e) {
    if (e && e.target !== document.getElementById('confirmOverlay')) return;
    document.getElementById('confirmOverlay').style.display = 'none';
}
