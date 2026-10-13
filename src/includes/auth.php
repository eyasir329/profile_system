<?php
declare(strict_types=1);

require_once __DIR__ . '/validate.php';
require_once __DIR__ . '/ratelimit.php';

if (session_status() !== PHP_SESSION_ACTIVE) {
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    // The Secure cookie flag is set when:
    //   - the request arrived over HTTPS directly, OR
    //   - we are behind a trusted proxy (TRUST_PROXY=1) and the proxy
    //     tells us the original scheme was https via X-Forwarded-Proto.
    // COOKIE_SECURE=1 forces the flag on regardless (for setups that
    // are always HTTPS, e.g. a permanent tunnel / load balancer).
    $cookieSecure = getenv('COOKIE_SECURE') === '1';
    if (!$cookieSecure) {
        $trustProxy   = getenv('TRUST_PROXY') === '1';
        $directHttps  = !empty($_SERVER['HTTPS']) && strcasecmp((string)$_SERVER['HTTPS'], 'off') !== 0;
        $forwardedHttps = $trustProxy
            && isset($_SERVER['HTTP_X_FORWARDED_PROTO'])
            && strcasecmp((string)$_SERVER['HTTP_X_FORWARDED_PROTO'], 'https') === 0;
        $cookieSecure = $directHttps || $forwardedHttps;
    }
    session_set_cookie_params([
        'httponly' => true,
        'secure'   => $cookieSecure,
        'samesite' => 'Lax',
    ]);
    session_start();
}

function e(mixed $value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function text_length(string $value): int
{
    return function_exists('mb_strlen') ? mb_strlen($value, 'UTF-8') : strlen($value);
}

function post_string(string $key): string
{
    $value = $_POST[$key] ?? '';
    return is_string($value) ? trim($value) : '';
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token']) || !is_string($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function verify_csrf(): void
{
    $submitted = $_POST['csrf_token'] ?? '';
    if (!is_string($submitted) || !hash_equals(csrf_token(), $submitted)) {
        http_response_code(419);
        exit('This form has expired or is invalid. Go back, refresh the page, and try again.');
    }
}

function require_login(): void
{
    if (!isset($_SESSION['user_id']) || !is_numeric($_SESSION['user_id'])) {
        header('Location: /login.php');
        exit;
    }
    // Soft-delete: if the user is deactivated, force a logout + banner.
    $pdo = auth_pdo();
    if ($pdo !== null) {
        try {
            $st = $pdo->prepare('SELECT deleted_at FROM users WHERE id = :id');
            $st->execute(['id' => (int)$_SESSION['user_id']]);
            $row = $st->fetch();
            if ($row && !empty($row['deleted_at'])) {
                $_SESSION = [];
                if (ini_get('session.use_cookies')) {
                    $p = session_get_cookie_params();
                    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
                }
                session_destroy();
                header('Location: /login.php?deactivated=1');
                exit;
            }
        } catch (Throwable $e) { /* ignore — don't break auth on DB hiccup */ }
    }
}

function require_admin(): void
{
    require_login();
    if (($_SESSION['user_role'] ?? '') !== 'admin') {
        http_response_code(403);
        exit('Forbidden — admin access required. Use the role switcher in the top bar to act as Admin.');
    }
}

/**
 * Return the user's currently ACTIVE role — i.e. the single role the
 * user chose to act as for this session (stored in $_SESSION['user_role']).
 *
 * Distinct from current_user_role() (in url.php) which returns the
 * HIGHEST-PRIVILEGE role for legacy UI capability checks. Use
 * active_user_role() when you need to know "what is this user doing
 * right now", not "what is this user allowed to do".
 */
function active_user_role(): string
{
    if (!is_logged_in()) return '';
    return (string)($_SESSION['user_role'] ?? '');
}

/**
 * Validate that the session's active role is still in the user's
 * current role set. If the admin revoked the active role while the
 * user was logged in, fall back to the highest-privilege remaining
 * role. If the user has no roles left, destroy the session.
 *
 * Called once per request from header.php, after the session is loaded
 * but before any nav rendering.
 *
 * Always fetches a fresh role set from the database (does NOT trust
 * the cached $_SESSION['user_roles']) so admin-side revocations take
 * effect immediately.
 */
function ensure_active_role_valid(): void
{
    if (!is_logged_in()) return;

    $pdo = auth_pdo();
    $uid = (int)current_user_id();

    // Always read fresh from DB so admin revocations take effect on
    // the next request, even if $_SESSION['user_roles'] is stale.
    $roles = [];
    if ($pdo !== null) {
        try {
            $st = $pdo->prepare('SELECT role FROM user_roles WHERE user_id = :u');
            $st->execute(['u' => $uid]);
            $roles = $st->fetchAll(PDO::FETCH_COLUMN);
        } catch (Throwable $e) {
            // If the table is missing/broken, fall back to whatever the
            // session had — better than logging them out.
            $roles = isset($_SESSION['user_roles']) && is_array($_SESSION['user_roles'])
                ? $_SESSION['user_roles']
                : [];
        }
    } else {
        $roles = isset($_SESSION['user_roles']) && is_array($_SESSION['user_roles'])
            ? $_SESSION['user_roles']
            : [];
    }

    if (!$roles) {
        // No roles at all — force re-login.
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
        }
        session_destroy();
        header('Location: /login.php?logged_out=1');
        exit;
    }

    // Sync the cached roles set with the fresh DB read.
    $_SESSION['user_roles'] = $roles;

    $active = (string)($_SESSION['user_role'] ?? '');
    if ($active === '' || !in_array($active, $roles, true)) {
        // Pick the highest-privilege role the user still holds.
        $oldActive = $active;
        foreach (['admin', 'teacher', 'student'] as $r) {
            if (in_array($r, $roles, true)) {
                $_SESSION['user_role'] = $r;
                if ($pdo !== null && $oldActive !== '' && $oldActive !== $r) {
                    try {
                        log_activity($uid, 'role_fallback', $oldActive . '->' . $r);
                    } catch (Throwable $e) { /* ignore */ }
                }
                break;
            }
        }
    }
}

/**
 * Return all roles currently assigned to the logged-in user.
 * Reads from the new `user_roles` join table. Falls back to deriving
 * a single role from the legacy `users.role` session key if the
 * join table is empty / unavailable (so this works during the window
 * before migration 004 has run).
 */
function current_user_roles(): array
{
    if (!is_logged_in()) return [];
    if (isset($_SESSION['user_roles']) && is_array($_SESSION['user_roles'])) {
        return $_SESSION['user_roles'];
    }
    // Lazily populate from the DB if the session was set before the
    // multi-role migration. Persist back to the session so subsequent
    // calls in the same request are free.
    $pdo = auth_pdo();
    if ($pdo === null) {
        $legacy = (string)($_SESSION['user_role'] ?? '');
        return $legacy !== '' ? [$legacy] : [];
    }
    $uid = (int)current_user_id();
    try {
        $st = $pdo->prepare('SELECT role FROM user_roles WHERE user_id = :u');
        $st->execute(['u' => $uid]);
        $rows = $st->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        $rows = [];
    }
    if (!$rows) {
        // Fall back to legacy single-value role so the user is not
        // locked out if the backfill hasn't run for some reason.
        $legacy = (string)($_SESSION['user_role'] ?? '');
        if ($legacy !== '') {
            // Mirror it into the join table so subsequent reads are fast.
            try {
                $pdo->prepare('INSERT IGNORE INTO user_roles (user_id, role) VALUES (:u, :r)')
                    ->execute(['u' => $uid, 'r' => $legacy]);
            } catch (Throwable $e) { /* ignore */ }
            $rows = [$legacy];
        }
    }
    $_SESSION['user_roles'] = $rows;
    return $rows;
}

function has_role(string $role): bool
{
    return in_array($role, current_user_roles(), true);
}

function require_teacher(): void
{
    require_login();
    if (!has_role('teacher') && !has_role('admin')) {
        http_response_code(403);
        exit('Forbidden — teacher or admin access required.');
    }
}

/**
 * Return the user's single "primary" role for legacy callers
 * (e.g. the top nav's Admin link). Picks the highest-privilege role
 * from the multi-role set so existing single-value checks still work.
 */
function primary_user_role(): string
{
    $roles = current_user_roles();
    foreach (['admin', 'teacher', 'student'] as $r) {
        if (in_array($r, $roles, true)) return $r;
    }
    return '';
}

/**
 * Lazily resolve the global $pdo without forcing every auth caller
 * to require db.php. Returns null if db.php hasn't been included.
 */
function auth_pdo(): ?PDO
{
    if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
        return $GLOBALS['pdo'];
    }
    $candidates = [
        __DIR__ . '/db.php',
    ];
    foreach ($candidates as $f) {
        if (is_file($f)) { require_once $f; break; }
    }
    return isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO ? $GLOBALS['pdo'] : null;
}

/**
 * RFC 6238 / RFC 4226 TOTP code generator.
 *
 * @param string $secret   Base32-encoded secret (no padding).
 * @param int|null $time   Unix timestamp; defaults to time().
 * @param int $digits      Number of digits (default 6).
 * @param int $period      Time step in seconds (default 30).
 * @return string|null     The OTP code, or null on decode failure.
 */
function totp_generate(string $secret, ?int $time = null, int $digits = 6, int $period = 30): ?string
{
    $time = $time ?? time();
    $counter = (int)floor($time / $period);

    // RFC 4648 base32 decoder (no padding, uppercase).
    $secret = strtoupper(rtrim($secret, '='));
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $binMap = [];
    for ($i = 0; $i < 32; $i++) { $binMap[$alphabet[$i]] = $i; }
    $bin = '';
    $bits = 0; $value = 0;
    for ($i = 0; $i < strlen($secret); $i++) {
        $ch = $secret[$i];
        if (!isset($binMap[$ch])) { return null; }
        $value = ($value << 5) | $binMap[$ch];
        $bits += 5;
        if ($bits >= 8) {
            $bits -= 8;
            $bin .= chr(($value >> $bits) & 0xFF);
        }
    }
    $key = $bin;

    // Counter as 8-byte big-endian.
    $msg = pack('N*', 0) . pack('N*', $counter & 0xFFFFFFFF);
    $hash = hash_hmac('sha1', $msg, $key, true);
    $offset = ord($hash[19]) & 0x0F;
    $code = (
        ((ord($hash[$offset])     & 0x7F) << 24) |
        ((ord($hash[$offset + 1]) & 0xFF) << 16) |
        ((ord($hash[$offset + 2]) & 0xFF) << 8)  |
         (ord($hash[$offset + 3]) & 0xFF)
    ) % (10 ** $digits);
    return str_pad((string)$code, $digits, '0', STR_PAD_LEFT);
}

/**
 * Constant-time comparison of a submitted TOTP code against the current
 * window. Allows ±1 step of clock drift (default ±30s).
 */
function totp_verify(string $secret, string $code, int $windowSteps = 1): bool
{
    $code = preg_replace('/\s+/', '', $code);
    if (!preg_match('/^\d{6,8}$/', $code)) { return false; }
    $now = time();
    for ($i = -$windowSteps; $i <= $windowSteps; $i++) {
        $candidate = totp_generate($secret, $now + $i * 30);
        if ($candidate !== null && hash_equals($candidate, $code)) { return true; }
    }
    return false;
}

/**
 * Require a TOTP step at login. Called after `verify_csrf` and after the
 * password has been verified but before the user is signed in.
 *
 * The session carries the user's id in `pending_totp_user_id` set by
 * `login.php`; we move it to `user_id` only when `totp_verify` succeeds.
 */
function require_totp_or_login(): void
{
    if (empty($_SESSION['pending_totp_user_id'])) { return; }
    $pendingId = (int)$_SESSION['pending_totp_user_id'];

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['totp'])) {
        verify_csrf();
        $code = (string)($_POST['totp'] ?? '');
        $pdo = auth_pdo();
        if ($pdo === null) { http_response_code(500); exit('DB unavailable'); }
        $st = $pdo->prepare('SELECT secret FROM two_factor WHERE user_id = :u AND enabled = 1');
        $st->execute(['u' => $pendingId]);
        $tfRow = $st->fetch();
        if (!$tfRow || !totp_verify((string)$tfRow['secret'], $code)) {
            http_response_code(401);
            $_SESSION['totp_error'] = 'Wrong code. Try again.';
            header('Location: login_totp.php');
            exit;
        }
        // Mark TOTP step OK and continue to login.
        $_SESSION['totp_verified'] = $pendingId;
        unset($_SESSION['pending_totp_user_id']);
        complete_totp_login($pendingId);
        return;
    }

    // No POST: redirect to the TOTP form.
    header('Location: login_totp.php');
    exit;
}

/**
 * Complete the login after a successful TOTP (or when not required).
 * Loads the user's display fields from `users`, sets the session, and
 * redirects to the post-login destination.
 */
function complete_totp_login(int $userId): void
{
    $pdo = auth_pdo();
    $st = $pdo->prepare('SELECT id, username, name FROM users WHERE id = :id AND deleted_at IS NULL');
    $st->execute(['id' => $userId]);
    $u = $st->fetch();
    if (!$u) {
        header('Location: /login.php?error=1');
        exit;
    }
    $_SESSION['user_id']       = (int)$u['id'];
    $_SESSION['user_username'] = (string)$u['username'];
    $_SESSION['user_name']     = (string)$u['name'];
    // Populate roles from `user_roles`.
    try {
        $rs = $pdo->prepare('SELECT role FROM user_roles WHERE user_id = :u');
        $rs->execute(['u' => $userId]);
        $roles = $rs->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) { $roles = []; }
    $_SESSION['user_roles'] = $roles;
    $_SESSION['user_role']  = $roles[0] ?? '';
    unset($_SESSION['totp_verified']);
    header('Location: ' . ($_SESSION['post_login_redirect'] ?? url_home()));
    unset($_SESSION['post_login_redirect']);
    exit;
}
