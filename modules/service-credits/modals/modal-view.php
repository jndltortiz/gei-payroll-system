<?php /** modal-view.php — View service credit details + activity history */ ?>
<div class="sc-modal-overlay" id="viewModal" style="display:none;">
  <div class="sc-modal-box sc-modal-box--lg">
    <div class="sc-modal-header">
      <h3><i class="fa fa-eye"></i> Service Credit Details</h3>
      <button onclick="closeModal('viewModal')"><i class="fa fa-times"></i></button>
    </div>
    <div class="sc-modal-body" id="viewModalBody">
      <div class="sc-loading"><i class="fa fa-spinner fa-spin"></i> Loading…</div>
    </div>
    <div class="sc-modal-footer">
      <button type="button" class="sc-btn-ghost" onclick="closeModal('viewModal')">Close</button>
    </div>
  </div>
</div>
