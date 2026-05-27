<?php
/**
 * includes/header.php
 * Top navigation bar for GEI HR System.
 */
$_firstName = htmlspecialchars($_SESSION['user']['first_name'] ?? '');
$_lastName  = htmlspecialchars($_SESSION['user']['last_name']  ?? '');
$_roleName  = htmlspecialchars($_SESSION['user']['role_name']  ?? '');
$_initials  = strtoupper(
    substr($_SESSION['user']['first_name'] ?? '', 0, 1) .
    substr($_SESSION['user']['last_name']  ?? '', 0, 1)
);
?>
<header class="header">

  <!-- Left: portal identity -->
  <div class="header-portal">
    <div class="header-portal-icon">
      <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
        <rect x="2" y="3" width="20" height="14" rx="2"/>
        <line x1="8" y1="21" x2="16" y2="21"/>
        <line x1="12" y1="17" x2="12" y2="21"/>
      </svg>
    </div>
    <div class="header-portal-text">
      <strong><?= (function_exists('isPrincipalRole') && isPrincipalRole()) ? 'Principal Portal' : 'Admin Portal' ?></strong>
      <span>Great Eastern Institute</span>
    </div>
  </div>

  <!-- Right: notifications + user -->
  <div class="header-right">

    <!-- Notification Bell -->
    <div class="notif-wrap">
      <button class="notif-btn" id="notifBtn" aria-label="Notifications">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
          <path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/>
          <path d="M13.73 21a2 2 0 0 1-3.46 0"/>
        </svg>
        <span class="notif-badge" id="notifBadge"></span>
      </button>
      <div class="notif-dropdown" id="notifDropdown">
        <div class="notif-dd-header">
          <span class="notif-dd-title"><i class="fa fa-bell" style="margin-right:6px;"></i>Notifications</span>
          <button class="notif-dd-markall" id="notifMarkAll">Mark all read</button>
        </div>
        <div class="notif-dd-body" id="notifDdBody">
          <div class="notif-empty"><i class="fa fa-circle-notch fa-spin"></i><p>Loading…</p></div>
        </div>
        <div class="notif-dd-footer">
          <a href="<?= BASE_URL ?>modules/notifications/index.php">View all notifications →</a>
        </div>
      </div>
    </div>

    <div class="header-user">
      <div class="header-user-text">
        <strong><?= $_firstName . ' ' . $_lastName ?></strong>
        <span><?= $_roleName ?></span>
      </div>
      <div class="header-avatar"><?= $_initials ?></div>
    </div>
  </div>

</header>