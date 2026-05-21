<!-- EDIT MODAL -->
<div id="editModal" class="modal">

    <form class="modal-box edit-box" method="POST" action="<?= BASE_URL ?>actions/payroll-update.php">

        <input type="hidden" id="edit-empid"    name="employee_id">
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

        <!-- BASIC PAY (always present, not from allowance_types) -->
        <h4 class="section-title">BASIC PAY</h4>
        <div class="form-grid" style="grid-template-columns:1fr 1fr;">
            <div>
                <label>Basic Salary</label>
                <input type="number" id="edit-basic" name="basic" step="0.01" min="0">
            </div>
        </div>

        <!-- ALLOWANCES — rendered dynamically by openEdit() in payroll.js -->
        <h4 class="section-title">ALLOWANCES &amp; ASSIGNMENTS</h4>
        <div class="form-grid" id="edit-allowances-container">
            <!-- Populated by JS -->
        </div>

        <!-- DEDUCTIONS — rendered dynamically by openEdit() in payroll.js -->
        <h4 class="section-title">DEDUCTIONS</h4>
        <div class="form-grid" id="edit-deductions-container">
            <!-- Populated by JS -->
        </div>

        <!-- COMPUTED TOTALS -->
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
