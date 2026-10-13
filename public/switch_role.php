<?php
/**
 * Switch the active role for the current session.
 *
 * Route:  POST /switch_role.php   (GET is rejected)
 * Auth:   require_login()
 * CSRF:   verify_csrf()
 *
 * Body:   csrf_token=<token>&role=<admin|teacher|student>
 *
 * Validates the requested role against the user's actual `user_roles`
 * set (fresh from DB, never trusted from session). On success:
 *   - $_SESSION['user_role']  = $chosen
 *   - $_SESSION['user_roles'] = $freshFromDB
 *   - log_activity($uid, 'role_switch', $chosen)
 *   - 302 → Referer (if same-origin) else /dashboard.php
 *
 * On failure: 400 with a short message. The user keeps their existing
 * active role.
 */
declare(strict_types=1);
require_once __DIR__ . '/../src/includes/auth.php';
require_once __DIR__ . '/../src/includes/db.php';
require_once __DIR__ . '/../src/includes/url.php';
require_once __DIR__ . '/../src/includes/validate.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit('Method not allowed.');
}

require_login();
verify_csrf();

$uid  = (int)current_user_id();
$want = (string)($_POST['role'] ?? '');

// Fetch fresh from DB so an admin's revocation takes effect immediately.
$st = $pdo->prepare('SELECT role FROM user_roles WHERE user_id = :u');
$st->execute(['u' => $uid]);
$allowed = $st->fetchAll(PDO::FETCH_COLUMN);

if (!in_array($want, $allowed, true)) {
    http_response_code(400);
    exit('You do not have that role. Reload the page and try again.');
}

// Apply the switch. Always sync the full roles set with the DB read
// (handles the case where the admin added/removed a different role
// between page loads).
$_SESSION['user_role']  = $want;
$_SESSION['user_roles'] = $allowed;
log_activity($uid, 'role_switch', $want);

// Same-origin redirect only.
$back = url_dashboard();
$ref  = $_SERVER['HTTP_REFERER'] ?? '';
if ($ref !== '') {
    $host = $_SERVER['HTTP_HOST'] ?? '';
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $allowed_prefix = $scheme . '://' . $host;
    if (str_starts_with($ref, $allowed_prefix . '/') || $ref === $allowed_prefix) {
        $back = $ref;
    }
}

header('Location: ' . $back);
exit;
