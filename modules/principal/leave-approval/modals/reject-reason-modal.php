<!-- ── Reject with Reason Modal ───────────────────────────────────────────── -->
<div class="pr-modal-overlay" id="rejectModal">
  <div class="pr-modal-box pr-modal-box--sm">
    <div class="pr-modal-header">
      <h3>Reject Leave Date</h3>
      <button onclick="closeModal('rejectModal')" title="Close"><i class="fa fa-times"></i></button>
    </div>
    <div class="pr-modal-body">
      <div class="lv-confirm-icon lv-confirm-icon--reject">
        <i class="fa fa-circle-xmark"></i>
      </div>
      <p class="pr-confirm-desc" id="rejectMsg" style="margin-bottom:14px;">
        You are rejecting this leave date.
      </p>
      <div class="lv-form-group">
        <label class="lv-form-label" for="rejectRemarks">
          Reason for rejection <span class="opt">(optional)</span>
        </label>
        <textarea id="rejectRemarks" class="lv-form-control" rows="3"
                  placeholder="Provide a brief reason (optional)..."></textarea>
      </div>
    </div>
    <div class="pr-modal-footer">
      <button class="pr-btn-cancel" onclick="closeModal('rejectModal')">Cancel</button>
      <button class="pr-btn-reject-confirm" id="rejectOkBtn">
        <i class="fa fa-circle-xmark"></i> Reject
      </button>
    </div>
  </div>
</div>