<?php /** modal-reject.php — Rejection reason */ ?>
<div class="sc-modal-overlay" id="rejectModal" style="display:none;">
  <div class="sc-modal-box sc-modal-box--sm">
    <div class="sc-modal-header">
      <h3><i class="fa fa-circle-xmark" style="color:#ef4444"></i> Reject Service Credit</h3>
      <button onclick="closeModal('rejectModal')"><i class="fa fa-times"></i></button>
    </div>
    <form method="POST" action="<?= BASE_URL ?>actions/service-credits-action.php">
      <input type="hidden" name="action" value="reject">
      <input type="hidden" name="service_credit_id" id="rejectScId" value="">
      <div class="sc-modal-body">
        <div class="sc-reject-info">
          <i class="fa fa-triangle-exclamation"></i>
          Rejecting service credit for <strong id="rejectEmpName"></strong>.
          Please provide a reason — the creator will see this.
        </div>
        <div class="sc-form-group" style="margin-top:14px;">
          <label>Rejection Reason <span class="req">*</span></label>
          <textarea name="rejection_reason" id="rejectReason" rows="3"
                    placeholder="State why this credit is being rejected…"
                    required maxlength="500"></textarea>
        </div>
      </div>
      <div class="sc-modal-footer">
        <button type="button" class="sc-btn-ghost" onclick="closeModal('rejectModal')">Cancel</button>
        <button type="submit" class="sc-btn-danger">
          <i class="fa fa-circle-xmark"></i> Submit Rejection
        </button>
      </div>
    </form>
  </div>
</div>