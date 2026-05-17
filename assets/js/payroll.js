console.log("PAYROLL JS LOADED");
function peso(num) {
    return "₱" + Number(num).toLocaleString();
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

    let totalDed =
        peraaP + peraaL +
        hdmfP + hdmfL +
        philhealth +
        sssP + sssL;

    let net = gross - totalDed;

    document.getElementById("edit-gross").innerText = peso(gross);
    document.getElementById("edit-totalded").innerText = peso(totalDed);
    document.getElementById("edit-net").innerText = peso(net);
}

window.openPayslip = function(el) {

    let basic = Number(el.dataset.basic);
    let assign = Number(el.dataset.assign);
    let rice = Number(el.dataset.rice);
    let laundry = Number(el.dataset.laundry);

    let peraa = Number(el.dataset.peraaPremium || 0);
    let peraaLoan = Number(el.dataset.peraaLoan || 0);

    let hdmf = Number(el.dataset.hdmfPremium || 0);
    let hdmfLoan = Number(el.dataset.hdmfLoan || 0);

    let philhealth = Number(el.dataset.philhealth || 0);

    let sss = Number(el.dataset.sssPremium || 0);
    let sssLoan = Number(el.dataset.sssLoan || 0);

    let gross = basic + assign + rice + laundry;

    let totalDed = peraa + peraaLoan + hdmf + hdmfLoan + philhealth + sss + sssLoan;
    let net = gross - totalDed;

    document.getElementById("ps-name").innerText = el.dataset.name;
    document.getElementById("ps-position").innerText = el.dataset.position;
    document.getElementById("ps-dept").innerText = el.dataset.dept;
    document.getElementById("ps-empid").innerText = el.dataset.empid;

    document.getElementById("ps-basic").innerText = peso(basic);
    document.getElementById("ps-assign").innerText = peso(assign);
    document.getElementById("ps-rice").innerText = peso(rice);
    document.getElementById("ps-laundry").innerText = peso(laundry);

    document.getElementById("ps-gross").innerText = peso(gross);

    document.getElementById("ps-peraa").innerText = peso(peraa);
    document.getElementById("ps-peraa-loan").innerText = peso(peraaLoan);

    document.getElementById("ps-hdmf").innerText = peso(hdmf);
    document.getElementById("ps-hdmf-loan").innerText = peso(hdmfLoan);

    document.getElementById("ps-philhealth").innerText = peso(philhealth);

    document.getElementById("ps-sss").innerText = peso(sss);
    document.getElementById("ps-sss-loan").innerText = peso(sssLoan);

    document.getElementById("ps-totalded").innerText = peso(totalDed);
    document.getElementById("ps-net").innerText = peso(net);

    document.getElementById("payslipModal").style.display = "flex";
    document.body.style.overflow = "hidden";
}

window.closePayslip = function() {
    document.getElementById("payslipModal").style.display = "none";
}

window.openEdit = function(el) {

    console.log("EDIT CLICKED", el); // keep this for debugging
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

  // Sidebar collapse
  const sidebar = document.getElementById('sidebar');
  document.getElementById('collapseBtn').addEventListener('click', () => {
    sidebar.classList.toggle('collapsed');
  });