<!-- DENY REASON MODAL -->
<div class="modal-overlay" id="denyReasonOverlay" style="display:none;" onclick="closeDenyModal(event)">
    <div class="modal-box modal-box--sm" onclick="event.stopPropagation()">

        <div class="modal-header">
            <h3 class="modal-title">Deny Leave Request</h3>
            <button class="modal-close" onclick="closeDenyModal()">×</button>
        </div>

        <div class="modal-body">
            <p class="deny-desc">Please provide a reason for denial. This will be visible to the employee.</p>
            <div class="form-group">
                <label class="form-label">Reason for Denial <span class="required">*</span></label>
                <textarea class="form-textarea" id="denyReasonText" rows="3"
                    placeholder="Enter reason..."></textarea>
                <span class="form-error" id="denyReasonError" style="display:none;">
                    Reason is required.
                </span>
            </div>
        </div>

        <div class="modal-footer">
            <button class="btn btn--ghost" onclick="closeDenyModal()">Cancel</button>
            <button class="btn btn--danger" onclick="submitDeny()">
                <i class="fa fa-times-circle"></i> Deny Request
            </button>
        </div>

    </div>
</div>