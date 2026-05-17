<!-- EDIT MODAL -->
<div id="editModal" class="modal">

    <form class="modal-box edit-box" method="POST" action="<?= BASE_URL ?>actions/payroll-update.php">

        <input type="hidden" id="edit-empid" name="employee_id">
        <input type="hidden" id="edit-payrollid" name="payroll_id">

        <!-- HEADER -->
        <div class="modal-header">
            <h3>Edit Payroll — <span id="edit-name"></span></h3>
            <span class="close" onclick="closeEdit()">&times;</span>
        </div>

        <!-- POSITION + DEPT -->
        <div class="info-banner">
            <strong>Position:</strong> <span id="edit-position"></span>
            &nbsp; | &nbsp;
            <strong>Department:</strong> <span id="edit-dept"></span>
        </div>

        <!-- ALLOWANCES -->
        <h4 class="section-title">ALLOWANCES & ASSIGNMENTS</h4>

        <div class="form-grid">
            <div>
                <label>Basic Salary</label>
                <input type="number" id="edit-basic" name="basic">
            </div>

            <div>
                <label>Additional Assignment Pay</label>
                <input type="number" id="edit-assign" name="assign">
            </div>

            <div>
                <label>Rice Subsidy</label>
                <input type="number" id="edit-rice" name="rice">
            </div>

            <div>
                <label>Laundry Allowance</label>
                <input type="number" id="edit-laundry" name="laundry">
            </div>
        </div>

        <!-- DEDUCTIONS -->
        <h4 class="section-title">DEDUCTIONS</h4>

        <div class="form-grid">
            <div>
                <label>PERAA Premium</label>
                <input type="number" id="edit-peraa-premium" name="peraa_premium">
            </div>

            <div>
                <label>PERAA Loan</label>
                <input type="number" id="edit-peraa-loan" name="peraa_loan">
            </div>

            <div>
                <label>HDMF Premium</label>
                <input type="number" id="edit-hdmf-premium" name="hdmf_premium">
            </div>

            <div>
                <label>HDMF Loan</label>
                <input type="number" id="edit-hdmf-loan" name="hdmf_loan">
            </div>

            <div>
                <label>PhilHealth</label>
                <input type="number" id="edit-philhealth" name="philhealth">
            </div>

            <div>
                <label>SSS Premium</label>
                <input type="number" id="edit-sss-premium" name="sss_premium">
            </div>

            <div>
                <label>SSS Loan</label>
                <input type="number" id="edit-sss-loan" name="sss_loan">
            </div>
        </div>

        <!-- COMPUTED -->
        <div class="computed-box">
            <div>
                <small>Gross Pay</small>
                <strong id="edit-gross"></strong>
            </div>

            <div>
                <small>Total Deductions</small>
                <strong class="text-red" id="edit-totalded"></strong>
            </div>

            <div>
                <small>Net Pay</small>
                <strong id="edit-net"></strong>
            </div>
        </div>

        <!-- ACTIONS -->
        <div class="modal-actions">
            <button type="button" class="btn-outline" onclick="closeEdit()">Cancel</button>
            <button type="submit" class="btn-primary">Apply Changes</button>
        </div>

    </form>
</div>

