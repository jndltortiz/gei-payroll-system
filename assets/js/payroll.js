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
    let basic = Number(document.getElementById("edit-basic").value) || 0;
    let assign = Number(document.getElementById("edit-assign").value) || 0;
    let rice = Number(document.getElementById("edit-rice").value) || 0;
    let laundry = Number(document.getElementById("edit-laundry").value) || 0;

    let peraaP = Number(document.getElementById("edit-peraa-premium").value) || 0;
    let peraaL = Number(document.getElementById("edit-peraa-loan").value) || 0;
    let hdmfP = Number(document.getElementById("edit-hdmf-premium").value) || 0;
    let hdmfL = Number(document.getElementById("edit-hdmf-loan").value) || 0;
    let philhealth = Number(document.getElementById("edit-philhealth").value) || 0;
    let sssP = Number(document.getElementById("edit-sss-premium").value) || 0;
    let sssL = Number(document.getElementById("edit-sss-loan").value) || 0;

    let gross = basic + assign + rice + laundry;
    let totalDed = peraaP + peraaL + hdmfP + hdmfL + philhealth + sssP + sssL;
    let net = gross - totalDed;

    document.getElementById("edit-gross").innerText = peso(gross);
    document.getElementById("edit-totalded").innerText = peso(totalDed);
    document.getElementById("edit-net").innerText = peso(net);
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
    window.print();
}

window.downloadPayslipPDF = function() {
    window.print();
}

window.openEdit = function(el) {
    console.log("EDIT CLICKED", el);
    console.log(el.dataset);

    document.getElementById("edit-empid").value = el.dataset.empid;
    document.getElementById("edit-name").innerText = el.dataset.name || '';
    document.getElementById("edit-position").innerText = el.dataset.position || '';
    document.getElementById("edit-dept").innerText = el.dataset.dept || '';

    document.getElementById("edit-basic").value = el.dataset.basic || 0;
    document.getElementById("edit-assign").value = el.dataset.assign || 0;
    document.getElementById("edit-rice").value = el.dataset.rice || 0;
    document.getElementById("edit-laundry").value = el.dataset.laundry || 0;
    document.getElementById("edit-peraa-premium").value = el.dataset.peraaPremium || 0;
    document.getElementById("edit-peraa-loan").value = el.dataset.peraaLoan || 0;
    document.getElementById("edit-hdmf-premium").value = el.dataset.hdmfPremium || 0;
    document.getElementById("edit-hdmf-loan").value = el.dataset.hdmfLoan || 0;
    document.getElementById("edit-philhealth").value = el.dataset.philhealth || 0;
    document.getElementById("edit-sss-premium").value = el.dataset.sssPremium || 0;
    document.getElementById("edit-sss-loan").value = el.dataset.sssLoan || 0;
    document.getElementById("edit-payrollid").value = el.dataset.payrollId;

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
