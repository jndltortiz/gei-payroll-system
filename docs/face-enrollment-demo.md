# GEI one-laptop face attendance demo

Use Chrome on the XAMPP laptop via localhost. Face features refuse any database other than gei_test. Do not switch databases or run migrations for this demonstration.

## Preparation
- Confirm the existing gei_test installation contains employee_face_templates and audit_logs, with transactional InnoDB tables. The implementation has not been tested against the live database in this change.
- Use existing authorized admin credentials privately. The public demo-credential hint was removed; account credentials were not changed.
- Use two consenting people with two separate employee records. Verify which record belongs to each person before capturing.
- Use active employees for attendance. The kiosk excludes inactive employees even if they have enrollment samples.
- Confirm good lighting, camera permissions, and the local face-api models load.

## Walkthrough
1. Sign in as admin. Open Employees > Face Enrollment.
2. Select employee A. Register/add samples: capture 3–5 small pose variations, confirm consent, and save. Check enrolled sample count.
3. Try enrolling A's face under employee B. Expect duplicate rejection; neither enrollment should change.
4. Enroll person B under employee B. Verify the two employee records are correctly assigned.
5. Select Replace for A, capture new samples, consent and confirm replacement. Cancel once to demonstrate that cancellation changes nothing.
6. Sign out using the existing application logout control. Return to the login page and choose Face Attendance. Do not leave an admin session available on the shared kiosk.
7. A chooses Time In, holds one face in view for about two seconds, and checks the actual response. B can scan immediately after A's request settles.
8. Return via Back to Login, sign in as admin, and verify Attendance Records (Face ID method where applicable), dashboard and audit entries.
9. Test A's repeat scan: within 30 seconds it should report cooldown; after 30 seconds without matched attempts it should report duplicate Time In. It must never turn a second Time In into Time Out.
10. Test explicit Time Out after the cooldown; verify the same day's attendance row.
11. In Face Enrollment, select the intended employee and Remove enrollment. Cancel once, then confirm only if intended. Check Not enrolled and retained employee/attendance history. Re-enroll if this person will demonstrate attendance again.

## Safeguards and limits
- Registration and replacement require consent. Replacement and removal require explicit confirmation; all mutations recheck active admin authorization and CSRF.
- Successful template changes and non-biometric audit metadata commit together. Audit failure rolls back template changes.
- A named database connection lock serializes enrollment saves across employees; employee and template row locks protect writes. Direct SQL edits or other future writers must follow the same protocol.
- Duplicate checking compares every submitted sample to every other employee's stored templates (including inactive employees), using the kiosk's existing Euclidean distance < 0.5. Similar faces can be falsely rejected and different samples of the same person can evade this threshold. This is a prototype safeguard, not a calibrated identity guarantee.
- Invalid stored templates block registration/replacement for review. Confirmed removal remains available for the affected employee. No automatic cleanup is performed.
- Existing add mode remains supported: 3–5 samples per request, no total per-employee cap. Replace retains only the new samples.
- Templates remain sensitive JSON descriptors in the existing database, not encrypted at rest by this feature. Restrict laptop/database/backup access and remove consenting demo enrollments when no longer needed. No descriptors are added to the interface or audit text.
- No liveness detection: photos or phone replays may spoof recognition.
- Same-employee matched attempts restart the 30-second cooldown; other employees are unaffected. The shared IP limit remains 20 requests per minute.
- If a network request fails, reload status/check attendance before retrying: the server may have committed.
- Back to Login preserves normal session behavior: an already signed-in user is redirected to their existing portal. It does not silently log out an admin.
- Database integration, real webcam performance and concurrency remain manual verification tasks. If required tables, transactional engines or GET_LOCK support are unavailable, stop and investigate; do not bypass safeguards.

## Database-free regression checks
- PHP: tests/face-enrollment.test.php (27 assertions), tests/face-kiosk-cooldown.test.php (7).
- JavaScript: tests/face-enrollment-ui.test.cjs (9), tests/face-kiosk-queue.test.cjs (45).
- Run JS files with Node if installed. In the implementation session their test functions ran under V8 with simulated DOM, camera, models, HTTP and timers.

