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
      var icon = document.createElement('i');
      icon.className = 'fa ' + (icons[type] || icons.info);
      var text = document.createElement('span');
      text.textContent = message;
      var close = document.createElement('button');
      close.className = 'toast-close';
      close.title = 'Dismiss';
      close.innerHTML = '<i class="fa fa-times"></i>';
      toast.appendChild(icon);
      toast.appendChild(text);
      toast.appendChild(close);
      container.appendChild(toast);
      requestAnimationFrame(function(){ requestAnimationFrame(function(){ toast.classList.add('toast--show'); }); });
      var timer = setTimeout(function() {
          toast.classList.remove('toast--show');
          setTimeout(function(){ if(toast.parentNode) toast.remove(); }, 280);
      }, duration);
      close.addEventListener('click', function() {
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

  <!-- Global JS variables -->
  <script>
  window.BASE_URL = '<?= BASE_URL ?>';
  window.CSRF_TOKEN = '<?= function_exists('csrfToken') ? htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') : '' ?>';
  window.appendCsrfToken = function(formData) {
      if (formData && typeof formData.append === 'function') {
          if (typeof formData.has !== 'function' || !formData.has('csrf_token')) {
              formData.append('csrf_token', window.CSRF_TOKEN || '');
          }
      }
      return formData;
  };
  (function() {
      var nativeFetch = window.fetch;
      window.fetch = function(input, init) {
          init = init || {};
          var method = (init.method || '').toUpperCase();
          if (method === 'POST') {
              init.headers = init.headers || {};
              if (init.headers instanceof Headers) {
                  if (!init.headers.has('X-CSRF-Token')) init.headers.set('X-CSRF-Token', window.CSRF_TOKEN || '');
              } else if (!init.headers['X-CSRF-Token'] && !init.headers['x-csrf-token']) {
                  init.headers['X-CSRF-Token'] = window.CSRF_TOKEN || '';
              }
              if (init.body instanceof FormData) {
                  window.appendCsrfToken(init.body);
              }
          }
          return nativeFetch(input, init);
      };
  })();
  </script>

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
      var icon = document.createElement('i');
      icon.className = 'fa ' + (icons[type] || icons.info);
      var text = document.createElement('span');
      text.textContent = message;
      var close = document.createElement('button');
      close.className = 'toast-close';
      close.title = 'Dismiss';
      close.innerHTML = '<i class="fa fa-times"></i>';
      toast.appendChild(icon);
      toast.appendChild(text);
      toast.appendChild(close);
      container.appendChild(toast);
      requestAnimationFrame(function(){ requestAnimationFrame(function(){ toast.classList.add('toast--show'); }); });
      var timer = setTimeout(function() {
          toast.classList.remove('toast--show');
          setTimeout(function(){ if(toast.parentNode) toast.remove(); }, 280);
      }, duration);
      close.addEventListener('click', function() {
          clearTimeout(timer);
          toast.classList.remove('toast--show');
          setTimeout(function(){ if(toast.parentNode) toast.remove(); }, 280);
      });
  };
  </script>
</head>
