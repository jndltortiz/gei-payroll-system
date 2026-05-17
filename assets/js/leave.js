/* ============================================================
   leave.js – GEI HR System Leave Management
   ============================================================ */

'use strict';

/* ────────────────────────────────────────────────────────────
   CALENDAR STATE
──────────────────────────────────────────────────────────── */
const calState = {
    year:  new Date().getFullYear(),
    month: new Date().getMonth(),   // 0-based
    selected: []                     // array of 'YYYY-MM-DD' strings
};

/* ────────────────────────────────────────────────────────────
   UTILITY
──────────────────────────────────────────────────────────── */
function padZ(n) { return String(n).padStart(2, '0'); }

function toYMD(year, month, day) {
    return `${year}-${padZ(month + 1)}-${padZ(day)}`;
}

function formatDisplayDate(ymd) {
    const [y, m, d] = ymd.split('-').map(Number);
    const dt = new Date(y, m - 1, d);
    return dt.toLocaleDateString('en-US', { month: 'short', day: '2-digit', year: 'numeric' });
}

function todayYMD() {
    const t = new Date();
    return toYMD(t.getFullYear(), t.getMonth(), t.getDate());
}

/* ────────────────────────────────────────────────────────────
   CALENDAR RENDERING
──────────────────────────────────────────────────────────── */
function renderCalendar() {
    const monthNames = ['January','February','March','April','May','June',
                        'July','August','September','October','November','December'];

    document.getElementById('calMonthLabel').textContent =
        `${monthNames[calState.month]} ${calState.year}`;

    const tbody = document.getElementById('calendarBody');
    tbody.innerHTML = '';

    const firstDay  = new Date(calState.year, calState.month, 1).getDay();
    const daysInMon = new Date(calState.year, calState.month + 1, 0).getDate();
    const today     = todayYMD();

    let day = 1;
    for (let row = 0; row < 6; row++) {
        if (day > daysInMon) break;
        const tr = document.createElement('tr');

        for (let col = 0; col < 7; col++) {
            const td = document.createElement('td');
            if (row === 0 && col < firstDay) {
                td.innerHTML = '<span class="cal-day cal-day--empty"></span>';
            } else if (day > daysInMon) {
                td.innerHTML = '<span class="cal-day cal-day--empty"></span>';
            } else {
                const ymd  = toYMD(calState.year, calState.month, day);
                const past = ymd < today;
                const sel  = calState.selected.includes(ymd);
                const isToday = ymd === today;

                let cls = 'cal-day';
                if (past)    cls += ' cal-day--past';
                if (sel)     cls += ' cal-day--selected';
                if (isToday && !past) cls += ' cal-day--today';

                td.innerHTML = `<span class="${cls}" data-date="${ymd}">${day}</span>`;
                if (!past) {
                    td.querySelector('.cal-day').addEventListener('click', toggleDate);
                }
                day++;
            }
            tr.appendChild(td);
        }
        tbody.appendChild(tr);
    }

    renderSelectedTags();
}

function toggleDate(e) {
    const ymd = e.currentTarget.dataset.date;
    const idx = calState.selected.indexOf(ymd);
    if (idx === -1) {
        calState.selected.push(ymd);
        calState.selected.sort();
    } else {
        calState.selected.splice(idx, 1);
    }
    renderCalendar();
}

function renderSelectedTags() {
    const container = document.getElementById('selectedDateTags');
    container.innerHTML = '';
    calState.selected.forEach(ymd => {
        const tag = document.createElement('span');
        tag.className = 'selected-tag';
        tag.innerHTML = `${formatDisplayDate(ymd)}
            <button class="selected-tag__remove" data-date="${ymd}" title="Remove">×</button>`;
        tag.querySelector('.selected-tag__remove').addEventListener('click', function() {
            const i = calState.selected.indexOf(this.dataset.date);
            if (i !== -1) calState.selected.splice(i, 1);
            renderCalendar();
        });
        container.appendChild(tag);
    });
}

/* ────────────────────────────────────────────────────────────
   FILE-A-LEAVE MODAL
──────────────────────────────────────────────────────────── */
document.getElementById('btnFileLeave').addEventListener('click', openFileLeaveModal);

function openFileLeaveModal() {
    // Reset
    calState.selected = [];
    calState.year  = new Date().getFullYear();
    calState.month = new Date().getMonth();
    document.getElementById('leaveTypeSelect').value = '';
    document.getElementById('leaveReason').value = '';
    renderCalendar();
    document.getElementById('fileLeaveOverlay').style.display = 'flex';
}

function closeFileLeaveModal() {
    document.getElementById('fileLeaveOverlay').style.display = 'none';
}

function closeFileLeave(e) {
    if (e.target === document.getElementById('fileLeaveOverlay')) closeFileLeaveModal();
}

// Calendar nav
document.getElementById('calPrev').addEventListener('click', () => {
    if (calState.month === 0) { calState.month = 11; calState.year--; }
    else calState.month--;
    renderCalendar();
});
document.getElementById('calNext').addEventListener('click', () => {
    if (calState.month === 11) { calState.month = 0; calState.year++; }
    else calState.month++;
    renderCalendar();
});

/* ────────────────────────────────────────────────────────────
   SUBMIT FILE-A-LEAVE → Confirm Dialog
──────────────────────────────────────────────────────────── */
let pendingSubmit = null;

function submitFileLeave() {
    const leaveTypeId = document.getElementById('leaveTypeSelect').value;
    const reason      = document.getElementById('leaveReason').value.trim();

    if (!leaveTypeId) {
        alert('Please select a leave type.');
        return;
    }
    if (calState.selected.length === 0) {
        alert('Please select at least one date.');
        return;
    }
    if (!reason) {
        alert('Please enter a reason.');
        return;
    }

    const count = calState.selected.length;
    pendingSubmit = { leaveTypeId, dates: [...calState.selected], reason };

    showConfirm(
        'Submit Leave Request',
        `Submit ${count} date(s) as a leave request? Each date will be reviewed individually.`,
        'Yes, Submit',
        'teal',
        doSubmitLeave
    );
}

async function doSubmitLeave() {
    closeConfirmModal();

    const fd = new FormData();
    fd.append('leave_type_id', pendingSubmit.leaveTypeId);
    fd.append('reason', pendingSubmit.reason);
    pendingSubmit.dates.forEach(d => fd.append('dates[]', d));

    try {
        const res = await fetch(`${BASE_URL}actions/leave-file.php`, {
            method: 'POST',
            body: fd
        });
        const data = await res.json();

        if (data.success) {
            closeFileLeaveModal();
            showFlash('success', data.message || 'Leave request submitted successfully!');
            setTimeout(() => location.reload(), 1200);
        } else {
            showFlash('error', data.message || 'Failed to submit leave request.');
        }
    } catch (err) {
        showFlash('error', 'A network error occurred. Please try again.');
    }
}

/* ────────────────────────────────────────────────────────────
   EXPAND ROW (accordion)
──────────────────────────────────────────────────────────── */
const loadedExpands = {};

async function toggleExpand(row, leaveId) {
    const expandRow   = document.getElementById(`expand-${leaveId}`);
    const expandInner = document.getElementById(`expand-inner-${leaveId}`);
    const isOpen = row.classList.contains('is-expanded');

    // Close all other open rows
    document.querySelectorAll('.leave-row.is-expanded').forEach(r => {
        const lid = r.dataset.leaveId;
        r.classList.remove('is-expanded');
        const er = document.getElementById(`expand-${lid}`);
        if (er) er.style.display = 'none';
    });

    if (isOpen) return; // Just closing

    row.classList.add('is-expanded');
    expandRow.style.display = 'table-row';

    if (loadedExpands[leaveId]) return; // Already loaded

    expandInner.innerHTML = '<div class="expand-loading"><i class="fa fa-spinner fa-spin"></i> Loading...</div>';

    try {
        const res = await fetch(`${BASE_URL}actions/leave-get-expand.php?leave_id=${leaveId}`);
        const data = await res.json();

        if (data.success) {
            loadedExpands[leaveId] = true;
            expandInner.innerHTML = buildExpandHtml(data);
        } else {
            expandInner.innerHTML = '<p style="color:#ef4444;font-size:13px;">Failed to load details.</p>';
        }
    } catch (err) {
        expandInner.innerHTML = '<p style="color:#ef4444;font-size:13px;">Network error.</p>';
    }
}

function buildExpandHtml(data) {
    const b = data.balance;
    const dates = data.dates;

    let balanceHtml = '';
    if (b) {
        balanceHtml = `
        <div class="expand-balance">
            <div class="expand-balance__title">${escHtml(b.employee_name)}'s Leave Balance</div>
            <div class="expand-balance__grid">
                <div class="expand-balance__item">
                    <label>Standard Leave</label>
                    <span>${b.standard_leave}</span>
                </div>
                <div class="expand-balance__item">
                    <label>Service Credits</label>
                    <span class="amber">${b.service_credits}</span>
                </div>
                <div class="expand-balance__item">
                    <label>Days Used</label>
                    <span>${b.days_used}</span>
                </div>
                <div class="expand-balance__item">
                    <label>Remaining</label>
                    <span class="teal">${b.remaining}</span>
                </div>
            </div>
        </div>`;
    }

    let dateRows = dates.map(d => {
        const statusClass = `status-badge--${d.status.toLowerCase()}`;
        const isPending   = d.status === 'PENDING';
        const hasDateId   = d.date_id !== null && d.date_id !== undefined;

        let actionHtml = '';
        if (isPending && hasDateId) {
            actionHtml = `
                <button class="btn-action btn-action--approve"
                    onclick="quickDateAction(${d.date_id}, 'approve')">
                    <i class="fa fa-check"></i> Approve
                </button>
                <button class="btn-action btn-action--reject"
                    onclick="quickDateAction(${d.date_id}, 'reject')">
                    <i class="fa fa-times"></i> Reject
                </button>`;
        } else {
            actionHtml = `<span style="color:#94a3b8;font-size:12px;">Actioned</span>`;
        }

        return `<tr>
            <td style="font-weight:600;">${escHtml(d.date_formatted)}</td>
            <td class="td-status">
                <span class="status-badge ${statusClass}">
                    ${d.status.charAt(0) + d.status.slice(1).toLowerCase()}
                </span>
            </td>
            <td class="td-action">${actionHtml}</td>
        </tr>`;
    }).join('');

    const count = dates.length;

    return `
        ${balanceHtml}
        <div class="expand-breakdown__title">DATE BREAKDOWN — ${count} DATE${count !== 1 ? 'S' : ''}</div>
        <table class="expand-breakdown-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th style="width:200px;">Status</th>
                    <th style="width:200px;text-align:right;">Action</th>
                </tr>
            </thead>
            <tbody>${dateRows}</tbody>
        </table>
    `;
}

/* ────────────────────────────────────────────────────────────
   VIEW MODAL
──────────────────────────────────────────────────────────── */
async function openViewModal(leaveId) {
    document.getElementById('viewLeaveOverlay').style.display = 'flex';
    const body = document.getElementById('viewLeaveBody');
    body.innerHTML = '<div class="modal-loading"><i class="fa fa-spinner fa-spin"></i> Loading...</div>';

    try {
        const res = await fetch(`${BASE_URL}actions/leave-get-details.php?leave_id=${leaveId}`);
        const data = await res.json();

        if (data.success) {
            body.innerHTML = buildViewHtml(data);
        } else {
            body.innerHTML = '<p style="color:#ef4444;">Failed to load details.</p>';
        }
    } catch {
        body.innerHTML = '<p style="color:#ef4444;">Network error.</p>';
    }
}

function buildViewHtml(data) {
    const r = data.record;
    const dates = data.dates;
    const statusClass = `status-badge--${r.status.toLowerCase()}`;
    const isPending   = r.status === 'PENDING';

    let dateRows = dates.map(d => {
        const sc = `status-badge--${d.status.toLowerCase()}`;
        const isPend    = d.status === 'PENDING';
        const hasDateId = d.date_id !== null && d.date_id !== undefined;
        const actionHtml = (isPend && hasDateId)
            ? `<div class="td-actions">
                <button class="btn-action btn-action--approve btn--sm" onclick="quickDateAction(${d.date_id},'approve')"><i class="fa fa-check"></i> Approve</button>
                <button class="btn-action btn-action--reject btn--sm" onclick="quickDateAction(${d.date_id},'reject')"><i class="fa fa-times"></i> Reject</button>
               </div>`
            : `<div class="td-actions"><span style="color:#94a3b8;font-size:12px;">${isPend ? '—' : 'Done'}</span></div>`;

        return `<tr>
            <td style="font-weight:600;">${escHtml(d.date_formatted)}</td>
            <td><span class="status-badge ${sc}">${d.status.charAt(0) + d.status.slice(1).toLowerCase()}</span></td>
            <td>${actionHtml}</td>
        </tr>`;
    }).join('');

    let reviewHtml = '';
    if (isPending) {
        reviewHtml = `
        <hr class="view-divider">
        <div class="review-section">
            <div class="review-section__title">Review Request</div>
            <textarea class="review-notes" id="reviewNotes" rows="3"
                placeholder="Add notes (optional)..."></textarea>
            <div class="review-btn-row">
                <button class="btn btn--approve"
                    onclick="openApproveAll(${r.leave_id})">
                    <i class="fa fa-check"></i> Approve Request
                </button>
                <button class="btn btn--reject"
                    onclick="openDenyAll(${r.leave_id})">
                    <i class="fa fa-times-circle"></i> Deny Request
                </button>
            </div>
        </div>`;
    } else {
        const boxClass  = r.status === 'APPROVED' ? 'reviewed-box--approved' : 'reviewed-box--rejected';
        const icon      = r.status === 'APPROVED' ? 'fa-circle-check' : 'fa-circle-xmark';
        const verb      = r.status === 'APPROVED' ? 'approved' : 'denied';
        const approver  = r.approver_name || 'Admin';
        const date      = r.approved_at ? new Date(r.approved_at).toLocaleDateString('en-US',{month:'short',day:'2-digit',year:'numeric'}) : '';

        reviewHtml = `
        <hr class="view-divider">
        <div class="reviewed-box ${boxClass}">
            <i class="fa ${icon}"></i>
            <div class="reviewed-box__text">
                <strong>This request was ${verb} by ${escHtml(approver)}${date ? ' on ' + date : ''}.</strong>
                ${r.remarks ? `<span>${escHtml(r.remarks)}</span>` : ''}
            </div>
        </div>`;
    }

    return `
        <div class="view-grid">
            <div class="view-field">
                <label>Employee</label>
                <span>${escHtml(r.employee_name)}</span>
            </div>
            <div class="view-field">
                <label>Leave Type</label>
                <span>${escHtml(r.leave_name)}</span>
            </div>
            <div class="view-field">
                <label>Applied On</label>
                <span>${escHtml(r.applied_date)}</span>
            </div>
            <div class="view-field">
                <label>Total Days</label>
                <span>${r.total_days}</span>
            </div>
        </div>

        <div class="view-section-label">Reason</div>
        <div class="view-reason-box">${escHtml(r.reason)}</div>

        <div class="view-section-label">Date Breakdown</div>
        <table class="view-breakdown-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th style="width:150px;">Status</th>
                    <th style="width:200px;text-align:right;">Action</th>
                </tr>
            </thead>
            <tbody>${dateRows}</tbody>
        </table>

        ${reviewHtml}
    `;
}

function closeViewLeaveModal() {
    document.getElementById('viewLeaveOverlay').style.display = 'none';
}
function closeViewModal(e) {
    if (e.target === document.getElementById('viewLeaveOverlay')) closeViewLeaveModal();
}

/* ────────────────────────────────────────────────────────────
   QUICK ACTIONS (Approve All / Reject All from main row)
──────────────────────────────────────────────────────────── */
function quickAction(leaveId, type) {
    if (type === 'approve') {
        openApproveAll(leaveId);
    } else {
        openDenyAll(leaveId);
    }
}

function openApproveAll(leaveId) {
    pendingSubmit = { leaveId, action: 'approve', notes: '' };
    showConfirm(
        'Approve Leave Request',
        'Are you sure you want to approve this leave request? All pending dates will be approved.',
        'Yes, Approve',
        'teal',
        doLeaveAction
    );
}

function openDenyAll(leaveId) {
    pendingSubmit = { leaveId, action: 'reject' };
    closeViewLeaveModal();

    // Reset deny modal
    document.getElementById('denyReasonText').value = '';
    document.getElementById('denyReasonError').style.display = 'none';
    document.getElementById('denyReasonOverlay').style.display = 'flex';
}

/* ────────────────────────────────────────────────────────────
   QUICK DATE-LEVEL ACTION
──────────────────────────────────────────────────────────── */
function quickDateAction(dateId, type) {
    pendingSubmit = { dateId, action: type, isDateLevel: true };
    const verb = type === 'approve' ? 'approve' : 'reject';
    showConfirm(
        `${verb.charAt(0).toUpperCase() + verb.slice(1)} Date`,
        `Are you sure you want to ${verb} this specific date?`,
        `Yes, ${verb.charAt(0).toUpperCase() + verb.slice(1)}`,
        type === 'approve' ? 'teal' : 'danger',
        doLeaveAction
    );
}

/* ────────────────────────────────────────────────────────────
   DENY MODAL
──────────────────────────────────────────────────────────── */
function closeDenyModal(e) {
    if (e && e.target !== document.getElementById('denyReasonOverlay')) return;
    document.getElementById('denyReasonOverlay').style.display = 'none';
}

function submitDeny() {
    const reason = document.getElementById('denyReasonText').value.trim();
    if (!reason) {
        document.getElementById('denyReasonError').style.display = 'block';
        return;
    }
    document.getElementById('denyReasonError').style.display = 'none';
    document.getElementById('denyReasonOverlay').style.display = 'none';
    pendingSubmit.notes = reason;
    doLeaveAction();
}

/* ────────────────────────────────────────────────────────────
   EXECUTE LEAVE ACTION
──────────────────────────────────────────────────────────── */
async function doLeaveAction() {
    closeConfirmModal();

    const fd = new FormData();
    fd.append('action', pendingSubmit.action);
    fd.append('notes', pendingSubmit.notes || '');

    if (pendingSubmit.isDateLevel) {
        fd.append('date_id', pendingSubmit.dateId);
        fd.append('level', 'date');
    } else {
        fd.append('leave_id', pendingSubmit.leaveId);
        fd.append('level', 'all');
    }

    try {
        const res = await fetch(`${BASE_URL}actions/leave-action.php`, {
            method: 'POST',
            body: fd
        });
        const data = await res.json();

        if (data.success) {
            const verb = pendingSubmit.action === 'approve' ? 'approved' : 'rejected';
            // Clear expand cache so the row reloads with fresh statuses
            Object.keys(loadedExpands).forEach(k => delete loadedExpands[k]);
            showFlash('success', `Leave request ${verb} successfully!`);
            setTimeout(() => location.reload(), 1000);
        } else {
            showFlash('error', data.message || 'Action failed. Please try again.');
        }
    } catch {
        showFlash('error', 'Network error. Please try again.');
    }
}

/* ────────────────────────────────────────────────────────────
   CONFIRM MODAL
──────────────────────────────────────────────────────────── */
let confirmCallback = null;

function showConfirm(title, desc, btnLabel, btnType, callback) {
    document.getElementById('confirmTitle').textContent = title;
    document.getElementById('confirmDesc').textContent  = desc;

    const btn = document.getElementById('confirmBtn');
    btn.textContent = btnLabel;
    btn.className = `btn btn--${btnType}`;

    const icon = document.getElementById('confirmIcon');
    icon.className = btnType === 'teal'
        ? 'fa fa-circle-check'
        : 'fa fa-circle-xmark';
    icon.parentElement.style.background = btnType === 'teal' ? '#f0fdfa' : '#fef2f2';
    icon.style.color = btnType === 'teal' ? '#0d9488' : '#dc2626';

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

/* ────────────────────────────────────────────────────────────
   FILTER
──────────────────────────────────────────────────────────── */
function applyFilter(value) {
    const url = new URL(window.location.href);
    url.searchParams.set('status', value);
    window.location.href = url.toString();
}

/* ────────────────────────────────────────────────────────────
   FLASH MESSAGES
──────────────────────────────────────────────────────────── */
function showFlash(type, message) {
    const existing = document.querySelector('.alert-flash');
    if (existing) existing.remove();

    const el = document.createElement('div');
    el.className = `alert-flash alert-flash--${type}`;
    el.innerHTML = `${escHtml(message)} <button class="alert-flash__close" onclick="this.parentElement.remove()">×</button>`;
    document.querySelector('.main-content').prepend(el);

    setTimeout(() => { if (el.parentElement) el.remove(); }, 5000);
}

/* ────────────────────────────────────────────────────────────
   HELPERS
──────────────────────────────────────────────────────────── */
function escHtml(str) {
    if (!str) return '';
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

/* ────────────────────────────────────────────────────────────
   INIT
──────────────────────────────────────────────────────────── */
document.addEventListener('DOMContentLoaded', () => {
    renderCalendar();
});