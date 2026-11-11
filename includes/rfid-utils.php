<?php
/**
 * includes/rfid-utils.php
 * Shared helpers for RFID card registration and the RFID attendance kiosk.
 * Both sides must normalize card UIDs the same way so a registered card is
 * always recognized no matter how the reader formats its output.
 */

if (!function_exists('normalizeRfidUid')) {
    /**
     * Uppercases the UID and strips whitespace, colons and hyphens.
     * "04:a3:1f:9b" and "04 A3 1F 9B" both become "04A31F9B".
     */
    function normalizeRfidUid(?string $uid): string
    {
        return strtoupper((string)preg_replace('/[\s:\-]+/', '', (string)$uid));
    }

    /** A normalized UID is 4–64 letters/digits. */
    function isValidRfidUid(string $normalizedUid): bool
    {
        return (bool)preg_match('/^[A-Z0-9]{4,64}$/', $normalizedUid);
    }

    /** Masks a UID for display and audit logs, keeping only the last 4 characters. */
    function maskRfidUid(?string $uid): string
    {
        $uid = (string)$uid;
        if ($uid === '') return '';
        return '•••• ' . substr($uid, -4);
    }
}
