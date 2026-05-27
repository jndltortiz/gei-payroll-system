<?php
/**
 * modules/auth/change-password.php
 * Forced / voluntary password-change screen.
 * Uses requireLoginOnly() — users with must_change_password = 1 are NOT
 * redirected away from this page.
 */
require_once __DIR__ . '/../../includes/auth.php';
requireLoginOnly();

$flash = getFlash();
$isForcedChange = !empty($_SESSION['user']['must_change_password']);
$userName = trim(($_SESSION['user']['first_name'] ?? '') . ' ' . ($_SESSION['user']['last_name'] ?? ''));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <title>Change Password | GEI HR &amp; Payroll System</title>

    <!-- Fonts + Font Awesome (always available) -->
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet"/>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css"/>

    <!-- Global JS BASE_URL -->
    <script>window.BASE_URL = '<?= BASE_URL ?>';</script>

    <style>
    /* ── Reset ──────────────────────────────────────────────────────────────── */
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

    /* ── Page shell — full-viewport teal gradient, card centered ───────────── */
    html, body {
        height: 100%;
        font-family: 'Plus Jakarta Sans', sans-serif;
        background: #0d3d38;    /* fallback */
    }

    body {
        min-height: 100vh;
        display: flex;
        align-items: center;
        justify-content: center;
        background: linear-gradient(145deg, #0d3d38 0%, #0f5248 45%, #0e6b5a 100%);
        padding: 28px 16px;
        overflow-y: auto;
        position: relative;
    }

    /* ── Decorative background circles (matches login left-panel aesthetic) ── */
    body::before {
        content: '';
        position: fixed; inset: 0; pointer-events: none; z-index: 0;
        background:
            radial-gradient(circle at 15% 85%,  rgba(14,124,106,.35) 0%, transparent 50%),
            radial-gradient(circle at 88% 12%,  rgba(30,201,168,.20) 0%, transparent 45%),
            radial-gradient(circle at 70% 90%,  rgba(12, 74, 60,.40) 0%, transparent 55%);
    }

    /* ── Card ───────────────────────────────────────────────────────────────── */
    .cp-card {
        position: relative; z-index: 1;
        background: #ffffff;
        border-radius: 20px;
        width: 100%;
        max-width: 472px;
        padding: 40px 40px 32px;
        box-shadow:
            0 4px 6px  rgba(0,0,0,.07),
            0 16px 40px rgba(0,0,0,.18),
            0 40px 80px rgba(0,0,0,.12);
    }

    /* ── Brand header ───────────────────────────────────────────────────────── */
    .cp-brand {
        display: flex;
        align-items: center;
        gap: 13px;
        margin-bottom: 28px;
        padding-bottom: 20px;
        border-bottom: 1px solid #f0f2f5;
    }
    .cp-logo {
        width: 44px; height: 44px; flex-shrink: 0;
        background: #0e7c6a;
        border-radius: 12px;
        display: flex; align-items: center; justify-content: center;
        color: #fff; font-weight: 800; font-size: 17px;
        letter-spacing: -.5px;
        box-shadow: 0 4px 12px rgba(14,124,106,.35);
    }
    .cp-brand-text .name {
        font-size: 14px; font-weight: 700;
        color: #0d2b27; line-height: 1.25;
    }
    .cp-brand-text .sub {
        font-size: 11.5px; color: #6b7280; font-weight: 500;
    }

    /* ── Heading block ──────────────────────────────────────────────────────── */
    .cp-heading { margin-bottom: 20px; }
    .cp-heading h2 {
        font-size: 22px; font-weight: 800;
        color: #0d2b27; letter-spacing: -.4px;
        margin-bottom: 4px;
    }
    .cp-heading .subtitle {
        font-size: 13.5px; color: #6b7280; line-height: 1.5;
    }

    /* ── Forced-change banner ───────────────────────────────────────────────── */
    .forced-banner {
        display: flex; gap: 10px; align-items: flex-start;
        background: #fffbeb;
        border: 1px solid #fcd34d;
        border-left: 4px solid #f59e0b;
        border-radius: 10px;
        padding: 12px 14px;
        margin-bottom: 20px;
        font-size: 13px; color: #92400e; line-height: 1.55;
    }
    .forced-banner .fb-icon {
        font-size: 15px; color: #d97706;
        flex-shrink: 0; margin-top: 1px;
    }

    /* ── Flash alerts ───────────────────────────────────────────────────────── */
    .cp-alert {
        display: flex; align-items: center; gap: 9px;
        border-radius: 10px;
        padding: 11px 14px;
        font-size: 13.5px; font-weight: 500;
        margin-bottom: 20px;
    }
    .cp-alert i { flex-shrink: 0; font-size: 15px; }
    .cp-alert.danger {
        background: #fef2f2; border: 1px solid #fecaca; color: #dc2626;
    }
    .cp-alert.success {
        background: #f0fdf4; border: 1px solid #bbf7d0; color: #16a34a;
    }

    /* ── Form fields ────────────────────────────────────────────────────────── */
    .cp-form { display: flex; flex-direction: column; gap: 0; }

    .cp-field { margin-bottom: 18px; }
    .cp-field:last-of-type { margin-bottom: 22px; }

    .cp-label {
        display: block;
        font-size: 12.5px; font-weight: 600;
        color: #374151;
        margin-bottom: 7px;
    }

    .cp-input-wrap { position: relative; }

    .cp-input-wrap .cp-icon {
        position: absolute; left: 14px; top: 50%;
        transform: translateY(-50%);
        color: #9ca3af; font-size: 14px;
        pointer-events: none;
    }

    .cp-input-wrap input {
        width: 100%;
        height: 46px;
        padding: 0 44px 0 40px;
        border: 1.5px solid #e5e7eb;
        border-radius: 10px;
        font-family: inherit;
        font-size: 14px;
        color: #111827;
        background: #fdfdfd;
        outline: none;
        transition: border-color .18s, box-shadow .18s;
    }
    .cp-input-wrap input::placeholder { color: #b0b7c0; }
    .cp-input-wrap input:focus {
        border-color: #0e7c6a;
        box-shadow: 0 0 0 3px rgba(14,124,106,.13);
        background: #fff;
    }

    .cp-toggle-pw {
        position: absolute; right: 12px; top: 50%;
        transform: translateY(-50%);
        background: none; border: none; cursor: pointer;
        color: #9ca3af; font-size: 14px; padding: 4px;
        transition: color .15s;
    }
    .cp-toggle-pw:hover { color: #0e7c6a; }

    /* ── Submit button ──────────────────────────────────────────────────────── */
    .cp-btn {
        width: 100%;
        height: 48px;
        background: #0e7c6a;
        color: #fff;
        border: none;
        border-radius: 10px;
        font-family: inherit;
        font-size: 15px; font-weight: 700;
        cursor: pointer;
        display: flex; align-items: center; justify-content: center; gap: 8px;
        transition: background .2s, transform .1s, box-shadow .2s;
        box-shadow: 0 4px 14px rgba(14,124,106,.30);
    }
    .cp-btn:hover  {
        background: #0d6b5a;
        box-shadow: 0 6px 20px rgba(14,124,106,.38);
    }
    .cp-btn:active { transform: scale(.99); }

    /* ── Back link ──────────────────────────────────────────────────────────── */
    .cp-back {
        text-align: center;
        margin-top: 16px;
        font-size: 13px;
    }
    .cp-back a {
        color: #0e7c6a; text-decoration: none; font-weight: 600;
        display: inline-flex; align-items: center; gap: 5px;
    }
    .cp-back a:hover { color: #0d6b5a; text-decoration: underline; }

    /* ── Secure badge ───────────────────────────────────────────────────────── */
    .cp-secure {
        display: flex; align-items: center; justify-content: center; gap: 7px;
        margin-top: 24px;
        padding-top: 20px;
        border-top: 1px solid #f0f2f5;
        font-size: 12px; color: #9ca3af;
    }
    .cp-secure i { color: #0e7c6a; font-size: 13px; }

    /* ── Responsive ─────────────────────────────────────────────────────────── */
    @media (max-width: 520px) {
        body { padding: 20px 12px; align-items: flex-start; padding-top: 32px; }
        .cp-card { padding: 32px 24px 24px; border-radius: 16px; }
        .cp-heading h2 { font-size: 20px; }
    }
    </style>
</head>
<body>

<div class="cp-card">

    <!-- Brand -->
    <div class="cp-brand">
        <div class="cp-logo">GEI</div>
        <div class="cp-brand-text">
            <div class="name">Great Eastern Institute</div>
            <div class="sub">HR &amp; Payroll System</div>
        </div>
    </div>

    <!-- Heading -->
    <div class="cp-heading">
        <h2>Change Password</h2>
        <p class="subtitle">
            <?= $isForcedChange
                ? 'You must set a new password before accessing the system.'
                : 'Update your account password below.' ?>
        </p>
    </div>

    <!-- Forced-change warning banner -->
    <?php if ($isForcedChange): ?>
    <div class="forced-banner">
        <i class="fa fa-shield-halved fb-icon"></i>
        <span>
            Your administrator has required a password reset.
            Dashboard access is locked until you complete this step.
        </span>
    </div>
    <?php endif; ?>

    <!-- Flash message -->
    <?php if ($flash): ?>
    <div class="cp-alert <?= htmlspecialchars($flash['type']) ?>">
        <i class="fa <?= $flash['type'] === 'danger' ? 'fa-circle-exclamation' : 'fa-circle-check' ?>"></i>
        <?= htmlspecialchars($flash['message']) ?>
    </div>
    <?php endif; ?>

    <!-- Form -->
    <form action="<?= BASE_URL ?>actions/change-password-action.php" method="POST" class="cp-form">

        <div class="cp-field">
            <label class="cp-label" for="current_password">Current Password</label>
            <div class="cp-input-wrap">
                <i class="fa fa-lock cp-icon"></i>
                <input type="password" id="current_password" name="current_password"
                       placeholder="Your current password" required autofocus>
                <button type="button" class="cp-toggle-pw" onclick="togglePw('current_password','eye1')" tabindex="-1">
                    <i class="fa fa-eye" id="eye1"></i>
                </button>
            </div>
        </div>

        <div class="cp-field">
            <label class="cp-label" for="new_password">New Password</label>
            <div class="cp-input-wrap">
                <i class="fa fa-key cp-icon"></i>
                <input type="password" id="new_password" name="new_password"
                       placeholder="Minimum 8 characters" minlength="8" required>
                <button type="button" class="cp-toggle-pw" onclick="togglePw('new_password','eye2')" tabindex="-1">
                    <i class="fa fa-eye" id="eye2"></i>
                </button>
            </div>
        </div>

        <div class="cp-field">
            <label class="cp-label" for="confirm_password">Confirm New Password</label>
            <div class="cp-input-wrap">
                <i class="fa fa-key cp-icon" style="opacity:.55;"></i>
                <input type="password" id="confirm_password" name="confirm_password"
                       placeholder="Repeat new password" required>
                <button type="button" class="cp-toggle-pw" onclick="togglePw('confirm_password','eye3')" tabindex="-1">
                    <i class="fa fa-eye" id="eye3"></i>
                </button>
            </div>
        </div>

        <button type="submit" class="cp-btn">
            <i class="fa fa-shield-halved"></i>
            Set New Password
        </button>

    </form>

    <!-- Back link (voluntary change only) -->
    <?php if (!$isForcedChange): ?>
    <div class="cp-back">
        <a href="javascript:history.back()">
            <i class="fa fa-arrow-left"></i> Go back
        </a>
    </div>
    <?php endif; ?>

    <!-- Secure badge -->
    <div class="cp-secure">
        <i class="fa fa-shield"></i>
        Secure Connection — Data Encrypted
    </div>

</div><!-- /.cp-card -->

<script>
function togglePw(inputId, iconId) {
    const input = document.getElementById(inputId);
    const icon  = document.getElementById(iconId);
    if (!input || !icon) return;
    const isHidden = input.type === 'password';
    input.type      = isHidden ? 'text' : 'password';
    icon.className  = isHidden ? 'fa fa-eye-slash' : 'fa fa-eye';
}
</script>
</body>
</html>
