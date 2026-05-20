<?php /** modal-delete.php — Delete draft confirmation */ ?>
<div class="sc-modal-overlay" id="deleteModal" style="display:none;">
  <div class="sc-modal-box sc-modal-box--sm">
    <div class="sc-modal-header">
      <h3>Delete Draft?</h3>
      <button onclick="closeModal('deleteModal')"><i class="fa fa-times"></i></button>
    </div>
    <div class="sc-modal-body sc-modal-body--center">
      <div class="sc-delete-icon"><i class="fa fa-triangle-exclamation"></i></div>
      <p id="deleteDesc" style="font-size:14px;color:#374151;margin:0;"></p>
      <p style="font-size:12px;color:#94a3b8;margin-top:4px;">This cannot be undone.</p>
    </div>
    <form method="POST" action="<?= BASE_URL ?>actions/service-credits-action.php">
      <input type="hidden" name="action" value="delete">
      <input type="hidden" name="service_credit_id" id="deleteScId" value="">
      <div class="sc-modal-footer">
        <button type="button" class="sc-btn-ghost" onclick="closeModal('deleteModal')">Cancel</button>
        <button type="submit" class="sc-btn-danger"><i class="fa fa-trash"></i> Delete</button>
      </div>
    </form>
  </div>
</div>