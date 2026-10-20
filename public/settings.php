<?php
/**
 * Account settings (Account / Appearance / Security / Danger zone).
 *
 * Route:  GET/POST /settings.php?tab=account|appearance|security|danger
 * Auth:   require_login()
 * CSRF:   verify_csrf() on POST
 * Actions:
 *   - save_account:    update name/email (and theme, if
 *                      posted). Logs 'account_update'.
 *   - save_theme:      update the theme only. Logs 'theme_update'.
 *   - change_password: verify current password, hash + set new. Logs 'password_change'.
 *   - delete_account:  verify current password + typed 'delete my account' confirmation,
 *                      soft-delete the user (deleted_at + 30-day purge date).
 *                      Logs 'account_deactivate'. Destroys session, 302 → /?deactivated=1.
 *
 * A successful save redirects back with ?saved=<what> (so a refresh never
 * re-submits); a failed one re-renders the tab with the errors inline.
 */
declare(strict_types=1);
require_once __DIR__ . '/../src/includes/auth.php';
require_once __DIR__ . '/../src/includes/db.php';
require_once __DIR__ . '/../src/includes/url.php';
require_once __DIR__ . '/../src/includes/validate.php';
require_once __DIR__ . '/../src/includes/lab_fields.php';
require_once __DIR__ . '/../src/includes/ui.php';
require_once __DIR__ . '/../src/includes/mail_templates.php';
require_once __DIR__ . '/../src/includes/email_change.php';
require_login();

$userId = (int)current_user_id();
$TABS = [
    'account'    => ['Account',     'user'],
    'appearance' => ['Appearance',  'palette'],
    'security'   => ['Security',    'lock'],
    'danger'     => ['Danger zone', 'alert'],
];
$tab = (string)($_GET['tab'] ?? 'account');
if (!isset($TABS[$tab])) { $tab = 'account'; }

$THEMES = [
    'light' => ['Light', 'Always use the light theme. This is the default.'],
    'dark'  => ['Dark', 'Always use the dark theme.'],
    'auto'  => ['Match my system', 'Follows the light or dark setting of this device.'],
];

$flash = [];
$errors = [];

// Load current user + profile row.
$st = $pdo->prepare('SELECT id, name, email, email_verified FROM users WHERE id = :id LIMIT 1');
$st->execute(['id' => $userId]);
$me = $st->fetch();
if (!$me) { $_SESSION = []; session_destroy(); header('Location: ' . url_login()); exit; }

$prof = $pdo->prepare('SELECT theme FROM profiles WHERE user_id = :u LIMIT 1');
$prof->execute(['u' => $userId]);
$profRow = $prof->fetch() ?: ['theme' => 'light'];

$values = [
    'name'        => $me['name'],
    'email'       => $me['email'],
    'theme'       => (string)($profRow['theme'] ?? 'light'),
];

/** Store the theme and mirror it into the cookie header.php reads. */
$saveTheme = function (string $theme) use ($pdo, $userId): void {
    $pdo->prepare('INSERT IGNORE INTO profiles (user_id) VALUES (:u)')->execute(['u' => $userId]);
    $pdo->prepare('UPDATE profiles SET theme = :t WHERE user_id = :u')->execute(['t' => $theme, 'u' => $userId]);
    setcookie('ps_theme', $theme, ['expires' => time() + 60 * 60 * 24 * 365, 'path' => '/', 'samesite' => 'Lax']);
    log_activity($userId, 'theme_update', 'theme=' . $theme);
};
$done = function (string $tab, string $what): void {
    header('Location: ' . url_settings() . '?tab=' . $tab . '&saved=' . $what);
    exit;
};

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = $_POST['action'] ?? '';

    if ($action === 'save_account') {
        $vName     = validate_name((string)($_POST['name'] ?? ''));
        $vEmail    = validate_email((string)($_POST['email'] ?? ''));
        $values['name']        = $vName['value'];
        $values['email']       = $vEmail['value'];
        $errors = array_merge($errors, $vName['errors'], $vEmail['errors']);
        $newTheme = null;
        if (isset($_POST['theme'])) {
            $vTheme = validate_select((string)$_POST['theme'], $THEMES, 'theme');
            $errors = array_merge($errors, $vTheme['errors']);
            $newTheme = $vTheme['value'];
        }
        if (!$errors) {
            try {
                // check email uniqueness too
                $exists = $pdo->prepare('SELECT id FROM users WHERE email = :e AND id <> :u');
                $exists->execute(['e' => $values['email'], 'u' => $userId]);
                if ($exists->fetch()) {
                    $errors['email'] = 'That email is already in use.';
                } else {
                    // The name saves now. A new email waits for confirmation
                    // from the new address (src/includes/email_change.php).
                    $pdo->prepare('UPDATE users SET name = :n WHERE id = :id')
                        ->execute(['n' => $values['name'], 'id' => $userId]);
                    $_SESSION['user_name'] = $values['name'];
                    log_activity($userId, 'account_update', 'name');
                    if ($newTheme !== null) { $saveTheme($newTheme); }
                    $emailResult = strcasecmp((string)$me['email'], (string)$values['email']) !== 0
                        ? request_email_change($pdo, $userId, (string)$values['name'], (string)$values['email'])
                        : '';
                    if ($emailResult === 'failed') {
                        $errors['email'] = 'We couldn’t send the confirmation email, so your email address was not changed. Try again later.';
                    } else {
                        $done('account', $emailResult === 'sent' ? 'email_sent' : 'account');
                    }
                }
            } catch (PDOException $e) {
                $errors['form'] = 'Could not save. Please try again.';
            }
        }
        $tab = 'account';
    }

    if ($action === 'save_theme') {
        $vTheme = validate_select((string)($_POST['theme'] ?? ''), $THEMES, 'theme');
        if ($vTheme['errors'] || $vTheme['value'] === null) {
            $errors['theme'] = 'Please choose one of the available themes.';
            $tab = 'appearance';
        } else {
            $saveTheme($vTheme['value']);
            $done('appearance', 'theme');
        }
    }

    if ($action === 'change_password') {
        $current = (string)($_POST['current_password'] ?? '');
        $vPw  = validate_password((string)($_POST['new_password'] ?? ''));
        $vPwC = validate_password_confirm((string)($_POST['new_password'] ?? ''), (string)($_POST['confirm_password'] ?? ''));
        // validate_password() reports under 'password'; the field here is new_password.
        if (isset($vPw['errors']['password'])) { $errors['new_password'] = $vPw['errors']['password']; }
        $errors = array_merge($errors, $vPwC['errors']);

        $u = $pdo->prepare('SELECT password_hash FROM users WHERE id = :id');
        $u->execute(['id' => $userId]);
        $row = $u->fetch();
        if (!$row || !password_verify($current, $row['password_hash'])) {
            $errors['current_password'] = 'Current password is incorrect.';
        }
        if (!$errors) {
            $newHash = password_hash($vPw['value'], PASSWORD_DEFAULT);
            $pdo->prepare('UPDATE users SET password_hash = :h WHERE id = :id')->execute(['h' => $newHash, 'id' => $userId]);
            log_activity($userId, 'password_change', null);
            session_epoch_bump($pdo, $userId);   // signs out other devices, keeps this one
            mail_password_changed((string)$me['email'], (string)$me['name']);
            $done('security', 'password');
        }
        $tab = 'security';
    }

    if ($action === 'delete_account') {
        $confirm = trim((string)($_POST['confirm_text'] ?? ''));
        $current = (string)($_POST['current_password'] ?? '');
        $u = $pdo->prepare('SELECT password_hash FROM users WHERE id = :id');
        $u->execute(['id' => $userId]);
        $row = $u->fetch();
        if (strtolower($confirm) !== 'delete my account') {
            $errors['confirm_text'] = 'Type "delete my account" exactly to confirm.';
        } elseif (!$row || !password_verify($current, $row['password_hash'])) {
            $errors['delete_password'] = 'Current password is incorrect.';
        }
        if (!$errors) {
            // Soft-delete: set deleted_at + scheduled_purge_at (30 days).
            // Create an account_restorations token for the user to come back.
            $logDetail = $me['email'];
            $pdo->prepare(
                'UPDATE users SET deleted_at = NOW(), scheduled_purge_at = DATE_ADD(NOW(), INTERVAL 30 DAY) WHERE id = :id'
            )->execute(['id' => $userId]);
            $pair = generate_token_pair();
            try {
                $pdo->prepare(
                    'INSERT INTO account_restorations (user_id, token_hash, expires_at) VALUES (:u, :h, DATE_ADD(NOW(), INTERVAL 30 DAY))'
                )->execute(['u' => $userId, 'h' => $pair['hash']]);
            } catch (Throwable $e) { /* table not present yet */ }
            log_activity(null, 'account_deactivate', $logDetail);
            // The restore link is only ever sent by email (the token is not stored in clear).
            $restoreMailed = mail_account_deactivated((string)$me['email'], (string)$me['name'], $pair['token']);
            $_SESSION = [];
            session_destroy();
            header('Location: ' . url_home() . '?deactivated=' . ($restoreMailed ? '1' : '0'));
            exit;
        }
        $tab = 'danger';
    }
}

// Keep this device's cookie in step with the saved theme (it is what
// header.php applies), e.g. after signing in on a new browser.
if (($_COOKIE['ps_theme'] ?? '') !== $values['theme'] && isset($THEMES[$values['theme']])) {
    setcookie('ps_theme', $values['theme'], ['expires' => time() + 60 * 60 * 24 * 365, 'path' => '/', 'samesite' => 'Lax']);
    $_COOKIE['ps_theme'] = $values['theme'];
}

// One-time confirmations after a redirect.
$notices = [
    'account'  => 'Your account details were saved.',
    'email_sent' => 'Check your new email inbox: open the link we sent to confirm the change. Until then, keep signing in with your current address.',
    'theme'    => 'Theme updated.',
    'password' => 'Password changed. Use the new one next time you sign in.',
];
if (isset($_GET['saved'], $notices[(string)$_GET['saved']])) {
    $flash[] = ['type' => 'success', 'body' => $notices[(string)$_GET['saved']]];
}
if (isset($_GET['totp_disabled'])) {
    $flash[] = ['type' => 'success', 'body' => 'Two-factor authentication is off.'];
}

// Two-factor state (Security tab).
$tfEnabled = false; $tfSecret = ''; $tfCodes = []; $tfLeft = 0; $enrolling = false; $tfJustEnabled = false;
if ($tab === 'security') {
    $tfSt = $pdo->prepare('SELECT secret, enabled FROM two_factor WHERE user_id = :u');
    $tfSt->execute(['u' => $userId]);
    $tfRow = $tfSt->fetch();
    $tfEnabled = $tfRow && (int)$tfRow['enabled'] === 1;
    $tfSecret  = (string)($tfRow['secret'] ?? '');
    // A setup still waiting for its first code with an old, non-standard key
    // (Google Authenticator calls it "invalid"): swap in a standard one.
    // Nobody can have added it to an app successfully, so nothing breaks.
    if ($tfRow && !$tfEnabled && !totp_secret_is_standard($tfSecret)) {
        $tfSecret = generate_totp_secret();
        $pdo->prepare('UPDATE two_factor SET secret = :s WHERE user_id = :u AND enabled = 0')->execute(['s' => $tfSecret, 'u' => $userId]);
    }
    // Backup codes are stored hashed: the plain codes exist only right
    // after they were made (setup_totp.php), shown once here.
    if ($tfEnabled && !empty($_SESSION['tf_new_codes']) && is_array($_SESSION['tf_new_codes'])) {
        $tfCodes = array_map('strval', $_SESSION['tf_new_codes']);
    }
    unset($_SESSION['tf_new_codes']);
    $tfLeft = $tfEnabled ? backup_codes_left($pdo, $userId) : 0;
    $enrolling     = isset($_GET['enroll']) && !$tfEnabled && $tfSecret !== '';
    $tfJustEnabled = $tfEnabled && isset($_GET['totp_enabled']);
}

/** Attributes + error line for one field. */
$invalid = fn(string $key): string => isset($errors[$key]) ? ' aria-invalid="true"' : '';
$errorLine = function (string $key, string $id) use (&$errors): string {
    return isset($errors[$key])
        ? '<p class="field-error" id="' . $id . '" role="alert">' . e($errors[$key]) . '</p>'
        : '';
};

$page_title = 'Settings';
$page_id    = 'settings';
require __DIR__ . '/../src/includes/header.php';
?>
<div class="page">
  <header class="page-header">
    <div>
      <h1 class="page-title">Settings</h1>
      <p class="page-desc">Your account details, how the site looks, and how you sign in.</p>
    </div>
  </header>

  <div class="grid grid-cols-[minmax(0,1fr)] items-start gap-x-10 gap-y-6 lg:grid-cols-[12rem_minmax(0,1fr)]">
    <nav class="side-nav is-panel lg:sticky lg:top-24" aria-label="Settings sections">
      <?php foreach ($TABS as $key => [$label, $icon]): ?>
        <a href="?tab=<?= e($key) ?>" class="side-nav-item<?= $key === 'danger' ? ' is-danger' : '' ?>"<?= $tab === $key ? ' aria-current="page"' : '' ?>>
          <?= pf_icon($icon) ?> <span><?= e($label) ?></span>
        </a>
      <?php endforeach; ?>
    </nav>

    <div class="min-w-0 max-w-2xl">

    <?php if ($errors): ?>
      <?= ui_alert('error', isset($errors['form']) ? e($errors['form']) : 'Nothing was saved. Please fix the highlighted ' . (count($errors) === 1 ? 'field' : 'fields') . ' and try again.') ?>
    <?php endif; ?>

    <?php if ($tab === 'account'): ?>
      <form method="post" x-data="dirtyForm">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="save_account">

        <section class="card" aria-labelledby="set-details-h">
          <h2 class="card-title" id="set-details-h">Personal details</h2>
          <p class="card-desc">Your lab details are on <a href="<?= e(url_profile()) ?>">your profile</a>.</p>

          <div class="field">
            <label class="label" for="name">Full name</label>
            <input class="input" id="name" name="name" type="text" required maxlength="100" autocomplete="name"
                   value="<?= e($values['name']) ?>"<?= $invalid('name') ?> aria-describedby="name-err">
            <?= $errorLine('name', 'name-err') ?>
          </div>
          <div class="field">
            <label class="label" for="email">
              University email
              <?php if ((int)$me['email_verified'] === 1): ?><span class="badge badge-success">Verified</span><?php endif; ?>
            </label>
            <input class="input" id="email" name="email" type="email" required maxlength="150" autocomplete="email"
                   value="<?= e($values['email']) ?>"<?= $invalid('email') ?> aria-describedby="email-hint email-err">
            <p class="hint" id="email-hint">You sign in with this address, and it is the email your teachers see.
              <?php if (mail_type_enabled('verify_new_email')): ?>A new address changes only after you confirm it from that inbox.<?php endif; ?></p>
            <?php if (($pendingEmail = pending_email_change($pdo, $userId)) !== ''): ?>
              <p class="hint is-ok">Waiting for confirmation: <strong><?= e($pendingEmail) ?></strong>. Open the link we sent there (it works for 24 hours).</p>
            <?php endif; ?>
            <?= $errorLine('email', 'email-err') ?>
          </div>
        </section>

        <div class="form-actions">
          <span class="form-note" x-show="dirty" x-cloak>You have unsaved changes.</span>
          <button class="btn btn-primary" type="submit">Save changes</button>
        </div>
      </form>
    <?php endif; ?>

    <?php if ($tab === 'appearance'): ?>
      <section class="card" aria-labelledby="set-theme-h">
        <h2 class="card-title" id="set-theme-h">Theme</h2>
        <p class="card-desc">Saved to your account and applied on this device right away.</p>
        <form method="post" x-data="themePicker" @change="pick($event)">
          <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="save_theme">
          <div class="choice-list is-row" role="radiogroup" aria-labelledby="set-theme-h">
            <?php foreach ($THEMES as $tKey => [$tLabel, $tDesc]): ?>
              <label class="choice">
                <input type="radio" name="theme" value="<?= e($tKey) ?>"<?= $values['theme'] === $tKey ? ' checked' : '' ?>>
                <span>
                  <span class="choice-title"><?= e($tLabel) ?></span>
                  <span class="choice-desc"><?= e($tDesc) ?></span>
                </span>
              </label>
            <?php endforeach; ?>
          </div>
          <?= $errorLine('theme', 'theme-err') ?>
          <!-- Only needed without JavaScript: picking a theme saves it. -->
          <div class="form-actions" x-init="$el.hidden = true">
            <button class="btn btn-primary" type="submit">Save theme</button>
          </div>
        </form>
      </section>
    <?php endif; ?>

    <?php if ($tab === 'security'): ?>
      <section class="card" aria-labelledby="set-password-h">
        <h2 class="card-title" id="set-password-h">Password</h2>
        <p class="card-desc">Use at least 8 characters. A long phrase you don’t use anywhere else works best.</p>
        <form method="post" x-data="passwordForm">
          <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="change_password">
          <div class="field">
            <label class="label" for="current_password">Current password</label>
            <div class="password-field" x-data="{ show: false }">
              <input class="input" id="current_password" name="current_password" type="password" :type="show ? 'text' : 'password'" required autocomplete="current-password"
                     <?= $invalid('current_password') ?> aria-describedby="cp-err">
              <?= ui_pw_toggle() ?>
            </div>
            <?= $errorLine('current_password', 'cp-err') ?>
          </div>
          <div class="field">
            <label class="label" for="new_password">New password</label>
            <div class="password-field" x-data="{ show: false }">
              <input class="input" id="new_password" name="new_password" type="password" :type="show ? 'text' : 'password'" required minlength="8" maxlength="4096" autocomplete="new-password"
                     x-model="password"<?= $invalid('new_password') ?> aria-describedby="np-hint np-err">
              <?= ui_pw_toggle() ?>
            </div>
            <p class="hint" id="np-hint" :class="password.length >= 8 && 'is-ok'" x-text="lengthHint">At least 8 characters.</p>
            <?= $errorLine('new_password', 'np-err') ?>
          </div>
          <div class="field">
            <label class="label" for="confirm_password">Confirm new password</label>
            <div class="password-field" x-data="{ show: false }">
              <input class="input" id="confirm_password" name="confirm_password" type="password" :type="show ? 'text' : 'password'" required minlength="8" maxlength="4096" autocomplete="new-password"
                     x-model="confirmation" data-confirm-input<?= $invalid('confirm_password') ?> aria-describedby="cf-hint cf-err">
              <?= ui_pw_toggle() ?>
            </div>
            <p class="hint" id="cf-hint" x-show="confirmation !== ''" x-cloak :class="mismatch ? 'is-bad' : 'is-ok'" x-text="matchHint"></p>
            <?= $errorLine('confirm_password', 'cf-err') ?>
          </div>
          <div class="form-actions">
            <button class="btn btn-primary" type="submit">Update password</button>
          </div>
        </form>
      </section>

      <section class="card scroll-mt-24" id="two-factor" aria-labelledby="set-2fa-h">
        <h2 class="card-title" id="set-2fa-h">
          Two-factor authentication
          <?php if ($tfEnabled): ?><span class="badge badge-success">On</span>
          <?php elseif ($enrolling): ?><span class="badge badge-warning">Setting up</span>
          <?php else: ?><span class="badge">Off</span><?php endif; ?>
        </h2>

        <?php if ($tfEnabled): ?>
          <p class="card-desc">Each time you sign in you’ll be asked for a 6-digit code from your authenticator app.</p>
          <?php if ($tfJustEnabled): ?>
            <?= ui_alert('success', 'Two-factor authentication is now on.') ?>
          <?php endif; ?>
          <?php if ($tfCodes): ?>
            <div class="panel-tint mb-5">
              <h3 class="font-bold">Your backup codes</h3>
              <p class="mt-1 mb-3 text-sm text-muted-foreground-2">If you lose your phone, sign in with one of these instead of a 6-digit code. Each works once.
                <strong class="text-foreground">Save them now: they are shown only this once.</strong></p>
              <ul class="mb-4 grid grid-cols-2 gap-x-6 gap-y-1.5 sm:grid-cols-3">
                <?php foreach ($tfCodes as $bc): ?>
                  <li><code class="text-sm font-semibold tracking-wider"><?= e($bc) ?></code></li>
                <?php endforeach; ?>
              </ul>
              <button type="button" class="btn btn-secondary btn-sm" x-copy data-copy="<?= e(implode("\n", $tfCodes)) ?>"><?= pf_icon('copy') ?> Copy all codes</button>
            </div>
          <?php else: ?>
            <div class="action-row">
              <div>
                <strong>Backup codes: <?= $tfLeft ?> left</strong>
                <p><?= $tfLeft === 0 ? 'You have none, so losing your phone would lock you out. Make a new set.' : 'For signing in without your phone. Lost the list? Make a new set: the old codes stop working.' ?></p>
              </div>
              <form method="post" action="setup_totp.php" x-confirm
                    data-confirm-title="Make new backup codes?"
                    data-confirm-body="Your current backup codes will stop working. The new ones are shown once."
                    data-confirm-ok="Make new codes">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="regen_codes">
                <button class="btn btn-secondary" type="submit"><?= pf_icon('shield') ?> Make new backup codes</button>
              </form>
            </div>
          <?php endif; ?>
          <form method="post" action="setup_totp.php" x-confirm
                data-confirm-title="Turn off two-factor authentication?"
                data-confirm-body="Signing in will only need your password. You can turn it back on at any time, with a new key."
                data-confirm-ok="Turn off">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="disable">
            <button class="btn btn-danger-ghost -ml-4" type="submit">Turn off two-factor</button>
          </form>

        <?php elseif ($enrolling):
            $otpUri = 'otpauth://totp/' . rawurlencode('Profile System:' . $me['email'])
                    . '?secret=' . rawurlencode($tfSecret) . '&issuer=' . rawurlencode('Profile System');
        ?>
          <ol class="steps mt-5">
            <li>
              <strong>Add an account in your authenticator app</strong>
              <span>On this phone, <a href="<?= e($otpUri) ?>">open it in your authenticator app</a>. Otherwise choose “enter a setup key” in the app (time-based, 6 digits).</span>
            </li>
            <li>
              <strong>Enter this setup key</strong>
              <div class="copy-box mt-2 max-w-md">
                <span class="value"><?= e(trim(chunk_split($tfSecret, 4, ' '))) ?></span>
                <button type="button" class="btn btn-secondary btn-sm" x-copy data-copy="<?= e($tfSecret) ?>"><?= pf_icon('copy') ?> Copy</button>
              </div>
            </li>
            <li>
              <strong>Type the 6-digit code the app shows</strong>
              <form method="post" action="setup_totp.php" class="mt-2">
                <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="action" value="confirm">
                <label class="sr-only" for="totp-code">6-digit code</label>
                <div class="flex flex-wrap items-center gap-2">
                  <input class="input input-code" id="totp-code" name="code" type="text" inputmode="numeric" pattern="\d{6}" maxlength="6"
                         autocomplete="one-time-code" placeholder="000000" required autofocus
                         <?= isset($_GET['err']) ? 'aria-invalid="true"' : '' ?> aria-describedby="totp-err">
                  <button class="btn btn-primary" type="submit">Turn on</button>
                </div>
                <?php if (isset($_GET['err'])): ?>
                  <p class="field-error" id="totp-err" role="alert">That code didn’t match. Codes change every 30 seconds, so enter the newest one.</p>
                <?php endif; ?>
              </form>
            </li>
          </ol>
          <form method="post" action="setup_totp.php" class="mt-6">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="disable">
            <button class="btn btn-ghost btn-sm -ml-3" type="submit">Cancel setup</button>
          </form>

        <?php else: ?>
          <p class="card-desc">Adds a second step when you sign in: a 6-digit code from an authenticator app on your phone. If your password is ever stolen, it isn’t enough on its own.</p>
          <form method="post" action="setup_totp.php">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="generate">
            <button class="btn btn-secondary" type="submit"><?= pf_icon('shield') ?> Set up two-factor</button>
          </form>
        <?php endif; ?>
      </section>
    <?php endif; ?>

    <?php if ($tab === 'danger'): ?>
      <section class="card card-danger" aria-labelledby="set-danger-h">
        <h2 class="card-title" id="set-danger-h">Deactivate account</h2>
        <ul class="mt-3 mb-6 list-disc space-y-1.5 pl-5 text-sm text-muted-foreground-2">
          <li>You’re signed out straight away and can no longer sign in.</li>
          <li>Your profile and everything on it is kept for 30 days, then permanently deleted.</li>
          <li>There is no self-service way to undo this. To come back within the 30 days you’ll need an administrator.</li>
        </ul>
        <form method="post" x-data="{ phrase: '' }" x-confirm
              data-confirm-title="Deactivate your account?"
              data-confirm-body="You’ll be signed out now. After 30 days your data is permanently deleted."
              data-confirm-ok="Deactivate">
          <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="delete_account">
          <div class="field">
            <label class="label" for="del_current_password">Your password</label>
            <div class="password-field" x-data="{ show: false }">
              <input class="input" id="del_current_password" name="current_password" type="password" :type="show ? 'text' : 'password'" required autocomplete="current-password"
                     <?= $invalid('delete_password') ?> aria-describedby="dp-err">
              <?= ui_pw_toggle() ?>
            </div>
            <?= $errorLine('delete_password', 'dp-err') ?>
          </div>
          <div class="field">
            <label class="label" for="confirm_text"><span>Type <span class="mono">delete my account</span> to confirm</span></label>
            <input class="input" id="confirm_text" name="confirm_text" type="text" required autocomplete="off" autocapitalize="none" spellcheck="false"
                   x-model="phrase"<?= $invalid('confirm_text') ?> aria-describedby="ct-err">
            <?= $errorLine('confirm_text', 'ct-err') ?>
          </div>
          <div class="form-actions">
            <button class="btn btn-danger" type="submit" :disabled="phrase.trim().toLowerCase() !== 'delete my account'">Deactivate my account</button>
          </div>
        </form>
      </section>
    <?php endif; ?>

    </div>
  </div>
</div>
<?php require __DIR__ . '/../src/includes/footer.php'; ?>
