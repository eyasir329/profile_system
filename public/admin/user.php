<?php
/**
 * Admin: one user, everything in one place (laid out like My profile), or a
 * new user.
 *
 * Route:  GET  /admin/user.php            new user form
 *         GET  /admin/user.php?id=<id>    the user: identity, summary, editable
 *                                         account / roles / lab details, security,
 *                                         recent activity and emails, danger zone
 *         POST /admin/user.php[?id=<id>]  action = save | send_reset | signout_all |
 *                                         reset_2fa | deactivate | restore | delete
 * Auth:   require_admin()
 * CSRF:   verify_csrf() on POST
 *
 * save         Account (name, email), roles, an optional new password
 *              (required when creating) and the lab details.
 * send_reset   Emails the user a password-reset link (30 minutes), like
 *              "Forgot password", so the admin never has to know a password.
 * signout_all  Ends every session of the user (session_epoch_bump); an admin
 *              doing it to themselves keeps the current one.
 * reset_2fa    For someone who lost their phone and backup codes.
 * deactivate   Soft delete: sets deleted_at and a purge date 30 days out.
 * restore      Clears both, so the user can sign in again.
 * delete       Removes the user row; the profile and related rows cascade.
 *
 * Safety rules, enforced here and reflected in the form:
 *   - an admin cannot deactivate or delete their own account, or drop
 *     their own admin role;
 *   - the last active admin cannot be deactivated, deleted or demoted.
 *
 * Every action comes back to this page with ?done=<what> (delete goes to
 * the user list); a failed save re-renders with the errors inline.
 */
declare(strict_types=1);
require_once __DIR__ . '/../../src/includes/auth.php';
require_once __DIR__ . '/../../src/includes/db.php';
require_once __DIR__ . '/../../src/includes/url.php';
require_once __DIR__ . '/../../src/includes/validate.php';
require_once __DIR__ . '/../../src/includes/lab_fields.php';
require_once __DIR__ . '/../../src/includes/ui.php';
require_once __DIR__ . '/../../src/includes/mail_templates.php';
require_once __DIR__ . '/../../src/includes/email_change.php';
require_admin();

$meId   = (int)current_user_id();
$userId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$isNew  = $userId === 0;

$ROLES = [
    'student' => 'Has a profile and edits their own lab details.',
    'teacher' => 'Also gets the read-only Students sheet and can invite students.',
    'admin'   => 'Also manages every user and the site from this panel.',
];
$COURSES = LAB_COURSES;
$MONTHS  = LAB_MONTHS;
// Lab details: column => [kind, max length]. Labels and hints come from
// lab_field_defs(), so they read exactly as on the user's own profile.
$LAB = [
    'course'              => ['course', 0],
    'student_id'          => ['text', 64],
    'name_en'             => ['text', 255],
    'name_jp'             => ['text', 255],
    'enrolled_year'       => ['year', 0],
    'enrolled_month'      => ['month', 0],
    'previous_univ_level' => ['text', 255],
    'next_step'           => ['text', 255],
    'research_title'      => ['textarea', 2000],
];
$DEFS = lab_field_defs();
// Theses and papers are entries the student adds on their profile; the
// text here is generated from them, so it is shown read-only.
$LAB_ENTRIES = ['completed_thesis', 'journal_papers', 'conference_papers'];

$self = fn(array $q = []): string => url_admin_user() . ($isNew && !isset($q['id']) ? '' : '?' . http_build_query(['id' => $q['id'] ?? $userId] + $q));

$user = null;
if (!$isNew) {
    $st = $pdo->prepare(
        'SELECT u.id, u.name, u.email, u.email_verified, u.totp_required, u.deleted_at, u.scheduled_purge_at, u.created_at, p.*
           FROM users u LEFT JOIN profiles p ON p.user_id = u.id WHERE u.id = :id LIMIT 1'
    );
    $st->execute(['id' => $userId]);
    $user = $st->fetch();
    if (!$user) {
        require_once __DIR__ . '/../../src/includes/error_page.php';
        render_error_page(404, 'There is no user with that ID. They may have been deleted.');
    }
}
$rolesOf = function (int $id) use ($pdo): array {
    $r = $pdo->prepare('SELECT role FROM user_roles WHERE user_id = :u ORDER BY FIELD(role, "admin", "teacher", "student")');
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
    'name'  => (string)($user['name'] ?? ''),
    'email' => (string)($user['email'] ?? ''),
    'roles' => $isNew ? ['student'] : $currentRoles,
];
foreach ($LAB as $col => $_) { $values[$col] = trim((string)($user[$col] ?? '')); }
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? 'save');

    // ---- email a password-reset link (the admin never sees a password) ----
    if (!$isNew && $action === 'send_reset') {
        if ($isDeactivated) {
            header('Location: ' . $self(['done' => 'reset_deactivated'])); exit;
        }
        if (!mail_type_enabled('password_reset')) {
            header('Location: ' . $self(['done' => 'reset_mail_off'])); exit;
        }
        $token = bin2hex(random_bytes(32));
        $pdo->prepare('INSERT INTO password_resets (user_id, token_hash, expires_at) VALUES (:u, :t, :e)')
            ->execute(['u' => $userId, 't' => hash('sha256', $token), 'e' => (new DateTimeImmutable('+30 minutes'))->format('Y-m-d H:i:s')]);
        $sent = mail_password_reset((string)$user['email'], (string)$user['name'], $token);
        log_activity($meId, 'admin_password_link', (string)$user['email'] . ($sent ? '' : ';failed'));
        header('Location: ' . $self(['done' => $sent ? 'reset_sent' : 'reset_failed'])); exit;
    }

    // ---- sign out of every device ----
    if (!$isNew && $action === 'signout_all') {
        session_epoch_bump($pdo, $userId, $isSelf);   // an admin keeps this session when it is their own
        log_activity($meId, 'admin_signout_all', (string)$user['email']);
        header('Location: ' . $self(['done' => 'signed_out'])); exit;
    }

    // ---- reset two-factor: for someone who lost their phone and backup codes ----
    if (!$isNew && $action === 'reset_2fa') {
        $pdo->prepare('DELETE FROM two_factor WHERE user_id = :u')->execute(['u' => $userId]);
        $pdo->prepare('UPDATE users SET totp_required = 0 WHERE id = :u')->execute(['u' => $userId]);
        clear_attempts('totp:' . $userId);
        session_epoch_bump($pdo, $userId);
        log_activity($meId, 'admin_2fa_reset', (string)$user['email']);
        log_activity($userId, 'two_factor_reset', 'by admin');
        mail_two_factor_off((string)$user['email'], (string)$user['name'], true);
        header('Location: ' . $self(['done' => '2fa_reset'])); exit;
    }

    // ---- deactivate / restore / delete ----
    if (!$isNew && in_array($action, ['deactivate', 'restore', 'delete'], true)) {
        if ($action !== 'restore' && $locked !== '') {
            $errors['form'] = $locked . ' It cannot be ' . ($action === 'delete' ? 'deleted' : 'deactivated') . '.';
        } elseif ($action === 'deactivate') {
            $pdo->prepare('UPDATE users SET deleted_at = NOW(), scheduled_purge_at = DATE_ADD(NOW(), INTERVAL 30 DAY) WHERE id = :id')
                ->execute(['id' => $userId]);
            log_activity($meId, 'admin_user_deactivate', (string)$user['email']);
            header('Location: ' . $self(['done' => 'deactivated'])); exit;
        } elseif ($action === 'restore') {
            $pdo->prepare('UPDATE users SET deleted_at = NULL, scheduled_purge_at = NULL WHERE id = :id')
                ->execute(['id' => $userId]);
            log_activity($meId, 'admin_user_restore', (string)$user['email']);
            header('Location: ' . $self(['done' => 'restored'])); exit;
        } else {
            // The activity log has no foreign key, so it goes first.
            $pdo->prepare('DELETE FROM activity WHERE user_id = :id')->execute(['id' => $userId]);
            $pdo->prepare('DELETE FROM users WHERE id = :id')->execute(['id' => $userId]);
            log_activity($meId, 'admin_user_delete', (string)$user['email']);
            header('Location: ' . url_admin() . '?done=deleted'); exit;
        }
    }

    // ---- save (create or update) ----
    if ($action === 'save') {
        $vName     = validate_name((string)($_POST['name'] ?? ''));
        $vEmail    = validate_email((string)($_POST['email'] ?? ''));
        $rawRoles  = $_POST['roles'] ?? [];
        $vRoles    = validate_roles(is_array($rawRoles) ? $rawRoles : [], array_keys($ROLES));
        $values['name']  = $vName['value'];
        $values['email'] = $vEmail['value'];
        $values['roles'] = $vRoles['value'];
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
        foreach ($LAB as $col => [$kind, $max]) {
            $label = $DEFS[$col]['label'] ?? $col;
            $v = trim((string)($_POST[$col] ?? ''));
            $values[$col] = $v;
            if ($v === '') { $lab[$col] = null; continue; }
            if ($kind === 'course' && !in_array($v, $COURSES, true)) { $errors[$col] = 'Choose a course from the list.'; }
            if ($col === 'student_id' && ($idProblem = student_id_problem($pdo, $v, $isNew ? null : $userId)) !== '') { $errors[$col] = $idProblem; }
            if ($kind === 'month' && !in_array($v, $MONTHS, true)) { $errors[$col] = 'Choose a month from the list.'; }
            if ($kind === 'year' && (!ctype_digit($v) || (int)$v < 1900 || (int)$v > 2100)) { $errors[$col] = 'Enter a four-digit year.'; }
            if ($max > 0 && mb_strlen($v, 'UTF-8') > $max) { $errors[$col] = $label . ' must be ' . $max . ' characters or fewer.'; }
            $lab[$col] = $v;
        }

        if (!$errors) {
            try {
                $pdo->beginTransaction();
                if ($isNew) {
                    $pdo->prepare('INSERT INTO users (name, email, password_hash) VALUES (:n, :e, :p)')
                        ->execute(['n' => $values['name'], 'e' => $values['email'], 'p' => password_hash($password, PASSWORD_DEFAULT)]);
                    $userId = (int)$pdo->lastInsertId();
                } else {
                    $pdo->prepare('UPDATE users SET name = :n, email = :e WHERE id = :id')
                        ->execute(['n' => $values['name'], 'e' => $values['email'], 'id' => $userId]);
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

                if ($isSelf) { $_SESSION['user_name'] = $values['name']; }
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
                        email_change_applied($pdo, $userId);   // reset links sent to the old address stop working
                        mail_email_changed((string)$user['email'], (string)$values['name'], (string)$values['email']);
                    }
                }
                header('Location: ' . url_admin_user() . '?id=' . $userId . '&done=' . ($isNew ? 'created' : 'updated'));
                exit;
            } catch (PDOException $ex) {
                if ($pdo->inTransaction()) { $pdo->rollBack(); }
                error_log('admin user save: ' . $ex->getMessage());
                $errors['form'] = 'Could not save. Please try again.';
            }
        }
    }
}

// ---------- After an action ----------
$flash = match ((string)($_GET['done'] ?? '')) {
    'created'           => [['type' => 'success', 'title' => 'User created', 'body' => 'They can sign in now with the email and password you set.']],
    'updated'           => [['type' => 'success', 'body' => 'Changes saved.']],
    'reset_sent'        => [['type' => 'success', 'title' => 'Reset link sent', 'body' => 'It works once, for 30 minutes.']],
    'reset_failed'      => [['type' => 'error', 'title' => 'The reset link could not be sent', 'body' => 'See the newest entry in Emails for the reason.', 'duration' => 0]],
    'reset_mail_off'    => [['type' => 'error', 'body' => 'Password reset emails are off (' . rtrim(mail_off_reason('password_reset'), '.') . '). Set a new password below instead.', 'duration' => 0]],
    'reset_deactivated' => [['type' => 'error', 'body' => 'Restore the account first: a deactivated account cannot sign in.']],
    'signed_out'        => [['type' => 'success', 'body' => $isSelf ? 'Signed out of your other devices.' : 'Signed out of every device.']],
    '2fa_reset'         => [['type' => 'success', 'body' => 'Two-factor sign-in reset. They have been emailed.']],
    'deactivated'       => [['type' => 'success', 'body' => 'Account deactivated. It can be restored for 30 days.']],
    'restored'          => [['type' => 'success', 'body' => 'Account restored. They can sign in again.']],
    default             => [],
};

// ---------- What the page shows about an existing user ----------
$summary = $recent = $mails = [];
if (!$isNew) {
    $st = $pdo->prepare(
        "SELECT MAX(created_at) AS last_active, TIMESTAMPDIFF(SECOND, MAX(created_at), NOW()) AS last_active_ago,
                MAX(CASE WHEN kind = 'login' THEN created_at END) AS last_login,
                TIMESTAMPDIFF(SECOND, MAX(CASE WHEN kind = 'login' THEN created_at END), NOW()) AS last_login_ago,
                COALESCE(SUM(kind = 'login' AND created_at >= NOW() - INTERVAL 30 DAY), 0) AS logins30,
                COALESCE(SUM(kind IN ('login_failed', 'login_2fa_failed') AND created_at >= NOW() - INTERVAL 30 DAY), 0) AS failed30
           FROM activity WHERE user_id = :id"
    );
    $st->execute(['id' => $userId]);
    $summary = $st->fetch();
    $st = $pdo->prepare("SELECT kind, detail, ip, user_agent, created_at, TIMESTAMPDIFF(SECOND, created_at, NOW()) AS ago
                           FROM activity WHERE user_id = :id AND kind NOT IN ('role_fallback', 'login_password_ok')
                          ORDER BY created_at DESC, id DESC LIMIT 8");
    $st->execute(['id' => $userId]);
    $recent = $st->fetchAll();
    try {
        $st = $pdo->prepare('SELECT id, kind, status, subject, created_at, TIMESTAMPDIFF(SECOND, created_at, NOW()) AS ago
                               FROM email_log WHERE to_user_id = :id OR to_email = :e ORDER BY created_at DESC, id DESC LIMIT 5');
        $st->execute(['id' => $userId, 'e' => $user['email']]);
        $mails = $st->fetchAll();
    } catch (Throwable $e) { $mails = []; }
}
$agoOf = fn($sec, ?string $t): string => $t ? ui_ago((int)$sec, $t) : 'Never';
$isStudent = in_array('student', $values['roles'], true);

$invalid = fn(string $key): string => isset($errors[$key]) ? ' aria-invalid="true" aria-describedby="' . $key . '-err"' : '';
$errorLine = fn(string $key): string => isset($errors[$key])
    ? '<p class="field-error" id="' . $key . '-err" role="alert">' . e($errors[$key]) . '</p>' : '';
$formAction = $isNew ? url_admin_user() : url_admin_user() . '?id=' . $userId;
$hidden = fn(string $action): string => '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '"><input type="hidden" name="action" value="' . e($action) . '">';

// The "On this page" list.
$NAV = ['account' => 'Account', 'roles' => 'Roles', 'lab' => 'Lab details'];
if (!$isNew) { $NAV += ['security' => 'Sign-in & security', 'activity' => 'Recent activity', 'emails' => 'Emails', 'danger' => 'Remove access']; }

$page_title = $isNew ? 'New user' : $user['name'] . ' · Users';
$page_id    = 'admin';
require __DIR__ . '/../../src/includes/header.php';
?>
<div class="page">
  <a class="back-link" href="<?= e(url_admin()) ?>"><?= pf_icon('back') ?> All users</a>

  <?php if ($isNew): ?>
    <header class="page-header">
      <div>
        <h1 class="page-title">New user</h1>
        <p class="page-desc">Creates an account that can sign in straight away with the email and password you set. To let someone register themselves, send an <a href="<?= e(url_invites()) ?>">invitation</a> instead.</p>
      </div>
    </header>
  <?php else: ?>
    <header class="identity">
      <?= ui_avatar(null, (string)$user['name'], 'avatar-xl') ?>
      <div class="identity-body">
        <h1 class="identity-name"><?= e($user['name']) ?></h1>
        <p class="identity-meta">
          <span><?= e($user['email']) ?></span>
          <?php if (in_array('student', $currentRoles, true) && trim((string)($user['course'] ?? '')) !== ''): ?><span class="badge"><?= e($user['course']) ?></span><?php endif; ?>
          <?php if (in_array('student', $currentRoles, true) && trim((string)($user['student_id'] ?? '')) !== ''): ?><span>ID <?= e($user['student_id']) ?></span><?php endif; ?>
        </p>
        <p class="identity-meta">
          <?= ui_roles($currentRoles) ?>
          <?= $isDeactivated ? '<span class="badge badge-danger">Deactivated</span>' : '<span class="badge badge-success">Active</span>' ?>
          <?= $isSelf ? '<span class="badge">This is you</span>' : '' ?>
        </p>
        <div class="identity-actions">
          <a class="btn btn-secondary btn-sm" href="<?= e(url_admin_emails() . '?view=compose&user=' . $userId) ?>"><?= pf_icon('mail') ?> Email</a>
          <a class="btn btn-secondary btn-sm" href="<?= e(url_admin_activity() . '?user=' . $userId) ?>"><?= pf_icon('pulse') ?> Activity</a>
          <?php if (in_array('student', $currentRoles, true) && !$isDeactivated): ?>
            <a class="btn btn-ghost btn-sm" href="<?= e(url_students() . '?id=' . $userId) ?>" title="What teachers see"><?= pf_icon('user') ?> Teacher view</a>
          <?php endif; ?>
        </div>
      </div>
      <dl class="completion user-summary">
        <div><dt>Last active</dt><dd title="<?= e((string)$summary['last_active']) ?>"><?= e($agoOf($summary['last_active_ago'], $summary['last_active'])) ?></dd></div>
        <div><dt>Last sign-in</dt><dd title="<?= e((string)$summary['last_login']) ?>"><?= e($agoOf($summary['last_login_ago'], $summary['last_login'])) ?></dd></div>
        <div><dt>Sign-ins, 30 days</dt><dd><?= (int)$summary['logins30'] ?><?= (int)$summary['failed30'] ? ' <span class="badge badge-danger">' . (int)$summary['failed30'] . ' failed</span>' : '' ?></dd></div>
        <div><dt>Two-factor</dt><dd><?= (int)$user['totp_required'] === 1 ? '<span class="badge badge-success">On</span>' : '<span class="badge">Off</span>' ?></dd></div>
        <div><dt>Joined</dt><dd><?= e(date('M j, Y', strtotime((string)$user['created_at']))) ?></dd></div>
      </dl>
    </header>
  <?php endif; ?>

  <?php if ($isDeactivated): ?>
    <div class="mb-6"><?= ui_alert('warning', '<strong>This account is deactivated</strong> and cannot sign in.'
        . (!empty($user['scheduled_purge_at']) ? ' It will be deleted for good on ' . e(date('M j, Y', strtotime((string)$user['scheduled_purge_at']))) . '.' : '')
        . ' <a href="#sec-danger">Restore it</a>.') ?></div>
  <?php endif; ?>
  <?php if ($errors): ?>
    <div class="mb-6"><?= ui_alert('error', isset($errors['form']) ? e($errors['form']) : 'Nothing was saved. Please fix the highlighted ' . (count($errors) === 1 ? 'field' : 'fields') . ' and try again.') ?></div>
  <?php endif; ?>

  <div class="profile-layout" x-data="sectionNav" data-student="<?= $isStudent ? '1' : '0' ?>">
    <aside class="profile-nav" aria-label="Sections">
      <p class="side-nav-title">On this page</p>
      <nav class="side-nav">
        <?php $first = true; foreach ($NAV as $key => $label): ?>
          <a class="side-nav-item<?= $key === 'danger' ? ' is-danger' : '' ?>" href="#sec-<?= $key ?>" data-nav="<?= $key ?>"<?= $key === 'lab' ? ' x-show="student"' : '' ?> aria-current="<?= $first ? 'true' : 'false' ?>"><span><?= e($label) ?></span></a>
        <?php $first = false; endforeach; ?>
      </nav>
    </aside>

    <div class="min-w-0 max-w-3xl">
      <form method="post" action="<?= e($formAction) ?>" x-data="dirtyForm" id="user-form">
        <?= $hidden('save') ?>

        <section class="section" id="sec-account" aria-labelledby="h-account">
          <header class="section-head"><div>
            <h2 class="section-title" id="h-account">Account</h2>
            <p class="section-desc">The name and address they sign in with.</p>
          </div></header>
          <div class="form-grid pt-5">
            <div class="field">
              <label class="label" for="name">Full name</label>
              <input class="input" id="name" name="name" type="text" required maxlength="100" autocomplete="off" value="<?= e($values['name']) ?>"<?= $invalid('name') ?>>
              <?= $errorLine('name') ?>
            </div>
            <div class="field">
              <label class="label" for="email">University email</label>
              <input class="input" id="email" name="email" type="email" required maxlength="150" autocomplete="off" value="<?= e($values['email']) ?>"<?= $invalid('email') ?>>
              <p class="hint">Used to sign in, and shown in the Students sheet.<?= $isNew ? '' : ' Their old address is told if it changes.' ?></p>
              <?= $errorLine('email') ?>
            </div>
            <div class="field sm:col-span-2">
              <label class="label" for="password"><?= $isNew ? 'Password' : 'Set a new password' ?><?= $isNew ? '' : ' <span class="optional">(optional)</span>' ?></label>
              <div class="password-field max-w-sm" x-data="{ show: false }">
                <input class="input" id="password" name="password" type="password" :type="show ? 'text' : 'password'" minlength="8" maxlength="4096" autocomplete="new-password"
                       <?= $isNew ? 'required' : '' ?><?= $invalid('password') ?>>
                <?= ui_pw_toggle() ?>
              </div>
              <p class="hint"><?= $isNew ? 'At least 8 characters. Give it to them; they can change it in Settings.'
                  : 'Leave blank to keep their password. Better: send them a reset link under Sign-in &amp; security, so you never know it.' ?></p>
              <?= $errorLine('password') ?>
            </div>
          </div>
        </section>

        <section class="section" id="sec-roles" aria-labelledby="h-roles">
          <header class="section-head"><div>
            <h2 class="section-title" id="h-roles">Roles</h2>
            <p class="section-desc">Someone can hold several roles and switch between them from the top bar.</p>
          </div></header>
          <div class="choice-list mt-5" role="group" aria-labelledby="h-roles">
            <?php foreach ($ROLES as $rKey => $rDesc):
                $mustKeep = $rKey === 'admin' && in_array('admin', $currentRoles, true) && ($isSelf || $isLastAdmin); ?>
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

        <section class="section" id="sec-lab" aria-labelledby="h-lab" x-show="student">
          <header class="section-head"><div>
            <h2 class="section-title" id="h-lab">Lab details</h2>
            <p class="section-desc">The columns of the Students sheet; the student edits these on their own profile. Correct them here if needed. A course change corrects their current course.</p>
          </div></header>
          <div class="form-grid pt-5">
            <?php foreach ($LAB as $col => [$kind, $max]): $def = $DEFS[$col]; ?>
              <div class="field<?= $kind === 'textarea' ? ' sm:col-span-2' : '' ?>">
                <label class="label" for="<?= e($col) ?>"><?= e($def['label']) ?></label>
                <?php if ($kind === 'course' || $kind === 'month'): ?>
                  <select class="select" id="<?= e($col) ?>" name="<?= e($col) ?>"<?= $invalid($col) ?>>
                    <option value="">Not set</option>
                    <?php foreach (($kind === 'course' ? $COURSES : $MONTHS) as $opt): ?>
                      <option value="<?= e($opt) ?>"<?= $values[$col] === $opt ? ' selected' : '' ?>><?= e($opt) ?></option>
                    <?php endforeach; ?>
                  </select>
                <?php elseif ($kind === 'textarea'): ?>
                  <textarea class="textarea" id="<?= e($col) ?>" name="<?= e($col) ?>" rows="3" maxlength="<?= (int)$max ?>" placeholder="<?= e($def['placeholder']) ?>"<?= $invalid($col) ?>><?= e($values[$col]) ?></textarea>
                <?php elseif ($kind === 'year'): ?>
                  <input class="input" id="<?= e($col) ?>" name="<?= e($col) ?>" type="number" min="1900" max="2100" step="1" inputmode="numeric" placeholder="YYYY" value="<?= e($values[$col]) ?>"<?= $invalid($col) ?>>
                <?php else: ?>
                  <input class="input" id="<?= e($col) ?>" name="<?= e($col) ?>" type="text" maxlength="<?= (int)$max ?>" placeholder="<?= e($def['placeholder']) ?>" value="<?= e($values[$col]) ?>"<?= $invalid($col) ?>>
                <?php endif; ?>
                <?= $errorLine($col) ?>
              </div>
            <?php endforeach; ?>
          </div>
          <?php if (!$isNew): ?>
            <dl class="facts is-single is-stacked mt-2">
              <?php foreach ($LAB_ENTRIES as $col): $v = trim((string)($user[$col] ?? '')); ?>
                <div><dt><?= e($DEFS[$col]['label']) ?></dt><dd class="whitespace-pre-line"><?= $v !== '' ? e($v) : '<span class="faint">None yet</span>' ?></dd></div>
              <?php endforeach; ?>
            </dl>
            <p class="mt-3 text-xs text-muted-foreground-1">Theses and papers are entries the student adds on their own profile, so they are read-only here.</p>
          <?php endif; ?>
        </section>

        <div class="save-bar" :class="dirty && 'is-dirty'">
          <p class="save-bar-note" x-show="dirty" x-cloak>You have unsaved changes.</p>
          <p class="save-bar-note" x-show="!dirty"><?= $isNew ? 'Fill in the account, then create it.' : 'Edit any field above, then save.' ?></p>
          <a class="btn btn-ghost" href="<?= e($isNew ? url_admin() : $formAction) ?>">Cancel</a>
          <button class="btn btn-primary" type="submit"><?= $isNew ? pf_icon('plus') . ' Create user' : 'Save changes' ?></button>
        </div>
      </form>

      <?php if (!$isNew): ?>
        <section class="section" id="sec-security" aria-labelledby="h-security">
          <header class="section-head"><div>
            <h2 class="section-title" id="h-security">Sign-in &amp; security</h2>
            <p class="section-desc">Help them back in, or lock a lost device out.</p>
          </div></header>
          <div class="pt-4">
            <div class="action-row">
              <div>
                <strong>Send a password reset link</strong>
                <p>Emails <?= e($user['email']) ?> a link to choose a new password (works once, for 30 minutes). You never see the password.</p>
              </div>
              <form method="post" action="<?= e($formAction) ?>" x-confirm data-confirm-title="Email a reset link to <?= e($user['name']) ?>?"
                    data-confirm-body="<?= e('A one-time link goes to ' . $user['email'] . '. Their current password keeps working until they use it.') ?>"
                    data-confirm-ok="Send link" data-confirm-tone="primary">
                <?= $hidden('send_reset') ?>
                <button class="btn btn-secondary" type="submit"<?= $isDeactivated ? ' disabled' : '' ?>><?= pf_icon('mail') ?> Send link</button>
              </form>
            </div>
            <div class="action-row">
              <div>
                <strong>Sign out everywhere</strong>
                <p><?= $isSelf ? 'Ends your sessions on every other device; this one stays signed in.' : 'Ends every session at once, for a lost phone or a shared computer. They can sign in again.' ?></p>
              </div>
              <form method="post" action="<?= e($formAction) ?>" x-confirm data-confirm-title="Sign <?= e($isSelf ? 'out your other devices' : $user['name'] . ' out everywhere') ?>?"
                    data-confirm-body="Every open session ends straight away." data-confirm-ok="Sign out" data-confirm-tone="primary">
                <?= $hidden('signout_all') ?>
                <button class="btn btn-secondary" type="submit"><?= pf_icon('logout') ?> Sign out</button>
              </form>
            </div>
            <div class="action-row">
              <div>
                <strong>Two-factor sign-in <?= (int)$user['totp_required'] === 1 ? '<span class="badge badge-success">On</span>' : '<span class="badge">Off</span>' ?></strong>
                <p><?= (int)$user['totp_required'] === 1
                    ? 'For someone who lost their phone and backup codes: they sign in with just their password, then set it up again. They are emailed.'
                    : 'Only they can turn it on, in their Settings. Admin accounts should have it.' ?></p>
              </div>
              <?php if ((int)$user['totp_required'] === 1): ?>
                <form method="post" action="<?= e($formAction) ?>" x-confirm data-confirm-title="Reset two-factor for <?= e($user['name']) ?>?"
                      data-confirm-body="Only do this after checking it is really them asking. Signing in will need only their password until they set it up again."
                      data-confirm-ok="Reset two-factor">
                  <?= $hidden('reset_2fa') ?>
                  <button class="btn btn-secondary" type="submit"><?= pf_icon('shield') ?> Reset</button>
                </form>
              <?php endif; ?>
            </div>
          </div>
        </section>

        <section class="section" id="sec-activity" aria-labelledby="h-activity">
          <header class="section-head">
            <div>
              <h2 class="section-title" id="h-activity">Recent activity</h2>
              <p class="section-desc">Their last sign-ins and changes.</p>
            </div>
            <a class="btn btn-ghost btn-sm" href="<?= e(url_admin_activity() . '?user=' . $userId) ?>">See all</a>
          </header>
          <?php if (!$recent): ?>
            <p class="py-4 text-sm text-muted-foreground-1">Nothing recorded yet.</p>
          <?php else: ?>
            <ul>
              <?php foreach ($recent as $a): [$icon, $text, $tone] = ui_activity_admin((string)$a['kind'], $a['detail']); ?>
                <li class="flex items-start gap-3 border-b border-layer-line py-3 text-sm">
                  <span class="activity-line<?= $tone ? ' is-' . $tone : '' ?> min-w-0 flex-1 font-normal"><?= pf_icon($icon) ?><span><?= e($text) ?></span></span>
                  <span class="shrink-0 text-right text-xs text-muted-foreground-1" title="<?= e((string)$a['created_at']) ?>">
                    <?= e(ui_ago((int)$a['ago'], (string)$a['created_at'])) ?>
                    <?php if (($d = ui_device($a['user_agent'])) !== ''): ?><span class="block"><?= e($d) ?></span><?php endif; ?>
                  </span>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </section>

        <section class="section" id="sec-emails" aria-labelledby="h-emails">
          <header class="section-head">
            <div>
              <h2 class="section-title" id="h-emails">Emails</h2>
              <p class="section-desc">What the site sent them, and whether it went through.</p>
            </div>
            <a class="btn btn-ghost btn-sm" href="<?= e(url_admin_emails() . '?q=' . urlencode((string)$user['email'])) ?>">See all</a>
          </header>
          <?php if (!$mails): ?>
            <p class="py-4 text-sm text-muted-foreground-1">No emails yet.</p>
          <?php else: ?>
            <ul>
              <?php foreach ($mails as $m): ?>
                <li class="flex flex-wrap items-center gap-x-3 gap-y-1 border-b border-layer-line py-3 text-sm">
                  <?= ['sent' => '<span class="badge badge-success">Sent</span>', 'failed' => '<span class="badge badge-danger">Failed</span>'][$m['status']] ?? '<span class="badge badge-warning">Not sent</span>' ?>
                  <a class="min-w-0 flex-1 font-medium" href="<?= e(url_admin_emails() . '?id=' . (int)$m['id']) ?>"><?= e($m['subject']) ?></a>
                  <span class="text-xs text-muted-foreground-1"><?= e(ui_ago((int)$m['ago'], (string)$m['created_at'])) ?></span>
                </li>
              <?php endforeach; ?>
            </ul>
          <?php endif; ?>
        </section>

        <section class="section is-danger" id="sec-danger" aria-labelledby="h-danger">
          <header class="section-head"><div>
            <h2 class="section-title" id="h-danger">Remove access</h2>
            <p class="section-desc"><?= $locked !== '' ? e($locked) . ' It cannot be deactivated or deleted.' : 'Deactivating can be undone for 30 days; deleting cannot.' ?></p>
          </div></header>
          <?php if ($locked === ''): ?>
            <div class="pt-4">
              <div class="action-row">
                <?php if ($isDeactivated): ?>
                  <div>
                    <strong>Restore this account</strong>
                    <p>Lets <?= e($user['name']) ?> sign in again. Nothing was removed while it was deactivated.</p>
                  </div>
                  <form method="post" action="<?= e($formAction) ?>">
                    <?= $hidden('restore') ?>
                    <button class="btn btn-primary" type="submit">Restore account</button>
                  </form>
                <?php else: ?>
                  <div>
                    <strong>Deactivate</strong>
                    <p>Signs them out and blocks sign-in. Their data is kept, and you can restore the account here for 30 days.</p>
                  </div>
                  <form method="post" action="<?= e($formAction) ?>" x-confirm data-confirm-title="Deactivate <?= e($user['name']) ?>?"
                        data-confirm-body="They will be signed out and unable to sign in until an admin restores the account." data-confirm-ok="Deactivate">
                    <?= $hidden('deactivate') ?>
                    <button class="btn btn-secondary" type="submit">Deactivate</button>
                  </form>
                <?php endif; ?>
              </div>
              <div class="action-row">
                <div>
                  <strong>Delete permanently</strong>
                  <p>Removes the account, its lab details and its activity. This cannot be undone.</p>
                </div>
                <form method="post" action="<?= e($formAction) ?>" x-confirm data-confirm-title="Permanently delete <?= e($user['name']) ?>?"
                      data-confirm-body="<?= e('The account (' . $user['email'] . ') and its lab details will be removed for good. There is no undo.') ?>"
                      data-confirm-ok="Delete permanently">
                  <?= $hidden('delete') ?>
                  <button class="btn btn-danger" type="submit">Delete user</button>
                </form>
              </div>
            </div>
          <?php endif; ?>
        </section>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php require __DIR__ . '/../../src/includes/footer.php'; ?>
