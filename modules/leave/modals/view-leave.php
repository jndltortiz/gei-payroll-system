<!-- VIEW LEAVE DETAILS MODAL -->
<div class="modal-overlay" id="viewLeaveOverlay" style="display:none;" onclick="closeViewModal(event)">
    <div class="modal-box modal-box--lg" onclick="event.stopPropagation()">

        <div class="modal-header">
            <h3 class="modal-title">Leave Request Details</h3>
            <button class="modal-close" onclick="closeViewLeaveModal()">×</button>
        </div>

        <div class="modal-body" id="viewLeaveBody">
            <div class="modal-loading">
                <i class="fa fa-spinner fa-spin"></i> Loading...
            </div>
        </div>

        <div class="modal-footer">
            <button class="btn btn--ghost" onclick="closeViewLeaveModal()">Close</button>
        </div>

    </div>
</div>