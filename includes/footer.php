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
</body>
</html>