<?php // modules/principal/leave-approval/modals/confirm-action-modal.php ?>
<div class="modal-overlay" id="confirmModal" role="dialog" aria-modal="true">
    <div class="modal-box modal-sm">
        <div class="modal-header">
            <h3 class="modal-title" id="confirmTitle">Confirm Action</h3>
            <button class="modal-close" data-close="confirmModal"><i data-lucide="x"></i></button>
        </div>
        <div class="modal-body">
            <div class="confirm-icon confirm-approve-icon">
                <i data-lucide="check-circle-2"></i>
            </div>
            <p class="confirm-msg" id="confirmMsg">Are you sure you want to approve this?</p>
        </div>
        <div class="modal-footer">
            <button class="btn-secondary" data-close="confirmModal">Cancel</button>
            <button class="btn-confirm-ok btn-primary" id="confirmOkBtn">Confirm</button>
        </div>
    </div>
</div>