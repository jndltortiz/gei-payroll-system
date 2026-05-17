<?php
require_once __DIR__ . '/../../includes/auth.php';
guestOnly();

$flash = getFlash();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <title>Sign In | GEI HR & Payroll System</title>
    <?php include __DIR__ . '/../../includes/head.php'; ?>
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/login.css">

</head>
<body>

    <!-- ══ LEFT PANEL ══ -->
    <div class="left-panel">
        <div class="brand-bar">
            <div class="brand-logo">GEI</div>
            <div class="brand-text">
                <div class="name">Great Eastern Institute</div>
                <div class="sub">HR &amp; Payroll System</div>
            </div>
        </div>

        <div class="hero-content">
            <h1>Manage your<br>workforce<br>with ease.</h1>
            <p>A unified platform for attendance, payroll,
               and leave management — built for Great
               Eastern Institute.</p>

            <div class="feature-list">
                <div class="feature-item">
                    <div class="feature-icon">
                        <i class="bi bi-clock-history"></i>
                    </div>
                    Real-time attendance tracking
                </div>
                <div class="feature-item">
                    <div class="feature-icon">
                        <i class="bi bi-cash-coin"></i>
                    </div>
                    Automated payroll computation
                </div>
                <div class="feature-item">
                    <div class="feature-icon">
                        <i class="bi bi-calendar2-check"></i>
                    </div>
                    Leave &amp; approval management
                </div>
                <div class="feature-item">
                    <div class="feature-icon">
                        <i class="bi bi-shield-lock"></i>
                    </div>
                    Role-based access control
                </div>
            </div>
        </div>
    </div>

    <!-- ══ RIGHT PANEL ══ -->
    <div class="right-panel">
        <div class="login-box">

            <h2>Welcome back</h2>
            <p class="subtitle">Sign in to your account to continue</p>

            <?php if ($flash): ?>
                <div class="custom-alert <?= htmlspecialchars($flash['type']) ?>">
                    <i class="bi bi-<?= $flash['type'] === 'danger' ? 'exclamation-circle' : 'check-circle' ?>"></i>
                    <?= htmlspecialchars($flash['message']) ?>
                </div>
            <?php endif; ?>

            <div class="form-card">
                <form action="<?= BASE_URL ?>actions/login-action.php" method="POST">

                    <label class="field-label" for="username">Username</label>
                    <div class="input-wrap">
                        <i class="bi bi-envelope icon"></i>
                        <input
                            type="text"
                            id="username"
                            name="username"
                            placeholder="yourname@gei.edu.ph"
                            required
                            autofocus
                        >
                    </div>

                    <div class="pw-row">
                        <label class="field-label mb-0" for="password">Password</label>
                        <a href="#" class="forgot-link">Forgot Password?</a>
                    </div>
                    <div class="input-wrap">
                        <i class="bi bi-lock icon"></i>
                        <input
                            type="password"
                            id="password"
                            name="password"
                            placeholder="••••••••"
                            required
                        >
                        <button type="button" class="toggle-pw" onclick="togglePassword()">
                            <i class="bi bi-eye" id="eyeIcon"></i>
                        </button>
                    </div>

                    <button type="submit" class="btn-signin">Sign In</button>
                </form>
            </div>

            <!-- Demo accounts -->
            <div class="demo-card">
                <div class="demo-header">
                    Demo accounts (password: <code>password123</code> )
                </div>
                <div class="demo-row">
                    <span class="demo-email">
                        <span class="demo-dot"></span>
                        admin@gei.edu.ph
                    </span>
                    <span class="demo-role">Admin</span>
                </div>
                <div class="demo-row">
                    <span class="demo-email">
                        <span class="demo-dot"></span>
                        principal@gei.edu.ph
                    </span>
                    <span class="demo-role">Principal</span>
                </div>
                <div class="demo-row">
                    <span class="demo-email">
                        <span class="demo-dot"></span>
                        employee@gei.edu.ph
                    </span>
                    <span class="demo-role">Employee</span>
                </div>
            </div>

            <div class="secure-badge">
                <i class="bi bi-shield-check"></i>
                Secure Connection — Data Encrypted
            </div>

        </div>
    </div>
    
    <script src="<?= BASE_URL ?>assets/js/login.js"></script>
</body>
</html>