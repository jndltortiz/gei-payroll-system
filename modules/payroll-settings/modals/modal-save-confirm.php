<!-- Modal: Save Payroll Settings Confirmation -->
<div class="modal fade" id="modalSaveConfirm" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width:460px;">
        <div class="modal-content ps-modal">
            <div class="ps-modal-body text-center py-4 px-4">
                <div class="ps-save-icon-wrap mb-3">
                    <i class="bi bi-floppy2-fill" style="color:#0d9488;font-size:22px;"></i>
                </div>
                <h5 class="fw-bold mb-2" style="color:#0f172a;">Save Payroll Settings</h5>
                <p class="text-muted mb-0" style="font-size:13.5px;">
                    Are you sure you want to update payroll settings?
                    These changes will affect future payroll computations.
                </p>
            </div>
            <div class="ps-modal-footer justify-content-center gap-2 pb-4">
                <button type="button" class="ps-btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="ps-btn-primary" id="btnConfirmSave" onclick="submitPayrollSettings()">
                    <i class="bi bi-check-lg"></i> Confirm Save
                </button>
            </div>
        </div>
    </div>
</div>