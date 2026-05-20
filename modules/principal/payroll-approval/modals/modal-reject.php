<!-- ── Reject / Return Modal ─────────────────────────────────────────────── -->
<div class="pr-modal-overlay" id="rejectOverlay">
  <div class="pr-modal-box pr-modal-box--sm">
    <div class="pr-modal-header">
      <h3>Reject Payroll</h3>
      <button onclick="closeReject()" title="Close"><i class="fa fa-times"></i></button>
    </div>
    <div class="pr-modal-body">
      <div class="pr-reject-info">
        <i class="fa fa-triangle-exclamation"></i>
        Please provide remarks explaining why the payroll is being returned.
        This will be sent to the HR Admin for corrections.
      </div>
      <div style="margin-top:16px;">
        <label style="font-size:13px;font-weight:600;color:#374151;display:block;margin-bottom:6px;">
          Remarks <span style="color:#ef4444;">*</span>
        </label>
        <textarea id="rejectRemarks" class="pr-reject-textarea" rows="4"
                  placeholder="Describe the issues that need to be corrected before resubmission…"></textarea>
        <div id="rejectError" style="display:none;color:#ef4444;font-size:12px;margin-top:4px;">
          <i class="fa fa-circle-exclamation"></i> Please enter a reason for rejection.
        </div>
      </div>
    </div>
    <div class="pr-modal-footer">
      <button class="pr-btn-cancel" onclick="closeReject()">Cancel</button>
      <button class="pr-btn-reject-confirm" id="rejectSubmitBtn" onclick="submitReject()">
        <i class="fa fa-circle-xmark"></i> Submit Rejection
      </button>
    </div>
  </div>
</div>