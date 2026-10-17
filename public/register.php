<?php
/**
 * Create an account from an invitation link.
 *
 * Route:  GET/POST /register.php?invite=<token>
 * Auth:   none; the invitation token is the permission to register.
 * CSRF:   verify_csrf() on POST
 *
 * There is no open sign-up. A teacher (students only) or an admin (any
 * role) creates a link on /invites.php; this page redeems it. The roles
 * come from the invitation, never from the form. Without a usable link
 * the page only explains how to get one.
 *
 * First-run exception: while the `users` table is empty there is nobody
 * who could send an invitation, so the very first account may be created
 * here without one and becomes the admin.
 *
 * On success:
 *   - inserts `users` (+ invite_id), a `profiles` row and the roles. When
 *     the invitation grants the student role, the form also requires
 *     Course and Student ID and stores them on that `profiles` row
 *   - counts one use on the invitation (row-locked, so a link cannot be
 *     used more often than allowed)
 *   - log_activity($userId, 'register', ...)
 *   - 302 → /login.php?registered=1
 */
declare(strict_types=1);
require_once __DIR__ . '/../src/includes/auth.php';
require_once __DIR__ . '/../src/includes/db.php';
require_once __DIR__ . '/../src/includes/url.php';
require_once __DIR__ . '/../src/includes/validate.php';
require_once __DIR__ . '/../src/includes/lab_fields.php';
require_once __DIR__ . '/../src/includes/invites.php';

$token     = (string)($_GET['invite'] ?? $_POST['invite'] ?? '');
$firstRun  = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn() === 0;
$invite    = null;
$problem   = '';

if ($firstRun) {
    $grantRoles = ['admin', 'teacher', 'student'];
} elseif (is_logged_in()) {
    $problem = 'You are already signed in as ' . current_user_name() . '. Log out first if this invitation is for a different account.';
} elseif ($token === '') {
    $problem = 'none';
} else {
    $check   = invite_check($pdo, $token);
    $invite  = $check['invite'];
    $problem = $check['problem'];
    $grantRoles = $invite ? explode(',', (string)$invite['roles']) : [];
}
$canRegister = $firstRun || ($invite && $problem === '');
$lockedEmail = $canRegister && $invite && !empty($invite['email']) ? (string)$invite['email'] : '';

// An invitation that grants the student role also asks for the two lab
// fields a teacher needs to recognise the student: course and student ID.
// (Not on the first-run admin account, which has no invitation.)
$COURSES   = LAB_COURSES;
$asStudent = $canRegister && !$firstRun && in_array('student', $grantRoles ?? [], true);

$errors = [];
$values = ['name' => '', 'email' => $lockedEmail, 'course' => '', 'student_id' => ''];

if ($canRegister && $_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();

    // IP-based rate limit: 5 registration POSTs per hour. The
    // invitation flow already throttles sign-ups strongly (you need a
    // valid invite token to even see the form), but a leaked token
    // could still be hammered.
    $ip = client_ip();
    if (too_many_attempts('ip:' . $ip, 5, 3600)) {
        $errors['form'] = 'Too many attempts from your network. Please try again in an hour.';
        log_activity(null, 'register_throttled', 'ip=' . $ip);
    } else {

    $vName     = validate_name((string)($_POST['name'] ?? ''));
    $vEmail    = validate_email($lockedEmail !== '' ? $lockedEmail : (string)($_POST['email'] ?? ''));
    $vPw       = validate_password((string)($_POST['password'] ?? ''));
    $vPwC      = validate_password_confirm((string)($_POST['password'] ?? ''), (string)($_POST['confirm_password'] ?? ''));

    $values = [
        'name'        => $vName['value'],
        'email'       => $vEmail['value'],
        'course'      => $asStudent ? trim((string)($_POST['course'] ?? '')) : '',
        'student_id'  => $asStudent ? trim((string)($_POST['student_id'] ?? '')) : '',
    ];
    $errors = array_merge($vName['errors'], $vEmail['errors'], $vPw['errors'], $vPwC['errors']);
    if ($asStudent) {
        if (!in_array($values['course'], $COURSES, true)) {
            $errors['course'] = 'Choose your course from the list.';
        }
        if ($values['student_id'] === '') {
            $errors['student_id'] = 'Enter your student ID.';
        } elseif (($idProblem = student_id_problem($pdo, $values['student_id'])) !== '') {
            $errors['student_id'] = $idProblem;
        }
    }
    if (!isset($errors['email'])) {
        $dup = $pdo->prepare('SELECT 1 FROM users WHERE email = :e');
        $dup->execute(['e' => $values['email']]);
        if ($dup->fetch()) { $errors['email'] = 'There is already an account with that email. Log in instead.'; }
    }

    if (!$errors) {
        try {
            $pdo->beginTransaction();

            // Re-check under a row lock: the invitation may have been used
            // up, cancelled or expired since the form was opened.
            if (!$firstRun) {
                $check = invite_check($pdo, $token, lock: true);
                if ($check['problem'] !== '' || !$check['invite']) {
                    $pdo->rollBack();
                    $problem     = $check['problem'];
                    $canRegister = false;
                    throw new RuntimeException('invite no longer usable');
                }
                $invite     = $check['invite'];
                $grantRoles = explode(',', (string)$invite['roles']);
            } elseif ((int)$pdo->query('SELECT COUNT(*) FROM users FOR UPDATE')->fetchColumn() !== 0) {
                $pdo->rollBack();
                $problem     = 'none';
                $canRegister = false;
                throw new RuntimeException('first-run window closed');
            }

            $pdo->prepare(
                'INSERT INTO users (name, email, password_hash, invite_id)
                 VALUES (:n, :e, :p, :i)'
            )->execute([
                'n' => $values['name'],
                'e' => $values['email'],
                'p' => password_hash($vPw['value'], PASSWORD_DEFAULT),
                'i' => $invite ? (int)$invite['id'] : null,
            ]);
            $userId = (int)$pdo->lastInsertId();
            // Only when the invitation still grants the student role (it was
            // re-read under the lock above).
            $withLab = $asStudent && in_array('student', $grantRoles, true);
            $pdo->prepare('INSERT INTO profiles (user_id, student_id) VALUES (:u, :s)')->execute([
                'u' => $userId,
                's' => $withLab ? $values['student_id'] : null,
            ]);
            if ($withLab) {
                // Enrolled = the day the invitation link was generated.
                enrollment_add($pdo, $userId, $values['course'], (string)($invite['created_at'] ?? ''));
            }

            $roleIns = $pdo->prepare('INSERT INTO user_roles (user_id, role) VALUES (:u, :r)');
            foreach ($grantRoles as $r) {
                $roleIns->execute(['u' => $userId, 'r' => $r]);
            }
            if ($invite) {
                $pdo->prepare('UPDATE invites SET used_count = used_count + 1 WHERE id = :id')->execute(['id' => (int)$invite['id']]);
            }

            $pdo->commit();
            log_activity($userId, 'register', 'roles=' . implode(',', $grantRoles)
                . ($invite ? ';invite=' . (int)$invite['id'] : ';first-run'));
            // Successful registration: clear any earlier failed
            // attempts from this IP so retries aren't penalised.
            clear_attempts('ip:' . $ip);
            header('Location: ' . url_login() . '?registered=1');
            exit;
        } catch (RuntimeException $ex) {
            // $problem is set; fall through to the explanation below.
        } catch (PDOException $ex) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            error_log('Registration error: ' . $ex->getMessage());
            $errors['form'] = 'Your account could not be created. Please try again.';
        }
    }

    // Record every POST that didn't go through to a successful
    // registration, so the IP rate-limit actually accumulates.
    if ($errors) {
        record_attempt('ip:' . $ip, null, $ip, $values['email'] ?? '', false);
    }
    } // close the rate-limit guard else { from above
}

$page_title = $canRegister ? 'Create your account' : 'Invitation needed';
$page_id    = 'register';
if (!$canRegister) { http_response_code($problem === 'none' ? 403 : 410); }
require __DIR__ . '/../src/includes/header.php';

$invalid = fn(string $k): string => isset($errors[$k]) ? ' aria-invalid="true"' : '';
$errorLine = fn(string $k): string => isset($errors[$k]) ? '<p class="field-error" id="' . $k . '-err" role="alert">' . e($errors[$k]) . '</p>' : '';
?>
<div class="page-narrow">
  <section class="card" aria-labelledby="register-h">

  <?php if (!$canRegister): ?>
    <h1 class="page-title text-2xl" id="register-h"><?= $problem === 'none' ? 'Accounts are by invitation' : 'This invitation can’t be used' ?></h1>
    <?php if ($problem === 'none'): ?>
      <p class="page-desc">There is no open sign-up. To get an account, ask your teacher or an administrator to send you an invitation link, then open that link.</p>
    <?php else: ?>
      <div class="mt-5"><?= ui_alert('warning', e($problem)) ?></div>
    <?php endif; ?>
    <div class="mt-6">
      <?php if (is_logged_in()): ?>
        <a class="btn btn-primary btn-block" href="<?= e(url_dashboard()) ?>">Go to my dashboard</a>
      <?php else: ?>
        <a class="btn btn-primary btn-block" href="<?= e(url_login()) ?>">I already have an account</a>
      <?php endif; ?>
    </div>

  <?php else: ?>
    <h1 class="page-title text-2xl" id="register-h">Create your account</h1>
    <?php if ($firstRun): ?>
      <p class="page-desc">This is the first account on this site, so it becomes the <strong class="text-foreground">administrator</strong>. Everyone after you joins through an invitation link.</p>
    <?php else: ?>
      <p class="page-desc flex flex-wrap items-center gap-x-2.5 gap-y-1">You have been invited to join as <?= ui_roles($grantRoles) ?></p>
    <?php endif; ?>

    <?php if ($errors): ?>
      <div class="mt-5"><?= ui_alert('error', isset($errors['form']) ? e($errors['form']) : 'Please fix the highlighted ' . (count($errors) === 1 ? 'field' : 'fields') . '.') ?></div>
    <?php endif; ?>

    <form class="mt-6" method="post" action="<?= e(url_register() . ($token !== '' ? '?invite=' . rawurlencode($token) : '')) ?>">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

      <div class="field">
        <label class="label" for="name">Full name</label>
        <input class="input" id="name" name="name" type="text" required maxlength="100" autocomplete="name"
               value="<?= e($values['name']) ?>"<?= $invalid('name') ?> aria-describedby="name-err">
        <?= $errorLine('name') ?>
      </div>

      <div class="field">
        <label class="label" for="email">University email</label>
        <input class="input" id="email" name="email" type="email" required maxlength="150" autocomplete="email"
               value="<?= e($values['email']) ?>"<?= $lockedEmail !== '' ? ' readonly' : '' ?><?= $invalid('email') ?> aria-describedby="email-hint email-err">
        <p class="hint" id="email-hint"><?= $lockedEmail !== '' ? 'This invitation was sent to this address, so it cannot be changed.' : 'You will sign in with this address.' ?></p>
        <?= $errorLine('email') ?>
      </div>

      <?php if ($asStudent): ?>
        <div class="form-grid">
          <div class="field">
            <label class="label" for="course">Course</label>
            <select class="select" id="course" name="course" required<?= $invalid('course') ?> aria-describedby="course-hint course-err">
              <option value=""<?= $values['course'] === '' ? ' selected' : '' ?> disabled>Choose…</option>
              <?php foreach ($COURSES as $c): ?>
                <option value="<?= e($c) ?>"<?= $values['course'] === $c ? ' selected' : '' ?>><?= e($c) ?></option>
              <?php endforeach; ?>
            </select>
            <p class="hint" id="course-hint">D, M, B = year of study. R = research student.</p>
            <?= $errorLine('course') ?>
          </div>
          <div class="field">
            <label class="label" for="student_id">Student ID</label>
            <input class="input" id="student_id" name="student_id" type="text" required maxlength="64"
                   autocapitalize="characters" autocomplete="off" spellcheck="false"
                   value="<?= e($values['student_id']) ?>"<?= $invalid('student_id') ?> aria-describedby="student_id-hint student_id-err">
            <p class="hint" id="student_id-hint">As printed on your student card.</p>
            <?= $errorLine('student_id') ?>
          </div>
        </div>
      <?php endif; ?>

      <div class="field">
        <label class="label" for="password">Password</label>
        <div class="password-field" x-data="{ show: false }">
          <input class="input" id="password" name="password" type="password" :type="show ? 'text' : 'password'" required minlength="8" maxlength="4096" autocomplete="new-password"
                 <?= $invalid('password') ?> aria-describedby="password-hint password-err">
          <?= ui_pw_toggle() ?>
        </div>
        <p class="hint" id="password-hint">At least 8 characters.</p>
        <?= $errorLine('password') ?>
      </div>

      <div class="field">
        <label class="label" for="confirm_password">Confirm password</label>
        <div class="password-field" x-data="{ show: false }">
          <input class="input" id="confirm_password" name="confirm_password" type="password" :type="show ? 'text' : 'password'" required minlength="8" maxlength="4096" autocomplete="new-password"
                 <?= $invalid('confirm_password') ?> aria-describedby="confirm_password-err">
          <?= ui_pw_toggle() ?>
        </div>
        <?= $errorLine('confirm_password') ?>
      </div>

      <button class="btn btn-primary btn-block" type="submit">Create account</button>
    </form>

    <p class="mt-6 text-sm text-muted-foreground-2">
      Already have an account? <a href="<?= e(url_login()) ?>">Log in</a>
    </p>
  <?php endif; ?>

  </section>
</div>
<?php require __DIR__ . '/../src/includes/footer.php'; ?>
