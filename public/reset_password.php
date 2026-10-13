<?php
/**
 * Password reset (set a new password using a token).
 *
 * Route:  GET/POST /reset_password.php?token=<64-hex-chars>
 * Auth:   none
 * CSRF:   verify_csrf() on POST (and only if the token is valid)
 * Token rules: must be 64 hex chars, must hash to a row in `password_resets`
 *              with used_at IS NULL and expires_at > NOW().
 * On success:
 *   - UPDATE users SET password_hash
 *   - UPDATE password_resets SET used_at = NOW() (for this user)
 *   - log_activity($userId, 'password_reset')
 *   - 302 → /login.php?reset=1
 */
declare(strict_types=1);
require_once __DIR__ . '/../src/includes/auth.php';
require_once __DIR__ . '/../src/includes/db.php';
require_once __DIR__ . '/../src/includes/url.php';
require_once __DIR__ . '/../src/includes/validate.php';

if (is_logged_in()) {
    header('Location: ' . url_dashboard());
    exit;
}

$errors = [];
$rawToken = isset($_GET['token']) && is_string($_GET['token']) ? trim($_GET['token']) : '';
$validToken = false;
$userId = null;

if ($rawToken !== '' && ctype_xdigit($rawToken) && strlen($rawToken) === 64) {
    $tokenHash = hash('sha256', $rawToken);
    $st = $pdo->prepare(
        'SELECT pr.id, pr.user_id, pr.expires_at, pr.used_at
         FROM password_resets pr
         WHERE pr.token_hash = :h LIMIT 1'
    );
    $st->execute(['h' => $tokenHash]);
    $row = $st->fetch();
    if ($row && $row['used_at'] === null && strtotime($row['expires_at']) > time()) {
        $validToken = true;
        $userId = (int)$row['user_id'];
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $validToken) {
    verify_csrf();
    $vPw  = validate_password((string)($_POST['password'] ?? ''));
    $vPwC = validate_password_confirm((string)($_POST['password'] ?? ''), (string)($_POST['confirm_password'] ?? ''));
    $errors = array_merge($errors, $vPw['errors'], $vPwC['errors']);

    if (!$errors) {
        $newHash = password_hash($vPw['value'], PASSWORD_DEFAULT);
        $pdo->beginTransaction();
        try {
            $pdo->prepare('UPDATE users SET password_hash = :h WHERE id = :u')->execute(['h' => $newHash, 'u' => $userId]);
            $pdo->prepare('UPDATE password_resets SET used_at = NOW() WHERE user_id = :u AND used_at IS NULL')->execute(['u' => $userId]);
            $pdo->commit();
            log_activity($userId, 'password_reset', null);
            header('Location: ' . url_login() . '?reset=1');
            exit;
        } catch (Throwable $e) {
            $pdo->rollBack();
            $errors['password'] = 'Could not update password. Please try again.';
        }
    }
}

$page_title = 'Reset password';
$page_id    = 'reset';
require __DIR__ . '/../src/includes/header.php';
?>
<div class="page-narrow">
  <section class="card" aria-labelledby="reset-h">
    <h1 class="page-title text-2xl" id="reset-h">Choose a new password</h1>
    <?php if (!$validToken): ?>
      <div class="mt-5"><?= ui_alert('error', 'This reset link is invalid or has expired. Request a new one.') ?></div>
      <a class="btn btn-primary btn-block" href="<?= e(url_forgot()) ?>">Request a new link</a>
    <?php else: ?>
      <p class="page-desc">After saving you can log in with the new password straight away.</p>
      <form class="mt-6" method="post" action="<?= e(url_reset()) ?>?token=<?= e($rawToken) ?>" novalidate>
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <div class="field">
          <label class="label" for="password">New password</label>
          <div class="password-field" x-data="{ show: false }">
            <input class="input" id="password" name="password" type="password" :type="show ? 'text' : 'password'" required minlength="8" maxlength="4096" autocomplete="new-password" autofocus
                   <?= isset($errors['password']) ? 'aria-invalid="true"' : '' ?> aria-describedby="password-hint password-err">
            <?= ui_pw_toggle() ?>
          </div>
          <p class="hint" id="password-hint">At least 8 characters.</p>
          <?php if (isset($errors['password'])): ?><p class="field-error" id="password-err" role="alert"><?= e($errors['password']) ?></p><?php endif; ?>
        </div>
        <div class="field">
          <label class="label" for="confirm_password">Confirm new password</label>
          <div class="password-field" x-data="{ show: false }">
            <input class="input" id="confirm_password" name="confirm_password" type="password" :type="show ? 'text' : 'password'" required minlength="8" maxlength="4096" autocomplete="new-password"
                   <?= isset($errors['confirm_password']) ? 'aria-invalid="true" aria-describedby="confirm-err"' : '' ?>>
            <?= ui_pw_toggle() ?>
          </div>
          <?php if (isset($errors['confirm_password'])): ?><p class="field-error" id="confirm-err" role="alert"><?= e($errors['confirm_password']) ?></p><?php endif; ?>
        </div>
        <button class="btn btn-primary btn-block" type="submit">Save new password</button>
      </form>
    <?php endif; ?>
  </section>
</div>
<?php require __DIR__ . '/../src/includes/footer.php'; ?>