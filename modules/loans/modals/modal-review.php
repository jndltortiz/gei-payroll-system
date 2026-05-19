<?php
/** modal-review.php — included by modules/loans/index.php */
?>
<!-- REVIEW APPLICATION MODAL -->
<div class="loan-modal-overlay" id="reviewOverlay">
  <div class="loan-modal-box">
    <div class="loan-modal-header">
      <h3>Review Loan Application</h3>
      <button onclick="closeReview()"><i class="fa fa-times"></i></button>
    </div>
    <div class="loan-modal-body" id="reviewBody">
      <div class="loading-state"><i class="fa fa-spinner fa-spin"></i> Loading…</div>
    </div>
  </div>
</div>