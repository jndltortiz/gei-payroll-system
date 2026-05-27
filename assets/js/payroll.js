console.log("PAYROLL JS LOADED");

function peso(num) {
    return "₱" + Number(num || 0).toLocaleString('en-PH', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
}

function escHtml(value) {
    return String(value ?? '').replace(/[&<>"']/g, ch => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#039;'
    }[ch]));
}

function parseJsonData(value, fallback) {
    if (!value) return fallback;
    try {
        const parsed = JSON.parse(value);
        return Array.isArray(parsed) ? parsed : fallback;
    } catch {
        return fallback;
    }
}

// Render a flat list of rows into a container
function renderPayslipRows(containerId, rows, emptyLabel) {
    const container = document.getElementById(containerId);
    if (!container) return;

    if (!rows || !rows.length) {
        container.innerHTML = `<div style="padding:10px 14px;color:#94a3b8;font-size:12px;">${escHtml(emptyLabel || 'None')}</div>`;
        return;
    }

    container.innerHTML = rows.map(row =>
        `<div style="display:flex;justify-content:space-between;padding:9px 14px;font-size:13px;border-bottom:1px solid rgba(0,0,0,0.05);">` +
        `<span style="color:#374151;">${escHtml(row.name)}</span>` +
        `<span style="font-weight:600;color:#0f172a;">${peso(row.amount)}</span></div>`
    ).join('');
}

// Render deduction rows grouped by Government / Loans / Other
function renderGroupedDeductionRows(containerId, rows) {
    const container = document.getElementById(containerId);
    if (!container) return;

    if (!rows.length) {
        container.innerHTML = '<div class="row"><span>No deductions</span><span>' + peso(0) + '</span></div>';
        return;
    }

    const govRows   = rows.filter(r => r.is_gov);
    const loanRows  = rows.filter(r => r.is_loan);
    const otherRows = rows.filter(r => !r.is_gov && !r.is_loan);

    function section(label, items) {
        if (!items.length) return '';
        return '<div class="ded-group-label">' + escHtml(label) + '</div>'
            + items.map(r => '<div class="row"><span>' + escHtml(r.name) + '</span><span>' + peso(r.amount) + '</span></div>').join('');
    }

    container.innerHTML =
        section('Government Contributions', govRows) +
        section('Loans', loanRows) +
        section('Other Deductions', otherRows);
}

// ── computePayroll — sums all visible inputs + saved adjustment amounts ────────
function computePayroll() {
    const basic = Number(document.getElementById("edit-basic").value) || 0;

    let totalAllowances = 0;
    // Standard allowance inputs
    document.querySelectorAll('#edit-allowances-container input[type="number"]').forEach(input => {
        totalAllowances += Number(input.value) || 0;
    });
    // Newly added one-time allowances (not yet saved)
    document.querySelectorAll('#edit-new-allowances input[type="number"]').forEach(input => {
        totalAllowances += Number(input.value) || 0;
    });
    // Already-saved adjustment rows (tracked via data-adj-amount)
    document.querySelectorAll('#edit-allowances-container .adj-saved-row').forEach(row => {
        totalAllowances += Number(row.dataset.adjAmount) || 0;
    });

    let totalDeductions = 0;
    // Standard deduction inputs
    document.querySelectorAll('#edit-deductions-container input[type="number"]').forEach(input => {
        totalDeductions += Number(input.value) || 0;
    });
    // Newly added one-time deductions (not yet saved)
    document.querySelectorAll('#edit-new-deductions input[type="number"]').forEach(input => {
        totalDeductions += Number(input.value) || 0;
    });
    // Already-saved adjustment rows
    document.querySelectorAll('#edit-deductions-container .adj-saved-row').forEach(row => {
        totalDeductions += Number(row.dataset.adjAmount) || 0;
    });

    const gross = basic + totalAllowances;
    const net   = gross - totalDeductions;

    document.getElementById("edit-gross").innerText    = peso(gross);
    document.getElementById("edit-totalded").innerText = peso(totalDeductions);
    document.getElementById("edit-net").innerText      = peso(net);
}

// ── Remove a previously-saved adjustment row via AJAX ─────────────────────────
window.removeExistingAdjustment = async function(btn, rowId, type) {
    if (!confirm('Remove this adjustment? This cannot be undone.')) return;

    const payrollId = document.getElementById('edit-payrollid').value;
    btn.disabled = true;
    btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i>';

    const fd = new FormData();
    fd.append('row_id',     rowId);
    fd.append('type',       type);
    fd.append('payroll_id', payrollId);

    try {
        const res  = await fetch(BASE_URL + 'actions/payroll-delete-adjustment.php', { method: 'POST', body: fd });
        const data = await res.json();
        if (data.success) {
            const adjRow = btn.closest('.adj-saved-row');
            if (adjRow) {
                adjRow.dataset.adjAmount = 0;
                adjRow.remove();
            }
            computePayroll();
        } else {
            alert(data.message || 'Could not remove adjustment.');
            btn.disabled = false;
            btn.innerHTML = '<i class="fa fa-xmark"></i>';
        }
    } catch {
        alert('Network error. Please try again.');
        btn.disabled = false;
        btn.innerHTML = '<i class="fa fa-xmark"></i>';
    }
};

// ── openPayslip — view modal (admin/principal portal) ────────────────────────
window.openPayslip = function(el) {
    const basic = Number(el.dataset.basic || 0);

    const fallbackAllowances = [
        { name: 'Additional Assignment Pay', amount: Number(el.dataset.assign || 0) },
        { name: 'Rice Subsidy',              amount: Number(el.dataset.rice || 0) },
        { name: 'Laundry Allowance',         amount: Number(el.dataset.laundry || 0) },
    ].filter(row => Number(row.amount) !== 0);

    const fallbackDeductions = [
        { name: 'PERAA Premium',  is_gov: false, amount: Number(el.dataset.peraaPremium || 0) },
        { name: 'PERAA Loan',     is_gov: false, amount: Number(el.dataset.peraaLoan || 0) },
        { name: 'HDMF Premium',   is_gov: false, amount: Number(el.dataset.hdmfPremium || 0) },
        { name: 'HDMF Loan',      is_gov: false, amount: Number(el.dataset.hdmfLoan || 0) },
        { name: 'PhilHealth',     is_gov: true,  amount: Number(el.dataset.philhealth || 0) },
        { name: 'SSS Premium',    is_gov: true,  amount: Number(el.dataset.sssPremium || 0) },
        { name: 'SSS Loan',       is_gov: false, amount: Number(el.dataset.sssLoan || 0) },
    ].filter(row => Number(row.amount) !== 0);

    const allAllowances = parseJsonData(el.dataset.allowances, fallbackAllowances)
        .filter(r => Number(r.amount) !== 0)
        .map(r => ({
            ...r,
            name: /additional assignment/i.test(r.name) ? r.name + ' (Overload)' : r.name
        }));

    const allDeductions = parseJsonData(el.dataset.deductions, fallbackDeductions)
        .filter(r => Number(r.amount) !== 0);

    let storedGross = Number(el.dataset.gross || 0);
    if (!storedGross) storedGross = basic + allAllowances.reduce((s, r) => s + Number(r.amount), 0);

    let totalDed = Number(el.dataset.totalDeductions || 0);
    if (!totalDed && allDeductions.length) totalDed = allDeductions.reduce((s, r) => s + Number(r.amount || 0), 0);

    let storedNet = Number(el.dataset.net || 0);
    if (!storedNet) storedNet = storedGross - totalDed;

    // Period label
    const periodEl = document.getElementById('ps-period');
    if (periodEl) periodEl.textContent = el.dataset.periodLabel || '—';

    // Payroll reference number
    const psNumEl = document.getElementById('ps-numbers');
    if (psNumEl) {
        const payrollNo = el.dataset.payrollno || '—';
        psNumEl.innerHTML =
            `<span>Payroll #: <code style="font-size:11px;background:#e2e8f0;padding:1px 6px;border-radius:4px;">${escHtml(payrollNo)}</code></span>`;
    }

    // Employee info
    const empnameEl = document.getElementById('ps-empname');
    if (empnameEl) empnameEl.textContent = el.dataset.name || '';
    document.getElementById('ps-position').textContent = el.dataset.position || '—';
    document.getElementById('ps-dept').textContent     = el.dataset.dept     || '—';
    const empidEl = document.getElementById('ps-empid');
    if (empidEl) empidEl.textContent = el.dataset.empno || el.dataset.empid || '—';

    // Basic Pay section
    renderPayslipRows('ps-basicpay', [{ name: 'Basic Pay', amount: basic }], 'No basic pay recorded.');

    // Allowances section
    const totalAllowances   = allAllowances.reduce((s, r) => s + Number(r.amount), 0);
    const allowancesSection = document.getElementById('ps-allowances-section');
    if (allAllowances.length) {
        renderPayslipRows('ps-allowances', allAllowances, '');
        document.getElementById('ps-total-allowances').textContent = peso(totalAllowances);
        if (allowancesSection) allowancesSection.style.display = 'block';
    } else {
        if (allowancesSection) allowancesSection.style.display = 'none';
    }

    document.getElementById('ps-gross').textContent = peso(storedGross);

    // Deductions (flat list, same as employee portal)
    renderPayslipRows('ps-deductions', allDeductions, 'No deductions');
    document.getElementById('ps-totalded').textContent = peso(totalDed);
    document.getElementById('ps-net').textContent      = peso(storedNet);

    // Government deduction footnote
    const hasGov = allDeductions.some(d => d.is_gov || /sss|philhealth|pag.?ibig/i.test(d.name || ''));
    const govNoteEl = document.getElementById('ps-gov-note');
    if (govNoteEl) govNoteEl.textContent = hasGov ? '* SSS, PhilHealth, and Pag-IBIG amounts reflect employee share only.' : '';

    // Employer contributions (admin/principal only)
    const empSss     = Number(el.dataset.employerSss || 0);
    const empPhil    = Number(el.dataset.employerPhilhealth || 0);
    const empPagibig = Number(el.dataset.employerPagibig || 0);
    const empSection = document.getElementById('ps-employer-section');
    if (empSection) {
        const employerRows = [
            { name: 'SSS (Employer Share)',        amount: empSss },
            { name: 'PhilHealth (Employer Share)', amount: empPhil },
            { name: 'Pag-IBIG (Employer Share)',   amount: empPagibig },
        ].filter(r => Number(r.amount) !== 0);
        if (employerRows.length) {
            renderPayslipRows('ps-employer-rows', employerRows, '');
            const empTotalEl = document.getElementById('ps-employer-total');
            if (empTotalEl) empTotalEl.textContent = peso(employerRows.reduce((s, r) => s + Number(r.amount), 0));
            empSection.style.display = 'block';
        } else {
            empSection.style.display = 'none';
        }
    }

    // Released info
    const releasedInfoEl = document.getElementById('ps-released-info');
    if (releasedInfoEl) {
        const relAt = el.dataset.releasedAt;
        releasedInfoEl.textContent = relAt
            ? 'Released on ' + new Date(relAt).toLocaleDateString('en-PH', { year: 'numeric', month: 'long', day: 'numeric' })
            : '';
    }

    // Reset attendance section to dashes while fetching
    const attSection = document.getElementById('ps-att-section');
    if (attSection) {
        ['ps-att-present','ps-att-late','ps-att-halfday','ps-att-absent','ps-att-leave']
            .forEach(id => { const el = document.getElementById(id); if (el) el.textContent = '—'; });
        attSection.style.display = 'none';
    }

    document.getElementById('payslipOverlay').style.display = 'flex';
    document.body.style.overflow = 'hidden';

    // Fetch attendance summary
    const payrollId = el.dataset.payrollId;
    if (payrollId && attSection) {
        fetch(BASE_URL + 'actions/admin-payslip-attendance.php?payroll_id=' + encodeURIComponent(payrollId))
            .then(r => r.json())
            .then(data => {
                if (!data.success) return;
                const att = data.attendance || {};
                if (parseInt(att.total_records || 0) > 0) {
                    document.getElementById('ps-att-present').textContent = att.days_present || '0';
                    document.getElementById('ps-att-late').textContent    = att.days_late    || '0';
                    document.getElementById('ps-att-halfday').textContent = att.days_halfday || '0';
                    document.getElementById('ps-att-absent').textContent  = att.days_absent  || '0';
                    document.getElementById('ps-att-leave').textContent   = att.days_leave   || '0';
                    attSection.style.display = 'block';
                }
            })
            .catch(() => {}); // silently skip if attendance data unavailable
    }
};

window.closePayslip = function() {
    document.getElementById('payslipOverlay').style.display = 'none';
    document.body.style.overflow = 'auto';
};

window.printPayslip = function() {
    document.body.classList.add('printing-payslip');
    window.print();
    window.addEventListener('afterprint', function onAfterPrint() {
        document.body.classList.remove('printing-payslip');
        window.removeEventListener('afterprint', onAfterPrint);
    });
};

window.downloadPayslipPDF = function() { printPayslip(); };

// Click outside payslip overlay to close
document.addEventListener('DOMContentLoaded', function() {
    const overlay = document.getElementById('payslipOverlay');
    if (overlay) overlay.addEventListener('click', function(e) {
        if (e.target === this) closePayslip();
    });
});

// ── openEdit — edit modal (admin portal) ─────────────────────────────────────
window.openEdit = function(el) {
    document.getElementById("edit-empid").value         = el.dataset.empid;
    document.getElementById("edit-payrollid").value     = el.dataset.payrollId;
    document.getElementById("edit-name").innerText      = el.dataset.name     || '';
    document.getElementById("edit-position").innerText  = el.dataset.position || '';
    document.getElementById("edit-dept").innerText      = el.dataset.dept     || '';
    document.getElementById("edit-basic").value         = el.dataset.basic    || 0;

    // Show employee number in the modal subheading if element exists
    const editEmpNoEl = document.getElementById('edit-empno-display');
    if (editEmpNoEl) editEmpNoEl.innerText = el.dataset.empno || el.dataset.empid || '';

    // Clear previous one-time adjustment rows
    document.getElementById('edit-new-allowances').innerHTML = '';
    document.getElementById('edit-new-deductions').innerHTML = '';

    const allAllowances   = parseJsonData(el.dataset.allowances, []);
    const allDeductions   = parseJsonData(el.dataset.deductions, []);
    const totalAllowances = Number(el.dataset.totalAllowances || 0);

    // Separate adjustments from standard rows
    const stdAllowances = allAllowances.filter(r => !r.is_adjustment);
    const adjAllowances = allAllowances.filter(r =>  r.is_adjustment);
    const stdDeductions = allDeductions.filter(r => !r.is_adjustment);
    const adjDeductions = allDeductions.filter(r =>  r.is_adjustment);

    // ── Allowances ────────────────────────────────────────────────────────────
    const allowContainer = document.getElementById('edit-allowances-container');
    if (stdAllowances.length || adjAllowances.length) {
        let html = '';

        if (stdAllowances.length) {
            html += stdAllowances.map(row => `
                <div>
                    <label>${escHtml(row.name)}</label>
                    <input type="number" name="pa[${Number(row.type_id)}]"
                           value="${Number(row.amount || 0).toFixed(2)}"
                           step="0.01" min="0" oninput="computePayroll()">
                </div>`).join('');
        }

        // Saved adjustments — shown with a remove button; amount tracked for live totals
        if (adjAllowances.length) {
            html += `<p style="grid-column:1/-1;font-size:11px;font-weight:700;text-transform:uppercase;
                                color:#059669;letter-spacing:.5px;margin:10px 0 4px;
                                border-bottom:1px dashed #d1fae5;padding-bottom:3px;">Saved Adjustments</p>`;
            html += adjAllowances.map(row => `
                <div class="adj-saved-row" data-adj-amount="${Number(row.amount || 0)}" style="grid-column:1/-1;">
                    <div style="display:flex;align-items:center;gap:10px;padding:7px 0;">
                        <div style="flex:1;font-size:13px;color:#059669;font-weight:600;">
                            <i class="fa fa-sparkles" style="font-size:10px;"></i>
                            ${escHtml(row.name)}
                            <span class="row-tag" style="background:#d1fae5;color:#065f46;font-size:9px;">Adj</span>
                        </div>
                        <span style="font-size:13px;font-weight:700;color:#059669;min-width:80px;text-align:right;">${peso(row.amount)}</span>
                        <button type="button"
                                onclick="removeExistingAdjustment(this, ${Number(row.row_id)}, 'allowance')"
                                class="btn-remove-adj" title="Remove this adjustment">
                            <i class="fa fa-xmark"></i>
                        </button>
                    </div>
                </div>`).join('');
        }

        allowContainer.innerHTML = html;
    } else if (totalAllowances > 0) {
        allowContainer.innerHTML = `<p class="edit-empty" style="grid-column:1/-1;color:#b45309;">
            <i class="fa fa-triangle-exclamation"></i>
            Allowance details unavailable — <strong>regenerate payroll</strong> to populate individual entries.
        </p>`;
    } else {
        allowContainer.innerHTML = '<p class="edit-empty" style="grid-column:1/-1;">No allowances on this record.</p>';
    }

    // ── Deductions — grouped by category ─────────────────────────────────────
    const dedContainer = document.getElementById('edit-deductions-container');
    if (!stdDeductions.length && !adjDeductions.length) {
        dedContainer.innerHTML = '<p class="edit-empty" style="grid-column:1/-1;">No deductions on this record.</p>';
    } else {
        const govDeds   = stdDeductions.filter(d => d.is_gov);
        const loanDeds  = stdDeductions.filter(d => d.is_loan);
        const otherDeds = stdDeductions.filter(d => !d.is_gov && !d.is_loan);

        function buildDedInputs(items) {
            return items.map(row => `
                <div>
                    <label>
                        ${escHtml(row.name)}
                        ${row.is_gov  ? ' <span class="row-tag row-tag--gov">Gov</span>' : ''}
                        ${row.is_loan ? ' <span class="row-tag row-tag--loan">Loan</span>' : ''}
                    </label>
                    <input type="number" name="pd[${Number(row.type_id)}]"
                           value="${Number(row.amount || 0).toFixed(2)}"
                           step="0.01" min="0" oninput="computePayroll()">
                </div>`).join('');
        }

        function groupHeader(label) {
            return `<p style="grid-column:1/-1;font-size:11px;font-weight:700;text-transform:uppercase;
                               color:#6b7280;letter-spacing:.5px;margin:10px 0 4px;
                               border-bottom:1px dashed #d1d5db;padding-bottom:3px;">${escHtml(label)}</p>`;
        }

        let html = '';
        if (govDeds.length)   html += groupHeader('Government Contributions') + buildDedInputs(govDeds);
        if (loanDeds.length)  html += groupHeader('Loans')                    + buildDedInputs(loanDeds);
        if (otherDeds.length) html += groupHeader('Other Deductions')         + buildDedInputs(otherDeds);

        // Saved deduction adjustments — shown with remove button
        if (adjDeductions.length) {
            html += groupHeader('Saved Adjustments');
            html += adjDeductions.map(row => `
                <div class="adj-saved-row" data-adj-amount="${Number(row.amount || 0)}" style="grid-column:1/-1;">
                    <div style="display:flex;align-items:center;gap:10px;padding:7px 0;">
                        <div style="flex:1;font-size:13px;color:#dc2626;font-weight:600;">
                            <i class="fa fa-sparkles" style="font-size:10px;"></i>
                            ${escHtml(row.name)}
                            <span class="row-tag" style="background:#fee2e2;color:#991b1b;font-size:9px;">Adj</span>
                        </div>
                        <span style="font-size:13px;font-weight:700;color:#dc2626;min-width:80px;text-align:right;">${peso(row.amount)}</span>
                        <button type="button"
                                onclick="removeExistingAdjustment(this, ${Number(row.row_id)}, 'deduction')"
                                class="btn-remove-adj" title="Remove this adjustment">
                            <i class="fa fa-xmark"></i>
                        </button>
                    </div>
                </div>`).join('');
        }

        dedContainer.innerHTML = html;
    }

    document.getElementById("editModal").style.display = "flex";
    document.body.style.overflow = "hidden";
    computePayroll();
}

// ── One-time allowance / deduction adjustment rows ────────────────────────────
window.addAllowanceAdjustment = function() {
    const container = document.getElementById('edit-new-allowances');
    const n = container.querySelectorAll('.adj-row').length;
    const div = document.createElement('div');
    div.className = 'adj-row';
    div.style.cssText = 'display:grid;grid-template-columns:1fr 130px 36px;gap:8px;align-items:center;margin:6px 0;';
    div.innerHTML = `
        <input type="text"   name="new_pa[${n}][label]"  placeholder="Label (e.g. Reimbursement)"
               style="padding:8px 10px;border:1.5px solid #6ee7b7;border-radius:8px;font-size:13px;width:100%;" required>
        <input type="number" name="new_pa[${n}][amount]" placeholder="0.00"
               step="0.01" min="0"
               style="padding:8px 10px;border:1.5px solid #6ee7b7;border-radius:8px;font-size:13px;width:100%;"
               oninput="computePayroll()">
        <button type="button" onclick="this.closest('.adj-row').remove();computePayroll();"
                title="Remove"
                style="border:none;background:#fef2f2;color:#ef4444;border-radius:6px;
                       width:36px;height:36px;cursor:pointer;font-size:14px;">
            <i class="fa fa-xmark"></i>
        </button>`;
    container.appendChild(div);
};

window.addDeductionAdjustment = function() {
    const container = document.getElementById('edit-new-deductions');
    const n = container.querySelectorAll('.adj-row').length;
    const div = document.createElement('div');
    div.className = 'adj-row';
    div.style.cssText = 'display:grid;grid-template-columns:1fr 130px 36px;gap:8px;align-items:center;margin:6px 0;';
    div.innerHTML = `
        <input type="text"   name="new_pd[${n}][label]"  placeholder="Label (e.g. Cash Advance)"
               style="padding:8px 10px;border:1.5px solid #fca5a5;border-radius:8px;font-size:13px;width:100%;" required>
        <input type="number" name="new_pd[${n}][amount]" placeholder="0.00"
               step="0.01" min="0"
               style="padding:8px 10px;border:1.5px solid #fca5a5;border-radius:8px;font-size:13px;width:100%;"
               oninput="computePayroll()">
        <button type="button" onclick="this.closest('.adj-row').remove();computePayroll();"
                title="Remove"
                style="border:none;background:#fef2f2;color:#ef4444;border-radius:6px;
                       width:36px;height:36px;cursor:pointer;font-size:14px;">
            <i class="fa fa-xmark"></i>
        </button>`;
    container.appendChild(div);
};

window.closeEdit = function() {
    document.getElementById("editModal").style.display = "none";
    document.body.style.overflow = "auto";
}

window.openGenerateModal = function() {
    document.getElementById("generateModal").style.display = "flex";
}

window.closeGenerateModal = function() {
    document.getElementById("generateModal").style.display = "none";
}

document.addEventListener("input", function(e) {
    if (e.target.closest("#editModal")) {
        computePayroll();
    }
});

const sidebar = document.getElementById('sidebar');
const collapseBtn = document.getElementById('collapseBtn');
if (sidebar && collapseBtn) {
    collapseBtn.addEventListener('click', () => sidebar.classList.toggle('collapsed'));
}
