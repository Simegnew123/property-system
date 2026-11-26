<?php
/**
 * Authentication Helpers
 * Admin login check, role guard, CSRF token generation/validation.
 */
declare(strict_types=1);

// ─── Check if user is logged in ───
function isLoggedIn(): bool {
    return isset($_SESSION['user_id']) && isset($_SESSION['user_role']);
}

// ─── Require admin or accountant role ───
function requireAdminOrAccountant(): void {
    if (!isLoggedIn()) {
        header('Location: login.php');
        exit;
    }
    if (!in_array($_SESSION['user_role'], ['admin', 'accountant'], true)) {
        http_response_code(403);
        die('Access denied. Admin or Accountant role required.');
    }
}

// ─── Require any logged-in staff ───
function requireStaff(): void {
    if (!isLoggedIn()) {
        header('Location: login.php');
        exit;
    }
}

// ─── CSRF Token Management ───
function csrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfField(): string {
    return '<input type="hidden" name="csrf_token" value="' . csrfToken() . '">';
}

function verifyCsrf(): bool {
    $token = $_POST['csrf_token'] ?? '';
    return !empty($token) && hash_equals($_SESSION['csrf_token'] ?? '', $token);
}

// ─── HTML helpers ───
function e(string $str): string {
    return htmlspecialchars($str, ENT_QUOTES, 'UTF-8');
}
