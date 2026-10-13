<?php
/**
 * Forgot-password request form.
 *
 * Route:  GET/POST /forgot_password.php
 * Auth:   none
 * CSRF:   verify_csrf() on POST
 * Behavior:
 *   - Always shows the same generic success message to avoid leaking which
 *     emails exist.
 *   - If a matching user is found, inserts a row into `password_resets`
 *     (token stored as sha256, expires in 30 minutes).
 *   - In dev mode (no SMTP), the raw token is rendered on screen so the
 *     developer can copy the reset link manually. The same banner warns
 *     that email is not actually sent.
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

$errors  = [];
$email   = '';
$devTokenInfo = null; // local-dev: show the token on screen since we don't send email

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    // IP-based rate limit: 5 reset-link requests per hour. The reset
    // link is single-use, but without throttling an attacker can spam
    // the table and / or mail a target user into inbox fatigue.
    $ip = client_ip();
    if (too_many_attempts('ip:' . $ip, 5, 3600)) {
        $errors['email'] = 'Too many requests from your network. Please try again in an hour.';
        log_activity(null, 'forgot_password_throttled', 'ip=' . $ip);
    } else {
        $vEmail = validate_email((string)($_POST['email'] ?? ''));
        $email  = $vEmail['value'];
        $errors = array_merge($errors, $vEmail['errors']);

        if (!$errors) {
            $st = $pdo->prepare('SELECT id, name FROM users WHERE email = :e LIMIT 1');
            $st->execute(['e' => $email]);
            $u = $st->fetch();
            // Always show the same success message to avoid leaking which emails exist.
            if ($u) {
                $rawToken = bin2hex(random_bytes(32));
                $tokenHash = hash('sha256', $rawToken);
                $expiresAt = (new DateTimeImmutable('+30 minutes'))->format('Y-m-d H:i:s');
                $ins = $pdo->prepare('INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (:u, :t, :e)');
                $ins->execute(['u' => (int)$u['id'], 't' => $tokenHash, 'e' => $expiresAt]);
                // Only show the raw token on screen in development mode.
                // In production (APP_ENV=production) the link is only
                // sent via real email; the dev banner would otherwise
                // hand a working reset link to whoever is visiting
                // the tunnel URL.
                $isDev = getenv('APP_ENV') !== 'production';
                if ($isDev) {
                    $devTokenInfo = ['user_id' => (int)$u['id'], 'name' => $u['name'], 'token' => $rawToken];
                }
                log_activity((int)$u['id'], 'password_reset_requested', $isDev ? 'dev=1' : 'dev=0');
            } else {
                // No matching email: still record the attempt so the
                // limiter accumulates for unknown-address probes too.
                log_activity(null, 'password_reset_requested', 'no_match');
            }
            record_attempt('ip:' . $ip, $u ? (int)$u['id'] : null, $ip, $email, true);
        }
    }
}

$page_title = 'Forgot password';
$page_id    = 'forgot';
require __DIR__ . '/../src/includes/header.php';
?>
<div class="page-narrow">
  <section class="card" aria-labelledby="forgot-h">
    <h1 class="page-title text-2xl" id="forgot-h">Forgot password</h1>
    <p class="page-desc">Enter the email address for your account and we’ll send a link to choose a new password.</p>

    <?php if ($devTokenInfo): ?>
      <div class="alert alert-warning mt-5" role="status">
        <?= pf_icon('mail') ?>
        <div>
          <strong>Local development mode.</strong> No email is sent. Copy this reset link and open it instead:
          <div class="copy-box mt-2">
            <span class="value"><?= e(url_reset() . '?token=' . $devTokenInfo['token']) ?></span>
            <button class="btn btn-secondary btn-sm" type="button" x-copy data-copy="<?= e(url_reset() . '?token=' . $devTokenInfo['token']) ?>"><?= pf_icon('copy') ?> Copy</button>
          </div>
        </div>
      </div>
    <?php elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && !$errors): ?>
      <div class="mt-5"><?= ui_alert('success', 'If there is an account for that address, a reset link is on its way.') ?></div>
    <?php endif; ?>

    <form class="mt-6" method="post" action="<?= e(url_forgot()) ?>" novalidate>
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

      <div class="field">
        <label class="label" for="email">Email</label>
        <input class="input" id="email" name="email" type="email" required maxlength="150" autocomplete="email"
               value="<?= e($email) ?>"
               <?= isset($errors['email']) ? 'aria-invalid="true" aria-describedby="email-err"' : '' ?>>
        <?php if (isset($errors['email'])): ?>
          <p class="field-error" id="email-err" role="alert"><?= e($errors['email']) ?></p>
        <?php endif; ?>
      </div>

      <button class="btn btn-primary btn-block" type="submit">Send reset link</button>
    </form>

    <p class="mt-6 text-sm text-muted-foreground-2">
      Remembered it? <a href="<?= e(url_login()) ?>">Back to log in</a>
    </p>
  </section>
</div>
<?php require __DIR__ . '/../src/includes/footer.php'; ?>