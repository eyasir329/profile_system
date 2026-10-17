<?php
declare(strict_types=1);

require_once __DIR__ . '/url.php';

/**
 * Server-side validators.
 * Each returns ['value' => $normalized, 'errors' => ['field' => 'message', ...]].
 * Views render errors inline next to the matching input.
 */

function validate_name(string $name): array
{
    $errors = [];
    $trim = trim($name);
    if ($trim === '') {
        $errors['name'] = 'Please enter your name.';
    } elseif (mb_strlen($trim, 'UTF-8') > 100) {
        $errors['name'] = 'Name must be 100 characters or fewer.';
    }
    return ['value' => $trim, 'errors' => $errors];
}

function validate_email(string $email): array
{
    $errors = [];
    $trim = strtolower(trim($email));
    if ($trim === '' || !filter_var($trim, FILTER_VALIDATE_EMAIL)) {
        $errors['email'] = 'Enter a valid email address.';
    } elseif (strlen($trim) > 150) {
        $errors['email'] = 'Email must be 150 characters or fewer.';
    }
    return ['value' => $trim, 'errors' => $errors];
}

function validate_password(string $password, bool $required = true): array
{
    $errors = [];
    $len = strlen($password);
    if ($required && $len === 0) {
        $errors['password'] = 'Please enter a password.';
    } elseif ($required && $len < 8) {
        // Only enforce the 8-char minimum on registration / password change.
        // Login (required: false) only needs a non-empty value because
        // we authenticate against the stored hash regardless of length.
        $errors['password'] = 'Password must be at least 8 characters.';
    } elseif ($len > 4096) {
        $errors['password'] = 'Password is too long.';
    }
    return ['value' => $password, 'errors' => $errors];
}

function validate_password_confirm(string $password, string $confirm): array
{
    $errors = [];
    if ($password !== $confirm) {
        $errors['confirm_password'] = 'Passwords do not match.';
    }
    return ['value' => $confirm, 'errors' => $errors];
}

/**
 * Validate the role-picker form on login: the user must pick exactly one
 * role from their assigned set.
 */
function validate_role_choice(string $value, array $allowed): array
{
    $errors = [];
    if ($value === '' || !in_array($value, $allowed, true)) {
        $errors['role'] = 'Please choose one of your assigned roles.';
    }
    return ['value' => $value, 'errors' => $errors];
}

/**
 * Validate a multi-value role selection (form checkboxes).
 * - $raw is expected to be an array of strings (one per checked box).
 * - $allowed is the whitelist of acceptable role values.
 * - Admin role is never self-assignable via this helper; callers must
 *   not include 'admin' in $allowed.
 * Returns ['value' => $clean (array), 'errors' => ['roles' => 'msg' | []]]
 */
function validate_roles(array $raw, array $allowed, bool $requireOne = true): array
{
    $errors = [];
    $clean = [];
    foreach ($raw as $r) {
        if (!is_string($r)) continue;
        $r = strtolower(trim($r));
        if (in_array($r, $allowed, true) && !in_array($r, $clean, true)) {
            $clean[] = $r;
        }
    }
    if ($requireOne && !$clean) {
        $errors['roles'] = 'Please choose at least one role (Student and/or Teacher).';
    }
    return ['value' => $clean, 'errors' => $errors];
}

/**
 * Select validator. Returns the value if it appears in the allowed list,
 * else null with an error flag.
 */
function validate_select(string $value, array $options, string $fieldName): array
{
    $errors = [];
    $trim = trim($value);
    if ($trim === '') {
        return ['value' => null, 'errors' => $errors];
    }
    if (!array_key_exists($trim, $options)) {
        $errors[$fieldName] = 'Please choose one of the available options.';
        return ['value' => null, 'errors' => $errors];
    }
    return ['value' => $trim, 'errors' => $errors];
}

/**
 * Log an activity event. Silently no-ops if the table doesn't exist yet
 * (e.g. during initial migration).
 */
function log_activity(?int $userId, string $kind, ?string $detail = null): void
{
    global $pdo;
    try {
        $st = $pdo->prepare('INSERT INTO activity (user_id, kind, detail) VALUES (:u, :k, :d)');
        $st->execute(['u' => $userId, 'k' => $kind, 'd' => $detail]);
    } catch (Throwable $e) {
        // table might not exist yet; never break a request for logging
    }
}

/**
 * Generate a base32-encoded random secret. Used for TOTP enrollment.
 */
function generate_totp_secret(int $bytes = 20): string
{
    // RFC 4648 base32 alphabet (no padding).
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bin = random_bytes($bytes);
    $out = '';
    foreach (str_split($bin) as $b) {
        $out .= $alphabet[ord($b) % 32];
    }
    return $out;
}

/**
 * Generate a one-time random token (hex) plus its hash (for storage).
 */
function generate_token_pair(): array
{
    $token  = bin2hex(random_bytes(32));
    $hash   = hash('sha256', $token);
    return ['token' => $token, 'hash' => $hash];
}
