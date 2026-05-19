<?php
/**
 * includes/head.php
 * Outputs the full <!DOCTYPE html>…</head> block.
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
</head>