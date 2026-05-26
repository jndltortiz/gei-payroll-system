<!-- FORWARD TO PRINCIPAL MODAL -->
<div class="modal-overlay" id="forwardOverlay" style="display:none;" onclick="closeForwardModal(event)">
    <div class="modal-box modal-box--md" onclick="event.stopPropagation()">

        <div class="modal-header">
            <h3 class="modal-title">
                <i class="fa fa-paper-plane" style="color:#2563eb;margin-right:6px;"></i>
                Forward to Principal
            </h3>
            <button class="modal-close" onclick="closeForwardModalDirect()">×</button>
        </div>

        <div class="modal-body">
            <div id="forwardEmpName" style="font-size:14px;font-weight:600;color:#0f172a;margin-bottom:4px;"></div>
            <p style="font-size:12px;color:#64748b;margin:0 0 16px;">
                This leave request will be moved to the principal's queue for per-date approval.
                Add an optional internal note before forwarding.
            </p>

            <div class="form-group">
                <label class="form-label">
                    Admin Note
                    <span style="font-size:11px;font-weight:400;color:#9ca3af;">(optional — visible to admin only)</span>
                </label>
                <textarea class="form-textarea" id="forwardNote" rows="3"
                    placeholder="e.g. Documentation verified. Sick leave with attached medical certificate."></textarea>
            </div>
        </div>

        <div class="modal-footer">
            <button class="btn btn--ghost" onclick="closeForwardModalDirect()">Cancel</button>
            <button class="btn btn--primary" id="btnConfirmForward" onclick="confirmForward()">
                <i class="fa fa-paper-plane"></i> Forward to Principal
            </button>
        </div>

    </div>
</div>
