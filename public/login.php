<?php
/**
 * Login form (public).
 *
 * Route:  GET/POST /login.php
 * Auth:   none
 * CSRF:   verify_csrf() on POST
 *
 * Single-role users: password verify → session create → 302 /dashboard.php.
 *
 * Multi-role users: password verify → stash user info in
 *   $_SESSION['_pending_user'] → render a role-picker form (one button
 *   per role in their user_roles set). Submitting the picker with
 *   csrf_token + role=<choice> finalizes the session and 302s to the
 *   dashboard.
 *
 * Side effects on success:
 *   - session_regenerate_id(true)
 *   - sets $_SESSION['user_id' | 'user_role' | 'user_roles' | 'user_name']
 *   - log_activity($userId, 'login', $chosenRole)
 *   - 302 → /dashboard.php
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
$email  = '';
$flash  = [];
$pendingUser = $_SESSION['_pending_user'] ?? null;

if (isset($_GET['registered']) && $_GET['registered'] === '1') {
    $flash[] = ['type' => 'success', 'title' => 'Welcome!', 'body' => 'Your account is ready. Please log in.'];
}
if (isset($_GET['logged_out']) && $_GET['logged_out'] === '1') {
    $flash[] = ['type' => 'info', 'body' => 'You have been logged out.'];
}
if (isset($_GET['reset']) && $_GET['reset'] === '1') {
    $flash[] = ['type' => 'success', 'title' => 'Password updated', 'body' => 'You can now log in with your new password.'];
}
if (isset($_GET['signed_out']) && $_GET['signed_out'] === '1') {
    $flash[] = ['type' => 'info', 'title' => 'Signed out', 'body' => 'Your password or sign-in settings changed, so you were signed out everywhere. Log in again.'];
}
if (isset($_GET['expired']) && $_GET['expired'] === '1') {
    $flash[] = ['type' => 'info', 'title' => 'Session ended', 'body' => 'You were signed out after a while without activity. Log in again.'];
}
if (isset($_GET['deactivated']) && $_GET['deactivated'] === '1') {
    $flash[] = ['type' => 'warn', 'title' => 'Account deactivated', 'body' => 'Your account is currently deactivated. Use the link in your deactivation email to reactivate it, or contact support.'];
}
if (isset($_GET['restored']) && $_GET['restored'] === '1') {
    $flash[] = ['type' => 'success', 'title' => 'Account restored', 'body' => 'Your account is active again. You can now log in.'];
}

/**
 * Finalize the session for $userId with $chosenRole, log the activity,
 * and redirect to the dashboard. Used by both the single-role auto-pick
 * path and the multi-role picker path.
 */
function finalize_login(int $userId, string $name, array $roles, string $chosenRole): void
{
    session_regenerate_id(true);
    session_mark_signed_in();
    global $pdo;
    $_SESSION['epoch']         = session_epoch_of($pdo, $userId);
    $_SESSION['user_id']       = $userId;
    $_SESSION['user_role']     = $chosenRole;
    $_SESSION['user_roles']    = $roles;
    $_SESSION['user_name']     = $name;
    unset($_SESSION['_pending_user']);

    // TOTP gate. If the user has 2FA enabled, stash the id in
    // `pending_totp_user_id` and require the TOTP step before the
    // session is considered "logged in".
    global $pdo;
    try {
        $t = $pdo->prepare('SELECT totp_required FROM users WHERE id = :u');
        $t->execute(['u' => $userId]);
        $totpRow = $t->fetch();
    } catch (Throwable $e) { $totpRow = null; }
    if ($totpRow && (int)$totpRow['totp_required'] === 1) {
        // Not signed in yet: the "login" event is logged once the code is right
        // (complete_totp_login()); this records that the password was.
        log_activity($userId, 'login_password_ok', $chosenRole);
        $_SESSION['pending_totp_user_id'] = $userId;
        // Don't keep user_id set until TOTP succeeds.
        unset($_SESSION['user_id']);
        unset($_SESSION['user_role']);
        unset($_SESSION['user_roles']);
        header('Location: login_totp.php');
        exit;
    }

    log_activity($userId, 'login', $chosenRole);
    header('Location: ' . url_dashboard());
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    // ---------------------------------------------------------------
    // Branch 1: role-picker submission (only for multi-role users).
    // The pending user + role set were stashed in the session by the
    // password-verify step below; re-validate against the DB so an
    // admin who revoked a role between steps can't get through.
    // ---------------------------------------------------------------
    if (isset($_POST['choose_role']) && is_array($pendingUser)) {
        $allowed = $pendingUser['roles'] ?? [];
        $v = validate_role_choice((string)($_POST['role'] ?? ''), $allowed);
        if (!$v['errors']) {
            // Re-fetch fresh from DB to defeat stale-session races.
            $fresh = $pdo->prepare('SELECT role FROM user_roles WHERE user_id = :u');
            $fresh->execute(['u' => (int)$pendingUser['id']]);
            $freshRoles = $fresh->fetchAll(PDO::FETCH_COLUMN);
            if (!$freshRoles) {
                $errors['role'] = 'Your account no longer has any roles assigned. Please contact an admin.';
                // Stash is now stale; drop it.
                unset($_SESSION['_pending_user']);
            } elseif (!in_array($v['value'], $freshRoles, true)) {
                $errors['role'] = 'That role is no longer available on your account. Please pick another.';
                // Refresh the allowed list so the picker re-renders.
                $_SESSION['_pending_user']['roles'] = $freshRoles;
            } else {
                finalize_login(
                    (int)$pendingUser['id'],
                    (string)$pendingUser['name'],
                    $freshRoles,
                    $v['value']
                );
            }
        } else {
            $errors = array_merge($errors, $v['errors']);
        }
    }
    // ---------------------------------------------------------------
    // Branch 2: standard password-verify step.
    // ---------------------------------------------------------------
    elseif (isset($_POST['email'], $_POST['password'])) {
        $vEmail = validate_email((string)($_POST['email'] ?? ''));
        $email  = $vEmail['value'];
        $errors = array_merge($errors, $vEmail['errors']);

        $vPw = validate_password((string)($_POST['password'] ?? ''), required: false);
        $errors = array_merge($errors, $vPw['errors']);

        if (!$errors) {
            // Rate limiting: 5 failures per email in 15 minutes, and
            // 20 failures per IP in 10 minutes. The IP cap protects
            // against sprays; the email cap protects against targeted
            // attacks on a known account. We always run the bcrypt
            // verify below even when over-limit, but the errors are
            // already set so the user is locked out regardless.
            $ip          = client_ip();
            $emailBucket = 'email:' . strtolower($email);
            $ipBucket    = 'ip:' . $ip;
            // One attempt at a time per email and per IP (rate_limit_lock()),
            // so a burst of parallel requests can't all slip under the limit
            // before the first failure is recorded.
            $lockedOut   = !rate_limit_lock($emailBucket) || !rate_limit_lock($ipBucket);
            $overEmail   = $lockedOut || too_many_attempts($emailBucket, 5, 900);
            $overIp      = $lockedOut || too_many_attempts($ipBucket, 20, 600);
            if ($overEmail || $overIp) {
                $errors['email'] = 'Too many failed attempts. Please try again in a few minutes.';
            }

            $st = $pdo->prepare('SELECT id, name, email, password_hash FROM users WHERE email = :e LIMIT 1');
            $st->execute(['e' => $email]);
            $u = $st->fetch();
            $submittedPassword = (string)$_POST['password'];
            // Unknown email: still spend the time of a password check, so
            // response time doesn't reveal which emails have accounts.
            if (!$u) { password_verify($submittedPassword, '$2y$10$UFukhwq.jPHLPMhTV9od1./PWW1uSeMy9y5KLZ9mnOYJbHShVNr1O'); }
            if (!$overEmail && !$overIp && $u && password_verify($submittedPassword, $u['password_hash'])) {
                // Load the role set from the join table.
                $roleSt = $pdo->prepare('SELECT role FROM user_roles WHERE user_id = :u');
                $roleSt->execute(['u' => (int)$u['id']]);
                $roles = $roleSt->fetchAll(PDO::FETCH_COLUMN);
                if (!$roles) {
                    $errors['email'] = 'Your account has no roles assigned. Please contact an admin.';
                } elseif (count($roles) === 1) {
                    // Single-role user: skip the picker, finalize now.
                    // Clear this account's failed attempts so its owner
                    // isn't punished for earlier typos. The IP's count is
                    // left to expire: clearing it would let anyone with an
                    // account reset it by signing in between guesses at
                    // other accounts.
                    clear_attempts($emailBucket);
                    finalize_login(
                        (int)$u['id'],
                        (string)$u['name'],
                        $roles,
                        $roles[0]
                    );
                } else {
                    // Multi-role user: stash and show the picker.
                    clear_attempts($emailBucket);
                    $_SESSION['_pending_user'] = [
                        'id'       => (int)$u['id'],
                        'name'     => (string)$u['name'],
                        'email'    => (string)$u['email'],
                        'roles'    => $roles,
                    ];
                    // session_regenerate_id here to defend against fixation
                    // on the password-verify step (the picker submit gets a
                    // fresh id when finalize_login() runs).
                    session_regenerate_id(true);
                    $pendingUser = $_SESSION['_pending_user'];
                }
            } else {
                if ($u) {
                    $errors['email'] = $errors['email'] ?? 'Invalid email or password.';
                } else {
                    $errors['email'] = $errors['email'] ?? 'Invalid email or password.';
                }
                // Log every failure (even over-limit ones, so the
                // activity feed shows the attack pattern).
                record_attempt($emailBucket, $u ? (int)$u['id'] : null, $ip, $email, false);
                record_attempt($ipBucket, $u ? (int)$u['id'] : null, $ip, $email, false);
                log_activity($u ? (int)$u['id'] : null, 'login_failed', 'ip=' . $ip . ';email=' . $email);
            }
        }
    }
}

$page_title = 'Log in';
$page_id    = 'login';
$hide_topbar = true;     // No nav while picking / logging in.
require __DIR__ . '/../src/includes/header.php';

// Sort roles for display: admin > teacher > student.
$displayOrder = ['admin', 'teacher', 'student'];
?>
<div class="page-narrow">
  <?php if (is_array($pendingUser) && !$errors): ?>
    <section class="card" aria-labelledby="login-h">
      <h1 class="page-title text-2xl" id="login-h">Choose a role</h1>
      <p class="page-desc">Hi <?= e($pendingUser['name']) ?>. Your account has more than one role. Pick the one to use now; you can switch at any time from the top bar.</p>

      <form class="mt-6" method="post" action="<?= e(url_login()) ?>" novalidate>
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="choose_role" value="1">
        <?php
        $sortedRoles = $pendingUser['roles'];
        usort($sortedRoles, function ($a, $b) use ($displayOrder) {
            $ia = array_search($a, $displayOrder, true);
            $ib = array_search($b, $displayOrder, true);
            return ($ia === false ? 999 : $ia) <=> ($ib === false ? 999 : $ib);
        });
        ?>
        <div class="choice-list" role="group" aria-label="Choose a role">
          <?php foreach ($sortedRoles as $r): ?>
            <button class="choice w-full text-left" type="submit" name="role" value="<?= e($r) ?>">
              <span class="min-w-0 flex-1">
                <span class="choice-title"><?= ui_role($r) ?></span>
                <span class="choice-desc"><?= e(role_picker_description($r)) ?></span>
              </span>
              <?= pf_icon('chevron', 'mt-1 text-muted-foreground-1') ?>
            </button>
          <?php endforeach; ?>
        </div>
      </form>

      <p class="mt-6 text-sm text-muted-foreground-2">
        Not <?= e($pendingUser['name']) ?>? <a href="<?= e(url_login()) ?>">Use a different account</a>
      </p>
    </section>
  <?php else: ?>
    <section class="card" aria-labelledby="login-h">
      <h1 class="page-title text-2xl" id="login-h">Log in</h1>
      <p class="page-desc">Use the email address and password for your account.</p>

      <?php if (isset($errors['role'])): ?>
        <div class="mt-5"><?= ui_alert('error', e($errors['role'])) ?></div>
      <?php endif; ?>

      <form class="mt-6" method="post" action="<?= e(url_login()) ?>" novalidate>
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

        <div class="field">
          <label class="label" for="email">Email</label>
          <input class="input" id="email" name="email" type="email" autocomplete="email" required maxlength="150"
                 value="<?= e($email) ?>"<?= $email === '' ? ' autofocus' : '' ?>
                 <?= isset($errors['email']) ? 'aria-invalid="true" aria-describedby="email-err"' : '' ?>>
          <?php if (isset($errors['email'])): ?>
            <p class="field-error" id="email-err" role="alert"><?= e($errors['email']) ?></p>
          <?php endif; ?>
        </div>

        <div class="field">
          <label class="label" for="password">
            Password
            <a class="ml-auto text-sm font-normal" href="<?= e(url_forgot()) ?>">Forgot password?</a>
          </label>
          <div class="password-field" x-data="{ show: false }">
            <input class="input" id="password" name="password" type="password" :type="show ? 'text' : 'password'" autocomplete="current-password" required
                   <?= isset($errors['password']) ? 'aria-invalid="true" aria-describedby="password-err"' : '' ?>>
            <?= ui_pw_toggle() ?>
          </div>
          <?php if (isset($errors['password'])): ?>
            <p class="field-error" id="password-err" role="alert"><?= e($errors['password']) ?></p>
          <?php endif; ?>
        </div>

        <button class="btn btn-primary btn-block" type="submit">Log in</button>
      </form>
    </section>

    <p class="mt-5 px-1 text-sm text-muted-foreground-2">
      No account yet? Accounts are by invitation: ask your teacher or an administrator for a link.
    </p>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/../src/includes/footer.php'; ?>

<?php
/**
 * Helper for the role-picker UI. Returns a short description for each
 * role. Kept in this file (not validate.php) because it's UI-specific.
 */
function role_picker_description(string $role): string
{
    switch ($role) {
        case 'admin':   return 'Full access — manage users, roles, and all data.';
        case 'teacher': return 'Read-only view of all student profiles.';
        case 'student': return 'Manage your own profile and view public pages.';
        default:        return '';
    }
}
?>