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
 *   admin    health numbers, "Needs attention" (failed sign-ins and emails,
 *            admins without two-factor, email switched off, accounts about to
 *            be deleted, invitations about to expire, the time zone), a
 *            14-day activity chart, quick actions and everyone's recent activity
 * and, for everyone, the account summary and recent activity.
 */
declare(strict_types=1);
require_once __DIR__ . '/../src/includes/auth.php';
require_once __DIR__ . '/../src/includes/db.php';
require_once __DIR__ . '/../src/includes/url.php';
require_once __DIR__ . '/../src/includes/lab_fields.php';
require_once __DIR__ . '/../src/includes/lab_entries.php';
require_once __DIR__ . '/../src/includes/ui.php';
require_once __DIR__ . '/../src/includes/charts.php';
require_once __DIR__ . '/../src/includes/mailer.php';
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
        'accounts'    => $one('SELECT COUNT(*) FROM users WHERE deleted_at IS NULL'),
        'students'    => $one("SELECT COUNT(DISTINCT u.id) FROM users u JOIN user_roles r ON r.user_id = u.id AND r.role = 'student' WHERE u.deleted_at IS NULL"),
        'teachers'    => $one("SELECT COUNT(DISTINCT u.id) FROM users u JOIN user_roles r ON r.user_id = u.id AND r.role = 'teacher' WHERE u.deleted_at IS NULL"),
        'admins'      => $one("SELECT COUNT(DISTINCT u.id) FROM users u JOIN user_roles r ON r.user_id = u.id AND r.role = 'admin' WHERE u.deleted_at IS NULL"),
        'active7'     => $one('SELECT COUNT(DISTINCT a.user_id) FROM activity a JOIN users u ON u.id = a.user_id AND u.deleted_at IS NULL WHERE a.created_at >= NOW() - INTERVAL 7 DAY'),
        'failed24'    => $one("SELECT COUNT(*) FROM activity WHERE kind IN ('login_failed', 'login_2fa_failed') AND created_at >= NOW() - INTERVAL 1 DAY"),
        'mailfail7'   => 0,
        'invites'     => $one('SELECT COUNT(*) FROM invites WHERE revoked_at IS NULL AND used_count < max_uses AND expires_at > NOW()'),
    ];
    try { $stats['mailfail7'] = $one("SELECT COUNT(*) FROM email_log WHERE status = 'failed' AND created_at >= NOW() - INTERVAL 7 DAY"); } catch (Throwable $e) {}

    // Needs attention: [tone, icon, what, link, link text]. Most urgent first.
    $attention = [];
    if ($stats['failed24']) {
        $attention[] = ['danger', 'lock', $stats['failed24'] . ' failed sign-in' . ($stats['failed24'] === 1 ? '' : 's') . ' in the last 24 hours', url_admin_activity() . '?area=signin&period=1', 'Review'];
    }
    if ($stats['mailfail7']) {
        $attention[] = ['danger', 'mail', $stats['mailfail7'] . ' email' . ($stats['mailfail7'] === 1 ? '' : 's') . ' could not be sent this week', url_admin_emails() . '?status=failed&period=7', 'See why'];
    }
    if (!mail_enabled()) {
        $attention[] = ['warning', 'mail', 'Email is not set up on the server, so nobody gets reset links or notices', url_admin_emails() . '?view=settings', 'Details'];
    } elseif (mail_setting('mail.enabled', '1') !== '1') {
        $attention[] = ['warning', 'mail', 'All email is switched off', url_admin_emails() . '?view=settings', 'Turn on'];
    }
    $no2fa = $one("SELECT COUNT(DISTINCT u.id) FROM users u JOIN user_roles r ON r.user_id = u.id AND r.role = 'admin' WHERE u.deleted_at IS NULL AND u.totp_required = 0");
    if ($no2fa) {
        $attention[] = ['warning', 'shield', $no2fa . ' admin' . ($no2fa === 1 ? ' has' : 's have') . ' no two-factor sign-in', url_admin() . '?role=admin&twofa=off', 'Who'];
    }
    $purge = $one('SELECT COUNT(*) FROM users WHERE deleted_at IS NOT NULL AND scheduled_purge_at <= NOW() + INTERVAL 7 DAY');
    if ($purge) {
        $attention[] = ['warning', 'trash', $purge . ' deactivated account' . ($purge === 1 ? ' is' : 's are') . ' deleted for good within 7 days', url_admin() . '?status=deactivated', 'Review'];
    }
    $expiring = $one('SELECT COUNT(*) FROM invites WHERE revoked_at IS NULL AND used_count < max_uses AND expires_at > NOW() AND expires_at <= NOW() + INTERVAL 2 DAY');
    if ($expiring) {
        $attention[] = ['info', 'link', $expiring . ' invitation' . ($expiring === 1 ? '' : 's') . ' unused and expiring within 2 days', url_invites() . '?state=active', 'Open'];
    }
    $never = $one("SELECT COUNT(*) FROM users u WHERE u.deleted_at IS NULL AND u.created_at <= NOW() - INTERVAL 7 DAY
                    AND NOT EXISTS (SELECT 1 FROM activity a WHERE a.user_id = u.id AND a.kind = 'login')");
    if ($never) {
        $attention[] = ['info', 'user', $never . ' account' . ($never === 1 ? ' has' : 's have') . ' never signed in (older than a week)', url_admin() . '?sort=inactive', 'See'];
    }
    $tzSet = $one("SELECT COUNT(*) FROM app_settings WHERE name = 'app.timezone'");
    if (!$tzSet && trim((string)getenv('APP_TIMEZONE')) === '') {
        $attention[] = ['info', 'settings', 'Times are shown in UTC: choose your lab\'s time zone', url_admin_settings(), 'Choose'];
    }

    // Everyone's activity, last 14 days (same series as Admin › Activity).
    $seriesOf = function (string $kind): string {
        if (in_array($kind, ['login_failed', 'login_2fa_failed', 'login_2fa_locked'], true)) { return 'security'; }
        return match (activity_group_of($kind)) { 'signin' => 'signin', 'security' => 'security', default => 'changes' };
    };
    $byDay = [];
    foreach ($pdo->query('SELECT DATE(created_at) AS d, kind, COUNT(*) AS n FROM activity WHERE created_at >= CURDATE() - INTERVAL 13 DAY GROUP BY d, kind') as $r) {
        $k = $seriesOf((string)$r['kind']);
        $byDay[(string)$r['d']][$k] = ($byDay[(string)$r['d']][$k] ?? 0) + (int)$r['n'];
    }
    $today = new DateTimeImmutable((string)$pdo->query('SELECT CURDATE()')->fetchColumn());
    $chartPoints = [];
    for ($i = 13; $i >= 0; $i--) {
        $t = $today->modify('-' . $i . ' days');
        $chartPoints[] = ['label' => $t->format('D, M j'), 'tick' => $i % 7 === 0 ? $t->format('M j') : '', 'values' => $byDay[$t->format('Y-m-d')] ?? []];
    }

    // Everyone's latest events, for the right-hand column.
    $everyone = $pdo->query(
        "SELECT a.kind, a.detail, a.user_id, a.created_at, TIMESTAMPDIFF(SECOND, a.created_at, NOW()) AS age, u.name
           FROM activity a LEFT JOIN users u ON u.id = a.user_id
          WHERE a.kind NOT IN ('role_fallback', 'login_password_ok', 'logout', 'role_switch')
          ORDER BY a.created_at DESC, a.id DESC LIMIT 8"
    )->fetchAll();
}
$incomplete = array_values(array_filter($people, fn($x) => $x['percent'] < 100));
usort($incomplete, fn($a, $b) => $a['percent'] <=> $b['percent']);

// Recent activity. Bookkeeping rows are skipped and runs of the same kind
// are folded into one line, so the list is not twenty "profile_update"s.
$act = $pdo->prepare(
    "SELECT kind, detail, created_at, TIMESTAMPDIFF(SECOND, created_at, NOW()) AS age
       FROM activity
      WHERE user_id = :u AND kind NOT IN ('role_fallback', 'login_password_ok', 'logout')
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
                  <a class="min-w-0 flex-1 truncate py-1" href="<?= e(url_students()) ?>?id=<?= $x['id'] ?>"><?= e($x['name']) ?></a>
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
      <!-- ===== Admin: how the site is doing ===== -->
      <dl class="stat-strip is-2col">
        <div><dt><?= pf_icon('users') ?> Active accounts</dt><dd><a href="<?= e(url_admin()) ?>?status=active"><?= $stats['accounts'] ?></a></dd>
          <p class="stat-note"><?= $stats['students'] ?> students · <?= $stats['teachers'] ?> teachers · <?= $stats['admins'] ?> admins</p></div>
        <div><dt><?= pf_icon('pulse') ?> Active this week</dt><dd><a href="<?= e(url_admin_activity()) ?>?view=people"><?= $stats['active7'] ?></a></dd>
          <p class="stat-note">people who did anything in 7 days</p></div>
        <div<?= $stats['failed24'] ? ' class="is-danger"' : '' ?>><dt><?= pf_icon('lock') ?> Failed sign-ins</dt><dd><a href="<?= e(url_admin_activity()) ?>?area=signin&amp;period=1"><?= $stats['failed24'] ?></a></dd>
          <p class="stat-note">in the last 24 hours</p></div>
        <div<?= $stats['mailfail7'] ? ' class="is-danger"' : '' ?>><dt><?= pf_icon('mail') ?> Failed emails</dt><dd><a href="<?= e(url_admin_emails()) ?>?status=failed&amp;period=7"><?= $stats['mailfail7'] ?></a></dd>
          <p class="stat-note">in the last 7 days</p></div>
      </dl>

      <section class="card" aria-labelledby="dash-attn-h">
        <div class="card-head">
          <h2 class="card-title" id="dash-attn-h">Needs attention</h2>
          <?php if ($attention): ?><span class="badge badge-warning"><?= count($attention) ?></span><?php endif; ?>
        </div>
        <?php if (!$attention): ?>
          <p class="flex items-center gap-2 text-sm text-success"><?= pf_icon('check') ?> All clear: no failed sign-ins or emails, every admin has two-factor, and email is on.</p>
        <?php else: ?>
          <ul class="attention">
            <?php foreach ($attention as [$tone, $icon, $text, $href, $cta]): ?>
              <li class="attention-item is-<?= $tone ?>">
                <span class="attention-icon"><?= pf_icon($icon) ?></span>
                <span class="min-w-0 flex-1"><?= e($text) ?></span>
                <a class="btn btn-secondary btn-sm" href="<?= e($href) ?>"><?= e($cta) ?></a>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </section>

      <div class="mt-5"></div>
      <?= chart_columns(['signin' => ['Sign-ins', '--series-1'], 'changes' => ['Changes', '--series-2'], 'security' => ['Security', '--series-3']], $chartPoints, [
          'id' => 'dash-act', 'unit' => 'events', 'title' => 'Activity, last 14 days',
          'desc' => 'Everyone’s sign-ins, changes and security events per day. Details in Activity.',
      ]) ?>

      <section class="card mt-5" aria-labelledby="dash-quick-h">
        <div class="card-head"><h2 class="card-title" id="dash-quick-h">Quick actions</h2></div>
        <div class="tile-grid">
          <?php foreach ([
              ['plus', 'New user', 'Create an account yourself', url_admin_user()],
              ['link', 'Invite people', $stats['invites'] . ' open invitation' . ($stats['invites'] === 1 ? '' : 's'), url_invites()],
              ['mail', 'Write an email', 'To one person, a role or everyone', url_admin_emails() . '?view=compose'],
              ['download', 'Download users', 'Every account as a CSV file', url_admin() . '?export=csv'],
          ] as [$icon, $title, $value, $href]): ?>
            <a class="tile" href="<?= e($href) ?>">
              <span class="tile-icon"><?= pf_icon($icon) ?></span>
              <span class="min-w-0 flex-1"><span class="tile-title"><?= e($title) ?></span><span class="tile-value"><?= e($value) ?></span></span>
              <?= pf_icon('chevron', 'chev') ?>
            </a>
          <?php endforeach; ?>
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
                <a class="inline-flex min-h-6 items-center text-sm" href="<?= e(url_settings()) ?>?tab=security#two-factor">Turn on</a>
              <?php endif; ?>
            </dd>
          </div>
          <div>
            <dt>Member since</dt>
            <dd><?= e(date('M j, Y', strtotime((string)$me['created_at']))) ?></dd>
          </div>
        </dl>
      </section>

      <?php if ($activeRole === 'admin'): ?>
      <section class="card" aria-labelledby="dash-activity-h">
        <div class="card-head">
          <h2 class="card-title" id="dash-activity-h">Latest activity</h2>
          <a class="card-link" href="<?= e(url_admin_activity()) ?>">See all</a>
        </div>
        <?php if (!$everyone): ?>
          <p class="text-sm text-muted-foreground-2">Nothing yet.</p>
        <?php else: ?>
          <ul class="space-y-3">
            <?php foreach ($everyone as $a): [$icon, $text, $tone] = ui_activity_admin((string)$a['kind'], $a['detail']); ?>
              <li class="flex items-start gap-3 text-sm">
                <?= pf_icon($icon, 'mt-[0.2rem] ' . ($tone === 'danger' ? 'text-danger' : ($tone === 'warning' ? 'text-warning' : 'text-muted-foreground-1'))) ?>
                <span class="min-w-0 flex-1">
                  <?php if ($a['user_id'] && $a['name'] !== null): ?><a class="font-semibold" href="<?= e(url_admin_user()) ?>?id=<?= (int)$a['user_id'] ?>"><?= e($a['name']) ?></a><?php else: ?><span class="font-semibold">Someone</span><?php endif; ?>
                  <span class="block text-muted-foreground-2"><?= e($text) ?></span>
                </span>
                <time class="shrink-0 text-xs text-muted-foreground-1" datetime="<?= e(date('c', strtotime((string)$a['created_at']))) ?>"><?= e(ui_ago((int)$a['age'], (string)$a['created_at'])) ?></time>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </section>
      <?php else: ?>
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

      <?php endif; ?>
    </div>
  </div>
</div>
<?php require __DIR__ . '/../src/includes/footer.php'; ?>
