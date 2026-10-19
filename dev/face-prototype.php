<?php
require_once __DIR__ . '/../includes/auth.php';
requireAdminPage();
// The shared admin guard also admits Accounting; this prototype is Admin only.
if (currentRole() !== 'admin') {
    http_response_code(403);
    exit('Admin access required.');
}
header('Cache-Control: no-store');
header('Permissions-Policy: camera=(self), microphone=()');
session_write_close();
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Face prototype</title>
<style>
*{box-sizing:border-box}body{margin:0;background:#f3f5f8;color:#172234;font:16px system-ui,sans-serif}main{max-width:1000px;margin:36px auto;padding:24px;background:white;border-radius:16px}h1{margin-top:0}p{line-height:1.5}.controls{display:flex;gap:12px;flex-wrap:wrap;margin:20px 0}button{padding:12px 18px;border:0;border-radius:8px;background:#174bb8;color:white;font:inherit;cursor:pointer}button:disabled{opacity:.45;cursor:default}.feed{position:relative;width:100%;background:#142035;min-height:180px;border-radius:10px;overflow:hidden}video{display:block;width:100%;height:auto}#overlay{position:absolute;inset:0;width:100%;height:100%;pointer-events:none}#status{padding:12px;background:#edf2ff;border-radius:8px}#status.error{background:#fee2e2;color:#991b1b}pre{white-space:pre-wrap;overflow-wrap:anywhere;background:#f3f5f8;padding:16px;max-height:320px;overflow:auto}small{color:#526079}
</style>
<script defer src="../assets/vendor/face-api/face-api.min.js"></script>
<script defer src="face-prototype.js"></script>
</head>
<body><main>
<h1>Face recognition prototype</h1>
<p>Webcam detection and descriptor inspection only. Frames and descriptors stay in this browser tab. Nothing is saved or linked to an employee or attendance record.</p>
<div class="controls"><button id="start" disabled>Start camera</button><button id="capture" disabled>Compute descriptor</button><button id="stop" disabled>Stop camera</button></div>
<p id="status" role="status" aria-live="polite">Loading local models…</p>
<div class="feed"><video id="video" autoplay muted playsinline></video><canvas id="overlay" aria-hidden="true"></canvas></div>
<h2>Captured descriptor</h2>
<p id="confidence">No capture yet.</p>
<small>Detection confidence measures face detection, not identity accuracy. Keep exactly one face visible to compute a descriptor. This prototype does not identify people or check liveness.</small>
<pre id="descriptor">The 128-number descriptor will appear here.</pre>
<noscript>Enable JavaScript to use the camera prototype.</noscript>
</main></body></html>