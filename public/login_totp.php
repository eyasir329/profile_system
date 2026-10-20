<?php
/**
 * Two-step TOTP login.
 *
 * Route:  GET/POST /login_totp.php
 * Auth:   session has `pending_totp_user_id` (set by login.php after
 *         password verification if the user has `totp_required = 1`).
 * On POST: verify_csrf + second_factor_check(): the 6-digit code from the
 *          authenticator app, or a backup code (single use; the owner is
 *          emailed how many are left). 5 wrong tries per 10 minutes.
 */
declare(strict_types=1);
require_once __DIR__ . '/../src/includes/auth.php';
require_once __DIR__ . '/../src/includes/url.php';
require_once __DIR__ . '/../src/includes/mail_templates.php';

if (empty($_SESSION['pending_totp_user_id'])) {
    header('Location: login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $uid    = (int)$_SESSION['pending_totp_user_id'];
    $result = second_factor_check($uid, (string)($_POST['totp'] ?? ''));
    if ($result === 'locked') {
        log_activity($uid, 'login_2fa_locked', null);
        $err = 'Too many wrong codes. Wait 10 minutes and try again.';
    } elseif ($result === '') {
        log_activity($uid, 'login_2fa_failed', null);
        $err = 'That code didn’t work. Enter the newest code from your app, or one of your backup codes.';
    } else {
        if ($result === 'backup') {
            $pdo = auth_pdo();
            $who = $pdo->prepare('SELECT name, email FROM users WHERE id = :u');
            $who->execute(['u' => $uid]);
            if ($w = $who->fetch()) { mail_backup_code_used((string)$w['email'], (string)$w['name'], backup_codes_left($pdo, $uid)); }
            log_activity($uid, 'backup_code_used', null);
        }
        complete_totp_login($uid);
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
    <p class="page-desc">Open your authenticator app and enter the 6-digit code for this account. Lost your phone? Enter one of your backup codes instead.</p>
    <?php $totpErr = $err ?? ($_SESSION['totp_error'] ?? ''); unset($_SESSION['totp_error']); ?>
    <form class="mt-6" method="post" autocomplete="one-time-code">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
      <div class="field">
        <label class="label" for="totp">Code</label>
        <input class="input input-code w-[12rem]" id="totp" name="totp" type="text" maxlength="10" spellcheck="false" autocapitalize="characters"
               autocomplete="one-time-code" placeholder="000000" required autofocus aria-describedby="totp-hint<?= !empty($totpErr) ? ' totp-err' : '' ?>"
               <?= !empty($totpErr) ? 'aria-invalid="true"' : '' ?>>
        <p class="hint" id="totp-hint">6 digits from the app, or a backup code such as ABCD-1234.</p>
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
