<!-- CONFIRM ACTION MODAL (Approve/Submit confirmation) -->
<div class="modal-overlay" id="confirmOverlay" style="display:none;" onclick="closeConfirmModal(event)">
    <div class="modal-box modal-box--sm" onclick="event.stopPropagation()">

        <div class="modal-body modal-body--confirm">
            <div class="confirm-icon">
                <i class="fa fa-circle-check" id="confirmIcon"></i>
            </div>
            <h3 class="confirm-title" id="confirmTitle">Submit Leave Request</h3>
            <p class="confirm-desc" id="confirmDesc">Are you sure you want to proceed?</p>
        </div>

        <div class="modal-footer modal-footer--center">
            <button class="btn btn--ghost" onclick="closeConfirmModal()">Cancel</button>
            <button class="btn btn--teal" id="confirmBtn" onclick="executeConfirm()">Yes, Submit</button>
        </div>

    </div>
</div>