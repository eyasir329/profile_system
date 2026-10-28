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
    // Characters, not bytes: a Japanese character is 3 bytes in UTF-8, so
    // strlen() would let a 3-character password pass the 8-character rule.
    $len = mb_strlen($password, 'UTF-8');
    if ($required && $len === 0) {
        $errors['password'] = 'Please enter a password.';
    } elseif ($required && $len < 8) {
        // Only enforce the 8-char minimum on registration / password change.
        // Login (required: false) only needs a non-empty value because
        // we authenticate against the stored hash regardless of length.
        $errors['password'] = 'Password must be at least 8 characters.';
    } elseif (strlen($password) > 4096) {
        $errors['password'] = 'Password is too long.';
    }
    return ['value' => $password, 'errors' => $errors];
}

/**
 * First word of a name for greetings ("Hi Haruka"). Splits on any
 * whitespace, including the full-width space (U+3000) Japanese names use:
 * "佐藤　遥" -> "佐藤".
 */
function first_name(string $name): string
{
    $parts = preg_split('/[\s\x{3000}]+/u', trim($name), -1, PREG_SPLIT_NO_EMPTY);
    return $parts ? (string)$parts[0] : '';
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
 * Log an activity event, with the request's IP address, browser and the
 * role being acted as (shown to admins in Admin › Activity; cleared after
 * 90 days by housekeeping). Silently no-ops if the table doesn't exist yet
 * (e.g. during initial migration).
 */
function log_activity(?int $userId, string $kind, ?string $detail = null): void
{
    global $pdo;
    $web = PHP_SAPI !== 'cli';
    $ip  = $web && function_exists('client_ip') ? client_ip() : null;
    $ua  = $web ? mb_substr(trim((string)($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 255) : '';
    $role = isset($_SESSION['user_role']) && is_string($_SESSION['user_role']) ? $_SESSION['user_role'] : null;
    try {
        $st = $pdo->prepare('INSERT INTO activity (user_id, kind, detail, ip, user_agent, acting_role) VALUES (:u, :k, :d, :ip, :ua, :r)');
        $st->execute(['u' => $userId, 'k' => $kind, 'd' => $detail, 'ip' => $ip, 'ua' => $ua !== '' ? $ua : null, 'r' => $role]);
    } catch (Throwable $e) {
        try {
            // Before migration 035: the table has no context columns yet.
            $pdo->prepare('INSERT INTO activity (user_id, kind, detail) VALUES (:u, :k, :d)')
                ->execute(['u' => $userId, 'k' => $kind, 'd' => $detail]);
        } catch (Throwable $e2) {
            // table might not exist yet; never break a request for logging
        }
    }
}

/**
 * A new TOTP secret: $bytes random bytes (20 = 160 bits, RFC 4226's
 * recommendation), base32-encoded (RFC 4648, no padding). 20 bytes give the
 * standard 32-character key every authenticator app accepts.
 *
 * Earlier versions made one character per byte, so a 20-character key that
 * encodes 100 bits, not whole bytes: Google Authenticator rejects such keys
 * as "Key value is invalid". totp_secret_is_standard() spots them.
 */
function generate_totp_secret(int $bytes = 20): string
{
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $bits = '';
    foreach (str_split(random_bytes($bytes)) as $b) {
        $bits .= str_pad(decbin(ord($b)), 8, '0', STR_PAD_LEFT);
    }
    $out = '';
    foreach (str_split($bits, 5) as $chunk) {
        $out .= $alphabet[bindec(str_pad($chunk, 5, '0'))];
    }
    return $out;
}

/** Is this a key every authenticator app accepts (base32, whole bytes, at least 16 characters)? */
function totp_secret_is_standard(string $secret): bool
{
    return (bool)preg_match('/^[A-Z2-7]{16,}$/', $secret) && strlen($secret) % 8 === 0;
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
