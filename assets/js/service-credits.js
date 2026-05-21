// ── State ─────────────────────────────────────────────────────────────────
let _scDailyRate = 0;
const _scToday   = new Date().toISOString().split('T')[0];

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
}

function resetCreateModal() {
    document.getElementById('scCreateForm').reset();
    document.getElementById('scFormId').value            = '';
    document.getElementById('scCharCount').textContent   = '0 / 255';
    document.getElementById('scRateHint').style.display  = 'none';
    document.getElementById('scDateTotals').style.display = 'none';
    _scDailyRate = 0;
    renderScDateRows([]);
    document.getElementById('btnSaveDraft').style.display    = '';
    document.getElementById('btnSubmitApproval').innerHTML   =
        '<i class="fa fa-paper-plane"></i> Submit for Approval';
}

// ── Multi-date row management ─────────────────────────────────────────────
function makeScDateRow(date, days, pay) {
    const d   = String(date || _scToday);
    const dys = parseFloat(days || 1).toFixed(1);
    const p   = parseFloat(pay  || 0).toFixed(2);

    const div = document.createElement('div');
    div.className = 'sc-date-row';
    div.innerHTML = `
        <div class="sc-date-row-fields">
            <div class="sc-date-row-col">
                <span class="sc-small-label">Date</span>
                <input type="date" name="work_dates[]" value="${escAttr(d)}"
                       max="${_scToday}" required>
            </div>
            <div class="sc-date-row-col">
                <span class="sc-small-label">Days</span>
                <input type="number" name="days_per_date[]" value="${dys}"
                       step="0.5" min="0.5" max="30" required>
            </div>
            <div class="sc-date-row-col">
                <span class="sc-small-label">Equiv. Pay (₱)</span>
                <input type="number" name="pay_per_date[]" value="${p}"
                       step="0.01" min="0" required>
            </div>
            <button type="button" class="sc-date-row-remove" title="Remove row">
                <i class="fa fa-times"></i>
            </button>
        </div>`;

    div.querySelector('.sc-date-row-remove').addEventListener('click', () => {
        const container = document.getElementById('scDateRows');
        if (container.children.length > 1) { div.remove(); updateScDateTotals(); }
    });

    div.querySelector('[name="days_per_date[]"]').addEventListener('input', function () {
        if (_scDailyRate > 0) {
            const payInput = div.querySelector('[name="pay_per_date[]"]');
            const d = parseFloat(this.value) || 0;
            payInput.value = (Math.round(_scDailyRate * d * 100) / 100).toFixed(2);
        }
        updateScDateTotals();
    });

    div.querySelector('[name="pay_per_date[]"]').addEventListener('input', updateScDateTotals);

    return div;
}

function renderScDateRows(dateArr) {
    const container = document.getElementById('scDateRows');
    if (!container) return;
    container.innerHTML = '';
    if (dateArr && dateArr.length > 0) {
        dateArr.forEach(d => container.appendChild(
            makeScDateRow(d.work_date, d.days, d.equivalent_pay)
        ));
    } else {
        container.appendChild(makeScDateRow(_scToday, '1.0', '0.00'));
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
    const container = document.getElementById('scDateRows');
    if (!container) return;
    const rows = container.querySelectorAll('.sc-date-row');
    let totalDays = 0, totalPay = 0;
    rows.forEach(row => {
        totalDays += parseFloat(row.querySelector('[name="days_per_date[]"]').value) || 0;
        totalPay  += parseFloat(row.querySelector('[name="pay_per_date[]"]').value)  || 0;
    });
    const totalsEl = document.getElementById('scDateTotals');
    if (totalsEl) {
        totalsEl.style.display = rows.length > 1 ? 'block' : 'none';
        const tdEl = document.getElementById('scTotalDays');
        const tpEl = document.getElementById('scTotalPay');
        if (tdEl) tdEl.textContent = totalDays.toFixed(1);
        if (tpEl) tpEl.textContent = totalPay.toLocaleString('en-PH',
            { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    }
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

    onScEmployeeChange(r.employee_id);

    // Populate date rows from child dates array, fall back to legacy columns
    const dates = (r.dates && r.dates.length > 0) ? r.dates
        : [{ work_date: r.work_date, days: r.days, equivalent_pay: r.equivalent_pay }];
    renderScDateRows(dates);

    document.getElementById('btnSaveDraft').style.display  = 'none';
    document.getElementById('btnSubmitApproval').innerHTML =
        '<i class="fa fa-floppy-disk"></i> Update';

    openModal('createModal');
}

// ── Reject modal ──────────────────────────────────────────────────────────
function openRejectModal(id, name) {
    document.getElementById('rejectScId').value          = id;
    document.getElementById('rejectEmpName').textContent = name;
    document.getElementById('rejectReason').value        = '';
    openModal('rejectModal');
}

// ── Delete modal ──────────────────────────────────────────────────────────
function openDeleteModal(id, name) {
    document.getElementById('deleteScId').value = id;
    document.getElementById('deleteDesc').textContent =
        'Delete the draft service credit for ' + name + '?';
    openModal('deleteModal');
}

// ── View modal ────────────────────────────────────────────────────────────
function openViewModal(r) {
    const statusLabels = {
        DRAFT:'Draft', PENDING:'Pending', APPROVED:'Approved',
        APPLIED:'In Payroll', RELEASED:'Released', REJECTED:'Rejected'
    };
    const statusColors = {
        DRAFT:'#9ca3af', PENDING:'#d97706', APPROVED:'#059669',
        APPLIED:'#2563eb', RELEASED:'#7c3aed', REJECTED:'#ef4444'
    };
    const statusLabel = statusLabels[r.status] || r.status;
    const statusColor = statusColors[r.status] || '#9ca3af';

    // Build dates list (child rows, or fall back to legacy single-date columns)
    const dates = (r.dates && r.dates.length > 0) ? r.dates
        : (r.work_date ? [{ work_date: r.work_date, days: r.days || 0, equivalent_pay: r.equivalent_pay || 0 }] : []);

    let datesHtml = '';
    if (dates.length > 1) {
        datesHtml = `
        <div style="margin:0 0 14px;">
            <small style="display:block;font-size:10px;text-transform:uppercase;color:#94a3b8;letter-spacing:.5px;margin-bottom:6px;">Work Dates</small>
            <table style="width:100%;border-collapse:collapse;font-size:13px;">
                <thead>
                    <tr style="border-bottom:1px solid #e2e8f0;">
                        <th style="text-align:left;padding:4px 8px;font-size:11px;color:#64748b;font-weight:600;">Date</th>
                        <th style="text-align:right;padding:4px 8px;font-size:11px;color:#64748b;font-weight:600;">Days</th>
                        <th style="text-align:right;padding:4px 8px;font-size:11px;color:#64748b;font-weight:600;">Pay</th>
                    </tr>
                </thead>
                <tbody>
                    ${dates.map(d => `
                    <tr>
                        <td style="padding:5px 8px;">${formatDate(d.work_date)}</td>
                        <td style="padding:5px 8px;text-align:right;">${parseFloat(d.days).toFixed(1)}</td>
                        <td style="padding:5px 8px;text-align:right;font-weight:600;color:#0f766e;">₱${parseFloat(d.equivalent_pay).toLocaleString('en-PH',{minimumFractionDigits:2})}</td>
                    </tr>`).join('')}
                </tbody>
            </table>
        </div>`;
    }

    const dateLabel = dates.length > 1
        ? formatDate(dates[0].work_date) + ' – ' + formatDate(dates[dates.length - 1].work_date)
        : formatDate(dates[0]?.work_date || r.work_date);

    let rejHtml = '';
    if (r.status === 'REJECTED' && r.rejection_reason) {
        rejHtml = `<div class="sc-view-rejection">
            <i class="fa fa-circle-xmark"></i>
            <div><strong>Rejection Reason</strong><p>${escH(r.rejection_reason)}</p></div>
        </div>`;
    }

    const payrollHtml = r.payroll_id
        ? `<a href="${(typeof BASE_URL!=='undefined'?BASE_URL:'')}modules/payroll/index.php"
              style="font-size:12px;font-weight:600;color:#2563eb;text-decoration:none;">
              Payroll #${r.payroll_id} <i class="fa fa-arrow-up-right-from-square" style="font-size:10px;"></i>
           </a>`
        : '<span style="color:#94a3b8;">Not yet applied to payroll</span>';

    document.getElementById('viewModalBody').innerHTML = `
        <div class="sc-view-header">
            <div class="sc-view-emp">${escH(r.employee_name || '')}</div>
            <span class="sc-badge" style="background:${statusColor}20;color:${statusColor};">${statusLabel}</span>
        </div>
        <div class="sc-view-grid">
            <div class="sc-view-item">
                <small>Work Date${dates.length > 1 ? 's' : ''}</small>
                <strong>${dateLabel}</strong>
            </div>
            <div class="sc-view-item">
                <small>Total Days</small>
                <strong>${parseFloat(r.days || 0).toFixed(1)} day${r.days != 1 ? 's' : ''}</strong>
            </div>
            <div class="sc-view-item">
                <small>Equivalent Pay</small>
                <strong style="color:#0f766e;">₱${parseFloat(r.equivalent_pay || 0).toLocaleString('en-PH',{minimumFractionDigits:2})}</strong>
            </div>
            <div class="sc-view-item">
                <small>Payroll Status</small>
                <div>${payrollHtml}</div>
            </div>
        </div>
        ${datesHtml}
        ${r.remarks ? `<div class="sc-view-remarks"><strong>Description:</strong> ${escH(r.remarks)}</div>` : ''}
        ${rejHtml}
        <div class="sc-view-meta">
            ${r.approved_by_name ? `<span><i class="fa fa-user-check"></i> Approved by ${escH(r.approved_by_name)}</span>` : ''}
            ${r.approved_at ? `<span><i class="fa fa-clock"></i> ${formatDate(r.approved_at)}</span>` : ''}
        </div>`;

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
