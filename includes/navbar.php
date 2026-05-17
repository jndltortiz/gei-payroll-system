<div class="header">

    <!-- LEFT SIDE -->
    <div class="header-portal">
        <div class="header-portal-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <rect x="3" y="4" width="18" height="12" rx="2"/>
                <path d="M8 20h8"/>
            </svg>
        </div>

        <div class="header-portal-text">
            <strong>Admin Portal</strong>
            <span>Great Eastern Institute</span>
        </div>
    </div>

    <!-- RIGHT SIDE -->
    <div class="header-right">

        <!-- Notification -->
        <div class="notif-btn">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <path d="M18 8a6 6 0 10-12 0c0 7-3 7-3 7h18s-3 0-3-7"/>
                <path d="M13.73 21a2 2 0 01-3.46 0"/>
            </svg>
            <span class="notif-dot"></span>
        </div>

        <!-- USER -->
        <div class="header-user">
            <div class="header-user-text">
                <strong><?= $_SESSION['user']['role_name']; ?></strong>
                <span><?= $_SESSION['user']['first_name']; ?></span>
            </div>
            <div class="header-avatar">
                <?= strtoupper(substr($_SESSION['user']['first_name'], 0, 1)); ?>
            </div>
        </div>

    </div>

</div>