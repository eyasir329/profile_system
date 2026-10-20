<?php
/**
 * Admin: create, edit, deactivate / restore, or permanently delete a user.
 *
 * Route:  GET  /admin/user.php            blank form (new user)
 *         GET  /admin/user.php?id=<id>    edit form
 *         POST /admin/user.php[?id=<id>]  action = save | deactivate | restore | delete
 * Auth:   require_admin()
 * CSRF:   verify_csrf() on POST
 *
 * save        Account (name, email), roles, an
 *             optional new password (required when creating) and the lab
 *             details.
 * deactivate  Soft delete: sets deleted_at and a purge date 30 days out.
 * restore     Clears both, so the user can sign in again.
 * delete      Removes the user row; the profile and related rows cascade.
 *
 * Safety rules, enforced here and reflected in the form:
 *   - an admin cannot deactivate or delete their own account, or drop
 *     their own admin role;
 *   - the last active admin cannot be deactivated, deleted or demoted.
 *
 * Success redirects to /admin/index.php?done=<what>; failure re-renders
 * the form with the errors inline.
 */
declare(strict_types=1);
require_once __DIR__ . '/../../src/includes/auth.php';
require_once __DIR__ . '/../../src/includes/db.php';
require_once __DIR__ . '/../../src/includes/url.php';
require_once __DIR__ . '/../../src/includes/validate.php';
require_once __DIR__ . '/../../src/includes/lab_fields.php';
require_once __DIR__ . '/../../src/includes/ui.php';
require_once __DIR__ . '/../../src/includes/mail_templates.php';
require_admin();

$meId   = (int)current_user_id();
$userId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$isNew  = $userId === 0;

$ROLES = [
    'student' => 'Has a profile and edits their own data.',
    'teacher' => 'Also gets the read-only Students directory.',
    'admin'   => 'Also manages every user from this panel.',
];
$COURSES = LAB_COURSES;
$MONTHS  = LAB_MONTHS;
// Lab details: column => [label, kind, max length]
$LAB = [
    'course'              => ['Course', 'course', 0],
    'student_id'          => ['Student ID', 'text', 64],
    'name_en'             => ['Name (EN)', 'text', 255],
    'name_jp'             => ['Name (JP)', 'text', 255],
    'enrolled_year'       => ['Enrolled year', 'year', 0],
    'enrolled_month'      => ['Enrolled month', 'month', 0],
    'next_step'           => ['Next step', 'text', 255],
    'previous_univ_level' => ['Previous university / level', 'text', 255],
    'research_title'      => ['Research title', 'textarea', 2000],
];
// Theses and papers are entries the student adds on their profile; the
// text here is generated from them, so it is shown read-only.
$LAB_ENTRIES = [
    'completed_thesis'  => 'Completed thesis',
    'journal_papers'    => 'Journal papers',
    'conference_papers' => 'Conference papers',
];

$user = null;
if (!$isNew) {
    $st = $pdo->prepare(
        'SELECT u.id, u.name, u.email, u.totp_required, u.deleted_at, u.scheduled_purge_at, u.created_at, p.*
           FROM users u LEFT JOIN profiles p ON p.user_id = u.id WHERE u.id = :id LIMIT 1'
    );
    $st->execute(['id' => $userId]);
    $user = $st->fetch();
    if (!$user) {
        header('Location: ' . url_admin() . '?id=' . $userId); // renders the "not found" page
        exit;
    }
}
$rolesOf = function (int $id) use ($pdo): array {
    $r = $pdo->prepare('SELECT role FROM user_roles WHERE user_id = :u');
    $r->execute(['u' => $id]);
    return $r->fetchAll(PDO::FETCH_COLUMN);
};
$currentRoles  = $isNew ? [] : $rolesOf($userId);
$isSelf        = !$isNew && $userId === $meId;
$isDeactivated = !$isNew && !empty($user['deleted_at']);
$activeAdmins  = (int)$pdo->query(
    "SELECT COUNT(*) FROM users u JOIN user_roles r ON r.user_id = u.id AND r.role = 'admin' WHERE u.deleted_at IS NULL"
)->fetchColumn();
$isLastAdmin   = !$isNew && !$isDeactivated && in_array('admin', $currentRoles, true) && $activeAdmins <= 1;
// Why this account cannot be deactivated or deleted, if it cannot.
$locked = $isSelf ? 'This is your own account.' : ($isLastAdmin ? 'This is the only active admin.' : '');

$values = [
    'name'        => (string)($user['name'] ?? ''),
    'email'       => (string)($user['email'] ?? ''),
    'roles'       => $isNew ? ['student'] : $currentRoles,
];
foreach ($LAB as $col => $_) { $values[$col] = trim((string)($user[$col] ?? '')); }
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? 'save');

    // ---- reset two-factor: for someone who lost their phone and backup codes ----
    if (!$isNew && $action === 'reset_2fa') {
        $pdo->prepare('DELETE FROM two_factor WHERE user_id = :u')->execute(['u' => $userId]);
        $pdo->prepare('UPDATE users SET totp_required = 0 WHERE id = :u')->execute(['u' => $userId]);
        clear_attempts('totp:' . $userId);
        session_epoch_bump($pdo, $userId);
        log_activity($meId, 'admin_2fa_reset', (string)$user['email']);
        log_activity($userId, 'two_factor_reset', 'by admin');
        mail_two_factor_off((string)$user['email'], (string)$user['name'], true);
        header('Location: ' . url_admin() . '?id=' . $userId . '&done=2fa_reset');
        exit;
    }

    // ---- deactivate / restore / delete ----
    if (!$isNew && in_array($action, ['deactivate', 'restore', 'delete'], true)) {
        if ($action !== 'restore' && $locked !== '') {
            $errors['form'] = $locked . ' It cannot be ' . ($action === 'delete' ? 'deleted' : 'deactivated') . '.';
        } elseif ($action === 'deactivate') {
            $pdo->prepare('UPDATE users SET deleted_at = NOW(), scheduled_purge_at = DATE_ADD(NOW(), INTERVAL 30 DAY) WHERE id = :id')
                ->execute(['id' => $userId]);
            log_activity($meId, 'admin_user_deactivate', (string)$user['email']);
            header('Location: ' . url_admin() . '?done=deactivated');
            exit;
        } elseif ($action === 'restore') {
            $pdo->prepare('UPDATE users SET deleted_at = NULL, scheduled_purge_at = NULL WHERE id = :id')
                ->execute(['id' => $userId]);
            log_activity($meId, 'admin_user_restore', (string)$user['email']);
            header('Location: ' . url_admin() . '?done=restored');
            exit;
        } else {
            // The activity log has no foreign key, so it goes first.
            $pdo->prepare('DELETE FROM activity WHERE user_id = :id')->execute(['id' => $userId]);
            $pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $userId]);
            log_activity($meId, 'admin_user_delete', (string)$user['email']);
            header('Location: ' . url_admin() . '?done=deleted');
            exit;
        }
    }

    // ---- save (create or update) ----
    if ($action === 'save') {
        $vName     = validate_name((string)($_POST['name'] ?? ''));
        $vEmail    = validate_email((string)($_POST['email'] ?? ''));
        $rawRoles  = $_POST['roles'] ?? [];
        $vRoles    = validate_roles(is_array($rawRoles) ? $rawRoles : [], array_keys($ROLES));
        $values['name']        = $vName['value'];
        $values['email']       = $vEmail['value'];
        $values['roles']       = $vRoles['value'];
        $errors = array_merge($vName['errors'], $vEmail['errors']);
        if ($vRoles['errors']) { $errors['roles'] = 'Choose at least one role.'; }

        if (!isset($errors['email'])) {
            $dup = $pdo->prepare('SELECT id FROM users WHERE email = :e AND id <> :id');
            $dup->execute(['e' => $values['email'], 'id' => $userId]);
            if ($dup->fetch()) { $errors['email'] = 'That email is already in use.'; }
        }

        // Do not let an admin lock themselves (or everyone) out of this panel.
        if (!$isNew && in_array('admin', $currentRoles, true) && !in_array('admin', $values['roles'], true)) {
            if ($isSelf) {
                $errors['roles'] = 'You cannot remove your own admin role. Ask another admin to do it.';
            } elseif ($isLastAdmin) {
                $errors['roles'] = 'This is the only active admin. Make someone else an admin first.';
            }
        }

        $password = (string)($_POST['password'] ?? '');
        if ($isNew || $password !== '') {
            $vPw = validate_password($password);
            if (isset($vPw['errors']['password'])) { $errors['password'] = $vPw['errors']['password']; }
        }

        // Lab details.
        $lab = [];
        foreach ($LAB as $col => [$label, $kind, $max]) {
            $v = trim((string)($_POST[$col] ?? ''));
            $values[$col] = $v;
            if ($v === '') { $lab[$col] = null; continue; }
            if ($kind === 'course' && !in_array($v, $COURSES, true)) { $errors[$col] = 'Choose a course from the list.'; }
            if ($col === 'student_id' && ($idProblem = student_id_problem($pdo, $v, $isNew ? null : $userId)) !== '') { $errors[$col] = $idProblem; }
            if ($kind === 'month'  && !in_array($v, $MONTHS, true))  { $errors[$col] = 'Choose a month from the list.'; }
            if ($kind === 'year'   && (!ctype_digit($v) || (int)$v < 1900 || (int)$v > 2100)) { $errors[$col] = 'Enter a four-digit year.'; }
            if ($kind === 'email'  && !filter_var($v, FILTER_VALIDATE_EMAIL)) { $errors[$col] = 'Enter a valid email address.'; }
            if ($max > 0 && mb_strlen($v, 'UTF-8') > $max) { $errors[$col] = $label . ' must be ' . $max . ' characters or fewer.'; }
            $lab[$col] = $v;
        }

        if (!$errors) {
            try {
                $pdo->beginTransaction();
                if ($isNew) {
                    $pdo->prepare('INSERT INTO users (name, email, password_hash) VALUES (:n, :e, :p)')
                        ->execute([
                            'n' => $values['name'], 'e' => $values['email'],
                            'p' => password_hash($password, PASSWORD_DEFAULT),
                        ]);
                    $userId = (int)$pdo->lastInsertId();
                } else {
                    $pdo->prepare('UPDATE users SET name = :n, email = :e WHERE id = :id')
                        ->execute([
                            'n' => $values['name'], 'e' => $values['email'],
                            'id' => $userId,
                        ]);
                    if ($password !== '') {
                        $pdo->prepare('UPDATE users SET password_hash = :p WHERE id = :id')
                            ->execute(['p' => password_hash($password, PASSWORD_DEFAULT), 'id' => $userId]);
                    }
                }

                $pdo->prepare('INSERT IGNORE INTO profiles (user_id) VALUES (:u)')->execute(['u' => $userId]);
                // A new account with a course but no date: enrolled today.
                if ($isNew && !empty($lab['course']) && empty($lab['enrolled_year']) && empty($lab['enrolled_month'])) {
                    $lab['enrolled_year']  = date('Y');
                    $lab['enrolled_month'] = date('F');
                }
                $set = implode(', ', array_map(fn($c) => "`$c` = :$c", array_keys($lab)));
                $pdo->prepare("UPDATE profiles SET $set WHERE user_id = :uid")->execute($lab + ['uid' => $userId]);
                // A change to course or the enrolment date here is a correction of the current course.
                enrollment_sync_current($pdo, $userId);

                foreach (array_diff($currentRoles, $values['roles']) as $r) {
                    $pdo->prepare('DELETE FROM user_roles WHERE user_id = :u AND role = :r')->execute(['u' => $userId, 'r' => $r]);
                }
                foreach (array_diff($values['roles'], $currentRoles) as $r) {
                    $pdo->prepare('INSERT IGNORE INTO user_roles (user_id, role) VALUES (:u, :r)')->execute(['u' => $userId, 'r' => $r]);
                }
                $pdo->commit();

                if ($isSelf) {
                    $_SESSION['user_name']     = $values['name'];
                }
                if ($isNew) {
                    log_activity($meId, 'admin_user_create', $values['email'] . ';roles=' . implode(',', $values['roles']));
                } else {
                    log_activity($meId, 'admin_user_update', $values['email']);
                    if (array_diff($currentRoles, $values['roles']) || array_diff($values['roles'], $currentRoles)) {
                        log_activity($userId, 'role_change', implode(',', $values['roles']));
                    }
                    if ($password !== '') {
                        log_activity($userId, 'password_reset', 'by admin');
                        session_epoch_bump($pdo, $userId);   // their other sessions end
                        if (!$isSelf) { mail_password_changed((string)$values['email'], (string)$values['name'], true); }
                    }
                    if (strcasecmp((string)$user['email'], (string)$values['email']) !== 0) {
                        mail_email_changed((string)$user['email'], (string)$values['name'], (string)$values['email']);
                    }
                }
                header('Location: ' . url_admin() . '?id=' . $userId . '&done=' . ($isNew ? 'created' : 'updated'));
                exit;
            } catch (PDOException $ex) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                error_log('admin user save: ' . $ex->getMessage());
                $errors['form'] = 'Could not save. Please try again.';
            }
        }
    }
}

$invalid = fn(string $key): string => isset($errors[$key]) ? ' aria-invalid="true"' : '';
$errorLine = fn(string $key): string => isset($errors[$key])
    ? '<p class="field-error" id="' . $key . '-err" role="alert">' . e($errors[$key]) . '</p>' : '';

$page_title = $isNew ? 'New user' : 'Edit ' . $user['name'];
$page_id    = 'admin';
require __DIR__ . '/../../src/includes/header.php';
$formAction = url_admin_user() . ($isNew ? '' : '?id=' . $userId);
$backUrl    = $isNew ? url_admin() : url_admin() . '?id=' . $userId;
?>
<div class="page">
  <a class="back-link" href="<?= e($backUrl) ?>"><?= pf_icon('back') ?> <?= $isNew ? 'All users' : 'Back to ' . e($user['name']) ?></a>
  <header class="page-header">
    <div>
      <h1 class="page-title"><?= $isNew ? 'New user' : 'Edit user' ?></h1>
      <p class="page-desc">
        <?php if ($isNew): ?>
          Creates an account that can sign in straight away with the email and password you set.
        <?php else: ?>
          <?= e($user['email']) ?> · joined <?= e(date('M j, Y', strtotime((string)$user['created_at']))) ?>
          <?= $isSelf ? ' · this is you' : '' ?>
        <?php endif; ?>
      </p>
    </div>
  </header>

  <div class="max-w-3xl">
  <?php if ($isDeactivated): ?>
    <?= ui_alert('warning', 'This account is deactivated and cannot sign in. Restore it at the bottom of this page.') ?>
  <?php endif; ?>
  <?php if ($errors): ?>
    <?= ui_alert('error', isset($errors['form']) ? e($errors['form']) : 'Nothing was saved. Please fix the highlighted ' . (count($errors) === 1 ? 'field' : 'fields') . ' and try again.') ?>
  <?php endif; ?>

  <form method="post" action="<?= e($formAction) ?>" x-data="dirtyForm">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="action" value="save">

    <section class="card" aria-labelledby="adm-account-h">
      <h2 class="card-title mb-5" id="adm-account-h">Account</h2>
      <div class="form-grid">
        <div class="field">
          <label class="label" for="name">Full name</label>
          <input class="input" id="name" name="name" type="text" required maxlength="100" value="<?= e($values['name']) ?>"<?= $invalid('name') ?> aria-describedby="name-err">
          <?= $errorLine('name') ?>
        </div>
        <div class="field">
          <label class="label" for="email">University email</label>
          <input class="input" id="email" name="email" type="email" required maxlength="150" value="<?= e($values['email']) ?>"<?= $invalid('email') ?> aria-describedby="email-hint email-err">
          <p class="hint" id="email-hint">Used to sign in, and shown in the Students sheet.</p>
          <?= $errorLine('email') ?>
        </div>
        <div class="field">
          <label class="label" for="password"><?= $isNew ? 'Password' : 'New password' ?></label>
          <div class="password-field" x-data="{ show: false }">
            <input class="input" id="password" name="password" type="password" :type="show ? 'text' : 'password'" minlength="8" maxlength="4096" autocomplete="new-password"
                   <?= $isNew ? 'required' : '' ?><?= $invalid('password') ?> aria-describedby="password-hint password-err">
            <?= ui_pw_toggle() ?>
          </div>
          <p class="hint" id="password-hint">
            <?= $isNew ? 'At least 8 characters. Share it with the user; they can change it in Settings.'
                       : 'Leave blank to keep the current password. At least 8 characters to replace it.' ?>
          </p>
          <?= $errorLine('password') ?>
        </div>
      </div>
    </section>

    <!-- Lab details are a student's profile: shown only while Student is ticked. -->
    <div x-data="{ student: <?= in_array('student', $values['roles'], true) ? 'true' : 'false' ?> }">
    <section class="card" aria-labelledby="adm-roles-h">
      <h2 class="card-title" id="adm-roles-h">Roles</h2>
      <p class="card-desc">A user can hold several roles and switches between them from the top bar.</p>
      <div class="choice-list" role="group" aria-labelledby="adm-roles-h">
        <?php foreach ($ROLES as $rKey => $rDesc):
            $mustKeep = $rKey === 'admin' && in_array('admin', $currentRoles, true) && ($isSelf || $isLastAdmin);
        ?>
          <label class="choice">
            <input type="checkbox" name="roles[]" value="<?= e($rKey) ?>"<?= $rKey === 'student' ? ' @change="student = $event.target.checked"' : '' ?><?= in_array($rKey, $values['roles'], true) ? ' checked' : '' ?><?= $mustKeep ? ' data-locked onclick="return false"' : '' ?>>
            <span>
              <span class="choice-title"><?= ui_role($rKey) ?></span>
              <span class="choice-desc"><?= e($rDesc) ?><?= $mustKeep ? ' ' . e($isSelf ? 'You cannot remove this from yourself.' : 'The only active admin must keep it.') : '' ?></span>
            </span>
          </label>
        <?php endforeach; ?>
      </div>
      <?= $errorLine('roles') ?>
    </section>

    <section class="card mt-5" aria-labelledby="adm-lab-h" x-show="student">
      <h2 class="card-title" id="adm-lab-h">Lab details</h2>
      <p class="card-desc">These are the columns of the Students sheet. The user can edit them on their own profile. The enrolment date is filled in automatically (the day their invitation link was generated, or the day they added a course); correct it here if needed. Changing the course here corrects the current course; the user adds a new course from their profile.</p>
      <div class="form-grid">
        <?php foreach ($LAB as $col => [$label, $kind, $max]): ?>
          <div class="field<?= $kind === 'textarea' ? ' sm:col-span-2' : '' ?>">
            <label class="label" for="<?= e($col) ?>"><?= e($label) ?></label>
            <?php if ($kind === 'course' || $kind === 'month'): ?>
              <select class="select" id="<?= e($col) ?>" name="<?= e($col) ?>"<?= $invalid($col) ?>>
                <option value="">Not set</option>
                <?php foreach (($kind === 'course' ? $COURSES : $MONTHS) as $opt): ?>
                  <option value="<?= e($opt) ?>"<?= $values[$col] === $opt ? ' selected' : '' ?>><?= e($opt) ?></option>
                <?php endforeach; ?>
              </select>
            <?php elseif ($kind === 'textarea'): ?>
              <textarea class="textarea" id="<?= e($col) ?>" name="<?= e($col) ?>" rows="3" maxlength="<?= (int)$max ?>"<?= $invalid($col) ?>><?= e($values[$col]) ?></textarea>
            <?php elseif ($kind === 'year'): ?>
              <input class="input" id="<?= e($col) ?>" name="<?= e($col) ?>" type="number" min="1900" max="2100" step="1" inputmode="numeric" placeholder="YYYY" value="<?= e($values[$col]) ?>"<?= $invalid($col) ?>>
            <?php else: ?>
              <input class="input" id="<?= e($col) ?>" name="<?= e($col) ?>" type="<?= $kind === 'email' ? 'email' : 'text' ?>" maxlength="<?= (int)$max ?>" value="<?= e($values[$col]) ?>"<?= $invalid($col) ?>>
            <?php endif; ?>
            <?= $errorLine($col) ?>
          </div>
        <?php endforeach; ?>
        <?php if (!$isNew): foreach ($LAB_ENTRIES as $col => $label): $v = trim((string)($user[$col] ?? '')); ?>
          <div class="field sm:col-span-2">
            <span class="label"><?= e($label) ?></span>
            <p class="text-sm whitespace-pre-line<?= $v === '' ? ' text-muted-foreground-1' : '' ?>"><?= e($v !== '' ? $v : 'None yet.') ?></p>
          </div>
        <?php endforeach; endif; ?>
      </div>
      <p class="mt-4 text-sm text-muted-foreground-1">Completed theses and papers are entries the user adds on their own profile.</p>
    </section>

    </div>

    <div class="form-actions">
      <span class="form-note" x-show="dirty" x-cloak>You have unsaved changes.</span>
      <a class="btn btn-ghost" href="<?= e($backUrl) ?>">Cancel</a>
      <button class="btn btn-primary" type="submit"><?= $isNew ? 'Create user' : 'Save changes' ?></button>
    </div>
  </form>

  <?php if (!$isNew && (int)$user['totp_required'] === 1): ?>
    <section class="card mt-10" aria-labelledby="adm-2fa-h">
      <h2 class="card-title mb-4" id="adm-2fa-h">Sign-in <span class="badge badge-success">Two-factor on</span></h2>
      <div class="action-row">
        <div>
          <strong>Reset two-factor</strong>
          <p>For someone who lost their phone and their backup codes. They can sign in with just their password, then set up two-factor again. They are emailed about it.</p>
        </div>
        <form method="post" action="<?= e($formAction) ?>" x-confirm
              data-confirm-title="Reset two-factor for <?= e($user['name']) ?>?"
              data-confirm-body="Only do this after checking it is really them asking. Signing in will need only their password until they set it up again."
              data-confirm-ok="Reset two-factor">
          <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="reset_2fa">
          <button class="btn btn-secondary" type="submit"><?= pf_icon('shield') ?> Reset two-factor</button>
        </form>
      </div>
    </section>
  <?php endif; ?>

  <?php if (!$isNew): ?>
    <section class="card card-danger mt-10" aria-labelledby="adm-danger-h">
      <h2 class="card-title mb-4" id="adm-danger-h">Remove access</h2>
      <?php if ($locked !== ''): ?>
        <p class="text-sm text-muted-foreground-2"><?= e($locked) ?> It cannot be deactivated or deleted from here.</p>
      <?php else: ?>
        <div class="action-row">
          <?php if ($isDeactivated): ?>
            <div>
              <strong>Restore this account</strong>
              <p>Lets <?= e($user['name']) ?> sign in again. Nothing was removed while it was deactivated.</p>
            </div>
            <form method="post" action="<?= e($formAction) ?>">
              <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="action" value="restore">
              <button class="btn btn-secondary" type="submit">Restore account</button>
            </form>
          <?php else: ?>
            <div>
              <strong>Deactivate</strong>
              <p>Signs them out and blocks sign-in. Their data is kept and you can restore the account from this page.</p>
            </div>
            <form method="post" action="<?= e($formAction) ?>" x-confirm
                  data-confirm-title="Deactivate <?= e($user['name']) ?>?"
                  data-confirm-body="They will be signed out and unable to sign in until an admin restores the account."
                  data-confirm-ok="Deactivate">
              <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
              <input type="hidden" name="action" value="deactivate">
              <button class="btn btn-secondary" type="submit">Deactivate</button>
            </form>
          <?php endif; ?>
        </div>
        <div class="action-row">
          <div>
            <strong>Delete permanently</strong>
            <p>Removes the account and its lab details. This cannot be undone.</p>
          </div>
          <form method="post" action="<?= e($formAction) ?>" x-confirm
                data-confirm-title="Permanently delete <?= e($user['name']) ?>?"
                data-confirm-body="The account (<?= e($user['email']) ?>) and its lab details will be removed for good. There is no undo."
                data-confirm-ok="Delete permanently">
            <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="action" value="delete">
            <button class="btn btn-danger" type="submit">Delete user</button>
          </form>
        </div>
      <?php endif; ?>
    </section>
  <?php endif; ?>
  </div>
</div>
<?php require __DIR__ . '/../../src/includes/footer.php'; ?>
