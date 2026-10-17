<?php
declare(strict_types=1);

/**
 * URL helpers. Keep tiny and dependency-free.
 */

function app_base(): string
{
    // Files are served from the project root, so all URLs are root-relative.
    return '';
}

function url_for(string $path): string
{
    return app_base() . '/' . ltrim($path, '/');
}

function url_home(): string { return url_for('index.php'); }
function url_login(): string { return url_for('login.php'); }
function url_register(): string { return url_for('register.php'); }
function url_logout(): string { return url_for('logout.php'); }
function url_dashboard(): string { return url_for('dashboard.php'); }
function url_profile(): string { return url_for('profile.php'); }
function url_settings(): string { return url_for('settings.php'); }
function url_admin(): string { return url_for('admin/index.php'); }
function url_students(): string { return url_for('students.php'); }
function url_admin_user(): string { return url_for('admin/user.php'); }
function url_invites(): string { return url_for('invites.php'); }
function url_resources(): string { return url_for('resources.php'); }
function url_switch_role(): string { return url_for('switch_role.php'); }
function url_forgot(): string { return url_for('forgot_password.php'); }
function url_reset(): string  { return url_for('reset_password.php'); }

function is_logged_in(): bool
{
    return isset($_SESSION['user_id']) && is_numeric($_SESSION['user_id']);
}

function current_user_id(): ?int
{
    return is_logged_in() ? (int)$_SESSION['user_id'] : null;
}

function current_user_role(): string
{
    // Prefer the multi-role set's highest-privilege role so legacy
    // single-value checks (e.g. header.php's `$role === 'admin'`) keep
    // working after the migration. Fall back to the legacy session
    // value if the join table is empty / unavailable.
    if (function_exists('primary_user_role')) {
        $primary = primary_user_role();
        if ($primary !== '') return $primary;
    }
    return (string)($_SESSION['user_role'] ?? '');
}

function current_user_name(): string
{
    return (string)($_SESSION['user_name'] ?? '');
}
