<?php
/**
 * includes/principal-sidebar.php
 * Sidebar navigation for the Principal Portal.
 * Active state auto-detected from REQUEST_URI.
 */
function principalSidebarActive(string $path): string {
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    return str_contains($uri, $path) ? 'active' : '';
}
?>
<nav class="sidebar" id="sidebar">

  <!-- Brand -->
  <div class="sidebar-brand">
    <div class="sidebar-logo">
      <span class="sidebar-logo-text">GEI</span>
    </div>
    <div class="sidebar-brand-name">
      <strong>Principal Portal</strong>
      <span>Great Eastern Institute</span>
    </div>
  </div>

  <!-- Toggle button -->
  <button class="sidebar-toggle-btn" id="sidebarToggle" title="Collapse sidebar">
    <i class="fa fa-bars"></i>
  </button>

  <div class="sidebar-nav">

    <div class="nav-section-label">OVERVIEW</div>
    <a class="nav-item <?= principalSidebarActive('principal/dashboard') ?>"
       href="<?= BASE_URL ?>modules/principal/dashboard/index.php">
      <i class="fa fa-house"></i>
      <span>Dashboard</span>
    </a>

    <div class="nav-section-label">APPROVALS</div>
    <a class="nav-item <?= principalSidebarActive('principal/payroll-approval') ?>"
       href="<?= BASE_URL ?>modules/principal/payroll-approval/index.php">
      <i class="fa fa-wallet"></i>
      <span>Payroll Approval</span>
    </a>
    <a class="nav-item <?= principalSidebarActive('principal/leave-approval') ?>"
       href="<?= BASE_URL ?>modules/principal/leave-approval/index.php">
      <i class="fa fa-calendar-check"></i>
      <span>Leave Approval</span>
    </a>
    <a class="nav-item <?= principalSidebarActive('principal/loan-approval') ?>"
       href="<?= BASE_URL ?>modules/principal/loan-approval/index.php">
      <i class="fa fa-hand-holding-dollar"></i>
      <span>Loan Approval</span>
    </a>

    <div class="nav-section-label">SYSTEM</div>
    <a class="nav-item <?= principalSidebarActive('modules/settings') ?>"
       href="<?= BASE_URL ?>modules/settings/index.php">
      <i class="fa fa-gear"></i>
      <span>Settings</span>
    </a>

  </div><!-- .sidebar-nav -->

  <!-- User / Logout -->
  <div class="sidebar-footer">
    <div class="sidebar-user">
      <div class="sidebar-user-avatar">
        <?= strtoupper(substr($_SESSION['user']['first_name'] ?? 'P', 0, 1)) ?>
      </div>
      <div class="sidebar-user-info">
        <strong><?= htmlspecialchars(($_SESSION['user']['first_name'] ?? '') . ' ' . ($_SESSION['user']['last_name'] ?? '')) ?></strong>
        <span><?= htmlspecialchars($_SESSION['user']['role_name'] ?? 'Principal') ?></span>
      </div>
    </div>
    <a class="nav-item nav-item--logout" href="<?= BASE_URL ?>actions/logout.php">
      <i class="fa fa-right-from-bracket"></i>
      <span>Logout</span>
    </a>
  </div>

</nav>

<script>
(function() {
    function initSidebar() {
        const sidebar = document.getElementById('sidebar');
        const btn     = document.getElementById('sidebarToggle');
        if (!sidebar || !btn) return;
        if (localStorage.getItem('principalSidebarCollapsed') === '1') {
            sidebar.classList.add('collapsed');
        }
        btn.addEventListener('click', function () {
            sidebar.classList.toggle('collapsed');
            localStorage.setItem('principalSidebarCollapsed',
                sidebar.classList.contains('collapsed') ? '1' : '0');
        });
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initSidebar);
    } else {
        initSidebar();
    }
})();
</script>