<?php
/**
 * Logged-in user's home page.
 *
 * Route:  GET /dashboard.php
 * Auth:   require_login()
 * Renders:
 *   - greeting with avatar, role and profile type
 *   - profile completeness (same checklist as /profile.php) with the next
 *     steps as links that open the matching editor
 *   - entry count per profile section
 *   - account summary (email, username, 2FA, joined)
 *   - recent rows from `activity`, in plain language
 *   - "Users" shortcut when the active role is admin
 */
declare(strict_types=1);
require_once __DIR__ . '/../src/includes/auth.php';
require_once __DIR__ . '/../src/includes/db.php';
require_once __DIR__ . '/../src/includes/url.php';
require_once __DIR__ . '/../src/includes/profile_sections.php';
require_once __DIR__ . '/../src/includes/ui.php';
require_login();

$userId = (int)current_user_id();
$st = $pdo->prepare(
    'SELECT u.id, u.name, u.username, u.email, u.email_verified, u.degree_type,
            u.totp_required, u.created_at,
            p.phone, p.address, p.profession, p.bio, p.photo
     FROM users u LEFT JOIN profiles p ON p.user_id = u.id
     WHERE u.id = :id LIMIT 1'
);
$st->execute(['id' => $userId]);
$me = $st->fetch();
if (!$me) {
    $_SESSION = []; session_destroy();
    header('Location: ' . url_login());
    exit;
}
$degreeType = normalize_degree_type($me['degree_type'] ?? 'bachelor');
$firstName  = explode(' ', trim((string)$me['name']))[0] ?: (string)$me['username'];
$activeRole = active_user_role();

// Entry count per visible section.
$sectionCounts = [];
foreach (visible_sections($degreeType) as $key) {
    $c = $pdo->prepare("SELECT COUNT(*) FROM `$key` WHERE user_id = :u");
    $c->execute(['u' => $userId]);
    $sectionCounts[$key] = (int)$c->fetchColumn();
}

// Profile completeness: the checklist /profile.php uses.
$steps       = profile_completion_steps($me, $sectionCounts, $degreeType);
$stepsTotal  = count($steps);
$pending     = array_values(array_filter($steps, fn($s) => !$s['done']));
$stepsDone   = $stepsTotal - count($pending);
$completePct = (int)round($stepsDone / $stepsTotal * 100);

// Recent activity. Bookkeeping rows are skipped and runs of the same kind
// are folded into one line, so the list is not twenty "profile_update"s.
$act = $pdo->prepare(
    "SELECT kind, detail, created_at, TIMESTAMPDIFF(SECOND, created_at, NOW()) AS age
       FROM activity
      WHERE user_id = :u AND kind NOT IN ('role_fallback')
      ORDER BY id DESC LIMIT 60"
);
$act->execute(['u' => $userId]);
$foldable = ['profile_update', 'login', 'role_switch'];
$activity = [];
foreach ($act->fetchAll() as $row) {
    $last = count($activity) - 1;
    if ($last >= 0 && $activity[$last]['kind'] === $row['kind'] && in_array($row['kind'], $foldable, true)) {
        $activity[$last]['count']++;
        continue;
    }
    if (count($activity) === 6) { break; }
    $activity[] = $row + ['count' => 1];
}

$page_title = 'Dashboard';
$page_id    = 'dashboard';
require __DIR__ . '/../src/includes/header.php';
?>
<div class="page">

  <header class="page-header items-center">
    <div class="flex min-w-0 items-center gap-4">
      <?= ui_avatar($me['photo'] ?? null, (string)$me['name'], 'avatar-lg') ?>
      <div class="min-w-0">
        <h1 class="page-title">Welcome back, <?= e($firstName) ?></h1>
        <p class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-muted-foreground-2">
          <span>@<?= e($me['username']) ?></span>
          <span class="badge"><?= e(degree_label($degreeType)) ?></span>
          <?php if ($activeRole !== ''): ?><?= ui_role($activeRole) ?><?php endif; ?>
        </p>
      </div>
    </div>
    <a class="btn btn-secondary" href="<?= e(url_profile()) ?>">Open my profile</a>
  </header>

  <div class="grid items-start gap-5 lg:grid-cols-[minmax(0,1fr)_21rem]">
    <div>

      <section class="card" aria-labelledby="dash-complete-h">
        <div class="card-head">
          <h2 class="card-title" id="dash-complete-h">Profile completeness</h2>
          <strong class="text-base font-semibold tabular-nums"><?= $completePct ?>%</strong>
        </div>
        <?= ui_progress($completePct, 'dash-complete-h') ?>

        <?php if (!$pending): ?>
          <p class="mt-4 flex items-center gap-2 text-sm text-success">
            <?= pf_icon('check') ?> All <?= $stepsTotal ?> steps done. Your profile is complete.
          </p>
        <?php else: ?>
          <p class="mt-4 text-sm text-muted-foreground-2"><?= $stepsDone ?> of <?= $stepsTotal ?> steps done. Pick up where you left off:</p>
          <ul class="step-list">
            <?php foreach (array_slice($pending, 0, 3) as $step): ?>
              <li>
                <a class="step" href="<?= e(url_profile()) ?>?step=<?= e(rawurlencode($step['key'])) ?>">
                  <?= pf_icon('plus') ?>
                  <span><?= e($step['label']) ?></span>
                  <?= pf_icon('chevron', 'chev') ?>
                </a>
              </li>
            <?php endforeach; ?>
          </ul>
          <?php if (count($pending) > 3): ?>
            <p class="mt-2 px-2 text-[0.8125rem]"><a href="<?= e(url_profile()) ?>">and <?= count($pending) - 3 ?> more on your profile</a></p>
          <?php endif; ?>
        <?php endif; ?>
      </section>

      <section class="card" aria-labelledby="dash-sections-h">
        <div class="card-head">
          <h2 class="card-title" id="dash-sections-h">What’s on your profile</h2>
        </div>
        <ul class="grid gap-x-8 sm:grid-cols-2">
          <?php foreach ($sectionCounts as $key => $n): ?>
            <li class="border-b border-layer-line">
              <a class="flex items-center justify-between gap-4 py-2.5 text-sm text-foreground no-underline hover:text-primary"
                 href="<?= e(url_profile()) ?><?= $n ? '#sec-' . e($key) : '?step=' . e(rawurlencode('section:' . $key)) ?>">
                <span><?= e($SECTIONS[$key]['title']) ?></span>
                <?php if ($n): ?>
                  <span class="tabular-nums text-muted-foreground-2"><?= $n ?> <span class="sr-only"><?= $n === 1 ? 'entry' : 'entries' ?></span></span>
                <?php else: ?>
                  <span class="inline-flex items-center gap-1 text-[0.8125rem] font-medium text-primary"><?= pf_icon('plus') ?> Add</span>
                <?php endif; ?>
              </a>
            </li>
          <?php endforeach; ?>
        </ul>
      </section>

      <?php if ($activeRole === 'admin'): ?>
        <section class="card" aria-labelledby="dash-admin-h">
          <div class="action-row">
            <div>
              <strong id="dash-admin-h">Users</strong>
              <p>Create accounts, change roles, edit or remove any user.</p>
            </div>
            <a class="btn btn-secondary" href="<?= e(url_admin()) ?>"><?= pf_icon('users') ?> Manage users</a>
          </div>
          <div class="action-row">
            <div>
              <strong>Invitations</strong>
              <p>Create a link that lets someone register with the role you choose.</p>
            </div>
            <a class="btn btn-secondary" href="<?= e(url_invites()) ?>"><?= pf_icon('link') ?> Invite by link</a>
          </div>
        </section>
      <?php elseif ($activeRole === 'teacher'): ?>
        <section class="card" aria-labelledby="dash-teacher-h">
          <div class="action-row">
            <div>
              <strong id="dash-teacher-h">Students</strong>
              <p>Browse every student’s profile, or see all of them in one sheet.</p>
            </div>
            <a class="btn btn-secondary" href="<?= e(url_students()) ?>"><?= pf_icon('users') ?> Open directory</a>
          </div>
        </section>
      <?php endif; ?>

    </div>

    <div>

      <section class="card" aria-labelledby="dash-account-h">
        <div class="card-head">
          <h2 class="card-title" id="dash-account-h">Account</h2>
          <a class="card-link" href="<?= e(url_settings()) ?>">Settings</a>
        </div>
        <dl class="facts is-single">
          <div>
            <dt>Email</dt>
            <dd>
              <span class="max-w-full truncate" title="<?= e($me['email']) ?>"><?= e($me['email']) ?></span>
              <?php if ((int)$me['email_verified'] === 1): ?>
                <span class="badge badge-success">Verified</span>
              <?php else: ?>
                <span class="badge">Not verified</span>
              <?php endif; ?>
            </dd>
          </div>
          <div>
            <dt>Two-factor</dt>
            <dd>
              <?php if ((int)$me['totp_required'] === 1): ?>
                <span class="badge badge-success">On</span>
              <?php else: ?>
                <span class="badge">Off</span>
                <a class="text-[0.8125rem]" href="<?= e(url_settings()) ?>?tab=security#two-factor">Turn on</a>
              <?php endif; ?>
            </dd>
          </div>
          <div>
            <dt>Member since</dt>
            <dd><?= e(date('M j, Y', strtotime((string)$me['created_at']))) ?></dd>
          </div>
        </dl>
      </section>

      <section class="card" aria-labelledby="dash-activity-h">
        <div class="card-head">
          <h2 class="card-title" id="dash-activity-h">Recent activity</h2>
        </div>
        <?php if (!$activity): ?>
          <p class="text-sm text-muted-foreground-2">Nothing yet. Sign-ins and changes to your profile and account will show up here.</p>
        <?php else: ?>
          <ul class="space-y-3">
            <?php foreach ($activity as $a):
                [$icon, $text] = ui_activity((string)$a['kind'], $a['detail'], (int)$a['count']);
            ?>
              <li class="flex items-start gap-3 text-sm">
                <?= pf_icon($icon, 'mt-[0.2rem] text-muted-foreground-1') ?>
                <span class="min-w-0 flex-1"><?= e($text) ?></span>
                <time class="shrink-0 text-[0.8125rem] text-muted-foreground-1" datetime="<?= e(date('c', strtotime((string)$a['created_at']))) ?>" title="<?= e($a['created_at']) ?>"><?= e(ui_ago((int)$a['age'], (string)$a['created_at'])) ?></time>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </section>

    </div>
  </div>
</div>
<?php require __DIR__ . '/../src/includes/footer.php'; ?>
