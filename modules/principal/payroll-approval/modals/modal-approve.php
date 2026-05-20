<!-- ── Approve Confirmation Modal ────────────────────────────────────────── -->
<div class="pr-modal-overlay" id="approveOverlay">
  <div class="pr-modal-box pr-modal-box--sm">
    <div class="pr-confirm-icon">
      <i class="fa fa-circle-check" style="color:#0f766e;font-size:32px;"></i>
    </div>
    <h3 class="pr-confirm-title">Approve &amp; Authorize Payroll</h3>
    <p class="pr-confirm-desc" id="approveDesc"></p>
    <div class="pr-confirm-actions">
      <button class="pr-btn-cancel" onclick="closeApprove()">Cancel</button>
      <button class="pr-btn-approve-confirm" id="approveConfirmBtn" onclick="submitApprove()">
        <i class="fa fa-circle-check"></i> Yes, Approve &amp; Authorize
      </button>
    </div>
  </div>
</div>