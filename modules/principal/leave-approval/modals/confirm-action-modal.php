<!-- ── Confirm Approve Modal ───────────────────────────────────────────────── -->
<div class="pr-modal-overlay" id="confirmModal">
  <div class="pr-modal-box pr-modal-box--sm">
    <div class="lv-confirm-icon lv-confirm-icon--approve">
      <i class="fa fa-circle-check"></i>
    </div>
    <h3 class="pr-confirm-title" id="confirmTitle">Confirm Approval</h3>
    <p class="pr-confirm-desc" id="confirmMsg">Are you sure you want to approve this?</p>
    <div class="pr-confirm-actions">
      <button class="pr-btn-cancel" onclick="closeModal('confirmModal')">Cancel</button>
      <button class="pr-btn-approve-confirm" id="confirmOkBtn">
        <i class="fa fa-circle-check"></i> Confirm
      </button>
    </div>
  </div>
</div>