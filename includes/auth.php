<?php

require_once __DIR__ . '/../config/config.php';

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
        if (userRole() === 'Principal') {
            header('Location: ' . BASE_URL . 'modules/principal/payroll-approval/index.php');
        } else {
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
    $lastName  = $_SESSION['user']['last_name']  ?? '';

    return trim($firstName . ' ' . $lastName);
}

function userRole(): string
{
    return $_SESSION['user']['role_name'] ?? '';
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

// ── Role-specific guards ──────────────────────────────────────────────────────

/**
 * Principal-only pages.
 * Non-principals → HR dashboard. Guests → login.
 */
function requirePrincipal(): void
{
    requireLogin();
    if (userRole() !== 'Principal') {
        header('Location: ' . BASE_URL . 'modules/dashboard/index.php');
        exit;
    }
}

/**
 * HR/Admin pages.
 * Principals → their portal. Guests → login.
 */
function requireHR(): void
{
    requireLogin();
    if (userRole() === 'Principal') {
        header('Location: ' . BASE_URL . 'modules/principal/payroll-approval/index.php');
        exit;
    }
}