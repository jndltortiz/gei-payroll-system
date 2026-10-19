'use strict';
(async () => {
    const video = document.getElementById('video');
    const overlay = document.getElementById('overlay');
    const status = document.getElementById('status');
    const start = document.getElementById('start');
    const stop = document.getElementById('stop');
    const capture = document.getElementById('capture');
    const output = document.getElementById('descriptor');
    const confidence = document.getElementById('confidence');
    const frame = document.createElement('canvas');
    let stream = null, generation = 0, busy = false, ready = false;
    const options = () => new faceapi.TinyFaceDetectorOptions({ inputSize: 320, scoreThreshold: 0.5 });
    function message(text, error = false) { status.textContent = text; status.className = error ? 'error' : ''; }
    function clearOutput() { output.textContent = 'The 128-number descriptor will appear here.'; confidence.textContent = 'No capture yet.'; }
    function stopCamera() {
        generation++;
        if (stream) stream.getTracks().forEach(track => track.stop());
        stream = null;
        video.srcObject = null;
        overlay.getContext('2d').clearRect(0, 0, overlay.width, overlay.height);
        start.disabled = !ready; stop.disabled = true; capture.disabled = true;
        clearOutput();
    }
    function cameraError(error) {
        const errors = {
            NotAllowedError: 'Camera permission was denied. Allow the camera for this site in your browser and Windows privacy settings, then retry.',
            SecurityError: 'Camera access is blocked by browser or system security settings.',
            NotFoundError: 'No camera was found. Connect or enable a webcam, then retry.',
            NotReadableError: 'The camera could not start. Close other apps using it and check Windows camera access settings.',
            OverconstrainedError: 'The camera cannot provide the requested video settings.',
            AbortError: 'Camera startup was interrupted. Please retry.'
        };
        return errors[error.name] || 'Could not start the camera: ' + error.message;
    }
    function draw(results, width, height) {
        overlay.width = width; overlay.height = height;
        faceapi.draw.drawDetections(overlay, results);
    }
    async function detectLoop(token) {
        if (token !== generation || !stream) return;
        if (!busy && video.readyState >= 2) {
            busy = true;
            try {
                const detections = await faceapi.detectAllFaces(video, options());
                if (token !== generation) return;
                draw(detections, video.videoWidth, video.videoHeight);
                capture.disabled = detections.length !== 1;
                message(detections.length === 1 ? 'One face detected. You can compute a descriptor.' : detections.length ? 'Multiple faces detected. Keep only one face in view.' : 'No face detected. Face the camera and improve the lighting.');
            } catch (error) {
                if (token !== generation) return;
                stopCamera(); message('Face detection failed: ' + error.message + '. Reload the page or try a current Chrome/Edge browser.', true);
            } finally { busy = false; }
        }
        if (token === generation && stream) setTimeout(() => detectLoop(token), 180);
    }
    start.addEventListener('click', async () => {
        if (busy) return;
        start.disabled = true; stop.disabled = false;
        const token = ++generation;
        clearOutput(); message('Waiting for camera permission…');
        try {
            const acquired = await navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user', width: { ideal: 640 }, height: { ideal: 480 } }, audio: false });
            if (token !== generation) { acquired.getTracks().forEach(track => track.stop()); return; }
            stream = acquired;
            stream.getVideoTracks()[0].addEventListener('ended', () => { if (token === generation) { stopCamera(); message('The camera disconnected or access was revoked. Reconnect it and retry.', true); } });
            video.srcObject = stream;
            await video.play();
            if (token === generation) detectLoop(token);
        } catch (error) { if (token === generation) { stopCamera(); message(cameraError(error), true); } }
    });
    stop.addEventListener('click', () => { stopCamera(); message('Camera stopped. Descriptor cleared.'); });
    capture.addEventListener('click', async () => {
        if (!stream || busy || video.readyState < 2) return;
        busy = true; capture.disabled = true; clearOutput();
        const token = generation;
        try {
            frame.width = video.videoWidth; frame.height = video.videoHeight;
            frame.getContext('2d').drawImage(video, 0, 0);
            const results = await faceapi.detectAllFaces(frame, options()).withFaceLandmarks(true).withFaceDescriptors();
            if (token !== generation) return;
            if (results.length !== 1) { message('Capture needs exactly one face. Adjust your position and try again.', true); return; }
            const result = results[0];
            const values = Array.from(result.descriptor);
            if (values.length !== 128 || !values.every(Number.isFinite)) throw new Error('Invalid descriptor output');
            output.textContent = JSON.stringify(values, null, 2);
            confidence.textContent = 'Captured detection confidence: ' + (result.detection.score * 100).toFixed(2) + '% • 128 values • ' + new Date().toLocaleTimeString();
            draw(results, frame.width, frame.height);
            message('Descriptor computed locally. It remains visible until your next capture or camera stop.');
        } catch (error) { if (token === generation) message('Descriptor computation failed: ' + error.message, true); }
        finally { frame.width = 0; frame.height = 0; busy = false; }
    });
    window.addEventListener('pagehide', stopCamera);
    document.addEventListener('visibilitychange', () => { if (document.hidden && stream) { stopCamera(); message('Camera stopped because the tab was hidden.'); } });
    if (!window.isSecureContext) { message('Camera access requires HTTPS or localhost. Open http://localhost/gei-payroll-system/dev/face-prototype.php on the XAMPP computer, or use trusted HTTPS for a LAN address.', true); return; }
    if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) { message('This browser does not expose webcam access. Use a current Chrome, Edge, or Firefox browser on HTTPS or localhost.', true); return; }
    try {
        if (!window.faceapi) throw new Error('The local face-api.js library could not load');
        const modelUrl = new URL('../assets/vendor/face-api/models/', window.location.href).href;
        await Promise.all([
            faceapi.nets.tinyFaceDetector.loadFromUri(modelUrl),
            faceapi.nets.faceLandmark68TinyNet.loadFromUri(modelUrl),
            faceapi.nets.faceRecognitionNet.loadFromUri(modelUrl)
        ]);
        ready = true; start.disabled = false;
        message('Local models ready. Click Start camera and allow access.');
    } catch (error) { message('Local model loading failed: ' + error.message + '. Check that Apache can serve the files in assets/vendor/face-api/models/.', true); }
})();