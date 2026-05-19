<?php
/** modal-details.php — included by modules/loans/index.php */
?>
<!-- LOAN DETAILS MODAL -->
<div class="loan-modal-overlay" id="detailsOverlay">
  <div class="loan-modal-box">
    <div class="loan-modal-header">
      <h3>Loan Details</h3>
      <button onclick="closeDetails()"><i class="fa fa-times"></i></button>
    </div>
    <div class="loan-modal-body" id="detailsBody">
      <div class="loading-state"><i class="fa fa-spinner fa-spin"></i> Loading…</div>
    </div>
  </div>
</div>