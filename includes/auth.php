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

/**
 * Bare login check — no must_change_password redirect.
 * Use ONLY on the change-password page and its action handler.
 */
function requireLoginOnly(): void
{
    if (!isLoggedIn()) {
        header('Location: ' . BASE_URL . 'modules/auth/login.php');
        exit;
    }
}

/**
 * If the current user has must_change_password = 1, redirect to the
 * change-password page.  Call this from page-level guards only.
 */
function blockIfMustChangePassword(): void
{
    if (!empty($_SESSION['user']['must_change_password'])) {
        header('Location: ' . BASE_URL . 'modules/auth/change-password.php');
        exit;
    }
}

function guestOnly(): void
{
    if (isLoggedIn()) {
        if (isPrincipalRole()) {
            header('Location: ' . BASE_URL . 'modules/principal/payroll-approval/index.php');
        } elseif (isEmployee()) {
            header('Location: ' . BASE_URL . 'modules/employee/dashboard/index.php');
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
 * Employee self-service role.
 */
function isEmployee(): bool
{
    return currentRole() === 'employee';
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

/**
 * Returns true for ANY logged-in user who has a linked employee record.
 * Covers the 'employee', 'admin', and 'principal' / 'special assistant' roles.
 * Use as a predicate when you need to check self-service eligibility without
 * blocking — use requireEmployeeAccess() to enforce it as a page guard.
 */
function hasEmployeeAccess(): bool
{
    if (!isLoggedIn()) {
        return false;
    }

    return !empty($_SESSION['user']['employee_id']);
}

// ─────────────────────────────────────────────────────────────────────────────
// PAGE GUARDS
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Principal-only pages.
 */
function requirePrincipal(): void
{
    requireLogin();
    blockIfMustChangePassword();

    if (!isPrincipalRole()) {
        if (isEmployee()) {
            header('Location: ' . BASE_URL . 'modules/employee/dashboard/index.php');
        } else {
            header('Location: ' . BASE_URL . 'modules/dashboard/index.php');
        }
        exit;
    }
}

/**
 * Admin-only pages.
 */
function requireAdminPage(): void
{
    requireLogin();
    blockIfMustChangePassword();

    if (!isAdmin()) {
        if (isPrincipalRole()) {
            header('Location: ' . BASE_URL . 'modules/principal/payroll-approval/index.php');
        } elseif (isEmployee()) {
            header('Location: ' . BASE_URL . 'modules/employee/dashboard/index.php');
        } else {
            header('Location: ' . BASE_URL . 'modules/dashboard/index.php');
        }
        exit;
    }
}

/**
 * Employee-only pages.
 */
function requireEmployee(): void
{
    requireLogin();
    blockIfMustChangePassword();

    if (!isEmployee()) {
        if (isPrincipalRole()) {
            header('Location: ' . BASE_URL . 'modules/principal/dashboard/index.php');
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

/**
 * Self-service page guard — allows any role that has a linked employee record.
 * Employee, Admin, and Principal all pass through when employee_id is set.
 * If no employee_id is in the session, the user is redirected to their own
 * primary portal home page (not to the employee portal).
 *
 * Use this instead of requireEmployee() on any self-service page that should
 * also be accessible to Admin and Principal users.
 */
function requireEmployeeAccess(): void
{
    requireLogin();
    blockIfMustChangePassword();

    if (empty($_SESSION['user']['employee_id'])) {
        // User is logged in but has no employee record — send them home
        if (isPrincipalRole()) {
            header('Location: ' . BASE_URL . 'modules/principal/dashboard/index.php');
        } elseif (isAdmin()) {
            header('Location: ' . BASE_URL . 'modules/dashboard/index.php');
        } else {
            header('Location: ' . BASE_URL . 'modules/auth/login.php');
        }
        exit;
    }
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
 * Employee-only AJAX actions.
 */
function requireEmployeeAction(): void
{
    requireLogin();

    if (!isEmployee()) {
        http_response_code(403);

        echo json_encode([
            'success' => false,
            'message' => 'Access denied. Employee access required.',
        ]);

        exit;
    }
}

/**
 * Self-service AJAX guard — allows any role with a linked employee record.
 * Employee, Admin, and Principal all pass when employee_id is set in session.
 * Returns JSON 403 if not logged in or if no employee_id exists in session.
 *
 * Use this instead of requireEmployeeAction() on AJAX endpoints that should
 * also be callable by Admin and Principal users for their own data.
 */
function requireEmployeeAjax(): void
{
    requireLogin();

    if (empty($_SESSION['user']['employee_id'])) {
        http_response_code(403);

        echo json_encode([
            'success' => false,
            'message' => 'Access denied. No employee record is linked to your account.',
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