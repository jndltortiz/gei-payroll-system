console.log("PAYROLL JS LOADED");

function peso(num) {
    return "\u20b1" + Number(num || 0).toLocaleString('en-PH', {
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
        { name: 'Rice Subsidy', amount: Number(el.dataset.rice || 0) },
        { name: 'Laundry Allowance', amount: Number(el.dataset.laundry || 0) },
    ].filter(row => Number(row.amount) !== 0);

    const fallbackDeductions = [
        { name: 'PERAA Premium', amount: Number(el.dataset.peraaPremium || 0) },
        { name: 'PERAA Loan', amount: Number(el.dataset.peraaLoan || 0) },
        { name: 'HDMF Premium', amount: Number(el.dataset.hdmfPremium || 0) },
        { name: 'HDMF Loan', amount: Number(el.dataset.hdmfLoan || 0) },
        { name: 'PhilHealth', amount: Number(el.dataset.philhealth || 0) },
        { name: 'SSS Premium', amount: Number(el.dataset.sssPremium || 0) },
        { name: 'SSS Loan', amount: Number(el.dataset.sssLoan || 0) },
    ].filter(row => Number(row.amount) !== 0);

    const allowanceRows = [
        { name: 'Basic Salary', amount: basic },
        ...parseJsonData(el.dataset.allowances, fallbackAllowances)
    ];
    const deductionRows = parseJsonData(el.dataset.deductions, fallbackDeductions);

    let gross = Number(el.dataset.gross || 0);
    if (!gross) {
        gross = allowanceRows.reduce((sum, row) => sum + Number(row.amount || 0), 0);
    }

    let totalDed = Number(el.dataset.totalDeductions || 0);
    if (!totalDed && deductionRows.length) {
        totalDed = deductionRows.reduce((sum, row) => sum + Number(row.amount || 0), 0);
    }

    let net = Number(el.dataset.net || 0);
    if (!net) {
        net = gross - totalDed;
    }

    document.getElementById("ps-name").innerText = el.dataset.name || '';
    document.getElementById("ps-position").innerText = el.dataset.position || '';
    document.getElementById("ps-dept").innerText = el.dataset.dept || '';
    document.getElementById("ps-empid").innerText = el.dataset.empid || '';

    const periodLabel = document.getElementById("ps-period-label");
    if (periodLabel) periodLabel.innerText = el.dataset.periodLabel || "-";

    renderPayslipRows("ps-earnings-rows", allowanceRows, "No earnings");
    document.getElementById("ps-gross").innerText = peso(gross);

    renderPayslipRows("ps-deductions-rows", deductionRows, "No deductions");
    document.getElementById("ps-totalded").innerText = peso(totalDed);
    document.getElementById("ps-net").innerText = peso(net);

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
            year: 'numeric',
            month: 'long',
            day: 'numeric'
        });
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
    document.getElementById("edit-empid").value      = el.dataset.empid;
    document.getElementById("edit-payrollid").value  = el.dataset.payrollId;
    document.getElementById("edit-name").innerText    = el.dataset.name     || '';
    document.getElementById("edit-position").innerText = el.dataset.position || '';
    document.getElementById("edit-dept").innerText     = el.dataset.dept     || '';
    document.getElementById("edit-basic").value        = el.dataset.basic    || 0;

    const allowances = parseJsonData(el.dataset.allowances, []);
    const deductions = parseJsonData(el.dataset.deductions, []);

    // Render allowance inputs keyed by type_id — no name matching needed
    const allowContainer = document.getElementById('edit-allowances-container');
    allowContainer.innerHTML = allowances.length
        ? allowances.map(row => `
            <div>
                <label>${escHtml(row.name)}</label>
                <input type="number" name="pa[${Number(row.type_id)}]"
                       value="${Number(row.amount || 0).toFixed(2)}"
                       step="0.01" min="0">
            </div>`).join('')
        : '<p style="color:#9ca3af;font-size:13px;grid-column:1/-1;">No allowances on this record.</p>';

    // Render deduction inputs keyed by type_id — no name matching needed
    const dedContainer = document.getElementById('edit-deductions-container');
    dedContainer.innerHTML = deductions.length
        ? deductions.map(row => `
            <div>
                <label>${escHtml(row.name)}</label>
                <input type="number" name="pd[${Number(row.type_id)}]"
                       value="${Number(row.amount || 0).toFixed(2)}"
                       step="0.01" min="0">
            </div>`).join('')
        : '<p style="color:#9ca3af;font-size:13px;grid-column:1/-1;">No deductions on this record.</p>';

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
