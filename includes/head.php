<?php
/**
 * includes/head.php
 * Outputs the full <!DOCTYPE html>…
  <!-- Global Toast Notification System -->
  <script>
  window.showToast = function(message, type, duration) {
      type = type || 'success'; duration = duration || 4000;
      var container = document.getElementById('toast-container');
      if (!container) {
          container = document.createElement('div');
          container.id = 'toast-container';
          document.body.appendChild(container);
      }
      var icons = {success:'fa-circle-check',error:'fa-circle-xmark',warning:'fa-triangle-exclamation',info:'fa-circle-info'};
      var toast = document.createElement('div');
      toast.className = 'toast toast--' + type;
      toast.innerHTML = '<i class="fa ' + (icons[type]||icons.info) + '"></i>' +
          '<span>' + message + '</span>' +
          '<button class="toast-close" title="Dismiss"><i class="fa fa-times"></i></button>';
      container.appendChild(toast);
      requestAnimationFrame(function(){ requestAnimationFrame(function(){ toast.classList.add('toast--show'); }); });
      var timer = setTimeout(function() {
          toast.classList.remove('toast--show');
          setTimeout(function(){ if(toast.parentNode) toast.remove(); }, 280);
      }, duration);
      toast.querySelector('.toast-close').addEventListener('click', function() {
          clearTimeout(timer);
          toast.classList.remove('toast--show');
          setTimeout(function(){ if(toast.parentNode) toast.remove(); }, 280);
      });
  };
  </script>
</head> block.
 *
 * Variables to set BEFORE including this file:
 *   $pageTitle    (string)  Page name shown in <title> — e.g. 'Dashboard'
 *   $extraCSS     (array)   Additional stylesheet URLs loaded after global.css
 *   $loadBootstrap (bool)   Set true for pages that use Bootstrap components
 *
 * Always loaded: Plus Jakarta Sans, Font Awesome 6.5.0, global.css
 */
$pageTitle     = isset($pageTitle)     ? $pageTitle     : 'GEI HR System';
$extraCSS      = isset($extraCSS)      ? $extraCSS      : [];
$loadBootstrap = isset($loadBootstrap) ? $loadBootstrap : false;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<title><?= htmlspecialchars($pageTitle) ?> | GEI HR System</title>

<!-- Fonts -->
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet"/>

<!-- Icons -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>

<?php if ($loadBootstrap): ?>
<!-- Bootstrap (only on pages that use it) -->
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"/>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css"/>
<?php endif; ?>

<!-- Base styles -->
<link rel="stylesheet" href="<?= BASE_URL ?>assets/css/global.css">

<!-- Page-specific styles -->
<?php foreach ($extraCSS as $href): ?>
<link rel="stylesheet" href="<?= htmlspecialchars($href) ?>">
<?php endforeach; ?>

  <!-- Global Toast Notification System -->
  <script>
  window.showToast = function(message, type, duration) {
      type = type || 'success'; duration = duration || 4000;
      var container = document.getElementById('toast-container');
      if (!container) {
          container = document.createElement('div');
          container.id = 'toast-container';
          document.body.appendChild(container);
      }
      var icons = {success:'fa-circle-check',error:'fa-circle-xmark',warning:'fa-triangle-exclamation',info:'fa-circle-info'};
      var toast = document.createElement('div');
      toast.className = 'toast toast--' + type;
      toast.innerHTML = '<i class="fa ' + (icons[type]||icons.info) + '"></i>' +
          '<span>' + message + '</span>' +
          '<button class="toast-close" title="Dismiss"><i class="fa fa-times"></i></button>';
      container.appendChild(toast);
      requestAnimationFrame(function(){ requestAnimationFrame(function(){ toast.classList.add('toast--show'); }); });
      var timer = setTimeout(function() {
          toast.classList.remove('toast--show');
          setTimeout(function(){ if(toast.parentNode) toast.remove(); }, 280);
      }, duration);
      toast.querySelector('.toast-close').addEventListener('click', function() {
          clearTimeout(timer);
          toast.classList.remove('toast--show');
          setTimeout(function(){ if(toast.parentNode) toast.remove(); }, 280);
      });
  };
  </script>
</head>