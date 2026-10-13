<?php
/**
 * Logout (POST only).
 *
 * Route:  POST /logout.php
 * Auth:   none (rejects anything that isn't POST)
 * CSRF:   verify_csrf() required
 * Side effects:
 *   - clears $_SESSION
 *   - expires the session cookie
 *   - session_destroy()
 *   - 302 → /login.php?logged_out=1
 */
declare(strict_types=1);
require_once __DIR__ . '/../src/includes/auth.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: dashboard.php');
    exit;
}

verify_csrf();

$_SESSION = [];

if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', [
        'expires' => time() - 42000,
        'path' => $params['path'],
        'domain' => $params['domain'],
        'secure' => $params['secure'],
        'httponly' => $params['httponly'],
        'samesite' => $params['samesite'] ?? 'Lax',
    ]);
}

session_destroy();
header('Location: login.php?logged_out=1');
exit;
