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

        <!-- POSITION + DEPT + EMPLOYEE NO -->
        <div class="info-banner" style="display:flex;flex-wrap:wrap;gap:10px;align-items:center;">
            <span><strong>ID:</strong> <code id="edit-empno-display" style="font-size:12px;color:#374151;"></code></span>
            <span style="color:#d1d5db;">|</span>
            <span><strong>Position:</strong> <span id="edit-position"></span></span>
            <span style="color:#d1d5db;">|</span>
            <span><strong>Dept:</strong> <span id="edit-dept"></span></span>
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
        <!-- One-time allowance additions for this period only -->
        <div id="edit-new-allowances"></div>
        <div style="margin:8px 0 4px;">
            <button type="button" onclick="addAllowanceAdjustment()"
                    style="width:100%;padding:7px 14px;font-size:12px;font-weight:600;
                           border:1.5px dashed #10b981;border-radius:8px;background:#f0fdf4;
                           color:#059669;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:6px;">
                <i class="fa fa-plus"></i> Add One-Time Allowance
            </button>
        </div>

        <!-- DEDUCTIONS — rendered dynamically by openEdit() in payroll.js -->
        <h4 class="section-title">DEDUCTIONS</h4>
        <div class="form-grid" id="edit-deductions-container">
            <!-- Populated by JS -->
        </div>
        <!-- One-time deduction additions for this period only -->
        <div id="edit-new-deductions"></div>
        <div style="margin:8px 0 4px;">
            <button type="button" onclick="addDeductionAdjustment()"
                    style="width:100%;padding:7px 14px;font-size:12px;font-weight:600;
                           border:1.5px dashed #ef4444;border-radius:8px;background:#fef2f2;
                           color:#dc2626;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:6px;">
                <i class="fa fa-plus"></i> Add One-Time Deduction
            </button>
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
