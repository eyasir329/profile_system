<?php
/**
 * Invitation links (the only way a new account is created).
 *
 * Route:  GET/POST /invites.php
 * Auth:   require_login(); the ACTIVE role must be teacher or admin.
 * CSRF:   verify_csrf() on POST
 * Actions:
 *   - create: make a link. A teacher's links always create students; an
 *             admin picks any roles. Optional: lock to one email, allow
 *             several uses, choose the expiry. A link that grants admin
 *             is always single-use.
 *   - revoke: cancel a link (your own; an admin may cancel anyone's).
 *
 * The link is shown once, right after it is created: only a hash of the
 * token is stored, so it cannot be displayed again later.
 * A teacher sees their own links; an admin sees everyone's.
 */
declare(strict_types=1);
require_once __DIR__ . '/../src/includes/auth.php';
require_once __DIR__ . '/../src/includes/db.php';
require_once __DIR__ . '/../src/includes/url.php';
require_once __DIR__ . '/../src/includes/validate.php';
require_once __DIR__ . '/../src/includes/ui.php';
require_once __DIR__ . '/../src/includes/invites.php';
require_once __DIR__ . '/../src/includes/mail_templates.php';
require_login();
ensure_active_role_valid();

$page_id = 'invites';
$acting  = active_user_role();
if (!in_array($acting, ['teacher', 'admin'], true)) {
    require_once __DIR__ . '/../src/includes/error_page.php';
    render_error_page(403, 'Invitations are made by teachers and admins.');
}

$meId      = (int)current_user_id();
$actAdmin  = $acting === 'admin';
$grantable = invite_grantable_roles([$acting]);
$ROLE_HELP = [
    'student' => 'Has a profile and edits their own data.',
    'teacher' => 'Also gets the read-only Students directory and can invite students.',
    'admin'   => 'Manages every user. Admin links always work once only.',
];

$errors = [];
$form   = ['roles' => ['student'], 'email' => '', 'note' => '', 'uses' => 1, 'days' => 7];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'revoke') {
        $id = (int)($_POST['id'] ?? 0);
        $st = $pdo->prepare('UPDATE invites SET revoked_at = NOW() WHERE id = :id AND revoked_at IS NULL' . ($actAdmin ? '' : ' AND created_by = :me'));
        $st->execute($actAdmin ? ['id' => $id] : ['id' => $id, 'me' => $meId]);
        if ($st->rowCount()) { log_activity($meId, 'invite_revoke', 'invite=' . $id); }
        header('Location: ' . url_invites() . '?done=' . ($st->rowCount() ? 'revoked' : 'none'));
        exit;
    }

    if ($action === 'create') {
        // A teacher has no role choice: the field is ignored, not trusted.
        $rawRoles = $actAdmin ? (array)($_POST['roles'] ?? []) : ['student'];
        $roles    = array_values(array_intersect(INVITE_ROLES, array_filter($rawRoles, 'is_string')));
        if (!$roles || array_diff($roles, $grantable)) { $errors['roles'] = 'Choose at least one role.'; }

        $email = trim((string)($_POST['email'] ?? ''));
        if ($email !== '') {
            $vEmail = validate_email($email);
            $email  = (string)$vEmail['value'];
            if ($vEmail['errors']) {
                $errors['email'] = 'Enter a valid email address, or leave it blank.';
            } else {
                $taken = $pdo->prepare('SELECT 1 FROM users WHERE email = :e');
                $taken->execute(['e' => $email]);
                if ($taken->fetch()) { $errors['email'] = 'There is already an account with that email.'; }
            }
        }
        $note = trim((string)($_POST['note'] ?? ''));
        if (mb_strlen($note, 'UTF-8') > 150) { $errors['note'] = 'Keep the note to 150 characters or fewer.'; }

        $uses = (int)($_POST['uses'] ?? 1);
        if ($uses < 1 || $uses > INVITE_MAX_USES) { $errors['uses'] = 'Choose between 1 and ' . INVITE_MAX_USES . ' uses.'; }
        // One address can only ever register once, and an admin link is single-use.
        if ($email !== '' || in_array('admin', $roles, true)) { $uses = 1; }

        $days = (int)($_POST['days'] ?? 7);
        if (!isset(INVITE_DAYS[$days])) { $errors['days'] = 'Choose how long the link should work.'; }

        $form = ['roles' => $roles ?: ['student'], 'email' => $email, 'note' => $note, 'uses' => $uses, 'days' => $days];

        if (!$errors) {
            $pair = generate_token_pair();
            $pdo->prepare(
                'INSERT INTO invites (token_hash, roles, email, note, max_uses, expires_at, created_by)
                 VALUES (:h, :r, :e, :n, :m, DATE_ADD(NOW(), INTERVAL :d DAY), :me)'
            )->execute([
                'h' => $pair['hash'], 'r' => implode(',', $roles),
                'e' => $email !== '' ? $email : null, 'n' => $note !== '' ? $note : null,
                'm' => $uses, 'd' => $days, 'me' => $meId,
            ]);
            $newId = (int)$pdo->lastInsertId();
            log_activity($meId, 'invite_create', 'invite=' . $newId . ';roles=' . implode(',', $roles));
            // An invitation for one address is also emailed to it. The link
            // is still shown below, in case the email does not arrive.
            $emailed = $email !== ''
                ? mail_invitation($email, $pair['token'], $roles, current_user_name(), date('Y-m-d', strtotime('+' . $days . ' days')))
                : null;
            // Kept in the session for exactly one page view; never stored.
            $_SESSION['invite_just_created'] = ['id' => $newId, 'token' => $pair['token'], 'email' => $email, 'emailed' => $emailed];
            header('Location: ' . url_invites() . '?done=created');
            exit;
        }
    }
}

// The freshly created link, shown once.
$fresh = null;
if (isset($_SESSION['invite_just_created']) && is_array($_SESSION['invite_just_created'])) {
    $fresh = $_SESSION['invite_just_created'];
    unset($_SESSION['invite_just_created']);
    $fresh['url'] = mail_link(url_register() . '?invite=' . $fresh['token']) ?: url_register() . '?invite=' . $fresh['token'];
}

$flash = [];
if (($_GET['done'] ?? '') === 'revoked') { $flash[] = ['type' => 'success', 'body' => 'Invitation cancelled. The link no longer works.']; }

// Existing invitations: own for a teacher, everyone's for an admin.
$st = $pdo->prepare(
    'SELECT i.*, c.name AS creator_name
       FROM invites i LEFT JOIN users c ON c.id = i.created_by'
    . ($actAdmin ? '' : ' WHERE i.created_by = :me')
    . ' ORDER BY i.id DESC LIMIT 200'
);
$st->execute($actAdmin ? [] : ['me' => $meId]);
$invites = $st->fetchAll();

// Admins: filter the list by state (?state=active|used|expired|cancelled), with counts.
$stateCounts = ['' => count($invites)];
foreach ($invites as $inv) { $k = invite_state($inv); $stateCounts[$k] = ($stateCounts[$k] ?? 0) + 1; }
$stateFilter = $actAdmin && in_array($_GET['state'] ?? '', ['active', 'used', 'expired', 'cancelled'], true) ? (string)$_GET['state'] : '';
$listed = $stateFilter === '' ? $invites : array_values(array_filter($invites, fn($i) => invite_state($i) === $stateFilter));

$joined = []; // invite id => [names]
if ($invites) {
    $ids = implode(',', array_map(fn($i) => (int)$i['id'], $invites));
    foreach ($pdo->query("SELECT invite_id, name FROM users WHERE invite_id IN ($ids) ORDER BY id") as $u) {
        $joined[(int)$u['invite_id']][] = (string)$u['name'];
    }
}
$STATE = [
    'active'    => ['Active', 'badge-success'],
    'used'      => ['Used', ''],
    'expired'   => ['Expired', ''],
    'cancelled' => ['Cancelled', 'badge-danger'],
];
$invalid = fn(string $k): string => isset($errors[$k]) ? ' aria-invalid="true"' : '';
$errorLine = fn(string $k): string => isset($errors[$k]) ? '<p class="field-error" id="' . $k . '-err" role="alert">' . e($errors[$k]) . '</p>' : '';

$page_title = 'Invitations';
require __DIR__ . '/../src/includes/header.php';
?>
<div class="page">
  <header class="page-header">
    <div>
      <h1 class="page-title">Invitations</h1>
      <p class="page-desc">
        Accounts can only be created through a link you make here.
        <?= $actAdmin ? 'As an admin you can invite students, teachers and admins.' : 'As a teacher you can invite students.' ?>
      </p>
    </div>
  </header>

  <?php if ($fresh): ?>
    <section class="alert alert-success mb-8 block" aria-labelledby="inv-fresh-h">
      <?php if (!empty($fresh['emailed'])): ?>
        <h2 class="flex items-center gap-2 font-bold" id="inv-fresh-h"><?= pf_icon('mail') ?> Invitation emailed to <?= e($fresh['email']) ?></h2>
        <p class="mt-1 text-foreground">They’ll get a link to create their account. If it doesn’t arrive, copy the link below and send it yourself. It is shown <strong>only this once</strong>.</p>
      <?php else: ?>
        <h2 class="flex items-center gap-2 font-bold" id="inv-fresh-h"><?= pf_icon('check') ?> Invitation link created</h2>
        <?php if (($fresh['emailed'] ?? null) === false): ?>
          <p class="mt-1 font-medium text-warning">The email to <?= e($fresh['email']) ?> was not sent<?= ($why = mail_off_reason('invitation')) !== '' ? ': ' . e(rtrim($why, '.')) . '.' : ' (the mail server refused it).' ?></p>
        <?php endif; ?>
        <p class="mt-1 text-foreground">Copy it now and send it to the person you are inviting. For security it is shown <strong>only this once</strong>; if you lose it, cancel it below and create a new one.</p>
      <?php endif; ?>
      <div class="copy-box mt-3">
        <span class="value bg-layer" data-invite-url><?= e($fresh['url']) ?></span>
        <button type="button" class="btn btn-primary btn-sm" x-copy data-copy="<?= e($fresh['url']) ?>"><?= pf_icon('copy') ?> Copy link</button>
      </div>
    </section>
  <?php endif; ?>

  <div class="grid items-start gap-x-10 gap-y-10 lg:grid-cols-[minmax(0,24rem)_minmax(0,1fr)]">
    <section class="card" aria-labelledby="inv-new-h">
      <h2 class="card-title mb-5" id="inv-new-h">New invitation</h2>
      <form method="post" action="<?= e(url_invites()) ?>">
        <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="action" value="create">

        <?php if ($actAdmin): ?>
          <fieldset class="field">
            <legend class="label">The new account will be a…</legend>
            <div class="choice-list">
              <?php foreach ($grantable as $r): ?>
                <label class="choice">
                  <input type="checkbox" name="roles[]" value="<?= e($r) ?>"<?= in_array($r, $form['roles'], true) ? ' checked' : '' ?>>
                  <span>
                    <span class="choice-title"><?= ui_role($r) ?></span>
                    <span class="choice-desc"><?= e($ROLE_HELP[$r]) ?></span>
                  </span>
                </label>
              <?php endforeach; ?>
            </div>
            <?= $errorLine('roles') ?>
          </fieldset>
        <?php else: ?>
          <p class="mb-5 flex flex-wrap items-center gap-2 text-sm text-muted-foreground-2">The new account will be a <?= ui_role('student') ?></p>
        <?php endif; ?>

        <div class="field">
          <label class="label" for="inv-email">Only for this email <span class="optional">(optional)</span></label>
          <input class="input" id="inv-email" name="email" type="email" maxlength="150" value="<?= e($form['email']) ?>"
                 placeholder="name@example.com"<?= $invalid('email') ?> aria-describedby="inv-email-hint email-err">
          <p class="hint" id="inv-email-hint">If set, the link works once and only for that address. Leave blank for a link anyone you send it to can use.</p>
          <?= $errorLine('email') ?>
        </div>

        <div class="form-grid">
          <div class="field">
            <label class="label" for="inv-uses">Number of uses</label>
            <input class="input" id="inv-uses" name="uses" type="number" min="1" max="<?= INVITE_MAX_USES ?>" step="1" inputmode="numeric"
                   value="<?= (int)$form['uses'] ?>"<?= $invalid('uses') ?> aria-describedby="inv-uses-hint uses-err">
            <p class="hint" id="inv-uses-hint">More than 1 for a whole class.</p>
            <?= $errorLine('uses') ?>
          </div>
          <div class="field">
            <label class="label" for="inv-days">Works for</label>
            <select class="select" id="inv-days" name="days"<?= $invalid('days') ?>>
              <?php foreach (INVITE_DAYS as $d => $label): ?>
                <option value="<?= $d ?>"<?= (int)$form['days'] === $d ? ' selected' : '' ?>><?= e($label) ?></option>
              <?php endforeach; ?>
            </select>
            <?= $errorLine('days') ?>
          </div>
        </div>

        <div class="field">
          <label class="label" for="inv-note">Note to yourself <span class="optional">(optional)</span></label>
          <input class="input" id="inv-note" name="note" type="text" maxlength="150" value="<?= e($form['note']) ?>"
                 placeholder="e.g. Spring 2027 lab intake"<?= $invalid('note') ?>>
          <?= $errorLine('note') ?>
        </div>

        <div class="form-actions">
          <button class="btn btn-primary" type="submit"><?= pf_icon('link') ?> Create link</button>
        </div>
      </form>
    </section>

    <section class="section mt-0" aria-labelledby="inv-list-h">
      <header class="section-head">
        <h2 class="section-title" id="inv-list-h">
          <?= $actAdmin ? 'All invitations' : 'Your invitations' ?>
          <?php if ($invites): ?><span class="section-count"><?= count($invites) ?></span><?php endif; ?>
        </h2>
      </header>
      <?php if ($actAdmin && $invites): ?>
        <nav class="filter-chips mt-4" aria-label="Filter by state">
          <?php foreach (['' => 'All', 'active' => 'Active', 'used' => 'Used', 'expired' => 'Expired', 'cancelled' => 'Cancelled'] as $k => $label):
              if ($k !== '' && empty($stateCounts[$k]) && $stateFilter !== $k) { continue; } ?>
            <a href="<?= e(url_invites() . ($k !== '' ? '?state=' . $k : '')) ?>"<?= $stateFilter === $k ? ' aria-current="page"' : '' ?>><?= e($label) ?> <span class="tab-count"><?= (int)($stateCounts[$k] ?? 0) ?></span></a>
          <?php endforeach; ?>
        </nav>
      <?php endif; ?>
      <?php if ($invites && !$listed): ?>
        <p class="py-6 text-sm text-muted-foreground-1">No <?= e($stateFilter) ?> invitations.</p>
      <?php endif; ?>
      <?php if (!$invites): ?>
        <div class="empty mt-4">
          <h3 class="empty-title">No invitations yet</h3>
          <p>Create a link and send it to the person who needs an account.</p>
        </div>
      <?php else: ?>
        <ul>
          <?php foreach ($listed as $inv):
              $state = invite_state($inv);
              [$stateLabel, $stateClass] = $STATE[$state];
              $names = $joined[(int)$inv['id']] ?? [];
              $isFresh = $fresh && (int)$fresh['id'] === (int)$inv['id'];
          ?>
            <li class="flex items-start justify-between gap-4 border-b border-layer-line py-4 last:border-b-0<?= $state === 'active' ? '' : ' opacity-70' ?>">
              <div class="min-w-0">
                <p class="flex flex-wrap items-center gap-x-3 gap-y-1">
                  <?= ui_roles(explode(',', (string)$inv['roles'])) ?>
                  <span class="badge <?= $stateClass ?>"><?= $stateLabel ?></span>
                  <?php if ($isFresh): ?><span class="badge badge-success">Just created</span><?php endif; ?>
                </p>
                <?php if (!empty($inv['note'])): ?><p class="mt-1.5 text-sm"><?= e($inv['note']) ?></p><?php endif; ?>
                <p class="mt-1 text-sm text-muted-foreground-1">
                  <?php if (!empty($inv['email'])): ?>Only for <?= e($inv['email']) ?> · <?php endif; ?>
                  Used <?= (int)$inv['used_count'] ?> of <?= (int)$inv['max_uses'] ?>
                  · <?= $state === 'expired' ? 'Expired' : 'Expires' ?> <?= e(date('M j, Y H:i', strtotime((string)$inv['expires_at']))) ?>
                  <?php if ($actAdmin): ?>
                    · By <?= $inv['creator_name'] !== null ? e($inv['creator_name']) : 'a deleted user' ?>
                  <?php endif; ?>
                </p>
                <?php if ($names): ?>
                  <p class="mt-0.5 text-sm text-muted-foreground-1">Joined: <?= e(implode(', ', $names)) ?></p>
                <?php endif; ?>
              </div>
              <?php if ($state === 'active'): ?>
                <form method="post" action="<?= e(url_invites()) ?>" x-confirm
                      data-confirm-title="Cancel this invitation?"
                      data-confirm-body="The link stops working straight away. Accounts already created with it are not affected."
                      data-confirm-ok="Cancel invitation"
                      data-confirm-cancel="Keep it">
                  <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
                  <input type="hidden" name="action" value="revoke">
                  <input type="hidden" name="id" value="<?= (int)$inv['id'] ?>">
                  <button class="btn btn-danger-ghost btn-sm" type="submit">Cancel</button>
                </form>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </section>
  </div>
</div>
<?php require __DIR__ . '/../src/includes/footer.php'; ?>
