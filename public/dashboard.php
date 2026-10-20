<?php
/**
 * Logged-in user's home page.
 *
 * Route:  GET /dashboard.php
 * Auth:   require_login()
 * Renders a greeting, then one main panel for the role being acted as:
 *   student  how much of their lab details is filled in, with the next
 *            fields as links that open the matching editor
 *   teacher  the students: how many, and who still has details to fill in
 *   admin    account counts by role, deactivated accounts, open invitations
 * and, for everyone, the account summary and recent activity.
 */
declare(strict_types=1);
require_once __DIR__ . '/../src/includes/auth.php';
require_once __DIR__ . '/../src/includes/db.php';
require_once __DIR__ . '/../src/includes/url.php';
require_once __DIR__ . '/../src/includes/lab_fields.php';
require_once __DIR__ . '/../src/includes/lab_entries.php';
require_once __DIR__ . '/../src/includes/ui.php';
require_login();

$userId = (int)current_user_id();
$st = $pdo->prepare(
    'SELECT u.id, u.name, u.email, u.email_verified,
            u.totp_required, u.created_at,
            p.*
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
$firstName  = explode(' ', trim((string)$me['name']))[0] ?: 'there';
$activeRole = active_user_role();

// ---- The main panel depends on the role being acted as. ----
$isStudentView = $activeRole === 'student';
$steps = $pending = $todo = $waiting = [];
$stepsTotal = $stepsDone = $completePct = 0;
$people = [];          // teacher: students; each with name, id, course, percent
$stats  = [];          // admin: headline counts

if ($isStudentView) {
    // How much of the lab details is filled in (same checklist as /profile.php).
    $steps       = profile_completion_steps($me);
    $stepsTotal  = count($steps);
    $pending     = array_values(array_filter($steps, fn($s) => !$s['done']));
    // What the user can fill in themselves vs. what the system or an admin sets.
    $todo        = array_values(array_filter($pending, fn($s) => $s['owner_edit']));
    $waiting     = array_values(array_filter($pending, fn($s) => !$s['owner_edit']));
    $stepsDone   = $stepsTotal - count($pending);
    $completePct = (int)round($stepsDone / $stepsTotal * 100);

    // Shortcuts to everything a student works with, each with a live number.
    $myCourses  = enrollments_for($pdo, $userId);
    $myEntries  = entries_for($pdo, $userId);
    $pubCount   = 0;
    foreach (['pub_journals', 'pub_proceedings', 'pub_presentations', 'awards'] as $k) { $pubCount += count($myEntries[$k]); }
    $journalsAll   = (int)$pdo->query('SELECT COUNT(*) FROM lab_journals')->fetchColumn();
    $cur = $myCourses[0] ?? null;
    $tiles = [
        ['cap', 'My course', $cur
            ? $cur['course'] . ($cur['completed'] !== '' ? ' · completed' : ' · current')
            : 'Add your course',
         url_profile() . '#sec-courses'],
        ['book', 'Publications & awards', $pubCount === 0 ? 'Add your first' : $pubCount . ($pubCount === 1 ? ' entry' : ' entries'),
         url_profile() . '#sec-publications'],
        ['check', 'Potential journals', $journalsAll === 0 ? 'None yet: add one' : $journalsAll . ($journalsAll === 1 ? ' journal' : ' journals'),
         url_resources() . '?tab=journals'],
    ];
} elseif ($activeRole === 'teacher') {
    // Every active student and how complete their lab details are.
    $rows = $pdo->query(
        "SELECT u.id, u.name, u.email, p.*
           FROM users u LEFT JOIN profiles p ON p.user_id = u.id
          WHERE u.deleted_at IS NULL
            AND EXISTS (SELECT 1 FROM user_roles r WHERE r.user_id = u.id AND r.role = 'student')
          ORDER BY u.name"
    )->fetchAll();
    foreach ($rows as $r) {
        $st = profile_completion_steps($r);
        $people[] = [
            'id' => (int)$r['id'], 'name' => (string)$r['name'], 'course' => trim((string)($r['course'] ?? '')),
            'percent' => (int)round(count(array_filter($st, fn($x) => $x['done'])) / count($st) * 100),
        ];
    }
} elseif ($activeRole === 'admin') {
    $one = fn(string $sql): int => (int)$pdo->query($sql)->fetchColumn();
    $stats = [
        'students'    => $one("SELECT COUNT(DISTINCT u.id) FROM users u JOIN user_roles r ON r.user_id = u.id AND r.role = 'student' WHERE u.deleted_at IS NULL"),
        'teachers'    => $one("SELECT COUNT(DISTINCT u.id) FROM users u JOIN user_roles r ON r.user_id = u.id AND r.role = 'teacher' WHERE u.deleted_at IS NULL"),
        'admins'      => $one("SELECT COUNT(DISTINCT u.id) FROM users u JOIN user_roles r ON r.user_id = u.id AND r.role = 'admin' WHERE u.deleted_at IS NULL"),
        'deactivated' => $one('SELECT COUNT(*) FROM users WHERE deleted_at IS NOT NULL'),
        'invites'     => $one('SELECT COUNT(*) FROM invites WHERE revoked_at IS NULL AND used_count < max_uses AND expires_at > NOW()'),
    ];
}
$incomplete = array_values(array_filter($people, fn($x) => $x['percent'] < 100));
usort($incomplete, fn($a, $b) => $a['percent'] <=> $b['percent']);

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
      <?= ui_avatar(null, (string)$me['name'], 'avatar-lg') ?>
      <div class="min-w-0">
        <h1 class="page-title">Welcome back, <?= e($firstName) ?></h1>
        <p class="mt-1 flex flex-wrap items-center gap-x-3 gap-y-1 text-sm text-muted-foreground-2">
          <?php if ($isStudentView && trim((string)($me['course'] ?? '')) !== ''): ?><span class="badge"><?= e($me['course']) ?></span><?php endif; ?>
          <?php if ($activeRole !== ''): ?><?= ui_role($activeRole) ?><?php endif; ?>
        </p>
      </div>
    </div>
  </header>

  <div class="grid items-start gap-5 lg:grid-cols-[minmax(0,1fr)_21rem]">
    <div>

      <?php if ($isStudentView): ?>
      <!-- ===== Student: my lab details ===== -->
      <section class="card" aria-labelledby="dash-complete-h">
        <div class="card-head">
          <h2 class="card-title" id="dash-complete-h">Lab details filled in</h2>
          <strong class="text-base font-semibold tabular-nums"><?= $completePct ?>%</strong>
        </div>
        <?= ui_progress($completePct, 'dash-complete-h') ?>

        <?php if (!$pending): ?>
          <p class="mt-4 flex items-center gap-2 text-sm text-success">
            <?= pf_icon('check') ?> All <?= $stepsTotal ?> fields are filled in.
          </p>
        <?php else: ?>
          <p class="mt-4 text-sm text-muted-foreground-2"><?= $stepsDone ?> of <?= $stepsTotal ?> fields filled in.<?= $todo ? ' Pick up where you left off:' : '' ?></p>
          <?php if ($todo): ?>
            <ul class="step-list">
              <?php foreach (array_slice($todo, 0, 3) as $step): ?>
                <li>
                  <a class="step" href="<?= e(url_profile()) ?>?step=<?= e(rawurlencode($step['key'])) ?>">
                    <?= pf_icon('plus') ?>
                    <span><?= e($step['label']) ?></span>
                    <?= pf_icon('chevron', 'chev') ?>
                  </a>
                </li>
              <?php endforeach; ?>
            </ul>
            <?php if (count($todo) > 3): ?>
              <p class="mt-2 px-2 text-sm text-muted-foreground-1">and <?= count($todo) - 3 ?> more on your profile.</p>
            <?php endif; ?>
          <?php endif; ?>
          <?php if ($waiting): ?>
            <p class="mt-3 flex items-start gap-2 text-sm text-muted-foreground-1">
              <?= pf_icon('lock', 'mt-[0.15rem]') ?>
              <span>Set by an admin, not yet filled in: <?= e(implode(', ', array_map(fn($s) => $s['field'], $waiting))) ?>.</span>
            </p>
          <?php endif; ?>
        <?php endif; ?>
      </section>

      <section class="mt-5" aria-labelledby="dash-lab-h">
        <h2 class="mb-3 text-lg font-bold" id="dash-lab-h">Your lab</h2>
        <div class="tile-grid">
          <?php foreach ($tiles as [$icon, $title, $value, $href]): ?>
            <a class="tile" href="<?= e($href) ?>">
              <span class="tile-icon"><?= pf_icon($icon) ?></span>
              <span class="min-w-0 flex-1">
                <span class="tile-title"><?= e($title) ?></span>
                <span class="tile-value"><?= e($value) ?></span>
              </span>
              <?= pf_icon('chevron', 'chev') ?>
            </a>
          <?php endforeach; ?>
        </div>
      </section>

      <?php elseif ($activeRole === 'teacher'): ?>
      <!-- ===== Teacher: the students ===== -->
      <section class="card" aria-labelledby="dash-students-h">
        <div class="card-head">
          <h2 class="card-title" id="dash-students-h">Students</h2>
        </div>
        <?php if (!$people): ?>
          <p class="text-sm text-muted-foreground-2">No students yet. Create an invitation link under Invitations; students appear here once they register.</p>
        <?php else: ?>
          <p class="text-sm text-muted-foreground-2">
            <strong class="font-semibold text-foreground"><?= count($people) ?></strong> <?= count($people) === 1 ? 'student' : 'students' ?>.
            <?php if (!$incomplete): ?>
              Everyone’s lab details are complete.
            <?php else: ?>
              <strong class="font-semibold text-foreground"><?= count($incomplete) ?></strong> still <?= count($incomplete) === 1 ? 'has' : 'have' ?> lab details to fill in:
            <?php endif; ?>
          </p>
          <?php if ($incomplete): ?>
            <ul class="mt-3">
              <?php foreach (array_slice($incomplete, 0, 6) as $x): ?>
                <li class="flex items-center gap-3 border-b border-layer-line py-2.5 text-sm">
                  <a class="min-w-0 flex-1 truncate" href="<?= e(url_students()) ?>?id=<?= $x['id'] ?>"><?= e($x['name']) ?></a>
                  <?php if ($x['course'] !== ''): ?><span class="badge"><?= e($x['course']) ?></span><?php endif; ?>
                  <span class="meter"><span class="progress"><span class="progress-bar" style="width: <?= $x['percent'] ?>%"></span></span><span class="meter-text"><?= $x['percent'] ?>%</span></span>
                </li>
              <?php endforeach; ?>
            </ul>
            <?php if (count($incomplete) > 6): ?>
              <p class="mt-3 text-sm text-muted-foreground-1">and <?= count($incomplete) - 6 ?> more in the sheet.</p>
            <?php endif; ?>
          <?php endif; ?>
        <?php endif; ?>
      </section>

      <?php elseif ($activeRole === 'admin'): ?>
      <!-- ===== Admin: the accounts ===== -->
      <section class="card" aria-labelledby="dash-users-h">
        <div class="card-head">
          <h2 class="card-title" id="dash-users-h">Accounts</h2>
        </div>
        <dl class="facts">
          <div><dt><?= ui_role('student') ?></dt><dd><a href="<?= e(url_admin()) ?>?role=student"><?= $stats['students'] ?></a></dd></div>
          <div><dt><?= ui_role('teacher') ?></dt><dd><a href="<?= e(url_admin()) ?>?role=teacher"><?= $stats['teachers'] ?></a></dd></div>
          <div><dt><?= ui_role('admin') ?></dt><dd><a href="<?= e(url_admin()) ?>?role=admin"><?= $stats['admins'] ?></a></dd></div>
          <div><dt>Deactivated</dt><dd><?= $stats['deactivated'] ? '<a href="' . e(url_admin()) . '?status=deactivated">' . $stats['deactivated'] . '</a>' : '0' ?></dd></div>
          <div><dt>Open invitations</dt><dd><a href="<?= e(url_invites()) ?>"><?= $stats['invites'] ?></a></dd></div>
        </dl>
      </section>

      <section class="card" aria-labelledby="dash-admin-h">
        <div class="action-row">
          <div>
            <strong id="dash-admin-h">Add someone</strong>
            <p>Create the account yourself and set a password. To let people register themselves, use Invitations.</p>
          </div>
          <a class="btn btn-secondary" href="<?= e(url_admin_user()) ?>"><?= pf_icon('plus') ?> New user</a>
        </div>
      </section>
      <?php endif; ?>

    </div>

    <div>

      <section class="card" aria-labelledby="dash-account-h">
        <div class="card-head">
          <h2 class="card-title" id="dash-account-h">Account</h2>
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
                <a class="text-sm" href="<?= e(url_settings()) ?>?tab=security#two-factor">Turn on</a>
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
                <time class="shrink-0 text-sm text-muted-foreground-1" datetime="<?= e(date('c', strtotime((string)$a['created_at']))) ?>" title="<?= e($a['created_at']) ?>"><?= e(ui_ago((int)$a['age'], (string)$a['created_at'])) ?></time>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </section>

    </div>
  </div>
</div>
<?php require __DIR__ . '/../src/includes/footer.php'; ?>
