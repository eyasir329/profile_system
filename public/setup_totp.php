<?php
/**
 * TOTP enrollment (POST) and disable (POST action=disable).
 *
 * Route:  POST /setup_totp.php
 * Auth:   require_login()
 * Input:  action = "generate" (default) | "confirm" (with `code` field) | "disable"
 *         csrf_token
 *
 * The flow is:
 *   1) GET /settings.php?tab=security&enroll=1  → server generates a
 *      secret, stores it (enabled=0) and shows the QR.
 *   2) User scans + submits a 6-digit code here with action=confirm.
 *      On success, `enabled` flips to 1, `users.totp_required = 1`, and
 *      backup codes are returned once.
 *   3) action=disable clears the secret and sets totp_required = 0.
 */
declare(strict_types=1);
require_once __DIR__ . '/../src/includes/auth.php';
require_once __DIR__ . '/../src/includes/db.php';
require_once __DIR__ . '/../src/includes/url.php';
require_once __DIR__ . '/../src/includes/validate.php';

require_login();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit; }
verify_csrf();

$userId = (int)current_user_id();
$action = (string)($_POST['action'] ?? 'generate');
$pdo = auth_pdo();

if ($action === 'disable') {
    $pdo->prepare('DELETE FROM two_factor WHERE user_id = :u')->execute(['u' => $userId]);
    $pdo->prepare('UPDATE users SET totp_required = 0 WHERE id = :u')->execute(['u' => $userId]);
    header('Location: ' . url_settings() . '?tab=security&totp_disabled=1');
    exit;
}

if ($action === 'confirm') {
    $code = trim((string)($_POST['code'] ?? ''));
    $st = $pdo->prepare('SELECT secret FROM two_factor WHERE user_id = :u');
    $st->execute(['u' => $userId]);
    $row = $st->fetch();
    if (!$row || !totp_verify((string)$row['secret'], $code)) {
        header('Location: ' . url_settings() . '?tab=security&enroll=1&err=1#two-factor');
        exit;
    }
    // Generate backup codes.
    $codes = [];
    for ($i = 0; $i < 10; $i++) {
        $codes[] = strtoupper(bin2hex(random_bytes(4))); // 8 hex chars each
    }
    $pdo->prepare('UPDATE two_factor SET enabled = 1, backup_codes = :bc WHERE user_id = :u')
        ->execute(['bc' => json_encode($codes), 'u' => $userId]);
    $pdo->prepare('UPDATE users SET totp_required = 1 WHERE id = :u')
        ->execute(['u' => $userId]);
    // The codes are read back from the DB by settings.php; keep them out of the URL.
    header('Location: ' . url_settings() . '?tab=security&totp_enabled=1#two-factor');
    exit;
}

// default: generate
$secret = generate_totp_secret();
$pdo->prepare(
    'INSERT INTO two_factor (user_id, secret, enabled) VALUES (:u, :s, 0)
     ON DUPLICATE KEY UPDATE secret = VALUES(secret), enabled = 0'
)->execute(['u' => $userId, 's' => $secret]);
header('Location: ' . url_settings() . '?tab=security&enroll=1#two-factor');
exit;
