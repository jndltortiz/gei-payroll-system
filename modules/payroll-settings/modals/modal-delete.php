<!-- Modal: Delete Confirmation -->
<div class="modal fade" id="modalDelete" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered" style="max-width:440px;">
        <div class="modal-content ps-modal">
            <div class="ps-modal-body text-center py-4 px-4">
                <div class="ps-delete-icon-wrap mb-3">
                    <i class="bi bi-exclamation-triangle-fill text-danger" style="font-size:22px;"></i>
                </div>
                <h5 class="fw-bold mb-2" id="deleteModalTitle" style="color:#0f172a;">Delete Item</h5>
                <p class="text-muted mb-0" id="deleteModalBody" style="font-size:13.5px;">
                    Are you sure you want to delete this item?
                </p>
            </div>
            <div class="ps-modal-footer justify-content-center gap-2 pb-4">
                <button type="button" class="ps-btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="ps-btn-danger" id="btnConfirmDelete">
                    <i class="bi bi-trash"></i> Delete
                </button>
            </div>
        </div>
    </div>
</div>