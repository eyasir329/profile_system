<?php
/**
 * Verify a pending email-change token.
 *
 * Route:  GET /verify_email.php?token=<hex>
 * Auth:   optional (must match the email_verifications row user_id).
 *
 * Marking `users.email_verified = 1` and copying `new_email` →
 * `users.email` happens here. The token row is marked `used_at`.
 * Requests come from src/includes/email_change.php. The address is checked
 * again (someone may have taken it meanwhile), and the OLD address is
 * emailed that the change happened.
 */
declare(strict_types=1);
require_once __DIR__ . '/../src/includes/auth.php';
require_once __DIR__ . '/../src/includes/db.php';
require_once __DIR__ . '/../src/includes/url.php';
require_once __DIR__ . '/../src/includes/mail_templates.php';

$token = (string)($_GET['token'] ?? '');
$err = '';
$okMsg = '';

if ($token === '' || !preg_match('/^[a-f0-9]{64}$/', $token)) {
    $err = 'Missing or invalid token.';
} else {
    $hash = hash('sha256', $token);
    $pdo = auth_pdo();
    $st = $pdo->prepare(
        'SELECT ev.*, u.name, u.email AS old_email FROM email_verifications ev
           JOIN users u ON u.id = ev.user_id
          WHERE ev.token_hash = :h AND ev.used_at IS NULL
            AND ev.expires_at > NOW() LIMIT 1'
    );
    $st->execute(['h' => $hash]);
    $row = $st->fetch();
    $taken = false;
    if ($row) {
        $t = $pdo->prepare('SELECT 1 FROM users WHERE email = :e AND id <> :u');
        $t->execute(['e' => $row['new_email'], 'u' => (int)$row['user_id']]);
        $taken = (bool)$t->fetch();
    }
    if (!$row) {
        $err = 'This verification link is invalid or has expired.';
    } elseif ($taken) {
        $err = 'Another account now uses this address, so it can’t be yours as well. Your email was not changed.';
    } else {
        try {
            $pdo->beginTransaction();
            $pdo->prepare('UPDATE users SET email = :e, email_verified = 1 WHERE id = :id')
                ->execute(['e' => $row['new_email'], 'id' => (int)$row['user_id']]);
            $pdo->prepare('UPDATE email_verifications SET used_at = NOW() WHERE id = :id')
                ->execute(['id' => (int)$row['id']]);
            $pdo->commit();
            log_activity((int)$row['user_id'], 'account_update', 'email');
            if (strcasecmp((string)$row['old_email'], (string)$row['new_email']) !== 0) {
                mail_email_changed((string)$row['old_email'], (string)$row['name'], (string)$row['new_email']);
            }
            $okMsg = 'Your email is now ' . $row['new_email'] . '. Use it to sign in from now on.';
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            $err = 'Could not verify your email. Please try again.';
        }
    }
}

$page_title = 'Verify email';
$page_id    = 'verify_email';
$body_class = 'no-topbar';
require __DIR__ . '/../src/includes/header.php';
?>
<div class="page-narrow">
  <section class="card" aria-labelledby="verify-h">
    <?php if ($okMsg): ?>
      <h1 class="page-title text-2xl" id="verify-h">Email verified</h1>
      <p class="page-desc"><?= e($okMsg) ?></p>
      <a class="btn btn-primary btn-block mt-6" href="<?= e(url_login()) ?>">Continue to log in</a>
    <?php else: ?>
      <h1 class="page-title text-2xl" id="verify-h">Verification failed</h1>
      <div class="mt-5"><?= ui_alert('warning', e($err)) ?></div>
      <a class="btn btn-primary btn-block" href="<?= e(url_home()) ?>">Go to the home page</a>
    <?php endif; ?>
  </section>
</div>
<?php require __DIR__ . '/../src/includes/footer.php'; ?>
