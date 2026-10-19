'use strict';
(async () => {
    // Initial usability parameters, not liveness checks or mandated timing standards.
    const TIMING = Object.freeze({
        stableFaceMs: 2000,
        detectionIntervalMs: 180,
        maxDetectionGapMs: 1500,
        scanTimeoutMs: 30000,
        requestTimeoutMs: 15000,
        resultMs: 8000,
        extensionMs: 10000,
        maxResultMs: 60000,
        countdownTickMs: 200
    });
    const el = id => document.getElementById(id);
    const video = el('video'), overlay = el('overlay'), frame = document.createElement('canvas');
    const timeIn = el('time-in'), timeOut = el('time-out'), stop = el('stop');
    const resultPanel = el('result-panel'), extend = el('extend-result');
    let phase = 'loading', ready = false, action = null, generation = 0, stream = null;
    let opening = false, detecting = false, requesting = false, requestController = null;
    let scanTimer, loopTimer, resultTimer;
    let stableSince = null, lastDetectionAt = null, scanDeadline = 0;
    let resultDeadline = 0, resultLimit = 0;
    const now = () => performance.now();
    const label = value => value === 'time_in' ? 'Time In' : 'Time Out';
    const options = () => new faceapi.TinyFaceDetectorOptions({inputSize: 320, scoreThreshold: 0.5});
    const outcomes = {
        time_in: ['Time In recorded', 'success'],
        time_out: ['Time Out recorded', 'success'],
        duplicate_time_in: ['Time In already recorded', 'notice'],
        duplicate_time_out: ['Time Out already recorded', 'notice'],
        cooldown: ['Please wait before another scan', 'notice'],
        not_recognized: ['Face not recognized', 'notice'],
        missing_time_in: ['Time In required', 'notice'],
        review: ['Admin review needed', 'notice'],
        invalid_action: ['Invalid attendance action', 'error'],
        cancelled: ['Scan cancelled', 'notice'],
        scan_timeout: ['Scan timed out', 'notice'],
        camera_error: ['Camera error', 'error'],
        detection_error: ['Face detection error', 'error'],
        network_error: ['Result not confirmed', 'error'],
        server_error: ['Attendance request failed', 'error']
    };

    function message(text, error = false) {
        el('status').textContent = text;
        el('status').className = error ? 'error' : '';
    }
    function controls() {
        const busy = opening || detecting || requesting;
        timeIn.disabled = timeOut.disabled = !ready || !['welcome', 'result'].includes(phase) || busy;
        stop.disabled = phase !== 'opening' && phase !== 'scanning';
        if (phase === 'result') message(busy
            ? 'Camera is off. Finishing the previous attempt safely.'
            : 'Camera is off. The next employee may choose Time In or Time Out.');
    }
    function resetStability() {
        stableSince = lastDetectionAt = null;
        el('stability-progress').value = 0;
    }
    function stopTracks() {
        const previous = stream;
        stream = null; // Intentional shutdown must not look like a disconnected camera.
        if (previous) previous.getTracks().forEach(track => track.stop());
        video.srcObject = null;
        frame.width = frame.height = overlay.width = overlay.height = 0;
        resetStability();
    }
    function clearScanTimers() {
        clearTimeout(scanTimer);
        clearTimeout(loopTimer);
    }
    function clearResult() {
        clearTimeout(resultTimer);
        resultDeadline = resultLimit = 0;
        resultPanel.hidden = true;
        resultPanel.dataset.kind = 'notice';
        for (const id of ['result-title', 'result-employee', 'result-action', 'result', 'result-countdown', 'extension-status']) {
            el(id).textContent = '';
        }
        extend.disabled = true;
    }
    function welcome(focus = true) {
        clearResult();
        phase = ready ? 'welcome' : 'loading';
        action = null;
        el('selected-action').textContent = '';
        el('scan-panel').hidden = true;
        message(opening
            ? 'Camera is off. Finish or dismiss the camera permission prompt before retrying.'
            : detecting || requesting
                ? 'Camera is off. Waiting for the previous attempt to finish safely.'
                : 'Welcome. Camera is off. Choose Time In or Time Out.');
        controls();
        if (focus && !timeIn.disabled && !document.hidden) timeIn.focus();
    }
    function tickResult(token) {
        if (token !== generation || phase !== 'result') return;
        clearTimeout(resultTimer);
        const remaining = resultDeadline - now();
        if (remaining <= 0) { welcome(); return; }
        el('result-countdown').textContent = 'Result clears in ' + Math.ceil(remaining / 1000) + ' seconds. A new scan clears it immediately.';
        extend.disabled = resultDeadline >= resultLimit;
        resultTimer = setTimeout(() => tickResult(token), Math.min(TIMING.countdownTickMs, remaining));
    }
    function showResult(code, text, employee = '') {
        const selectedAction = action;
        generation++;
        clearScanTimers();
        stopTracks();
        clearResult();
        phase = 'result';
        action = null;
        el('selected-action').textContent = '';
        el('scan-panel').hidden = true;
        const [title, kind] = outcomes[code] || outcomes.server_error;
        resultPanel.dataset.kind = kind;
        el('result-title').textContent = title;
        el('result-employee').textContent = employee ? 'Employee: ' + employee : '';
        el('result-action').textContent = selectedAction ? 'Selected action: ' + label(selectedAction) : '';
        el('result').textContent = text;
        el('extension-status').textContent = 'Need longer to read? Add 10 seconds, up to 60 seconds total. This does not block the next employee; a new scan clears this result.';
        resultPanel.hidden = false;
        message('Camera is off. Read your result below.');
        resultLimit = now() + TIMING.maxResultMs;
        resultDeadline = Math.min(resultLimit, now() + TIMING.resultMs);
        controls();
        tickResult(generation);
        if (!document.hidden) el('result-title').focus();
    }
    function extendResult() {
        if (phase !== 'result' || extend.disabled) return;
        // Replace the one timer synchronously so its old deadline cannot clear an extension.
        clearTimeout(resultTimer);
        resultDeadline = Math.min(resultLimit, Math.max(resultDeadline, now()) + TIMING.extensionMs);
        el('extension-status').textContent = resultDeadline >= resultLimit
            ? 'Maximum display time reached. The result will clear automatically for privacy.'
            : 'Result display extended. A new scan still clears it immediately. You can extend again if needed.';
        tickResult(generation);
    }
    // Extend on press, rather than waiting for mouse release near the deadline.
    extend.addEventListener('pointerdown', event => {
        if (event.button === 0) extendResult();
    });
    extend.addEventListener('keydown', event => {
        if (event.key === 'Enter' || event.key === ' ') {
            event.preventDefault();
            if (!event.repeat) extendResult();
        }
    });
    // Native keyboard/screen-reader activation may dispatch a click without a pointer.
    extend.addEventListener('click', event => { if (event.detail === 0) extendResult(); });

    function cameraError(error) {
        const errors = {
            NotAllowedError: 'Camera permission denied. Allow camera access, then try again from the welcome screen.',
            SecurityError: 'Camera access is blocked by browser security settings.',
            NotFoundError: 'No webcam found. Connect the camera before trying again.',
            NotReadableError: 'Camera unavailable. Close other apps using it before trying again.',
            OverconstrainedError: 'The camera cannot provide the requested video settings.',
            AbortError: 'Camera startup was interrupted. Try again from the welcome screen.'
        };
        return errors[error.name] || 'Camera could not start. Ask the guard to check the webcam.';
    }
    function activeScan(token) {
        return token === generation && phase === 'scanning' && !!stream && !document.hidden;
    }
    function scanTimedOut() {
        if (now() < scanDeadline) return false;
        showResult('scan_timeout', 'No scan was submitted within 30 seconds. Position yourself and choose an action again from the welcome screen.');
        return true;
    }
    async function submit(token, selectedAction, values) {
        // This state change occurs before any await: one POST per attempt.
        phase = 'submitting';
        requesting = true;
        clearScanTimers();
        stopTracks();
        controls();
        message('Recording ' + label(selectedAction) + '. Camera is off; please wait.');
        const body = new FormData();
        body.append('csrf_token', el('csrf').value);
        body.append('action', selectedAction);
        body.append('descriptor', JSON.stringify(values));
        values.fill(0);
        const controller = new AbortController();
        requestController = controller;
        const timeout = setTimeout(() => controller.abort(), TIMING.requestTimeoutMs);
        try {
            const response = await fetch('../../actions/face-kiosk-scan.php', {method: 'POST', body, signal: controller.signal});
            if (!(response.headers.get('content-type') || '').includes('application/json')) throw new Error('Unexpected response');
            const data = await response.json();
            if (token !== generation || phase !== 'submitting') return;
            if (!data || typeof data.message !== 'string') throw new Error('Invalid response');
            const code = typeof data.result === 'string' && outcomes[data.result] ? data.result : 'server_error';
            showResult(response.ok || code === 'invalid_action' ? code : 'server_error',
                data.message, typeof data.employee === 'string' ? data.employee : '');
        } catch (error) {
            if (token === generation && phase === 'submitting') {
                showResult('network_error', 'The result could not be confirmed. Ask the guard or admin to check attendance before retrying; it may already have been recorded. No automatic retry will be sent.');
            }
        } finally {
            clearTimeout(timeout);
            body.delete('descriptor');
            if (requestController === controller) requestController = null;
            requesting = false;
            controls();
        }
    }
    async function scan(token) {
        if (!activeScan(token) || detecting || scanTimedOut()) return;
        detecting = true;
        let captured = null, values = null;
        try {
            if (video.readyState < 2) { resetStability(); return; }
            // Detection only during positioning. No descriptors before the stability gate.
            const detections = await faceapi.detectAllFaces(video, options());
            if (!activeScan(token) || scanTimedOut()) return;
            overlay.width = video.videoWidth;
            overlay.height = video.videoHeight;
            faceapi.draw.drawDetections(overlay, detections);
            if (detections.length !== 1) {
                resetStability();
                message(detections.length
                    ? 'Multiple faces detected. Only one person may be in the camera view.'
                    : 'Position your face inside the camera view.');
                return;
            }
            const detectedAt = now();
            if (stableSince === null || lastDetectionAt === null || detectedAt - lastDetectionAt > TIMING.maxDetectionGapMs) {
                stableSince = detectedAt;
            }
            lastDetectionAt = detectedAt;
            const heldFor = detectedAt - stableSince;
            el('stability-progress').value = Math.min(1, heldFor / TIMING.stableFaceMs);
            message('One face detected. Hold still while the progress bar fills.');
            if (heldFor < TIMING.stableFaceMs) return;

            // Capture a fresh frame and recheck one face before sending its descriptor.
            frame.width = video.videoWidth;
            frame.height = video.videoHeight;
            frame.getContext('2d').drawImage(video, 0, 0);
            captured = await faceapi.detectAllFaces(frame, options()).withFaceLandmarks(true).withFaceDescriptors();
            if (!activeScan(token) || scanTimedOut()) return;
            if (captured.length !== 1) {
                resetStability();
                message('Capture needs exactly one face. Position your face and hold still again.');
                return;
            }
            values = Array.from(captured[0].descriptor);
            if (values.length !== 128 || !values.every(Number.isFinite)) throw new Error('Invalid descriptor');
            captured.forEach(result => result.descriptor.fill(0));
            captured = null;
            await submit(token, action, values);
        } catch (error) {
            if (token === generation && phase === 'scanning') {
                showResult('detection_error', 'Face detection failed. Try again from the welcome screen. If this continues, ask the guard to reload the page.');
            }
        } finally {
            if (values) values.fill(0);
            if (captured) captured.forEach(result => { if (result.descriptor) result.descriptor.fill(0); });
            captured = values = null;
            frame.width = frame.height = 0;
            detecting = false;
            controls();
            if (activeScan(token)) loopTimer = setTimeout(() => scan(token), TIMING.detectionIntervalMs);
        }
    }
    async function begin(selectedAction) {
        if (!ready || !['welcome', 'result'].includes(phase) || opening || detecting || requesting || document.hidden) return;
        action = selectedAction;
        phase = 'opening';
        opening = true;
        const token = ++generation;
        clearResult();
        clearScanTimers();
        resetStability();
        el('scan-panel').hidden = false;
        el('selected-action').textContent = label(action);
        message('Allow camera access, then position your face inside the camera view.');
        controls();
        stop.focus();
        scanDeadline = now() + TIMING.scanTimeoutMs;
        scanTimer = setTimeout(() => {
            if (token === generation && (phase === 'opening' || phase === 'scanning')) scanTimedOut();
        }, TIMING.scanTimeoutMs);
        try {
            const acquired = await navigator.mediaDevices.getUserMedia({video: {facingMode: 'user', width: {ideal: 640}, height: {ideal: 480}}, audio: false});
            if (token !== generation || document.hidden) {
                acquired.getTracks().forEach(track => track.stop());
                return;
            }
            stream = acquired;
            stream.getVideoTracks()[0].addEventListener('ended', () => {
                if (token === generation && stream && (phase === 'opening' || phase === 'scanning')) {
                    showResult('camera_error', 'Camera disconnected. Ask the guard to reconnect it before retrying.');
                }
            });
            video.srcObject = stream;
            await video.play();
            if (token === generation && !document.hidden && !scanTimedOut()) {
                phase = 'scanning';
                message('Position your face inside the camera view.');
                scan(token);
            }
        } catch (error) {
            if (token === generation) showResult('camera_error', cameraError(error));
        } finally {
            opening = false;
            if (phase === 'welcome') welcome(false);
            controls();
        }
    }
    timeIn.addEventListener('click', () => begin('time_in'));
    timeOut.addEventListener('click', () => begin('time_out'));
    stop.addEventListener('click', () => {
        if (phase === 'opening' || phase === 'scanning') showResult('cancelled', 'No attendance request was sent. Choose an action again after returning to welcome.');
    });
    document.addEventListener('visibilitychange', () => {
        if (document.hidden && (phase === 'opening' || phase === 'scanning')) {
            showResult('cancelled', 'Scan stopped because the tab was hidden. No attendance request was sent.');
        } else if (!document.hidden && phase === 'result') {
            tickResult(generation); // Enforce expiry even after browser timer throttling.
        }
    });
    window.addEventListener('pagehide', () => {
        generation++;
        clearScanTimers();
        stopTracks();
        if (requestController) requestController.abort();
        welcome(false); // Remove employee information when leaving the shared kiosk.
    });
    window.addEventListener('pageshow', () => {
        if (phase === 'result') tickResult(generation);
        controls();
    });
    if (!window.isSecureContext || !navigator.mediaDevices?.getUserMedia) {
        phase = 'unavailable';
        message('Camera access requires a supported browser on localhost. Ask the guard to check this kiosk.', true);
        return;
    }
    try {
        if (!window.faceapi) throw new Error('Library unavailable');
        const modelUrl = new URL('../../assets/vendor/face-api/models/', window.location.href).href;
        await Promise.all([
            faceapi.nets.tinyFaceDetector.loadFromUri(modelUrl),
            faceapi.nets.faceLandmark68TinyNet.loadFromUri(modelUrl),
            faceapi.nets.faceRecognitionNet.loadFromUri(modelUrl)
        ]);
        ready = true;
        welcome(false);
    } catch (error) {
        phase = 'unavailable';
        message('Local models could not load. Reload the page; if this continues, ask the admin to check the local model files.', true);
    }
})();
