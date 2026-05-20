// ── Daily rate state ──────────────────────────────────────────────────────
let _scDailyRate = 0;

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
    if (action === 'save_draft') {
        // For draft we allow submitting the form without all required fields
        // but employee, date, days are still required
    }
}

function resetCreateModal() {
    document.getElementById('scCreateForm').reset();
    document.getElementById('scFormId').value       = '';
    document.getElementById('scWorkDate').value     = new Date().toISOString().split('T')[0];
    document.getElementById('scDays').value         = '1.0';
    document.getElementById('scEquivPay').value     = '0.00';
    document.getElementById('scCharCount').textContent = '0 / 255';
    document.getElementById('scRateHint').style.display  = 'none';
    document.getElementById('scAutoLabel').style.display = 'none';
    _scDailyRate = 0;
}

// ── Employee change → fetch daily rate ────────────────────────────────────
function onScEmployeeChange(empId) {
    const sel      = document.getElementById('scEmployee');
    const opt      = sel?.options[sel.selectedIndex];
    const rate     = parseFloat(opt?.dataset?.rate || 0);
    const hint     = document.getElementById('scRateHint');
    const rateDisp = document.getElementById('scRateDisplay');

    _scDailyRate = rate;

    if (rate > 0) {
        rateDisp.textContent = '₱' + rate.toLocaleString('en-PH',
            { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        hint.style.display = 'block';
        computeScPay();
    } else {
        hint.style.display = 'none';
    }
}

// ── Auto-compute equivalent pay ───────────────────────────────────────────
function computeScPay() {
    const days = parseFloat(document.getElementById('scDays')?.value || 0);
    const el   = document.getElementById('scEquivPay');
    const lbl  = document.getElementById('scAutoLabel');
    if (!el) return;
    if (_scDailyRate > 0 && days > 0) {
        el.value = (Math.round(_scDailyRate * days * 100) / 100).toFixed(2);
        if (lbl) lbl.style.display = 'inline-flex';
    }
}

// ── Char counter ──────────────────────────────────────────────────────────
document.getElementById('scRemarks')?.addEventListener('input', function() {
    document.getElementById('scCharCount').textContent = this.value.length + ' / 255';
});

// ── Edit modal ────────────────────────────────────────────────────────────
function openEditModal(r) {
    resetCreateModal();
    document.getElementById('createModalTitle').innerHTML =
        '<i class="fa fa-pen"></i> Edit Service Credit';
    document.getElementById('scFormAction').value = 'edit';
    document.getElementById('scFormId').value     = r.service_credit_id;
    document.getElementById('scEmployee').value   = r.employee_id;
    document.getElementById('scWorkDate').value   = r.work_date;
    document.getElementById('scDays').value       = r.days;
    document.getElementById('scEquivPay').value   = parseFloat(r.equivalent_pay||0).toFixed(2);
    document.getElementById('scRemarks').value    = r.remarks || '';
    document.getElementById('scCharCount').textContent = (r.remarks||'').length + ' / 255';

    // Trigger employee change to show rate hint
    onScEmployeeChange(r.employee_id);

    // For edit mode, hide "Save Draft" button
    document.getElementById('btnSaveDraft').style.display = 'none';
    document.getElementById('btnSubmitApproval').innerHTML =
        '<i class="fa fa-floppy-disk"></i> Update';

    openModal('createModal');
}

// ── Reject modal ──────────────────────────────────────────────────────────
function openRejectModal(id, name) {
    document.getElementById('rejectScId').value       = id;
    document.getElementById('rejectEmpName').textContent = name;
    document.getElementById('rejectReason').value     = '';
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

    let rejHtml = '';
    if (r.status === 'REJECTED' && r.rejection_reason) {
        rejHtml = `<div class="sc-view-rejection">
            <i class="fa fa-circle-xmark"></i>
            <div><strong>Rejection Reason</strong><p>${escH(r.rejection_reason)}</p></div>
        </div>`;
    }

    let payrollHtml = r.payroll_id
        ? `<span class="sc-badge sc-badge--applied">Payroll #${r.payroll_id}</span>`
        : '<span style="color:#94a3b8;">Not yet applied to payroll</span>';

    document.getElementById('viewModalBody').innerHTML = `
        <div class="sc-view-header">
            <div class="sc-view-emp">${escH(r.employee_name || '')}</div>
            <span class="sc-badge" style="background:${statusColor}20;color:${statusColor};">${statusLabel}</span>
        </div>
        <div class="sc-view-grid">
            <div class="sc-view-item">
                <small>Work Date</small>
                <strong>${formatDate(r.work_date)}</strong>
            </div>
            <div class="sc-view-item">
                <small>Days</small>
                <strong>${parseFloat(r.days||0).toFixed(1)}</strong>
            </div>
            <div class="sc-view-item">
                <small>Equivalent Pay</small>
                <strong style="color:#0f766e;">₱${parseFloat(r.equivalent_pay||0).toLocaleString('en-PH',{minimumFractionDigits:2})}</strong>
            </div>
            <div class="sc-view-item">
                <small>Payroll Status</small>
                <div>${payrollHtml}</div>
            </div>
        </div>
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

// ── Helpers ───────────────────────────────────────────────────────────────
function escH(s) {
    return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
}
function formatDate(d) {
    if (!d) return '—';
    const dt = new Date(d + (d.length===10?'T00:00:00':''));
    return dt.toLocaleDateString('en-PH',{month:'short',day:'numeric',year:'numeric'});
}