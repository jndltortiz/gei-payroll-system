// ── State ─────────────────────────────────────────────────────────────────
let _scDailyRate = 0;
const _scToday   = new Date().toISOString().split('T')[0];

// ── Shared badge helpers ──────────────────────────────────────────────────
const SC_STATUS_LABELS = {
    DRAFT:'Draft', PENDING:'Pending', APPROVED:'Approved',
    PARTIALLY_APPROVED:'Partially Approved',
    APPLIED:'In Payroll', RELEASED:'Released', REJECTED:'Rejected', ARCHIVED:'Archived'
};
const SC_STATUS_COLORS = {
    DRAFT:'#9ca3af', PENDING:'#d97706', APPROVED:'#059669',
    PARTIALLY_APPROVED:'#0891b2',
    APPLIED:'#2563eb', RELEASED:'#7c3aed', REJECTED:'#ef4444', ARCHIVED:'#64748b'
};
const DATE_STATUS_BADGE = {
    PENDING:  { cls:'sc-badge--pending',  lbl:'Pending'  },
    APPROVED: { cls:'sc-badge--approved', lbl:'Approved' },
    REJECTED: { cls:'sc-badge--rejected', lbl:'Rejected' },
};

// ── Modal helpers ─────────────────────────────────────────────────────────
function openModal(id)  { document.getElementById(id).style.display = 'flex'; }
function closeModal(id) { document.getElementById(id).style.display = 'none'; }

document.querySelectorAll('.sc-modal-overlay').forEach(el => {
    el.addEventListener('click', e => { if (e.target === el) el.style.display = 'none'; });
});

// ── Create button ─────────────────────────────────────────────────────────
document.getElementById('btnCreate')?.addEventListener('click', () => {
    resetCreateModal();
    document.getElementById('createModalTitle').innerHTML =
        '<i class="fa fa-plus-circle"></i> Create Service Credit';
    document.getElementById('scFormAction').value = 'submit';
    document.getElementById('scFormId').value     = '';
    openModal('createModal');
});

function setScAction(action) {
    document.getElementById('scFormAction').value = action;
    document.getElementById('scCreateForm').submit();
}

function resetCreateModal() {
    document.getElementById('scCreateForm').reset();
    document.getElementById('scFormId').value            = '';
    document.getElementById('scCharCount').textContent   = '0 / 255';
    document.getElementById('scRateHint').style.display  = 'none';
    const tp = document.getElementById('scTargetPeriod');
    if (tp) tp.value = '';
    _scDailyRate = 0;
    renderScDateRows([]);
    document.getElementById('btnSaveDraft').style.display    = '';
    document.getElementById('btnSubmitApproval').innerHTML   =
        '<i class="fa fa-paper-plane"></i> Submit for Approval';
    document.getElementById('btnSubmitApproval').onclick = () => setScAction('submit');
}

// ── Multi-date table row management ───────────────────────────────────────
function makeScDateRow(date, days, pay) {
    const d   = String(date || _scToday);
    const dys = parseFloat(days || 1).toFixed(1);
    const p   = parseFloat(pay  || 0).toFixed(2);

    const tr = document.createElement('tr');
    tr.className = 'sc-date-row';
    tr.innerHTML = `
        <td>
            <input type="date" name="work_dates[]" value="${escAttr(d)}"
                   max="${_scToday}" required class="sc-date-input">
        </td>
        <td>
            <input type="number" name="days_per_date[]" value="${dys}"
                   step="0.5" min="0.5" max="30" required class="sc-date-input sc-date-input--sm">
        </td>
        <td>
            <input type="number" name="pay_per_date[]" value="${p}"
                   step="0.01" min="0" required class="sc-date-input sc-date-input--pay">
        </td>
        <td>
            <button type="button" class="sc-date-row-remove" title="Remove row">
                <i class="fa fa-times"></i>
            </button>
        </td>`;

    tr.querySelector('.sc-date-row-remove').addEventListener('click', () => {
        const tbody = document.getElementById('scDateRows');
        if (tbody.children.length > 1) { tr.remove(); updateScDateTotals(); }
    });

    tr.querySelector('[name="days_per_date[]"]').addEventListener('input', function () {
        if (_scDailyRate > 0) {
            const payInput = tr.querySelector('[name="pay_per_date[]"]');
            const d = parseFloat(this.value) || 0;
            payInput.value = (Math.round(_scDailyRate * d * 100) / 100).toFixed(2);
        }
        updateScDateTotals();
    });

    tr.querySelector('[name="pay_per_date[]"]').addEventListener('input', updateScDateTotals);

    return tr;
}

function renderScDateRows(dateArr) {
    const tbody = document.getElementById('scDateRows');
    if (!tbody) return;
    tbody.innerHTML = '';
    if (dateArr && dateArr.length > 0) {
        dateArr.forEach(d => tbody.appendChild(
            makeScDateRow(d.work_date, d.days, d.equivalent_pay)
        ));
    } else {
        tbody.appendChild(makeScDateRow(_scToday, '1.0', '0.00'));
    }
    updateScDateTotals();
}

function addScDateRow() {
    document.getElementById('scDateRows').appendChild(
        makeScDateRow(_scToday, '1.0', '0.00')
    );
    updateScDateTotals();
}

function updateScDateTotals() {
    const tbody = document.getElementById('scDateRows');
    if (!tbody) return;
    const rows = tbody.querySelectorAll('.sc-date-row');
    let totalDays = 0, totalPay = 0;
    rows.forEach(row => {
        totalDays += parseFloat(row.querySelector('[name="days_per_date[]"]').value) || 0;
        totalPay  += parseFloat(row.querySelector('[name="pay_per_date[]"]').value)  || 0;
    });
    const tdEl = document.getElementById('scTotalDays');
    const tpEl = document.getElementById('scTotalPay');
    if (tdEl) tdEl.textContent = totalDays.toFixed(1);
    if (tpEl) tpEl.textContent = '₱' + totalPay.toLocaleString('en-PH',
        { minimumFractionDigits: 2, maximumFractionDigits: 2 });
}

// ── Employee change → update rate + recompute all rows ───────────────────
function onScEmployeeChange(empId) {
    const sel  = document.getElementById('scEmployee');
    const opt  = sel?.options[sel.selectedIndex];
    const rate = parseFloat(opt?.dataset?.rate || 0);
    const hint = document.getElementById('scRateHint');

    _scDailyRate = rate;

    if (rate > 0) {
        const rd = document.getElementById('scRateDisplay');
        if (rd) rd.textContent = '₱' + rate.toLocaleString('en-PH',
            { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        if (hint) hint.style.display = 'block';
        document.querySelectorAll('#scDateRows [name="days_per_date[]"]').forEach(inp => {
            const payInp = inp.closest('.sc-date-row').querySelector('[name="pay_per_date[]"]');
            const d = parseFloat(inp.value) || 0;
            if (d > 0) payInp.value = (Math.round(rate * d * 100) / 100).toFixed(2);
        });
        updateScDateTotals();
    } else {
        if (hint) hint.style.display = 'none';
    }
}

// ── Char counter ──────────────────────────────────────────────────────────
document.getElementById('scRemarks')?.addEventListener('input', function() {
    const cc = document.getElementById('scCharCount');
    if (cc) cc.textContent = this.value.length + ' / 255';
});

// ── Edit modal ────────────────────────────────────────────────────────────
function openEditModal(r) {
    resetCreateModal();
    document.getElementById('createModalTitle').innerHTML =
        '<i class="fa fa-pen"></i> Edit Service Credit';
    document.getElementById('scFormAction').value = 'edit';
    document.getElementById('scFormId').value     = r.service_credit_id;
    document.getElementById('scEmployee').value   = r.employee_id;
    const rem = document.getElementById('scRemarks');
    if (rem) { rem.value = r.remarks || ''; }
    const cc = document.getElementById('scCharCount');
    if (cc) cc.textContent = (r.remarks || '').length + ' / 255';

    // Target period
    const tp = document.getElementById('scTargetPeriod');
    if (tp) tp.value = r.target_period_id || '';

    onScEmployeeChange(r.employee_id);

    const dates = (r.dates && r.dates.length > 0) ? r.dates
        : [{ work_date: r.work_date, days: r.days, equivalent_pay: r.equivalent_pay }];
    renderScDateRows(dates);

    document.getElementById('btnSaveDraft').style.display  = 'none';
    document.getElementById('btnSubmitApproval').innerHTML =
        '<i class="fa fa-floppy-disk"></i> Update';
    document.getElementById('btnSubmitApproval').onclick = () => setScAction('edit');

    openModal('createModal');
}

// ── Reject modal ──────────────────────────────────────────────────────────
function openRejectModal(id, name) {
    document.getElementById('rejectScId').value          = id;
    document.getElementById('rejectEmpName').textContent = name;
    document.getElementById('rejectReason').value        = '';
    openModal('rejectModal');
}

// ── View modal (admin — read-only per-date breakdown) ─────────────────────
function openViewModal(r) {
    const statusLabel = SC_STATUS_LABELS[r.status] || r.status;
    const statusColor = SC_STATUS_COLORS[r.status] || '#9ca3af';

    const dates = (r.dates && r.dates.length > 0) ? r.dates
        : (r.work_date ? [{ work_date: r.work_date, days: r.days || 0, equivalent_pay: r.equivalent_pay || 0, status: 'PENDING', rejection_reason: null }] : []);

    // ── Work dates table with per-date status badges ──────────────────────
    let datesHtml = '';
    if (dates.length > 0) {
        const hasMixedStatus = dates.some(d => d.status && d.status !== 'PENDING');
        datesHtml = `
        <div style="margin:0 0 14px;">
            <div style="font-size:10px;text-transform:uppercase;color:#94a3b8;letter-spacing:.5px;margin-bottom:6px;font-weight:700;">Work Dates</div>
            <table style="width:100%;border-collapse:collapse;font-size:13px;border:1px solid #e5e7eb;border-radius:8px;overflow:hidden;">
                <thead>
                    <tr style="background:#f8fafc;border-bottom:1px solid #e5e7eb;">
                        <th style="padding:7px 10px;text-align:left;font-size:11px;color:#64748b;font-weight:600;">Date</th>
                        <th style="padding:7px 10px;text-align:center;font-size:11px;color:#64748b;font-weight:600;">Day Equiv.</th>
                        <th style="padding:7px 10px;text-align:right;font-size:11px;color:#64748b;font-weight:600;">Amount</th>
                        ${hasMixedStatus ? '<th style="padding:7px 10px;text-align:center;font-size:11px;color:#64748b;font-weight:600;">Status</th>' : ''}
                    </tr>
                </thead>
                <tbody>
                    ${dates.map(d => {
                        const ds = d.status || 'PENDING';
                        const db = DATE_STATUS_BADGE[ds] || DATE_STATUS_BADGE.PENDING;
                        const rejNote = ds === 'REJECTED' && d.rejection_reason
                            ? `<br><small style="color:#ef4444;font-style:italic;">${escH(d.rejection_reason)}</small>` : '';
                        return `<tr style="border-bottom:1px solid #f8fafc;">
                            <td style="padding:7px 10px;">${formatDate(d.work_date)}${rejNote}</td>
                            <td style="padding:7px 10px;text-align:center;">${parseFloat(d.days).toFixed(1)}d</td>
                            <td style="padding:7px 10px;text-align:right;font-weight:600;color:${ds==='REJECTED'?'#d1d5db':'#0f766e'};">₱${parseFloat(d.equivalent_pay).toLocaleString('en-PH',{minimumFractionDigits:2})}</td>
                            ${hasMixedStatus ? `<td style="padding:7px 10px;text-align:center;"><span class="sc-badge ${db.cls}" style="font-size:10px;">${db.lbl}</span></td>` : ''}
                        </tr>`;
                    }).join('')}
                </tbody>
                <tfoot style="border-top:2px solid #e5e7eb;">
                    <tr style="background:#f0fdf9;">
                        <td style="padding:7px 10px;font-weight:700;">Total</td>
                        <td style="padding:7px 10px;text-align:center;font-weight:700;">${parseFloat(r.days||0).toFixed(1)}d</td>
                        <td style="padding:7px 10px;text-align:right;font-weight:700;color:#0f766e;">₱${parseFloat(r.equivalent_pay||0).toLocaleString('en-PH',{minimumFractionDigits:2})}</td>
                        ${hasMixedStatus ? '<td></td>' : ''}
                    </tr>
                </tfoot>
            </table>
        </div>`;
    }

    // ── Rejection reason (parent-level) ───────────────────────────────────
    let rejHtml = '';
    if (r.status === 'REJECTED' && r.rejection_reason) {
        rejHtml = `<div class="sc-view-rejection">
            <i class="fa fa-circle-xmark"></i>
            <div><strong>Rejection Reason</strong><p style="margin:3px 0 0;">${escH(r.rejection_reason)}</p></div>
        </div>`;
    }

    // ── Target period ─────────────────────────────────────────────────────
    let targetPeriodHtml = '';
    if (r.target_period_id) {
        const pLabel = r.target_period_name
            || (r.target_period_start ? formatDate(r.target_period_start) + ' – ' + formatDate(r.target_period_end) : '—');
        const pPay = r.target_pay_date ? ' · Pay date: ' + formatDate(r.target_pay_date) : '';
        targetPeriodHtml = `
            <div class="sc-view-meta-item">
                <div class="sc-view-meta-label">Target Payroll Period</div>
                <div class="sc-view-meta-val" style="color:#2563eb;">${escH(pLabel)}${escH(pPay)}</div>
            </div>`;
    }

    // ── Payroll reference ─────────────────────────────────────────────────
    const payrollHtml = r.payroll_id
        ? `<span style="font-weight:700;color:#2563eb;"><i class="fa fa-file-invoice-dollar" style="margin-right:4px;"></i>Payroll #${r.payroll_id}</span>`
        : '<span style="color:#94a3b8;font-size:12px;">Not yet applied</span>';

    // ── Activity history ──────────────────────────────────────────────────
    const aLabels = { CREATE:'Created', SUBMIT:'Submitted', RESUBMIT:'Resubmitted', UPDATE:'Edited',
                      APPROVE:'Approved', APPROVE_DATE:'Date Approved', REJECT:'Rejected',
                      REJECT_DATE:'Date Rejected', ARCHIVE:'Archived', RESTORE:'Restored', DELETE:'Deleted' };
    const aIcons  = { CREATE:'fa-plus-circle', SUBMIT:'fa-paper-plane', RESUBMIT:'fa-rotate-right',
                      UPDATE:'fa-pen', APPROVE:'fa-circle-check', APPROVE_DATE:'fa-check',
                      REJECT:'fa-circle-xmark', REJECT_DATE:'fa-xmark',
                      ARCHIVE:'fa-box-archive', RESTORE:'fa-rotate-left', DELETE:'fa-trash' };
    const aColors = { CREATE:'#64748b', SUBMIT:'#d97706', RESUBMIT:'#d97706', UPDATE:'#2563eb',
                      APPROVE:'#059669', APPROVE_DATE:'#0891b2', REJECT:'#ef4444',
                      REJECT_DATE:'#f97316', ARCHIVE:'#64748b', RESTORE:'#0d9488', DELETE:'#ef4444' };

    let historyHtml = '';
    if (r.audit && r.audit.length > 0) {
        historyHtml = `
        <div class="sc-view-history">
            <div style="font-size:10px;text-transform:uppercase;color:#94a3b8;letter-spacing:.5px;margin-bottom:10px;font-weight:700;">Activity History</div>
            <div class="sc-history-list">
                ${r.audit.map(a => {
                    const lbl  = aLabels[a.action] || a.action;
                    const icon = aIcons[a.action]  || 'fa-circle';
                    const col  = aColors[a.action] || '#64748b';
                    return `<div class="sc-history-item">
                        <div class="sc-history-dot" style="background:${col};"><i class="fa ${icon}"></i></div>
                        <div class="sc-history-body">
                            <div class="sc-history-action" style="color:${col};">${escH(lbl)}</div>
                            <div class="sc-history-who">${escH(a.actor_name || 'System')}</div>
                            <div class="sc-history-when">${formatDate(a.created_at)}</div>
                        </div>
                    </div>`;
                }).join('')}
            </div>
        </div>`;
    }

    document.getElementById('viewModalBody').innerHTML = `
        <div class="sc-view-header">
            <div>
                <div class="sc-view-emp">${escH(r.employee_name || '')}</div>
                <div style="font-size:12px;color:#64748b;margin-top:2px;">${escH(r.position_name || '')}</div>
            </div>
            <span class="sc-badge" style="background:${statusColor}20;color:${statusColor};">${escH(statusLabel)}</span>
        </div>

        ${r.remarks ? `<div class="sc-view-remarks"><strong>Description:</strong> ${escH(r.remarks)}</div>` : ''}

        ${datesHtml}
        ${rejHtml}

        <div class="sc-view-meta-grid">
            <div class="sc-view-meta-item">
                <div class="sc-view-meta-label">Created by</div>
                <div class="sc-view-meta-val">${escH(r.created_by_name || '—')}</div>
            </div>
            <div class="sc-view-meta-item">
                <div class="sc-view-meta-label">Submitted on</div>
                <div class="sc-view-meta-val">${formatDate(r.created_at)}</div>
            </div>
            ${r.approved_by_name ? `
            <div class="sc-view-meta-item">
                <div class="sc-view-meta-label">${r.status === 'REJECTED' ? 'Rejected by' : 'Approved by'}</div>
                <div class="sc-view-meta-val">${escH(r.approved_by_name)}</div>
            </div>
            <div class="sc-view-meta-item">
                <div class="sc-view-meta-label">${r.status === 'REJECTED' ? 'Rejected on' : 'Approved on'}</div>
                <div class="sc-view-meta-val">${r.approved_at ? formatDate(r.approved_at) : '—'}</div>
            </div>` : ''}
            ${targetPeriodHtml}
            <div class="sc-view-meta-item">
                <div class="sc-view-meta-label">Payroll Reference</div>
                <div class="sc-view-meta-val">${payrollHtml}</div>
            </div>
            ${r.archived_at ? `
            <div class="sc-view-meta-item">
                <div class="sc-view-meta-label">Archived on</div>
                <div class="sc-view-meta-val">${formatDate(r.archived_at)}</div>
            </div>` : ''}
        </div>

        ${historyHtml}`;

    openModal('viewModal');
}

// ── Live search debounce ──────────────────────────────────────────────────
let _scSearchTimer;
document.getElementById('scSearch')?.addEventListener('input', function() {
    clearTimeout(_scSearchTimer);
    _scSearchTimer = setTimeout(() => document.getElementById('filterForm').submit(), 500);
});

// ── Init ──────────────────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
    if (document.getElementById('scDateRows')) renderScDateRows([]);
});

// ── Helpers ───────────────────────────────────────────────────────────────
function escH(s) {
    return String(s || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;')
        .replace(/"/g,'&quot;').replace(/'/g,'&#039;');
}
function escAttr(s) {
    return String(s || '').replace(/"/g,'&quot;').replace(/'/g,'&#039;');
}
function formatDate(d) {
    if (!d) return '—';
    const dt = new Date(d + (d.length === 10 ? 'T00:00:00' : ''));
    return dt.toLocaleDateString('en-PH', { month:'short', day:'numeric', year:'numeric' });
}
