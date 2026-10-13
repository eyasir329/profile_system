<?php
/**
 * Two-step TOTP login.
 *
 * Route:  GET/POST /login_totp.php
 * Auth:   session has `pending_totp_user_id` (set by login.php after
 *         password verification if the user has `totp_required = 1`).
 * On POST: verify_csrf + totp_verify; on success, complete the login.
 */
declare(strict_types=1);
require_once __DIR__ . '/../src/includes/auth.php';
require_once __DIR__ . '/../src/includes/url.php';

if (empty($_SESSION['pending_totp_user_id'])) {
    header('Location: login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $code = trim((string)($_POST['totp'] ?? ''));
    $pdo = auth_pdo();
    $st = $pdo->prepare('SELECT secret FROM two_factor WHERE user_id = :u AND enabled = 1');
    $st->execute(['u' => (int)$_SESSION['pending_totp_user_id']]);
    $row = $st->fetch();
    if (!$row || !totp_verify((string)$row['secret'], $code)) {
        $err = 'Wrong code. Try again.';
    } else {
        complete_totp_login((int)$_SESSION['pending_totp_user_id']);
        exit;
    }
}

$page_title = 'Two-factor code';
$page_id    = 'login_totp';
$body_class = 'no-topbar';
require __DIR__ . '/../src/includes/header.php';
?>
<div class="page-narrow">
  <section class="card" aria-labelledby="totp-h">
    <h1 class="page-title text-2xl" id="totp-h">Two-factor code</h1>
    <p class="page-desc">Open your authenticator app and enter the 6-digit code for this account.</p>
    <?php $totpErr = $err ?? ($_SESSION['totp_error'] ?? ''); unset($_SESSION['totp_error']); ?>
    <form class="mt-6" method="post" autocomplete="one-time-code">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
      <div class="field">
        <label class="label" for="totp">6-digit code</label>
        <input class="input input-code" id="totp" name="totp" type="text" inputmode="numeric" pattern="\d{6}" maxlength="6"
               autocomplete="one-time-code" placeholder="000000" required autofocus
               <?= !empty($totpErr) ? 'aria-invalid="true" aria-describedby="totp-err"' : '' ?>>
        <?php if (!empty($totpErr)): ?>
          <p class="field-error" id="totp-err" role="alert"><?= e($totpErr) ?></p>
        <?php endif; ?>
      </div>
      <button class="btn btn-primary btn-block" type="submit">Verify</button>
    </form>
    <p class="mt-6 text-sm text-muted-foreground-2">
      Codes change every 30 seconds. <a href="<?= e(url_login()) ?>">Use a different account</a>
    </p>
  </section>
</div>
<?php require __DIR__ . '/../src/includes/footer.php'; ?>
