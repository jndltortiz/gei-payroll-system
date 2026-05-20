<?php // modules/principal/leave-approval/modals/reject-reason-modal.php ?>
<div class="modal-overlay" id="rejectModal" role="dialog" aria-modal="true">
    <div class="modal-box modal-sm">
        <div class="modal-header">
            <h3 class="modal-title">Reject Leave Date</h3>
            <button class="modal-close" data-close="rejectModal"><i data-lucide="x"></i></button>
        </div>
        <div class="modal-body">
            <div class="confirm-icon confirm-reject-icon">
                <i data-lucide="x-circle"></i>
            </div>
            <p class="confirm-msg" id="rejectMsg">You are rejecting this leave date.</p>
            <div class="form-group" style="margin-top:1rem;">
                <label class="form-label" for="rejectRemarks">Reason for rejection <span class="optional">(optional)</span></label>
                <textarea id="rejectRemarks" class="form-control" rows="3"
                          placeholder="Provide a brief reason (optional)..."></textarea>
            </div>
        </div>
        <div class="modal-footer">
            <button class="btn-secondary" data-close="rejectModal">Cancel</button>
            <button class="btn-danger" id="rejectOkBtn">
                <i data-lucide="x-circle"></i> Reject
            </button>
        </div>
    </div>
</div>