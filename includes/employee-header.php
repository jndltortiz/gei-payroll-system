<?php
/**
 * includes/employee-header.php
 * Shared top navbar for the Employee Self-Service Portal.
 * Set $empPortalIcon (FA icon class, e.g. 'fa-house') before including.
 */
$_empIcon  = isset($empPortalIcon) ? $empPortalIcon : 'fa-user-circle';
$_empFirst = htmlspecialchars(($_SESSION['user']['first_name'] ?? '') . ' ' . ($_SESSION['user']['last_name'] ?? ''));
$_empInit  = strtoupper(
    substr($_SESSION['user']['first_name'] ?? 'E', 0, 1) .
    substr($_SESSION['user']['last_name']  ?? 'M', 0, 1)
);
$_empRole  = htmlspecialchars($_SESSION['user']['role_name'] ?? 'Employee');
?>
<div class="header">

  <!-- Left: portal identity -->
  <div style="display:flex;align-items:center;gap:10px;">
    <i class="fa <?= $_empIcon ?>" style="color:var(--accent);font-size:18px;"></i>
    <div>
      <div style="font-size:15px;font-weight:700;color:#0f172a;">Employee Portal</div>
      <div style="font-size:12px;color:#64748b;">Great Eastern Institute</div>
    </div>
  </div>

  <!-- Right: bell + user -->
  <div style="margin-left:auto;display:flex;align-items:center;gap:12px;">

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

    <!-- User info + avatar -->
    <div style="text-align:right;">
      <div style="font-size:14px;font-weight:600;color:#0f172a;"><?= $_empFirst ?></div>
      <div style="font-size:11px;color:#64748b;"><?= $_empRole ?></div>
    </div>
    <div class="header-avatar"><?= $_empInit ?></div>

  </div>

</div><!-- .header -->
