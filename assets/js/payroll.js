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

// Render a flat list of rows into a container (earnings, employer contrib, etc.)
function renderPayslipRows(containerId, rows, emptyLabel) {
    const container = document.getElementById(containerId);
    if (!container) return;

    if (!rows.length) {
        container.innerHTML = `<div class="row"><span>${escHtml(emptyLabel)}</span><span>${peso(0)}</span></div>`;
        return;
    }

    container.innerHTML = rows.map(row => `
        <div class="row">
            <span>${escHtml(row.name)}</span>
            <span>${peso(row.amount)}</span>
        </div>
    `).join('');
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

function computePayroll() {
    const basic = Number(document.getElementById("edit-basic").value) || 0;

    let totalAllowances = 0;
    document.querySelectorAll('#edit-allowances-container input[type="number"]').forEach(input => {
        totalAllowances += Number(input.value) || 0;
    });

    let totalDeductions = 0;
    document.querySelectorAll('#edit-deductions-container input[type="number"]').forEach(input => {
        totalDeductions += Number(input.value) || 0;
    });

    const gross = basic + totalAllowances;
    const net   = gross - totalDeductions;

    document.getElementById("edit-gross").innerText    = peso(gross);
    document.getElementById("edit-totalded").innerText = peso(totalDeductions);
    document.getElementById("edit-net").innerText      = peso(net);
}

window.openPayslip = function(el) {
    const basic = Number(el.dataset.basic || 0);

    const fallbackAllowances = [
        { name: 'Additional Assignment Pay', amount: Number(el.dataset.assign || 0) },
        { name: 'Rice Subsidy',              amount: Number(el.dataset.rice || 0) },
        { name: 'Laundry Allowance',         amount: Number(el.dataset.laundry || 0) },
    ].filter(row => Number(row.amount) !== 0);

    const fallbackDeductions = [
        { name: 'PERAA Premium',  is_gov: false, is_loan: false, amount: Number(el.dataset.peraaPremium || 0) },
        { name: 'PERAA Loan',     is_gov: false, is_loan: true,  amount: Number(el.dataset.peraaLoan || 0) },
        { name: 'HDMF Premium',   is_gov: false, is_loan: false, amount: Number(el.dataset.hdmfPremium || 0) },
        { name: 'HDMF Loan',      is_gov: false, is_loan: true,  amount: Number(el.dataset.hdmfLoan || 0) },
        { name: 'PhilHealth',     is_gov: true,  is_loan: false, amount: Number(el.dataset.philhealth || 0) },
        { name: 'SSS Premium',    is_gov: true,  is_loan: false, amount: Number(el.dataset.sssPremium || 0) },
        { name: 'SSS Loan',       is_gov: false, is_loan: true,  amount: Number(el.dataset.sssLoan || 0) },
    ].filter(row => Number(row.amount) !== 0);

    // Earnings: Basic Salary always shown; allowances filtered to non-zero
    const parsedAllowances = parseJsonData(el.dataset.allowances, fallbackAllowances);
    const earningsRows = [
        { name: 'Basic Salary', amount: basic },
        ...parsedAllowances.filter(r => Number(r.amount) !== 0)
    ];

    // Deductions: filter zero-value rows before grouping
    const allDeductions = parseJsonData(el.dataset.deductions, fallbackDeductions)
        .filter(r => Number(r.amount) !== 0);

    let gross = Number(el.dataset.gross || 0);
    if (!gross) {
        gross = earningsRows.reduce((sum, row) => sum + Number(row.amount || 0), 0);
    }

    let totalDed = Number(el.dataset.totalDeductions || 0);
    if (!totalDed && allDeductions.length) {
        totalDed = allDeductions.reduce((sum, row) => sum + Number(row.amount || 0), 0);
    }

    let net = Number(el.dataset.net || 0);
    if (!net) net = gross - totalDed;

    document.getElementById("ps-name").innerText     = el.dataset.name || '';
    document.getElementById("ps-position").innerText = el.dataset.position || '';
    document.getElementById("ps-dept").innerText     = el.dataset.dept || '';
    document.getElementById("ps-empid").innerText    = el.dataset.empid || '';

    const periodLabel = document.getElementById("ps-period-label");
    if (periodLabel) periodLabel.innerText = el.dataset.periodLabel || "-";

    renderPayslipRows("ps-earnings-rows", earningsRows, "No earnings");
    document.getElementById("ps-gross").innerText = peso(gross);

    renderGroupedDeductionRows("ps-deductions-rows", allDeductions);
    document.getElementById("ps-totalded").innerText = peso(totalDed);
    document.getElementById("ps-net").innerText      = peso(net);

    const statusEl = document.getElementById("ps-status");
    if (statusEl) statusEl.innerText = el.dataset.payrollStatus || '—';

    const releasedAtEl = document.getElementById("ps-released-at");
    if (releasedAtEl) {
        const relAt = el.dataset.releasedAt;
        releasedAtEl.innerText = relAt
            ? new Date(relAt).toLocaleDateString('en-PH', { year: 'numeric', month: 'long', day: 'numeric' })
            : '—';
    }

    const releasedByEl = document.getElementById("ps-released-by");
    if (releasedByEl) releasedByEl.innerText = el.dataset.releasedBy || '—';

    const generatedOn = document.getElementById("ps-generated-on");
    if (generatedOn) {
        generatedOn.innerText = new Date().toLocaleDateString('en-PH', {
            year: 'numeric', month: 'long', day: 'numeric'
        });
    }

    // Employer contributions — admin/principal view only; hidden on print
    const empSss     = Number(el.dataset.employerSss || 0);
    const empPhil    = Number(el.dataset.employerPhilhealth || 0);
    const empPagibig = Number(el.dataset.employerPagibig || 0);
    const employerSection  = document.getElementById('ps-employer-section');
    const employerRowsEl   = document.getElementById('ps-employer-rows');
    const employerTotalEl  = document.getElementById('ps-employer-total');
    if (employerSection && employerRowsEl) {
        const employerRows = [
            { name: 'SSS (Employer Share)',        amount: empSss },
            { name: 'PhilHealth (Employer Share)', amount: empPhil },
            { name: 'Pag-IBIG (Employer Share)',   amount: empPagibig },
        ].filter(r => Number(r.amount) !== 0);
        if (employerRows.length) {
            renderPayslipRows('ps-employer-rows', employerRows, '');
            if (employerTotalEl) {
                const empTotal = employerRows.reduce((s, r) => s + Number(r.amount), 0);
                employerTotalEl.innerText = peso(empTotal);
            }
            employerSection.style.display = '';
        } else {
            employerSection.style.display = 'none';
        }
    }

    document.getElementById("payslipModal").style.display = "flex";
    document.body.style.overflow = "hidden";
}

window.closePayslip = function() {
    document.getElementById("payslipModal").style.display = "none";
    document.body.style.overflow = "auto";
}

window.printPayslip = function() {
    document.body.classList.add('printing-payslip');
    window.print();
    window.addEventListener('afterprint', function onAfterPrint() {
        document.body.classList.remove('printing-payslip');
        window.removeEventListener('afterprint', onAfterPrint);
    });
}

window.downloadPayslipPDF = function() {
    printPayslip();
}

window.openEdit = function(el) {
    document.getElementById("edit-empid").value        = el.dataset.empid;
    document.getElementById("edit-payrollid").value   = el.dataset.payrollId;
    document.getElementById("edit-name").innerText    = el.dataset.name     || '';
    document.getElementById("edit-position").innerText = el.dataset.position || '';
    document.getElementById("edit-dept").innerText    = el.dataset.dept     || '';
    document.getElementById("edit-basic").value       = el.dataset.basic    || 0;

    const allowances      = parseJsonData(el.dataset.allowances, []);
    const deductions      = parseJsonData(el.dataset.deductions, []);
    const totalAllowances = Number(el.dataset.totalAllowances || 0);

    // ── Allowances ────────────────────────────────────────────────────────────
    const allowContainer = document.getElementById('edit-allowances-container');
    if (allowances.length) {
        allowContainer.innerHTML = allowances.map(row => `
            <div>
                <label>${escHtml(row.name)}</label>
                <input type="number" name="pa[${Number(row.type_id)}]"
                       value="${Number(row.amount || 0).toFixed(2)}"
                       step="0.01" min="0">
            </div>`).join('');
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
    if (!deductions.length) {
        dedContainer.innerHTML = '<p class="edit-empty" style="grid-column:1/-1;">No deductions on this record.</p>';
    } else {
        const govDeds   = deductions.filter(d => d.is_gov);
        const loanDeds  = deductions.filter(d => d.is_loan);
        const otherDeds = deductions.filter(d => !d.is_gov && !d.is_loan);

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
                           step="0.01" min="0">
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
        dedContainer.innerHTML = html;
    }

    document.getElementById("editModal").style.display = "flex";
    document.body.style.overflow = "hidden";
    computePayroll();
}

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
