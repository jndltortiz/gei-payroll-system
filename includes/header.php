  <header class="header">
    <div class="header-portal">
      <div class="header-portal-icon">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="2" y="3" width="20" height="14" rx="2"/><line x1="8" y1="21" x2="16" y2="21"/><line x1="12" y1="17" x2="12" y2="21"/></svg>
      </div>
      <div class="header-portal-text">
        <strong>Admin Portal</strong>
        <span>Great Eastern Institute</span>
      </div>
    </div>
    <div class="header-right">
      <div class="notif-btn">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8A6 6 0 0 0 6 8c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
        <div class="notif-dot"></div>
      </div>
      <div class="header-user">
        <div class="header-user-text">
          <strong><?php echo $_SESSION['user']['first_name'] . ' ' . $_SESSION['user']['last_name']; ?></strong>
          <span><?php echo $_SESSION['user']['role_name']; ?></span>
        </div>
        <div class="header-avatar">AD</div>
      </div>
    </div>
  </header>