<?php

require_once __DIR__ . '/../config/config.php';

// ─────────────────────────────────────────────────────────────────────────────
// BASIC AUTH HELPERS
// ─────────────────────────────────────────────────────────────────────────────

function isLoggedIn(): bool
{
    return isset($_SESSION['user']);
}

function requireLogin(): void
{
    if (!isLoggedIn()) {
        header('Location: ' . BASE_URL . 'modules/auth/login.php');
        exit;
    }
}

function guestOnly(): void
{
    if (isLoggedIn()) {

        // Principal / Special Assistant
        if (isPrincipalRole()) {
            header('Location: ' . BASE_URL . 'modules/principal/payroll-approval/index.php');
        }

        // Admin / Accounting
        else {
            header('Location: ' . BASE_URL . 'modules/dashboard/index.php');
        }

        exit;
    }
}

function user(): ?array
{
    return $_SESSION['user'] ?? null;
}

function userFullName(): string
{
    if (!isLoggedIn()) {
        return 'Guest';
    }

    $firstName = $_SESSION['user']['first_name'] ?? '';
    $lastName  = $_SESSION['user']['last_name'] ?? '';

    return trim($firstName . ' ' . $lastName);
}

function userRole(): string
{
    return $_SESSION['user']['role_name'] ?? '';
}

/**
 * Returns lowercase role name.
 */
function currentRole(): string
{
    return strtolower($_SESSION['user']['role_name'] ?? '');
}

function hasRole(array $roles): bool
{
    if (!isLoggedIn()) {
        return false;
    }

    return in_array(userRole(), $roles, true);
}

function redirectIfNoRole(array $roles): void
{
    if (!hasRole($roles)) {
        header('Location: ' . BASE_URL . 'modules/dashboard/index.php');
        exit;
    }
}

// ─────────────────────────────────────────────────────────────────────────────
// FLASH MESSAGES
// ─────────────────────────────────────────────────────────────────────────────

function setFlash(string $type, string $message): void
{
    $_SESSION['flash'] = [
        'type'    => $type,
        'message' => $message,
    ];
}

function getFlash(): ?array
{
    if (!isset($_SESSION['flash'])) {
        return null;
    }

    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);

    return $flash;
}

// ─────────────────────────────────────────────────────────────────────────────
// ROLE HELPERS
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Admin / Accounting roles
 * (Payroll Officer, Treasurer, Bookkeeper, etc.)
 */
function isAdmin(): bool
{
    return currentRole() === 'admin';
}

/**
 * Principal-side approval roles
 * (Principal, Special Assistant)
 */
function isPrincipalRole(): bool
{
    return in_array(currentRole(), [
        'principal',
        'special assistant'
    ], true);
}

// ─────────────────────────────────────────────────────────────────────────────
// PAGE GUARDS
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Principal-only pages.
 * Non-principals → dashboard
 */
function requirePrincipal(): void
{
    requireLogin();

    if (!isPrincipalRole()) {
        header('Location: ' . BASE_URL . 'modules/dashboard/index.php');
        exit;
    }
}

/**
 * Admin-only pages.
 * Principals → principal portal
 */
function requireAdminPage(): void
{
    requireLogin();

    if (!isAdmin()) {

        // Redirect principal-side users back to their portal
        if (isPrincipalRole()) {
            header('Location: ' . BASE_URL . 'modules/principal/payroll-approval/index.php');
        } else {
            header('Location: ' . BASE_URL . 'modules/dashboard/index.php');
        }

        exit;
    }
}

/**
 * HR/Admin pages alias.
 * Keeps backward compatibility with older code.
 */
function requireHR(): void
{
    requireAdminPage();
}

// ─────────────────────────────────────────────────────────────────────────────
// AJAX / JSON ACTION GUARDS
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Admin-only AJAX actions.
 */
function requireAdminAction(): void
{
    requireLogin();

    if (!isAdmin()) {
        http_response_code(403);

        echo json_encode([
            'success' => false,
            'message' => 'Access denied. Admin access required.',
        ]);

        exit;
    }
}

/**
 * Principal-only AJAX actions.
 */
function requirePrincipalAction(): void
{
    requireLogin();

    if (!isPrincipalRole()) {
        http_response_code(403);

        echo json_encode([
            'success' => false,
            'message' => 'Access denied. Principal access required.',
        ]);

        exit;
    }
}

/**
 * Allow both Admin and Principal roles
 * (shared approvals, reports, review pages, etc.)
 */
function requireAdminOrPrincipalAction(): void
{
    requireLogin();

    if (!isAdmin() && !isPrincipalRole()) {
        http_response_code(403);

        echo json_encode([
            'success' => false,
            'message' => 'Access denied.'
        ]);

        exit;
    }
}