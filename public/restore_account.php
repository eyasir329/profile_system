<?php
/**
 * Restore a soft-deleted account.
 *
 * Route:  GET/POST /restore_account.php?token=<hex>
 * Auth:   none (the token is the credential).
 * On GET: show a confirmation form. On POST: consume the token, clear
 *         `deleted_at`, redirect to login.
 */
declare(strict_types=1);
require_once __DIR__ . '/../src/includes/auth.php';
require_once __DIR__ . '/../src/includes/db.php';
require_once __DIR__ . '/../src/includes/url.php';

$token = (string)($_GET['token'] ?? $_POST['token'] ?? '');
$err = '';
$userRow = null;
if ($token !== '' && preg_match('/^[a-f0-9]{64}$/', $token)) {
    $hash = hash('sha256', $token);
    $pdo = auth_pdo();
    $st = $pdo->prepare(
        'SELECT ar.*, u.id AS user_id, u.username, u.name, u.deleted_at
           FROM account_restorations ar
           JOIN users u ON u.id = ar.user_id
          WHERE ar.token_hash = :h AND ar.used_at IS NULL
            AND ar.expires_at > NOW() LIMIT 1'
    );
    $st->execute(['h' => $hash]);
    $userRow = $st->fetch();
    if (!$userRow) {
        $err = 'This restore link is invalid or has expired.';
    } elseif (empty($userRow['deleted_at'])) {
        $err = 'Your account is already active. You can log in.';
    }
} else {
    $err = 'Missing or invalid token.';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$err) {
    verify_csrf();
    $pdo->beginTransaction();
    try {
        $pdo->prepare('UPDATE users SET deleted_at = NULL, scheduled_purge_at = NULL WHERE id = :id')
            ->execute(['id' => (int)$userRow['user_id']]);
        $pdo->prepare('UPDATE account_restorations SET used_at = NOW() WHERE id = :id')
            ->execute(['id' => (int)$userRow['id']]);
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) { $pdo->rollBack(); }
        $err = 'Could not restore your account. Please try again.';
    }
    if (!$err) {
        header('Location: login.php?restored=1');
        exit;
    }
}

$page_title = 'Restore account';
$page_id    = 'restore_account';
$body_class = 'no-topbar';
require __DIR__ . '/../src/includes/header.php';
?>
<div class="page-narrow">
  <section class="card" aria-labelledby="restore-h">
    <h1 class="page-title text-2xl" id="restore-h">Restore your account</h1>
    <?php if ($err): ?>
      <div class="mt-5"><?= ui_alert('warning', e($err)) ?></div>
      <a class="btn btn-primary btn-block" href="<?= e(url_login()) ?>">Back to log in</a>
    <?php else: ?>
      <p class="page-desc">Hi <strong class="text-foreground"><?= e($userRow['name'] ?? '') ?></strong>. Restoring lets you sign in again; nothing on your profile was removed.</p>
      <form class="mt-6" method="post">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="token" value="<?= e($token) ?>">
        <button class="btn btn-primary btn-block" type="submit">Restore account</button>
      </form>
    <?php endif; ?>
  </section>
</div>
<?php require __DIR__ . '/../src/includes/footer.php'; ?>
