<!-- Modal: Edit Deduction -->
<div class="modal fade" id="modalEditDeduction" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content ps-modal">
            <div class="ps-modal-header">
                <h5 class="ps-modal-title">Edit Deduction</h5>
                <button type="button" class="ps-modal-close" data-bs-dismiss="modal"><i class="bi bi-x-lg"></i></button>
            </div>
            <form id="formEditDeduction" onsubmit="submitEditDeduction(event)">
                <input type="hidden" name="deduction_type_id" id="editDedId">
                <div class="ps-modal-body">
                    <div class="ps-form-group">
                        <label class="ps-form-label">Deduction Name <span class="text-danger">*</span></label>
                        <input type="text" class="ps-form-input" name="deduction_name" id="editDedName" required>
                    </div>
                    <div class="ps-form-group mt-3">
                        <label class="ps-form-label">Type</label>
                        <div class="ps-type-toggle-large" id="editDedTypeTgl">
                            <button type="button" class="ps-type-btn-lg" data-type="FIXED" onclick="setDedType('edit','FIXED')">
                                <i class="bi bi-currency-dollar"></i> Fixed (₱)
                            </button>
                            <button type="button" class="ps-type-btn-lg" data-type="PERCENTAGE" onclick="setDedType('edit','PERCENTAGE')">
                                <i class="bi bi-percent"></i> Percentage (%)
                            </button>
                        </div>
                        <input type="hidden" name="deduction_value_type" id="editDedType" value="FIXED">
                    </div>
                    <div class="ps-form-group mt-3">
                        <label class="ps-form-label" id="editDedAmtLabel">Amount (₱) <span class="text-danger">*</span></label>
                        <input type="number" class="ps-form-input" name="deduction_amount" id="editDedAmount"
                               step="0.01" min="0" required>
                    </div>
                    <div class="ps-form-group mt-3">
                        <label class="ps-form-label">Status</label>
                        <select class="ps-form-select" name="is_active" id="editDedStatus">
                            <option value="1">Active</option>
                            <option value="0">Inactive</option>
                        </select>
                    </div>
                    <div class="ps-form-hint mt-2">
                        <i class="bi bi-info-circle"></i>
                        To change who this applies to, use the <strong>Assign</strong> button in the table.
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