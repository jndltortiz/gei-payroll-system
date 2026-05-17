<!-- Modal: Add Allowance -->
<div class="modal fade" id="modalAddAllowance" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content ps-modal">
            <div class="ps-modal-header">
                <h5 class="ps-modal-title">Add Allowance</h5>
                <button type="button" class="ps-modal-close" data-bs-dismiss="modal"><i class="bi bi-x-lg"></i></button>
            </div>
            <form id="formAddAllowance" onsubmit="submitAddAllowance(event)">
                <div class="ps-modal-body">
                    <div class="ps-form-group">
                        <label class="ps-form-label">Allowance Name <span class="text-danger">*</span></label>
                        <input type="text" class="ps-form-input" name="allowance_name" id="addAlName"
                               placeholder="e.g. Rice Subsidy" required>
                    </div>
                    <div class="ps-form-group mt-3">
                        <label class="ps-form-label">Default Amount (₱) <span class="text-danger">*</span></label>
                        <input type="number" class="ps-form-input" name="default_amount" id="addAlAmount"
                               placeholder="0.00" step="0.01" min="0" required>
                    </div>
                    <div class="ps-form-group mt-3">
                        <label class="ps-form-label">Taxable?</label>
                        <select class="ps-form-select" name="is_taxable" id="addAlTaxable">
                            <option value="0">No (Non-taxable)</option>
                            <option value="1">Yes (Taxable)</option>
                        </select>
                    </div>
                    <div class="ps-form-group mt-3">
                        <label class="ps-form-label">Applies To</label>
                        <select class="ps-form-select" name="applies_to" id="addAlAppliesTo">
                            <option value="ALL">All Employees</option>
                            <option value="DEPARTMENT">By Department</option>
                            <option value="POSITION">By Position</option>
                            <option value="EMPLOYEE">Selected Employees</option>
                        </select>
                        <div class="ps-form-hint"><i class="bi bi-info-circle"></i> Use "Assign" button after saving to select specific employees, departments, or positions.</div>
                    </div>
                    <div class="ps-form-group mt-3">
                        <label class="ps-form-label">Status</label>
                        <select class="ps-form-select" name="is_active" id="addAlStatus">
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