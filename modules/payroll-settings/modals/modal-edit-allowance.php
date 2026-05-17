<!-- Modal: Edit Allowance -->
<div class="modal fade" id="modalEditAllowance" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content ps-modal">
            <div class="ps-modal-header">
                <h5 class="ps-modal-title">Edit Allowance</h5>
                <button type="button" class="ps-modal-close" data-bs-dismiss="modal"><i class="bi bi-x-lg"></i></button>
            </div>
            <form id="formEditAllowance" onsubmit="submitEditAllowance(event)">
                <input type="hidden" name="allowance_type_id" id="editAlId">
                <div class="ps-modal-body">
                    <div class="ps-form-group">
                        <label class="ps-form-label">Allowance Name <span class="text-danger">*</span></label>
                        <input type="text" class="ps-form-input" name="allowance_name" id="editAlName" required>
                    </div>
                    <div class="ps-form-group mt-3">
                        <label class="ps-form-label">Default Amount (₱) <span class="text-danger">*</span></label>
                        <input type="number" class="ps-form-input" name="default_amount" id="editAlAmount"
                               step="0.01" min="0" required>
                    </div>
                    <div class="ps-form-group mt-3">
                        <label class="ps-form-label">Taxable?</label>
                        <select class="ps-form-select" name="is_taxable" id="editAlTaxable">
                            <option value="0">No (Non-taxable)</option>
                            <option value="1">Yes (Taxable)</option>
                        </select>
                    </div>
                    <div class="ps-form-group mt-3">
                        <label class="ps-form-label">Status</label>
                        <select class="ps-form-select" name="is_active" id="editAlStatus">
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