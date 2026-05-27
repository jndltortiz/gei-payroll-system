<?php
/**
 * includes/sidebar.php
 * Unified sidebar navigation — GEI HR System (Admin / Accounting).
 * Active state auto-detected from REQUEST_URI.
 *
 * Ensures auth.php is loaded so hasEmployeeAccess() and role helpers
 * are always available, even on pages that only require config.php.
 */
require_once __DIR__ . '/auth.php';
function sidebarActive(string $path): string {
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    return str_contains($uri, $path) ? 'active' : '';
}

/**
 * Highlights "Settings" when on the hub page OR any of the
 * consolidated configuration sub-modules.
 */
function settingsActive(): string {
    $uri = $_SERVER['REQUEST_URI'] ?? '';
    $paths = [
        'modules/settings',
        'modules/payroll-settings',
        'modules/shifts',
        'modules/holidays',
        'modules/leave-credits',
        'modules/school-years',
    ];
    foreach ($paths as $p) {
        if (str_contains($uri, $p)) return 'active';
    }
    return '';
}

$_sidebarFirst = htmlspecialchars($_SESSION['user']['first_name'] ?? 'Admin');
$_sidebarRole  = htmlspecialchars($_SESSION['user']['role_name']  ?? '');
$_sidebarInit  = strtoupper(substr($_SESSION['user']['first_name'] ?? 'A', 0, 1));
?>
<nav class="sidebar" id="sidebar">

  <!-- Brand -->
  <div class="sidebar-brand">
    <div class="sidebar-logo">
      <span class="sidebar-logo-text">GEI</span>
    </div>
    <div class="sidebar-brand-name">
      <strong>GEI HR System</strong>
      <span>Great Eastern Institute</span>
    </div>
  </div>

  <!-- Toggle button (chevron on right edge) -->
  <button class="sidebar-toggle-btn" id="sidebarToggle" title="Collapse sidebar">
    <i class="fa fa-chevron-left" id="sidebarChevron"></i>
  </button>

  <!-- User role card (visible when expanded) -->
  <div class="sidebar-role-card" id="sidebarRoleCard">
    <div class="sidebar-role-label"><?= strtoupper($_sidebarRole) ?></div>
    <div class="sidebar-role-name"><?= $_sidebarFirst ?></div>
  </div>

  <div class="sidebar-nav">

    <div class="nav-section-label">OVERVIEW</div>
    <a class="nav-item <?= sidebarActive('modules/dashboard') ?>"
       href="<?= BASE_URL ?>modules/dashboard/index.php">
      <i class="fa fa-table-cells-large"></i>
      <span>Dashboard</span>
    </a>

    <div class="nav-section-label">WORKFORCE</div>
    <a class="nav-item <?= sidebarActive('modules/attendance') ?>"
       href="<?= BASE_URL ?>modules/attendance/index.php">
      <i class="fa fa-clock"></i>
      <span>Attendance</span>
    </a>
    <a class="nav-item <?= sidebarActive('modules/employees') ?>"
       href="<?= BASE_URL ?>modules/employees/index.php">
      <i class="fa fa-users"></i>
      <span>Employees</span>
    </a>

    <div class="nav-section-label">PAYROLL</div>
    <a class="nav-item <?= sidebarActive('modules/payroll/index') ?>"
       href="<?= BASE_URL ?>modules/payroll/index.php">
      <i class="fa fa-file-invoice-dollar"></i>
      <span>Payroll</span>
    </a>
    <a class="nav-item <?= sidebarActive('modules/payroll/archive') ?>"
       href="<?= BASE_URL ?>modules/payroll/archive.php">
      <i class="fa fa-box-archive"></i>
      <span>Payroll Archive</span>
    </a>
    <a class="nav-item <?= sidebarActive('modules/loans') ?>"
       href="<?= BASE_URL ?>modules/loans/index.php">
      <i class="fa fa-hand-holding-dollar"></i>
      <span>Loans</span>
    </a>
    <a class="nav-item <?= sidebarActive('modules/service-credits') ?>"
       href="<?= BASE_URL ?>modules/service-credits/index.php">
      <i class="fa fa-medal"></i>
      <span>Service Credits</span>
    </a>

    <div class="nav-section-label">LEAVE</div>
    <a class="nav-item <?= sidebarActive('modules/leave/') ?>"
       href="<?= BASE_URL ?>modules/leave/index.php">
      <i class="fa fa-calendar-days"></i>
      <span>Leave Records</span>
    </a>

    <div class="nav-section-label">ANALYTICS</div>
    <a class="nav-item <?= sidebarActive('modules/analytics') ?>"
       href="<?= BASE_URL ?>modules/analytics/index.php">
      <i class="fa fa-chart-bar"></i>
      <span>Analytics</span>
    </a>

    <div class="nav-section-label">SYSTEM</div>
    <a class="nav-item <?= sidebarActive('modules/notifications') ?>"
       href="<?= BASE_URL ?>modules/notifications/index.php">
      <i class="fa fa-bell"></i>
      <span>Notifications</span>
    </a>
    <a class="nav-item <?= sidebarActive('modules/audit') ?>"
       href="<?= BASE_URL ?>modules/audit/index.php">
      <i class="fa fa-shield-halved"></i>
      <span>Audit Logs</span>
    </a>
    <a class="nav-item <?= settingsActive() ?>"
       href="<?= BASE_URL ?>modules/settings/index.php">
      <i class="fa fa-gear"></i>
      <span>Settings</span>
    </a>

    <?php if (hasEmployeeAccess()): ?>
    <div class="nav-section-label">MY ACCOUNT</div>
    <a class="nav-item <?= sidebarActive('employee/attendance') ?>"
       href="<?= BASE_URL ?>modules/employee/attendance/index.php">
      <i class="fa fa-user-clock"></i>
      <span>My Attendance</span>
    </a>
    <a class="nav-item <?= sidebarActive('employee/leave') ?>"
       href="<?= BASE_URL ?>modules/employee/leave/index.php">
      <i class="fa fa-calendar-days"></i>
      <span>My Leave</span>
    </a>
    <a class="nav-item <?= sidebarActive('employee/payslips') ?>"
       href="<?= BASE_URL ?>modules/employee/payslips/index.php">
      <i class="fa fa-file-invoice-dollar"></i>
      <span>My Payslips</span>
    </a>
    <a class="nav-item <?= sidebarActive('employee/loans') ?>"
       href="<?= BASE_URL ?>modules/employee/loans/index.php">
      <i class="fa fa-hand-holding-dollar"></i>
      <span>My Loans</span>
    </a>
    <a class="nav-item <?= sidebarActive('employee/service-credits') ?>"
       href="<?= BASE_URL ?>modules/employee/service-credits/index.php">
      <i class="fa fa-medal"></i>
      <span>My Service Credits</span>
    </a>
    <a class="nav-item <?= sidebarActive('employee/profile') ?>"
       href="<?= BASE_URL ?>modules/employee/profile/index.php">
      <i class="fa fa-id-badge"></i>
      <span>My Profile</span>
    </a>
    <a class="nav-item <?= sidebarActive('employee/calendar') ?>"
       href="<?= BASE_URL ?>modules/employee/calendar/index.php">
      <i class="fa fa-calendar-check"></i>
      <span>My Calendar</span>
    </a>
    <?php endif; ?>

  </div><!-- .sidebar-nav -->

  <!-- User / Logout footer -->
  <div class="sidebar-footer">
    <div class="sidebar-user">
      <div class="sidebar-user-avatar"><?= $_sidebarInit ?></div>
      <div class="sidebar-user-info">
        <strong><?= $_sidebarFirst ?></strong>
        <span><?= $_sidebarRole ?></span>
      </div>
    </div>
    <a class="nav-item nav-item--logout" href="<?= BASE_URL ?>actions/logout.php">
      <i class="fa fa-right-from-bracket"></i>
      <span>Logout</span>
    </a>
  </div>

</nav>

<script>
(function () {
    function initSidebar() {
        const sidebar  = document.getElementById('sidebar');
        const btn      = document.getElementById('sidebarToggle');
        const chevron  = document.getElementById('sidebarChevron');
        if (!sidebar || !btn) return;

        function applyState(collapsed) {
            sidebar.classList.toggle('collapsed', collapsed);
            if (chevron) {
                chevron.className = collapsed ? 'fa fa-chevron-right' : 'fa fa-chevron-left';
            }
        }

        applyState(localStorage.getItem('sidebarCollapsed') === '1');

        btn.addEventListener('click', function () {
            const next = !sidebar.classList.contains('collapsed');
            applyState(next);
            localStorage.setItem('sidebarCollapsed', next ? '1' : '0');
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initSidebar);
    } else {
        initSidebar();
    }
})();
</script>
