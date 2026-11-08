<?php
require_once __DIR__ . '/../../includes/auth.php';
header('Cache-Control: no-store');
$flash = getFlash();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>RFID Attendance | GEI HR &amp; Payroll System</title>
    <?php include __DIR__ . '/../../includes/head.php'; ?>
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/login.css">
    <style>
        .rfid-page { min-height:100vh; display:grid; place-items:center; padding:24px; background:#f0fdfa; }
        .rfid-card { width:min(100%,460px); padding:34px; border:1px solid #d1d5db; border-radius:24px; background:#fff; box-shadow:0 12px 35px rgba(15,28,27,.10); text-align:center; }
        .rfid-icon { width:76px; height:76px; margin:0 auto 18px; display:grid; place-items:center; border-radius:22px; color:#fff; background:#0e7c6a; font-size:34px; }
        .rfid-card h1 { margin-bottom:8px; color:#0f1c1b; font-size:28px; }
        .rfid-card p { color:#6b7280; font-size:14px; line-height:1.6; }
        .rfid-form { margin-top:24px; text-align:left; }
        .rfid-form input { width:100%; height:52px; padding:0 16px; border:1.5px solid #e5e7eb; border-radius:12px; font:inherit; font-size:16px; text-align:center; letter-spacing:1px; outline:none; }
        .rfid-form input:focus { border-color:#0e7c6a; box-shadow:0 0 0 3px rgba(14,124,106,.12); }
        .rfid-actions { display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-top:14px; }
        .rfid-actions button { height:48px; border:0; border-radius:12px; color:#fff; background:#0e7c6a; font:700 14px inherit; cursor:pointer; }
        .rfid-actions button:last-child { background:#0f5248; }
        .rfid-back { display:inline-block; margin-top:22px; color:#0e7c6a; font-size:13px; text-decoration:none; }
        .rfid-help { margin-top:18px; font-size:12px !important; }
        @media (max-width:520px) { .rfid-card { padding:26px 20px; } }
    </style>
</head>
<body>
<main class="rfid-page"><section class="rfid-card" aria-labelledby="rfid-title">
    <div class="rfid-icon"><i class="bi bi-credit-card-2-front"></i></div>
    <h1 id="rfid-title">RFID Attendance</h1>
    <p>Tap your RFID card on the reader. The card number will be entered automatically.</p>
    <?php if ($flash): ?><div class="custom-alert <?= htmlspecialchars($flash['type']) ?>" role="alert"><i class="bi bi-<?= $flash['type'] === 'danger' ? 'exclamation-circle' : 'check-circle' ?>"></i><?= htmlspecialchars($flash['message']) ?></div><?php endif; ?>
    <form class="rfid-form" action="<?= BASE_URL ?>actions/rfid-attendance.php" method="post">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(csrfToken(), ENT_QUOTES, 'UTF-8') ?>">
        <label class="field-label" for="rfid_uid">RFID card number</label>
        <input id="rfid_uid" name="rfid_uid" type="text" maxlength="100" autocomplete="off" autofocus required>
        <div class="rfid-actions"><button type="submit" name="attendance_action" value="time_in"><i class="bi bi-box-arrow-in-right"></i> Time In</button><button type="submit" name="attendance_action" value="time_out"><i class="bi bi-box-arrow-right"></i> Time Out</button></div>
    </form>
    <p class="rfid-help">Use the regular sign-in form for payroll, leave, and employee portal access.</p>
    <a class="rfid-back" href="<?= BASE_URL ?>modules/auth/login.php">Back to Login</a>
</section></main>
</body></html>
