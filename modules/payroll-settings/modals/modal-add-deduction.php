<!-- Modal: Add Deduction -->
<div class="modal fade" id="modalAddDeduction" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content ps-modal">
            <div class="ps-modal-header">
                <h5 class="ps-modal-title">Add Deduction</h5>
                <button type="button" class="ps-modal-close" data-bs-dismiss="modal"><i class="bi bi-x-lg"></i></button>
            </div>
            <form id="formAddDeduction" onsubmit="submitAddDeduction(event)">
                <div class="ps-modal-body">
                    <div class="ps-form-group">
                        <label class="ps-form-label">Deduction Name <span class="text-danger">*</span></label>
                        <input type="text" class="ps-form-input" name="deduction_name" id="addDedName"
                               placeholder="e.g. PERAA Premium" required>
                    </div>
                    <div class="ps-form-group mt-3">
                        <label class="ps-form-label">Type</label>
                        <div class="ps-type-toggle-large" id="addDedTypeTgl">
                            <button type="button" class="ps-type-btn-lg active" data-type="FIXED" onclick="setDedType('add','FIXED')">
                                <i class="bi bi-currency-dollar"></i> Fixed (₱)
                            </button>
                            <button type="button" class="ps-type-btn-lg" data-type="PERCENTAGE" onclick="setDedType('add','PERCENTAGE')">
                                <i class="bi bi-percent"></i> Percentage (%)
                            </button>
                        </div>
                        <input type="hidden" name="deduction_value_type" id="addDedType" value="FIXED">
                    </div>
                    <div class="ps-form-group mt-3">
                        <label class="ps-form-label" id="addDedAmtLabel">Amount (₱) <span class="text-danger">*</span></label>
                        <input type="number" class="ps-form-input" name="deduction_amount" id="addDedAmount"
                               placeholder="0.00" step="0.01" min="0" required>
                    </div>
                    <div class="ps-form-group mt-3">
                        <label class="ps-form-label">Applies To</label>
                        <select class="ps-form-select" name="applies_to" id="addDedAppliesTo">
                            <option value="ALL">All Employees</option>
                            <option value="DEPARTMENT">By Department</option>
                            <option value="POSITION">By Position</option>
                            <option value="EMPLOYEE">Selected Employees</option>
                        </select>
                        <div class="ps-form-hint"><i class="bi bi-info-circle"></i> Use "Assign" button after saving to select specific employees, departments, or positions.</div>
                    </div>
                    <div class="ps-form-group mt-3">
                        <label class="ps-form-label">Status</label>
                        <select class="ps-form-select" name="is_active" id="addDedStatus">
                            <option value="1">Active</option>
                            <option value="0">Inactive</option>
                        </select>
                    </div>
                </div>
                <div class="ps-modal-footer">
                    <button type="button" class="ps-btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="ps-btn-primary">Save</button>
                </div>
            </form>
        </div>
    </div>
</div>