<?php
require_once __DIR__ . '/../../includes/auth.php';
requireLogin();

$pageTitle = 'Audit';
$extraCSS  = [];
require_once __DIR__ . '/../../includes/head.php';
?>
<body>
<?php include __DIR__ . '/../../includes/sidebar.php'; ?>
<div class="main">
  <?php include __DIR__ . '/../../includes/header.php'; ?>
  <div class="main-content" style="display:flex;flex-direction:column;align-items:center;justify-content:center;height:60vh;gap:16px;color:var(--text-secondary);">
    <i class="fa fa-hammer" style="font-size:48px;color:var(--accent);"></i>
    <h2 style="font-size:20px;font-weight:700;color:var(--text-primary);">Audit — Coming Soon</h2>
    <p style="font-size:14px;">This module is under construction.</p>
  </div>
</div>
<?php include __DIR__ . '/../../includes/footer.php'; ?>