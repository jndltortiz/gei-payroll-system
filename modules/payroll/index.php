<?php
require_once __DIR__ . '/../../includes/auth.php';
$pageTitle = 'Payroll Management';
$extraCSS  = [BASE_URL . 'assets/css/payroll.css'];
require_once __DIR__ . '/../../includes/head.php';
?>
<body>

    <div class="layout">

        <?php include __DIR__ . '/../../includes/sidebar.php'; ?>

        <div class="main">

            <?php include __DIR__ . '/../../includes/header.php'; ?>

            <div class="dashboard-content">

                <div class="payroll-container">

                    <!-- HEADER -->
                    <div class="page-header">
                        <div>
                            <h2>Payroll Management</h2>
                            <small>Generate payroll, edit allowances and deductions, then submit for approval.</small>
                        </div>

                        <div class="actions">
                            <button class="btn-outline">Export Report</button>
                            <button class="btn-primary" onclick="openGenerateModal()">
                                Generate Payroll
                            </button>
                        </div>
                    </div>

                    <!-- PAY PERIOD -->
                    <div class="pay-period">
                        <strong>Current Pay Period:</strong>
                        <select>
                            <option>Mar 1 – Mar 15, 2026 (Pending)</option>
                        </select>
                    </div>

                    <!-- TABLE -->
                    <div class="table-wrapper">
                        <table class="payroll-table">
                            <thead>
                                <tr>
                                    <th>Employee</th>
                                    <th>Employee ID</th>
                                    <th>Basic</th>
                                    <th>Add'l Assign</th>
                                    <th>Rice</th>
                                    <th>Laundry</th>
                                    <th>Gross</th>
                                    <th>Total Ded</th>
                                    <th>Net Pay</th>
                                    <th>Status</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>

                            <tbody>
                                <?php
                                require_once __DIR__ . '/../../config/database.php';

                                $stmt = $pdo->query("
                                    SELECT 
                                        pr.payroll_id,
                                        pr.employee_id,
                                        pr.basic_pay,
                                        pr.gross_pay,
                                        pr.total_deductions,
                                        pr.net_pay,
                                        pr.payroll_status,

                                        e.first_name,
                                        e.last_name,
                                        p.position_name,
                                        d.department_name,

                                        -- ALLOWANCES via subquery (avoids cartesian product)
                                        (SELECT SUM(pa.amount) FROM payroll_allowances pa 
                                        JOIN allowance_types atype ON pa.allowance_type_id = atype.allowance_type_id
                                        WHERE pa.payroll_id = pr.payroll_id AND atype.allowance_name = 'Additional Assignment Pay') AS assign,

                                        (SELECT SUM(pa.amount) FROM payroll_allowances pa 
                                        JOIN allowance_types atype ON pa.allowance_type_id = atype.allowance_type_id
                                        WHERE pa.payroll_id = pr.payroll_id AND atype.allowance_name = 'Rice Subsidy') AS rice,

                                        (SELECT SUM(pa.amount) FROM payroll_allowances pa 
                                        JOIN allowance_types atype ON pa.allowance_type_id = atype.allowance_type_id
                                        WHERE pa.payroll_id = pr.payroll_id AND atype.allowance_name = 'Laundry Allowance') AS laundry,

                                        -- DEDUCTIONS via subquery
                                        (SELECT SUM(pd.amount) FROM payroll_deductions pd 
                                        JOIN deduction_types dt ON pd.deduction_type_id = dt.deduction_type_id
                                        WHERE pd.payroll_id = pr.payroll_id AND dt.deduction_name = 'PERAA Premium') AS peraa_premium,

                                        (SELECT SUM(pd.amount) FROM payroll_deductions pd 
                                        JOIN deduction_types dt ON pd.deduction_type_id = dt.deduction_type_id
                                        WHERE pd.payroll_id = pr.payroll_id AND dt.deduction_name = 'PERAA Loan') AS peraa_loan,

                                        (SELECT SUM(pd.amount) FROM payroll_deductions pd 
                                        JOIN deduction_types dt ON pd.deduction_type_id = dt.deduction_type_id
                                        WHERE pd.payroll_id = pr.payroll_id AND dt.deduction_name = 'HDMF Premium') AS hdmf_premium,

                                        (SELECT SUM(pd.amount) FROM payroll_deductions pd 
                                        JOIN deduction_types dt ON pd.deduction_type_id = dt.deduction_type_id
                                        WHERE pd.payroll_id = pr.payroll_id AND dt.deduction_name = 'HDMF Loan') AS hdmf_loan,

                                        (SELECT SUM(pd.amount) FROM payroll_deductions pd 
                                        JOIN deduction_types dt ON pd.deduction_type_id = dt.deduction_type_id
                                        WHERE pd.payroll_id = pr.payroll_id AND dt.deduction_name = 'PhilHealth') AS philhealth,

                                        (SELECT SUM(pd.amount) FROM payroll_deductions pd 
                                        JOIN deduction_types dt ON pd.deduction_type_id = dt.deduction_type_id
                                        WHERE pd.payroll_id = pr.payroll_id AND dt.deduction_name = 'SSS Premium') AS sss_premium,

                                        (SELECT SUM(pd.amount) FROM payroll_deductions pd 
                                        JOIN deduction_types dt ON pd.deduction_type_id = dt.deduction_type_id
                                        WHERE pd.payroll_id = pr.payroll_id AND dt.deduction_name = 'SSS Loan') AS sss_loan

                                    FROM payroll_records pr
                                    JOIN employees e ON pr.employee_id = e.employee_id
                                    LEFT JOIN positions p ON e.position_id = p.position_id
                                    LEFT JOIN departments d ON e.department_id = d.department_id
                                ");

                                $totalGross = 0;
                                $totalDed = 0;
                                $totalNet = 0;

                                while ($row = $stmt->fetch()):
                                    $totalGross += $row['gross_pay'];
                                    $totalDed += $row['total_deductions'];
                                    $totalNet += $row['net_pay'];
                                ?>
                                <tr>
                                    <td>
                                        <strong><?= $row['first_name'].' '.$row['last_name'] ?></strong><br>
                                        <small><?= $row['position_name'] ?></small>
                                    </td>

                                    <td>EMP-<?= str_pad($row['employee_id'], 4, '0', STR_PAD_LEFT) ?></td>

                                    <td>₱<?= number_format($row['basic_pay'],2) ?></td>
                                    <td>₱<?= number_format($row['assign'] ?? 0,2) ?></td>
                                    <td>₱<?= number_format($row['rice'] ?? 0,2) ?></td>
                                    <td>₱<?= number_format($row['laundry'] ?? 0,2) ?></td>

                                    <td><strong>₱<?= number_format($row['gross_pay'],2) ?></strong></td>
                                    <td>₱<?= number_format($row['total_deductions'],2) ?></td>
                                    <td><strong>₱<?= number_format($row['net_pay'],2) ?></strong></td>

                                    <td><span class="badge"><?= $row['payroll_status'] ?></span></td>

                                    <td>
                                        <!-- VIEW -->
                                        <button class="btn-view" onclick="openPayslip(this)"
                                            data-name="<?= $row['first_name'].' '.$row['last_name'] ?>"
                                            data-position="<?= $row['position_name'] ?>"
                                            data-dept="<?= $row['department_name'] ?? 'N/A' ?>"
                                            data-empid="<?= $row['employee_id'] ?>"

                                            data-basic="<?= $row['basic_pay'] ?>"
                                            data-assign="<?= $row['assign'] ?? 0 ?>"
                                            data-rice="<?= $row['rice'] ?? 0 ?>"
                                            data-laundry="<?= $row['laundry'] ?? 0 ?>"

                                            data-peraa-premium="<?= $row['peraa_premium'] ?? 0 ?>"
                                            data-peraa-loan="<?= $row['peraa_loan'] ?? 0 ?>"
                                            data-hdmf-premium="<?= $row['hdmf_premium'] ?? 0 ?>"
                                            data-hdmf-loan="<?= $row['hdmf_loan'] ?? 0 ?>"
                                            data-philhealth="<?= $row['philhealth'] ?? 0 ?>"
                                            data-sss-premium="<?= $row['sss_premium'] ?? 0 ?>"
                                            data-sss-loan="<?= $row['sss_loan'] ?? 0 ?>">
                                            <i class="fa fa-eye"></i>
                                        </button>

                                        <!-- EDIT -->
                                        <button class="btn-edit" onclick="openEdit(this)"
                                            data-payroll-id="<?= $row['payroll_id'] ?>"
                                            data-basic="<?= $row['basic_pay'] ?>"
                                            data-assign="<?= $row['assign'] ?? 0 ?>"
                                            data-rice="<?= $row['rice'] ?? 0 ?>"
                                            data-laundry="<?= $row['laundry'] ?? 0 ?>"

                                            data-peraa-premium="<?= $row['peraa_premium'] ?? 0 ?>"
                                            data-peraa-loan="<?= $row['peraa_loan'] ?? 0 ?>"
                                            data-hdmf-premium="<?= $row['hdmf_premium'] ?? 0 ?>"
                                            data-hdmf-loan="<?= $row['hdmf_loan'] ?? 0 ?>"
                                            data-philhealth="<?= $row['philhealth'] ?? 0 ?>"
                                            data-sss-premium="<?= $row['sss_premium'] ?? 0 ?>"
                                            data-sss-loan="<?= $row['sss_loan'] ?? 0 ?>">
                                            <i class="fa fa-pen"></i>
                                        </button>
                                    </td>
                                </tr>
                                <?php endwhile; ?>
                                </tbody>
                        </table>
                    </div>

                    <!-- TOTALS -->
                    <div class="totals">
                        <div class="total-box">
                            <span>Total Gross Pay</span>
                            <strong>₱<?= number_format($totalGross, 2) ?></strong>  <!-- was ₱155,500 -->
                        </div>

                        <div class="total-box">
                            <span>Total Deductions</span>
                            <strong class="text-red">₱<?= number_format($totalDed, 2) ?></strong>  <!-- was ₱12,019 -->
                        </div>

                        <div class="total-box">
                            <span>Total Net Payroll</span>
                            <strong class="text-green">₱<?= number_format($totalNet, 2) ?></strong>  <!-- was ₱143,481 -->
                        </div>

                        <button class="btn-primary submit-btn">
                            Submit to Principal for Approval
                        </button>
                    </div>

                </div>
            </div>
        </div>
    </div>

<?php include __DIR__ . '/modals/view-modal.php'; ?>
<?php include __DIR__ . '/modals/edit-modal.php'; ?>
<?php include __DIR__ . '/modals/generate-modal.php'; ?>

<!-- JS -->
<script src="<?= BASE_URL ?>assets/js/payroll.js?v=<?= time() ?>"></script>
<?php include __DIR__ . '/../../includes/footer.php'; ?>