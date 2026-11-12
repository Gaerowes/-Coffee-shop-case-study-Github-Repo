<?php
// Shared authentication and security helpers.
// Include this file before any HTML output on protected pages.

if (session_status() !== PHP_SESSION_ACTIVE) {
    // Recommended for production when the site is served over HTTPS:
    // ini_set('session.cookie_secure', '1');
    ini_set('session.use_strict_mode', '1');
    session_start();
}

function is_logged_in(): bool {
    return isset($_SESSION['user_id']) && isset($_SESSION['role']);
}

function is_admin(): bool {
    return is_logged_in() && $_SESSION['role'] === 'admin';
}

function is_customer(): bool {
    return is_logged_in() && $_SESSION['role'] === 'customer';
}

function is_cashier(): bool {
    return is_logged_in() && $_SESSION['role'] === 'cashier';
}

function e($value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function require_login(): void {
    if (!is_logged_in()) {
        header('Location: login.php');
        exit;
    }
}

function require_admin(): void {
    require_login();
    if (!is_admin()) {
        http_response_code(403);
        exit('403 - Access denied. This page is for admins only.');
    }
}

function require_customer(): void {
    require_login();
    if (!is_customer()) {
        http_response_code(403);
        exit('403 - Access denied. This page is for customers only.');
    }
}

function require_cashier(): void {
    require_login();
    if (!is_cashier()) {
        http_response_code(403);
        exit('403 - Access denied. This page is for cashiers only.');
    }
}

function csrf_token(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrf_field(): string {
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

function verify_csrf(): void {
    $submitted = $_POST['csrf_token'] ?? '';
    $stored = $_SESSION['csrf_token'] ?? '';

    if (!is_string($submitted) || !is_string($stored) ||
        $stored === '' || !hash_equals($stored, $submitted)) {
        http_response_code(419);
        exit('Invalid or expired request token. Please go back and try again.');
    }
}
?>
