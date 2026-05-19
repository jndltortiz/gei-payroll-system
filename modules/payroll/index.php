<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/database.php';
requireLogin();

// ── Pay periods from DB ──────────────────────────────────────────────────────
$allPeriods = $pdo->query("SELECT * FROM payroll_periods ORDER BY pay_period_start DESC")->fetchAll();

// Selected period — default to first OPEN one, else most recent
$selectedId = (int)($_GET['period_id'] ?? 0);
if (!$selectedId) {
    foreach ($allPeriods as $p) {
        if ($p['status'] === 'OPEN') { $selectedId = $p['period_id']; break; }
    }
    if (!$selectedId && !empty($allPeriods)) {
        $selectedId = $allPeriods[0]['period_id'];
    }
}

// ── Payroll records for selected period ─────────────────────────────────────
$records = [];
$totalGross = $totalDed = $totalNet = 0;

if ($selectedId) {
    $stmt = $pdo->prepare("
        SELECT
            pr.payroll_id, pr.employee_id, pr.basic_pay, pr.gross_pay,
            pr.total_deductions, pr.net_pay, pr.payroll_status,
            e.first_name, e.last_name,
            p.position_name, d.department_name,
            (SELECT SUM(pa.amount) FROM payroll_allowances pa
             JOIN allowance_types at ON pa.allowance_type_id = at.allowance_type_id
             WHERE pa.payroll_id = pr.payroll_id AND at.allowance_name = 'Additional Assignment Pay') AS assign,
            (SELECT SUM(pa.amount) FROM payroll_allowances pa
             JOIN allowance_types at ON pa.allowance_type_id = at.allowance_type_id
             WHERE pa.payroll_id = pr.payroll_id AND at.allowance_name = 'Rice Subsidy') AS rice,
            (SELECT SUM(pa.amount) FROM payroll_allowances pa
             JOIN allowance_types at ON pa.allowance_type_id = at.allowance_type_id
             WHERE pa.payroll_id = pr.payroll_id AND at.allowance_name = 'Laundry Allowance') AS laundry,
            (SELECT SUM(pd.amount) FROM payroll_deductions pd
             JOIN deduction_types dt ON pd.deduction_type_id = dt.deduction_type_id
             WHERE pd.payroll_id = pr.payroll_id AND dt.deduction_name = 'SSS Premium') AS sss_premium,
            (SELECT SUM(pd.amount) FROM payroll_deductions pd
             JOIN deduction_types dt ON pd.deduction_type_id = dt.deduction_type_id
             WHERE pd.payroll_id = pr.payroll_id AND dt.deduction_name = 'PhilHealth') AS philhealth,
            (SELECT SUM(pd.amount) FROM payroll_deductions pd
             JOIN deduction_types dt ON pd.deduction_type_id = dt.deduction_type_id
             WHERE pd.payroll_id = pr.payroll_id AND (dt.deduction_name = 'HDMF Premium' OR dt.deduction_name = 'Pag-IBIG')) AS hdmf_premium,
            (SELECT SUM(pd.amount) FROM payroll_deductions pd
             JOIN deduction_types dt ON pd.deduction_type_id = dt.deduction_type_id
             WHERE pd.payroll_id = pr.payroll_id AND dt.deduction_name = 'PERAA Premium') AS peraa_premium,
            (SELECT SUM(pd.amount) FROM payroll_deductions pd
             JOIN deduction_types dt ON pd.deduction_type_id = dt.deduction_type_id
             WHERE pd.payroll_id = pr.payroll_id AND dt.deduction_name = 'SSS Loan') AS sss_loan,
            (SELECT SUM(pd.amount) FROM payroll_deductions pd
             JOIN deduction_types dt ON pd.deduction_type_id = dt.deduction_type_id
             WHERE pd.payroll_id = pr.payroll_id AND dt.deduction_name = 'HDMF Loan') AS hdmf_loan,
            (SELECT SUM(pd.amount) FROM payroll_deductions pd
             JOIN deduction_types dt ON pd.deduction_type_id = dt.deduction_type_id
             WHERE pd.payroll_id = pr.payroll_id AND dt.deduction_name = 'PERAA Loan') AS peraa_loan
        FROM payroll_records pr
        JOIN employees e ON pr.employee_id = e.employee_id
        LEFT JOIN positions p ON e.position_id = p.position_id
        LEFT JOIN departments d ON e.department_id = d.department_id
        WHERE pr.period_id = :pid
        ORDER BY e.last_name, e.first_name
    ");
    $stmt->execute([':pid' => $selectedId]);
    $records = $stmt->fetchAll();
    foreach ($records as $r) {
        $totalGross += $r['gross_pay'];
        $totalDed   += $r['total_deductions'];
        $totalNet   += $r['net_pay'];
    }
}

$pageTitle = 'Payroll Management';
$extraCSS  = [BASE_URL . 'assets/css/payroll.css'];
require_once __DIR__ . '/../../includes/head.php';
?>
<body>
<div class="layout">
    <?php include __DIR__ . '/../../includes/sidebar.php'; ?>
    <div class="main">
        <?php include __DIR__ . '/../../includes/header.php'; ?>
        <div class="main-content">
            <div class="payroll-container">

                <!-- HEADER -->
                <div class="page-header">
                    <div>
                        <h2>Payroll Management</h2>
                        <small>Generate payroll, edit allowances and deductions, then submit for approval.</small>
                    </div>
                    <div class="actions">
                        <button class="btn-outline" id="btnExport">Export Report</button>
                        <button class="btn-primary" onclick="openGenerateModal()">Generate Payroll</button>
                    </div>
                </div>

                <!-- PAY PERIOD — live from DB -->
                <div class="pay-period">
                    <strong>Current Pay Period:</strong>
                    <select id="periodSelect" onchange="changePeriod(this.value)">
                        <?php if (empty($allPeriods)): ?>
                            <option value="">No pay periods found</option>
                        <?php else: ?>
                            <?php foreach ($allPeriods as $p): ?>
                            <option value="<?= $p['period_id'] ?>"
                                <?= $p['period_id'] == $selectedId ? 'selected' : '' ?>>
                                <?= htmlspecialchars($p['period_name']) ?>
                                (<?= ucfirst(strtolower($p['status'])) ?>)
                            </option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                </div>

                <!-- FLASH -->
                <div id="payrollFlash" style="display:none;margin-bottom:12px;"></div>

                <!-- TABLE -->
                <div class="table-wrapper">
                    <table class="payroll-table">
                        <thead>
                            <tr>
                                <th>Employee</th><th>Employee ID</th><th>Basic</th>
                                <th>Add'l Assign</th><th>Rice</th><th>Laundry</th>
                                <th>Gross</th><th>Total Ded</th><th>Net Pay</th>
                                <th>Status</th><th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($records)): ?>
                            <tr><td colspan="11" style="text-align:center;padding:40px;color:#9ca3af;">
                                No payroll records for this period. Click <strong>Generate Payroll</strong> to create them.
                            </td></tr>
                        <?php else: ?>
                        <?php foreach ($records as $row): ?>
                            <tr>
                                <td>
                                    <strong><?= htmlspecialchars($row['first_name'].' '.$row['last_name']) ?></strong><br>
                                    <small><?= htmlspecialchars($row['position_name'] ?? '') ?></small>
                                </td>
                                <td>EMP-<?= str_pad($row['employee_id'], 4, '0', STR_PAD_LEFT) ?></td>
                                <td>₱<?= number_format($row['basic_pay'], 2) ?></td>
                                <td>₱<?= number_format($row['assign']   ?? 0, 2) ?></td>
                                <td>₱<?= number_format($row['rice']     ?? 0, 2) ?></td>
                                <td>₱<?= number_format($row['laundry']  ?? 0, 2) ?></td>
                                <td><strong>₱<?= number_format($row['gross_pay'], 2) ?></strong></td>
                                <td>₱<?= number_format($row['total_deductions'], 2) ?></td>
                                <td><strong>₱<?= number_format($row['net_pay'], 2) ?></strong></td>
                                <td><span class="badge"><?= htmlspecialchars($row['payroll_status']) ?></span></td>
                                <td>
                                    <button class="btn-view" onclick="openPayslip(this)"
                                        data-name="<?= htmlspecialchars($row['first_name'].' '.$row['last_name']) ?>"
                                        data-position="<?= htmlspecialchars($row['position_name'] ?? '') ?>"
                                        data-dept="<?= htmlspecialchars($row['department_name'] ?? 'N/A') ?>"
                                        data-empid="<?= $row['employee_id'] ?>"
                                        data-basic="<?= $row['basic_pay'] ?>"
                                        data-assign="<?= $row['assign']        ?? 0 ?>"
                                        data-rice="<?= $row['rice']          ?? 0 ?>"
                                        data-laundry="<?= $row['laundry']      ?? 0 ?>"
                                        data-sss-premium="<?= $row['sss_premium']  ?? 0 ?>"
                                        data-philhealth="<?= $row['philhealth']   ?? 0 ?>"
                                        data-hdmf-premium="<?= $row['hdmf_premium'] ?? 0 ?>"
                                        data-peraa-premium="<?= $row['peraa_premium']?? 0 ?>"
                                        data-sss-loan="<?= $row['sss_loan']    ?? 0 ?>"
                                        data-hdmf-loan="<?= $row['hdmf_loan']   ?? 0 ?>"
                                        data-peraa-loan="<?= $row['peraa_loan']  ?? 0 ?>">
                                        <i class="fa fa-eye"></i>
                                    </button>
                                    <button class="btn-edit" onclick="openEdit(this)"
                                        data-payroll-id="<?= $row['payroll_id'] ?>"
                                        data-basic="<?= $row['basic_pay'] ?>"
                                        data-assign="<?= $row['assign']        ?? 0 ?>"
                                        data-rice="<?= $row['rice']          ?? 0 ?>"
                                        data-laundry="<?= $row['laundry']      ?? 0 ?>"
                                        data-sss-premium="<?= $row['sss_premium']  ?? 0 ?>"
                                        data-philhealth="<?= $row['philhealth']   ?? 0 ?>"
                                        data-hdmf-premium="<?= $row['hdmf_premium'] ?? 0 ?>"
                                        data-peraa-premium="<?= $row['peraa_premium']?? 0 ?>"
                                        data-sss-loan="<?= $row['sss_loan']    ?? 0 ?>"
                                        data-hdmf-loan="<?= $row['hdmf_loan']   ?? 0 ?>"
                                        data-peraa-loan="<?= $row['peraa_loan']  ?? 0 ?>">
                                        <i class="fa fa-pen"></i>
                                    </button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- TOTALS -->
                <div class="totals">
                    <div class="total-box">
                        <span>Total Gross Pay</span>
                        <strong>₱<?= number_format($totalGross, 2) ?></strong>
                    </div>
                    <div class="total-box">
                        <span>Total Deductions</span>
                        <strong class="text-red">₱<?= number_format($totalDed, 2) ?></strong>
                    </div>
                    <div class="total-box">
                        <span>Total Net Payroll</span>
                        <strong class="text-green">₱<?= number_format($totalNet, 2) ?></strong>
                    </div>
                    <button class="btn-primary submit-btn">Submit to Principal for Approval</button>
                </div>

            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/modals/view-modal.php'; ?>
<?php include __DIR__ . '/modals/edit-modal.php'; ?>
<?php include __DIR__ . '/modals/generate-modal.php'; ?>

<script>
function changePeriod(id) {
    if (id) window.location.href = '<?= BASE_URL ?>modules/payroll/index.php?period_id=' + id;
}

// Intercept generate form to use fetch → show result without redirect
document.addEventListener('DOMContentLoaded', function () {
    const genForm = document.querySelector('#generateModal form');
    if (!genForm) return;
    genForm.addEventListener('submit', function (e) {
        e.preventDefault();
        const btn = genForm.querySelector('button[type="submit"]');
        btn.disabled = true;
        btn.textContent = 'Generating…';
        const fd = new FormData(genForm);
        fetch('<?= BASE_URL ?>actions/generate-payroll.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(res => {
                closeGenerateModal();
                const flash = document.getElementById('payrollFlash');
                flash.style.display = 'block';
                flash.style.cssText = 'padding:12px 16px;border-radius:8px;margin-bottom:12px;font-size:14px;' +
                    (res.success ? 'background:#d1fae5;color:#065f46;' : 'background:#fee2e2;color:#991b1b;');
                flash.textContent = res.message;
                if (res.success) setTimeout(() => location.reload(), 1200);
                else btn.disabled = false;
            })
            .catch(() => {
                closeGenerateModal();
                alert('Network error. Please try again.');
                btn.disabled = false;
            });
    });
});
</script>
<script src="<?= BASE_URL ?>assets/js/payroll.js?v=<?= time() ?>"></script>
<?php include __DIR__ . '/../../includes/footer.php'; ?>