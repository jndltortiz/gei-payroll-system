<?php
/**
 * includes/footer.php
 * Closes <body> and <html>. Include at the bottom of every page.
 * Bootstrap JS is loaded here only when $loadBootstrap was set to true.
 */
?>
<?php if (!empty($loadBootstrap)): ?>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<?php endif; ?>

<!-- Global Confirm Modal (powered by gei-ui.js) -->
<div id="gei-confirm-modal" role="dialog" aria-modal="true" aria-labelledby="gei-confirm-title-el">
  <div class="gei-confirm-box">
    <div class="gei-confirm-icon"></div>
    <div class="gei-confirm-title" id="gei-confirm-title-el"></div>
    <div class="gei-confirm-msg"></div>
    <div class="gei-confirm-note">
      <i class="fa fa-circle-info"></i>
      <span class="gei-confirm-note-text"></span>
    </div>
    <div class="gei-confirm-actions">
      <button class="gei-confirm-btn-cancel" onclick="_geiConfirmCancel()">Cancel</button>
      <button class="gei-confirm-btn-ok gei-confirm-btn--danger" onclick="_geiConfirmOk()">Confirm</button>
    </div>
  </div>
</div>
<script src="<?= BASE_URL ?>assets/js/gei-ui.js?v=1"></script>
<script src="<?= BASE_URL ?>assets/js/notifications.js?v=1"></script>
</body>
</html>