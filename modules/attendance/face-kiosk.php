<?php
require_once __DIR__.'/../../includes/face-kiosk-guard.php';
$csrf=csrfToken();session_write_close();
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>GEI Face Kiosk</title>
<link rel="stylesheet" href="../../assets/css/global.css"><link rel="stylesheet" href="../../assets/css/face-kiosk.css">
<script defer src="../../assets/vendor/face-api/face-api.min.js"></script><script defer src="../../assets/js/face-kiosk.js"></script></head>
<body><main><header><strong>GEI · Great Eastern Institute</strong><span>Local test kiosk</span></header><h1>Face timekeeping</h1><p>Choose Time In when arriving or Time Out before leaving. Position yourself in the camera view and hold still until capture.</p>
<input id="csrf" type="hidden" value="<?= htmlspecialchars($csrf,ENT_QUOTES,'UTF-8') ?>">
<p><a href="../auth/login.php">Back to Login</a></p>
<div id="welcome"><div class="controls"><button id="time-in" type="button" disabled>Time In</button><button id="time-out" type="button" disabled>Time Out</button></div></div>
<p id="status" role="status" aria-live="polite">Loading local models…</p>
<div id="scan-panel" hidden>
    <h2 id="selected-action"></h2>
    <p>Keep only your face in view for about 2 seconds. If no scan completes within 30 seconds, the camera stops.</p>
    <label for="stability-progress">Face positioning progress</label>
    <progress id="stability-progress" max="1" value="0"></progress>
    <div class="controls"><button id="stop" type="button">Cancel scan</button></div>
    <div class="feed"><video id="video" autoplay muted playsinline aria-label="Live webcam preview"></video><canvas id="overlay" aria-hidden="true"></canvas></div>
</div>
<section id="result-panel" aria-labelledby="result-title" hidden>
    <div role="status" aria-live="polite" aria-atomic="true">
        <h2 id="result-title" tabindex="-1"></h2>
        <p id="result-employee"></p>
        <p id="result-action"></p>
        <p id="result"></p>
    </div>
    <p id="result-countdown" role="timer" aria-live="off"></p>
    <button id="extend-result" type="button" aria-describedby="extension-status" disabled>Need More Time?</button>
    <p id="extension-status" role="status" aria-live="polite"></p>
</section>
<p class="note">Supervised test kiosk. The positioning delay is not liveness detection; a photo or replay may impersonate a person. Camera frames are processed locally; only the descriptor is sent for matching.</p><noscript>Enable JavaScript to use this kiosk.</noscript></main></body></html>
