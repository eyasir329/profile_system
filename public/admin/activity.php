<?php
/**
 * Admin: what everyone has been doing.
 *
 * Route:  GET /admin/activity.php                  timeline of every event, newest first, grouped by day
 *            ?user=<id>                            one person: summary (last seen, sign-ins, addresses) + their timeline
 *            ?q= &area= &period= &page=            filters (area = a key of ACTIVITY_GROUPS in ui.php)
 *            &export=csv                           the filtered timeline as a CSV file (at most EXPORT_MAX rows)
 *         GET /admin/activity.php?view=people      everyone with last activity, sign-ins and failures
 *
 * Above the timeline, charts for the same filters (period, or the last 30
 * days when none is chosen): events per day (per hour for "last 24 hours")
 * by sign-ins / changes / security, a weekday × hour heatmap, and the most
 * active people (or, for one person, their events by area).
 * Auth:   require_admin()
 *
 * Reads the `activity` table written by log_activity() (validate.php).
 * Rows have the IP address, browser and acting role since migration 035;
 * housekeeping clears the IP and browser after 90 days. Failed sign-ins
 * for unknown addresses have no user and show the typed address instead.
 */
declare(strict_types=1);
require_once __DIR__ . '/../../src/includes/auth.php';
require_once __DIR__ . '/../../src/includes/db.php';
require_once __DIR__ . '/../../src/includes/url.php';
require_once __DIR__ . '/../../src/includes/ui.php';
require_once __DIR__ . '/../../src/includes/charts.php';
require_admin();

const PER_PAGE   = 50;
const EXPORT_MAX = 10000;
const ACTIVITY_CONTEXT_SINCE = 'October 20, 2026'; // migration 035: IP, browser and role are recorded from here on

$self    = url_admin_activity();
$page_id = 'admin-activity';
$view    = ($_GET['view'] ?? '') === 'people' ? 'people' : 'timeline';

$PERIODS = ['' => 'Any time', '1' => 'Last 24 hours', '7' => 'Last 7 days', '30' => 'Last 30 days', '90' => 'Last 90 days'];
$stamp   = fn(?string $t): string => $t ? date('M j, Y · H:i', strtotime($t)) : '';
$agoOf   = fn($sec, ?string $t): string => $t ? ui_ago((int)$sec, $t) : 'never';
$typedEmail = function (?string $detail): string {
    return preg_match('/(?:^|;)email=([^;]*)/', (string)$detail, $m) ? $m[1] : '';
};

// Roles of every account, for the person column and the People view.
$rolesOf = [];
foreach ($pdo->query('SELECT user_id, GROUP_CONCAT(role ORDER BY FIELD(role, "admin", "teacher", "student")) r FROM user_roles GROUP BY user_id') as $r) {
    $rolesOf[(int)$r['user_id']] = explode(',', (string)$r['r']);
}

// ---------- Filters (timeline) ----------
$f = [
    'user'   => max(0, (int)($_GET['user'] ?? 0)),
    'area'   => isset(ACTIVITY_GROUPS[$_GET['area'] ?? '']) ? (string)$_GET['area'] : '',
    'q'      => mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100),
    'period' => isset($PERIODS[$_GET['period'] ?? '']) ? (string)($_GET['period'] ?? '') : '',
];
$where = []; $args = [];
if ($f['user'])   { $where[] = 'a.user_id = :uid'; $args['uid'] = $f['user']; }
if ($f['area'])   {
    $kinds = ACTIVITY_GROUPS[$f['area']][1];
    $in = [];
    foreach ($kinds as $i => $k) { $in[] = ':k' . $i; $args['k' . $i] = $k; }
    $where[] = 'a.kind IN (' . implode(',', $in) . ')';
}
if ($f['period']) { $where[] = 'a.created_at >= NOW() - INTERVAL :days DAY'; $args['days'] = (int)$f['period']; }
if ($f['q'] !== '') {
    $like = '%' . addcslashes($f['q'], '%_\\') . '%';
    $where[] = '(u.name LIKE :q1 OR u.email LIKE :q2 OR a.detail LIKE :q3 OR a.ip LIKE :q4 OR a.kind LIKE :q5)';
    $args += ['q1' => $like, 'q2' => $like, 'q3' => $like, 'q4' => $like, 'q5' => $like];
}
$w = $where ? ' WHERE ' . implode(' AND ', $where) : '';
$filtered = (bool)array_filter($f);
$link = function (array $over = []) use ($self, $f): string {
    $qs = http_build_query(array_filter(array_merge($f, $over), fn($v) => $v !== '' && $v !== null && $v !== 0));
    return $self . ($qs !== '' ? '?' . $qs : '');
};
$baseSql = 'FROM activity a LEFT JOIN users u ON u.id = a.user_id' . $w;

// ---------- CSV export ----------
if ($view === 'timeline' && ($_GET['export'] ?? '') === 'csv') {
    $st = $pdo->prepare('SELECT a.created_at, a.user_id, u.name, u.email, a.kind, a.detail, a.acting_role, a.ip, a.user_agent '
        . $baseSql . ' ORDER BY a.created_at DESC, a.id DESC LIMIT ' . EXPORT_MAX);
    $st->execute($args);
    // Logged before streaming: an interrupted download must still be on record.
    log_activity((int)current_user_id(), 'admin_activity_export', 'rows=' . $st->rowCount() . ($filtered ? ';filtered' : ''));
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="activity-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // so Excel reads UTF-8
    fputcsv($out, ['Time', 'User ID', 'Name', 'Email', 'Area', 'What happened', 'Kind', 'Detail', 'Acting as', 'IP address', 'Device', 'User agent']);
    // Cells starting with = + - @ are prefixed so a spreadsheet does not run them as formulas.
    $cell = fn($v): string => preg_match('/^[=+\-@\t\r]/', (string)$v) ? "'" . $v : (string)$v;
    foreach ($st as $r) {
        [, $text] = ui_activity_admin((string)$r['kind'], $r['detail']);
        $area = activity_group_of((string)$r['kind']);
        fputcsv($out, array_map($cell, [
            $r['created_at'], $r['user_id'], $r['name'] ?? '', $r['email'] ?? $typedEmail($r['detail']),
            ACTIVITY_GROUPS[$area][0] ?? 'Other', $text, $r['kind'], $r['detail'], $r['acting_role'], $r['ip'], ui_device($r['user_agent']), $r['user_agent'],
        ]));
    }
    exit;
}

// ---------- Numbers at the top ----------
$stats = $pdo->query(
    "SELECT COUNT(*) AS events,
            COUNT(DISTINCT user_id) AS people,
            COALESCE(SUM(kind = 'login'), 0) AS logins,
            COALESCE(SUM(kind IN ('login_failed', 'login_2fa_failed')), 0) AS failed,
            (SELECT COUNT(*) FROM users WHERE deleted_at IS NULL) AS accounts,
            CURDATE() AS today
       FROM activity WHERE created_at >= NOW() - INTERVAL 1 DAY"
)->fetch();

// ---------- One person ----------
$person = null;
if ($view === 'timeline' && $f['user']) {
    $st = $pdo->prepare('SELECT id, name, email, deleted_at, created_at FROM users WHERE id = :id');
    $st->execute(['id' => $f['user']]);
    $person = $st->fetch() ?: null;
    if ($person) {
        $st = $pdo->prepare(
            "SELECT MAX(created_at) AS last_active,
                    TIMESTAMPDIFF(SECOND, MAX(created_at), NOW()) AS last_active_ago,
                    MAX(CASE WHEN kind = 'login' THEN created_at END) AS last_login,
                    TIMESTAMPDIFF(SECOND, MAX(CASE WHEN kind = 'login' THEN created_at END), NOW()) AS last_login_ago,
                    COALESCE(SUM(kind = 'login' AND created_at >= NOW() - INTERVAL 30 DAY), 0) AS logins30,
                    COALESCE(SUM(kind IN ('login_failed', 'login_2fa_failed') AND created_at >= NOW() - INTERVAL 30 DAY), 0) AS failed30,
                    COALESCE(SUM(created_at >= NOW() - INTERVAL 30 DAY), 0) AS events30,
                    COUNT(*) AS events
               FROM activity WHERE user_id = :id"
        );
        $st->execute(['id' => $f['user']]);
        $pstats = $st->fetch();
        $st = $pdo->prepare(
            'SELECT ip, MAX(user_agent) AS ua, COUNT(*) AS n, MAX(created_at) AS last
               FROM activity WHERE user_id = :id AND ip IS NOT NULL GROUP BY ip ORDER BY last DESC LIMIT 5'
        );
        $st->execute(['id' => $f['user']]);
        $places = $st->fetchAll();
    }
}

// ---------- Timeline rows ----------
if ($view === 'timeline') {
    $c = $pdo->prepare('SELECT COUNT(*) ' . $baseSql);
    $c->execute($args);
    $total  = (int)$c->fetchColumn();
    $pages  = max(1, (int)ceil($total / PER_PAGE));
    $pageNo = min($pages, max(1, (int)($_GET['page'] ?? 1)));
    $st = $pdo->prepare(
        'SELECT a.id, a.user_id, a.kind, a.detail, a.ip, a.user_agent, a.acting_role, a.created_at,
                DATE(a.created_at) AS day, TIMESTAMPDIFF(SECOND, a.created_at, NOW()) AS ago,
                u.name, u.email, u.deleted_at '
        . $baseSql . ' ORDER BY a.created_at DESC, a.id DESC LIMIT ' . PER_PAGE . ' OFFSET ' . (($pageNo - 1) * PER_PAGE)
    );
    $st->execute($args);
    // A run of the same event by the same person on the same day (role
    // switches, sign-ins, edits) becomes one row with a count and a time span.
    $FOLD = ['login', 'role_switch', 'theme_update', 'profile_update', 'lab_fields_update', 'resources_update', 'login_failed', 'login_2fa_failed', 'logout'];
    $rows = [];
    foreach ($st->fetchAll() as $r) {
        $prev = $rows ? count($rows) - 1 : -1;
        if ($prev >= 0 && in_array($r['kind'], $FOLD, true) && $rows[$prev]['kind'] === $r['kind']
            && $rows[$prev]['user_id'] === $r['user_id'] && $rows[$prev]['day'] === $r['day']
            && ($r['user_id'] !== null || $typedEmail($rows[$prev]['detail']) === $typedEmail($r['detail']))) {
            $rows[$prev]['count']++;
            $rows[$prev]['first_at'] = $r['created_at'];
            continue;
        }
        $rows[] = $r + ['count' => 1, 'first_at' => $r['created_at']];
    }

    $areaCounts = array_fill_keys(array_keys(ACTIVITY_GROUPS), 0);
    $ac = $pdo->prepare('SELECT a.kind, COUNT(*) FROM activity a LEFT JOIN users u ON u.id = a.user_id'
        . ($f['user'] ? ' WHERE a.user_id = :uid' : '') . ' GROUP BY a.kind');
    $ac->execute($f['user'] ? ['uid' => $f['user']] : []);
    foreach ($ac->fetchAll(PDO::FETCH_KEY_PAIR) as $k => $n) {
        $g = activity_group_of((string)$k);
        if (isset($areaCounts[$g])) { $areaCounts[$g] += (int)$n; }
    }
    // ---------- Charts: same filters, bounded to the period (default 30 days) ----------
    $rangeDays = (int)($f['period'] ?: 30);
    $hourly    = $rangeDays === 1;
    $cw = ($w ? $w . ' AND ' : ' WHERE ') . 'a.created_at >= NOW() - INTERVAL ' . $rangeDays . ' DAY';
    $chartSql = 'FROM activity a LEFT JOIN users u ON u.id = a.user_id' . $cw;
    $seriesOf = function (string $kind): string {
        if (in_array($kind, ['login_failed', 'login_2fa_failed', 'login_2fa_locked'], true)) { return 'security'; }
        return match (activity_group_of($kind)) { 'signin' => 'signin', 'security' => 'security', default => 'changes' };
    };
    $CHART_SERIES = [
        'signin'   => ['Sign-ins', '--series-1'],
        'changes'  => ['Changes', '--series-2'],
        'security' => ['Security', '--series-3'],
    ];
    $bucketSql = $hourly ? "DATE_FORMAT(a.created_at, '%Y-%m-%d %H:00')" : 'DATE(a.created_at)';
    $st = $pdo->prepare("SELECT $bucketSql AS b, a.kind, COUNT(*) AS n $chartSql GROUP BY b, a.kind");
    $st->execute($args);
    $byBucket = [];
    foreach ($st as $r) {
        $byBucket[(string)$r['b']][$seriesOf((string)$r['kind'])] = ($byBucket[(string)$r['b']][$seriesOf((string)$r['kind'])] ?? 0) + (int)$r['n'];
    }
    $now = new DateTimeImmutable((string)$pdo->query('SELECT NOW()')->fetchColumn());
    $points = [];
    if ($hourly) {
        for ($i = 23; $i >= 0; $i--) {
            $t = $now->modify('-' . $i . ' hours');
            $key = $t->format('Y-m-d H:00');
            $points[] = ['label' => $t->format('M j, H:00'), 'tick' => (int)$t->format('G') % 6 === 0 ? $t->format('H:00') : '', 'values' => $byBucket[$key] ?? []];
        }
    } else {
        $every = $rangeDays <= 7 ? 1 : ($rangeDays <= 30 ? 7 : 14);
        for ($i = $rangeDays - 1; $i >= 0; $i--) {
            $t = $now->modify('-' . $i . ' days');
            $key = $t->format('Y-m-d');
            $points[] = ['label' => $t->format('D, M j'), 'tick' => ($i % $every === 0) ? $t->format('M j') : '', 'values' => $byBucket[$key] ?? []];
        }
    }
    $rangeText = $hourly ? 'last 24 hours' : 'last ' . $rangeDays . ' days';

    $st = $pdo->prepare("SELECT WEEKDAY(a.created_at) AS wd, HOUR(a.created_at) AS h, COUNT(*) AS n $chartSql GROUP BY wd, h");
    $st->execute($args);
    $heat = array_fill(0, 7, array_fill(0, 24, 0));
    foreach ($st as $r) { $heat[(int)$r['wd']][(int)$r['h']] = (int)$r['n']; }

    if ($f['user']) {
        $st = $pdo->prepare("SELECT a.kind, COUNT(*) AS n $chartSql GROUP BY a.kind");
        $st->execute($args);
        $byArea = [];
        foreach ($st as $r) {
            $g = activity_group_of((string)$r['kind']);
            $byArea[$g] = ($byArea[$g] ?? 0) + (int)$r['n'];
        }
        arsort($byArea);
        $barRows = [];
        foreach ($byArea as $g => $n) {
            $barRows[] = ['label' => ACTIVITY_GROUPS[$g][0] ?? 'Other', 'value' => $n, 'href' => isset(ACTIVITY_GROUPS[$g]) ? $link(['area' => $g, 'page' => null]) : null];
        }
    } else {
        $st = $pdo->prepare("SELECT a.user_id, u.name, u.email, COUNT(*) AS n $chartSql AND a.user_id IS NOT NULL AND u.id IS NOT NULL
                              GROUP BY a.user_id, u.name, u.email ORDER BY n DESC LIMIT 8");
        $st->execute($args);
        $top = $st->fetchAll();
        // Two people with the same name are told apart by their address.
        $names = array_count_values(array_map(fn($r) => (string)$r['name'], $top));
        $barRows = array_map(fn($r) => [
            'label' => $names[(string)$r['name']] > 1 ? $r['name'] . ' · ' . $r['email'] : (string)$r['name'],
            'sub' => $r['name'] . ' · ' . $r['email'], 'value' => (int)$r['n'], 'href' => $self . '?user=' . (int)$r['user_id'],
        ], $top);
    }

    $peopleList = $pdo->query('SELECT id, name, email FROM users ORDER BY name')->fetchAll();

    $dayLabel = function (string $day) use ($stats): string {
        $diff = (int)round((strtotime((string)$stats['today']) - strtotime($day)) / 86400);
        return match (true) {
            $diff === 0 => 'Today',
            $diff === 1 => 'Yesterday',
            $diff < 7   => date('l, M j', strtotime($day)),
            default     => date('M j, Y', strtotime($day)),
        };
    };
}

// ---------- People ----------
if ($view === 'people') {
    $pq    = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100);
    $sort  = in_array($_GET['sort'] ?? '', ['active', 'name', 'logins', 'failed', 'quiet'], true) ? (string)$_GET['sort'] : 'active';
    $order = match ($sort) {
        'name'   => 'name ASC',
        'logins' => 'logins30 DESC, last_active DESC',
        'failed' => 'failed30 DESC, last_active DESC',
        'quiet'  => 'last_active IS NOT NULL, last_active ASC',
        default  => 'last_active IS NULL, last_active DESC',
    };
    $pargs = [];
    $pw = '';
    if ($pq !== '') {
        $pw = ' WHERE (u.name LIKE :q1 OR u.email LIKE :q2)';
        $like = '%' . addcslashes($pq, '%_\\') . '%';
        $pargs = ['q1' => $like, 'q2' => $like];
    }
    // Wrapped, because MariaDB cannot sort on an expression over an aggregate alias.
    $st = $pdo->prepare(
        "SELECT * FROM (SELECT u.id, u.name, u.email, u.deleted_at,
                MAX(a.created_at) AS last_active,
                TIMESTAMPDIFF(SECOND, MAX(a.created_at), NOW()) AS last_active_ago,
                MAX(CASE WHEN a.kind = 'login' THEN a.created_at END) AS last_login,
                TIMESTAMPDIFF(SECOND, MAX(CASE WHEN a.kind = 'login' THEN a.created_at END), NOW()) AS last_login_ago,
                COALESCE(SUM(a.kind = 'login' AND a.created_at >= NOW() - INTERVAL 30 DAY), 0) AS logins30,
                COALESCE(SUM(a.kind IN ('login_failed', 'login_2fa_failed') AND a.created_at >= NOW() - INTERVAL 30 DAY), 0) AS failed30,
                COALESCE(SUM(a.created_at >= NOW() - INTERVAL 30 DAY), 0) AS events30,
                SUBSTRING_INDEX(GROUP_CONCAT(CASE WHEN a.ip IS NOT NULL THEN CONCAT(a.ip, '|', COALESCE(a.user_agent, '')) END ORDER BY a.created_at DESC SEPARATOR '\\n'), '\\n', 1) AS last_place
           FROM users u LEFT JOIN activity a ON a.user_id = u.id
           $pw
          GROUP BY u.id) t ORDER BY $order"
    );
    $st->execute($pargs);
    $people = $st->fetchAll();
}

$tabs = function (string $current) use ($self, $stats): string {
    $html = '<nav class="view-tabs" aria-label="Activity views">';
    foreach (['timeline' => ['pulse', 'Timeline', $self], 'people' => ['users', 'People', $self . '?view=people']] as $v => [$icon, $label, $href]) {
        $html .= '<a href="' . e($href) . '"' . ($current === $v ? ' aria-current="page"' : '') . '>' . pf_icon($icon) . '<span>' . e($label) . '</span>'
            . ($v === 'people' ? ' <span class="view-tabs-count">' . (int)$stats['accounts'] . '</span>' : '') . '</a>';
    }
    return $html . '</nav>';
};
$who = function (array $r) use ($self, $rolesOf, $typedEmail): string {
    if ($r['user_id'] === null) {
        $typed = $typedEmail($r['detail']);
        return '<span class="person"><span class="avatar avatar-sm" aria-hidden="true">?</span><span class="min-w-0">'
            . '<span class="person-name text-muted-foreground-2">No account</span>'
            . ($typed !== '' ? '<span class="person-sub">typed ' . e($typed) . '</span>' : '') . '</span></span>';
    }
    if ($r['name'] === null) {
        return '<span class="person"><span class="avatar avatar-sm" aria-hidden="true">–</span><span class="person-name text-muted-foreground-2">Deleted user #' . (int)$r['user_id'] . '</span></span>';
    }
    return '<a class="person" href="' . e($self . '?user=' . (int)$r['user_id']) . '">' . ui_avatar(null, (string)$r['name'], 'avatar-sm')
        . '<span class="min-w-0"><span class="person-name">' . e($r['name']) . (!empty($r['deleted_at']) ? ' <span class="badge">Deactivated</span>' : '') . '</span>'
        . '<span class="person-sub">' . e($r['email']) . '</span></span></a>';
};
/** [icon, sentence, tone] for a row, saying how often when it is a folded run. */
$describe = function (array $r): array {
    [$icon, $text, $tone] = ui_activity_admin((string)$r['kind'], $r['count'] > 1 && in_array($r['kind'], ['role_switch', 'profile_update', 'lab_fields_update', 'resources_update', 'theme_update'], true) ? null : $r['detail']);
    if ($r['count'] > 1) {
        $text = match ((string)$r['kind']) {
            'login'        => 'Signed in ' . $r['count'] . ' times',
            'login_failed' => $r['count'] . ' failed sign-ins' . (($t = preg_match('/email=([^;]*)/', (string)$r['detail'], $m) ? $m[1] : '') !== '' ? ' as ' . $t : ''),
            'role_switch'  => 'Switched role ' . $r['count'] . ' times',
            'theme_update' => 'Changed the theme ' . $r['count'] . ' times',
            default        => $text . ' (' . $r['count'] . ' changes)',
        };
    }
    return [$icon, $text, $tone];
};
$timeCell = function (array $r) use ($stamp): string {
    $to = date('H:i', strtotime((string)$r['created_at']));
    $from = date('H:i', strtotime((string)$r['first_at']));
    return $r['count'] > 1 && $from !== $to ? $from . '–' . $to : $to;
};
$where_ = function (array $r): string {
    $parts = [];
    if ($r['acting_role']) { $parts[] = 'as ' . e($r['acting_role']); }
    if ($r['ip']) { $parts[] = '<span class="mono">' . e($r['ip']) . '</span>'; }
    if (($d = ui_device($r['user_agent'])) !== '') { $parts[] = '<span title="' . e((string)$r['user_agent']) . '">' . e($d) . '</span>'; }
    return $parts ? implode(' · ', $parts) : '<span class="faint" title="Recorded only since ' . e(ACTIVITY_CONTEXT_SINCE) . '">–</span>';
};

$page_title = $person ? $person['name'] . ' · Activity' : 'Activity';
require __DIR__ . '/../../src/includes/header.php';
?>
<div class="page-wide">
<?php if ($person): ?>
  <a class="back-link" href="<?= e($self) ?>"><?= pf_icon('back') ?> All activity</a>
  <header class="page-header">
    <div class="flex min-w-0 items-center gap-4">
      <?= ui_avatar(null, (string)$person['name'], 'avatar-lg') ?>
      <div class="min-w-0">
        <h1 class="page-title wrap-break-word"><?= e($person['name']) ?></h1>
        <p class="page-desc flex flex-wrap items-center gap-2">
          <?= e($person['email']) ?> <?= ui_roles($rolesOf[(int)$person['id']] ?? []) ?>
          <?= $person['deleted_at'] ? '<span class="badge badge-danger">Deactivated</span>' : '' ?>
        </p>
      </div>
    </div>
    <div class="flex flex-wrap gap-2">
      <a class="btn btn-secondary" href="<?= e(url_admin_emails() . '?view=compose&user=' . (int)$person['id']) ?>"><?= pf_icon('mail') ?> Email</a>
      <a class="btn btn-secondary" href="<?= e(url_admin_user() . '?id=' . (int)$person['id']) ?>"><?= pf_icon('edit') ?> Edit user</a>
    </div>
  </header>

  <dl class="stat-strip">
    <div><dt>Last active</dt><dd class="text-xl!"><?= e($agoOf($pstats['last_active_ago'], $pstats['last_active'])) ?></dd>
      <p class="stat-note"><?= e($stamp($pstats['last_active']) ?: 'No activity recorded') ?></p></div>
    <div><dt>Last sign-in</dt><dd class="text-xl!"><?= e($agoOf($pstats['last_login_ago'], $pstats['last_login'])) ?></dd>
      <p class="stat-note"><?= (int)$pstats['logins30'] ?> sign-ins in 30 days</p></div>
    <div<?= (int)$pstats['failed30'] ? ' class="is-danger"' : '' ?>><dt>Failed sign-ins</dt><dd><?= (int)$pstats['failed30'] ?></dd>
      <p class="stat-note">in the last 30 days</p></div>
    <div><dt>Events</dt><dd><?= number_format((int)$pstats['events']) ?></dd>
      <p class="stat-note"><?= (int)$pstats['events30'] ?> in 30 days · joined <?= e(date('M Y', strtotime((string)$person['created_at']))) ?></p></div>
  </dl>

  <?php if ($places): ?>
    <section class="section mb-6" aria-labelledby="act-places-h">
      <header class="section-head"><h2 class="section-title" id="act-places-h">Signed in from <span class="section-count"><?= count($places) ?> address<?= count($places) === 1 ? '' : 'es' ?>, last 90 days</span></h2></header>
      <ul>
        <?php foreach ($places as $pl): ?>
          <li class="flex flex-wrap items-center gap-x-4 gap-y-1 border-b border-layer-line py-3 text-sm">
            <span class="mono font-semibold"><?= e($pl['ip']) ?></span>
            <span class="text-muted-foreground-2"><?= e(ui_device($pl['ua']) ?: 'Unknown device') ?></span>
            <a class="ml-auto inline-flex min-h-6 items-center text-muted-foreground-1" href="<?= e($self . '?q=' . urlencode((string)$pl['ip'])) ?>" title="Everyone seen from this address"><?= (int)$pl['n'] ?> event<?= (int)$pl['n'] === 1 ? '' : 's' ?> · last <?= e(date('M j, H:i', strtotime((string)$pl['last']))) ?></a>
          </li>
        <?php endforeach; ?>
      </ul>
    </section>
  <?php endif; ?>
<?php else: ?>
  <header class="page-header">
    <div>
      <h1 class="page-title">Activity</h1>
      <p class="page-desc">What everyone has done: sign-ins, profile and account changes, security events and admin actions, with where they came from.</p>
    </div>
    <?php if ($view === 'timeline'): ?>
      <div class="flex flex-wrap gap-2">
        <a class="btn btn-secondary" href="<?= e($link(['export' => 'csv', 'page' => null])) ?>" download><?= pf_icon('download') ?> Download CSV</a>
      </div>
    <?php endif; ?>
  </header>

  <?= $tabs($view) ?>

  <dl class="stat-strip">
    <div><dt><?= pf_icon('pulse') ?> Events</dt><dd><a href="<?= e($self . '?period=1') ?>"><?= number_format((int)$stats['events']) ?></a></dd><p class="stat-note">in the last 24 hours</p></div>
    <div><dt><?= pf_icon('users') ?> Active people</dt><dd><a href="<?= e($self . '?view=people') ?>"><?= (int)$stats['people'] ?></a></dd><p class="stat-note">of <?= (int)$stats['accounts'] ?> accounts, last 24 hours</p></div>
    <div><dt><?= pf_icon('login') ?> Sign-ins</dt><dd><a href="<?= e($self . '?area=signin&period=1') ?>"><?= (int)$stats['logins'] ?></a></dd><p class="stat-note">in the last 24 hours</p></div>
    <div<?= (int)$stats['failed'] ? ' class="is-danger"' : '' ?>><dt><?= pf_icon('lock') ?> Failed sign-ins</dt><dd><a href="<?= e($self . '?q=login_failed&period=1') ?>"><?= (int)$stats['failed'] ?></a></dd><p class="stat-note">in the last 24 hours</p></div>
  </dl>
<?php endif; ?>

<?php if ($view === 'timeline'): ?>
  <form method="get" action="<?= e($self) ?>" class="toolbar" role="search" x-autosubmit>
    <div class="grow search-field">
      <label class="sr-only" for="a-q">Search activity</label>
      <?= pf_icon('search') ?>
      <input class="input" id="a-q" name="q" type="search" value="<?= e($f['q']) ?>" placeholder="Search name, email, IP address or detail">
    </div>
    <?php if (!$person): ?>
      <div>
        <label class="sr-only" for="a-user">Person</label>
        <select class="select max-w-56" id="a-user" name="user">
          <option value="">Everyone</option>
          <?php foreach ($peopleList as $p): ?>
            <option value="<?= (int)$p['id'] ?>"<?= $f['user'] === (int)$p['id'] ? ' selected' : '' ?>><?= e($p['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    <?php else: ?>
      <input type="hidden" name="user" value="<?= (int)$f['user'] ?>">
    <?php endif; ?>
    <div>
      <label class="sr-only" for="a-period">When</label>
      <select class="select" id="a-period" name="period">
        <?php foreach ($PERIODS as $v => $l): ?><option value="<?= $v ?>"<?= $f['period'] === (string)$v ? ' selected' : '' ?>><?= e($l) ?></option><?php endforeach; ?>
      </select>
    </div>
    <?php if ($f['area']): ?><input type="hidden" name="area" value="<?= e($f['area']) ?>"><?php endif; ?>
    <button class="btn btn-secondary" type="submit">Search</button>
    <?php if ($filtered && !($person && count(array_filter($f)) === 1)): ?>
      <a class="btn btn-ghost" href="<?= e($person ? $self . '?user=' . (int)$person['id'] : $self) ?>">Clear</a>
    <?php endif; ?>
    <?php if ($person): ?>
      <a class="btn btn-secondary ml-auto" href="<?= e($link(['export' => 'csv', 'page' => null])) ?>" download><?= pf_icon('download') ?> CSV</a>
    <?php endif; ?>
  </form>

  <nav class="filter-chips" aria-label="Filter by area">
    <span class="filter-chips-label">Area</span>
    <a href="<?= e($link(['area' => '', 'page' => null])) ?>"<?= $f['area'] === '' ? ' aria-current="page"' : '' ?>>All <span class="tab-count"><?= array_sum($areaCounts) ?></span></a>
    <?php foreach (ACTIVITY_GROUPS as $g => [$label]): if (!$areaCounts[$g] && $f['area'] !== $g) { continue; } ?>
      <a href="<?= e($link(['area' => $g, 'page' => null])) ?>"<?= $f['area'] === $g ? ' aria-current="page"' : '' ?>><?= e($label) ?> <span class="tab-count"><?= $areaCounts[$g] ?></span></a>
    <?php endforeach; ?>
  </nav>

  <section class="mb-6 flex flex-col gap-4" aria-label="Charts">
    <?= chart_columns($CHART_SERIES, $points, [
        'id' => 'act-cols', 'unit' => 'events', 'xLabel' => $hourly ? 'Hour' : 'Day',
        'title' => ($person ? 'Their activity' : 'Activity') . ($hourly ? ' per hour' : ' per day'),
        'desc' => ucfirst($rangeText) . ($filtered && !($person && count(array_filter($f)) === 1) ? ', matching the filters above' : '') . '. Sign-ins include role switches; Security covers failed sign-ins, password and two-factor events.',
    ]) ?>
    <div class="chart-grid-2">
      <?= chart_heatmap($heat, [
          'id' => 'act-heat', 'unit' => 'events',
          'title' => 'When ' . ($person ? 'they are' : 'people are') . ' active',
          'desc' => 'Events by weekday and hour (' . app_timezone_label() . '), ' . $rangeText . '.',
      ]) ?>
      <?= chart_bars($barRows, $person
          ? ['id' => 'act-bars', 'title' => 'By area', 'desc' => 'Their events, ' . $rangeText . '. Click an area to filter.']
          : ['id' => 'act-bars', 'title' => 'Most active people', 'desc' => 'Events per person, ' . $rangeText . '. Click a name for their history.']) ?>
    </div>
  </section>

  <?php if (!$rows): ?>
    <div class="empty mt-2">
      <h2 class="empty-title"><?= $filtered ? 'Nothing matches' : 'No activity yet' ?></h2>
      <p><?= $filtered ? 'Try another search, or a longer period.' : 'Sign-ins and changes appear here as people use the site.' ?></p>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <div class="table-scroll max-md:hidden">
        <table class="table">
          <thead>
            <tr>
              <th scope="col" class="w-20">Time</th>
              <?php if (!$person): ?><th scope="col">Person</th><?php endif; ?>
              <th scope="col">What happened</th>
              <th scope="col">From</th>
            </tr>
          </thead>
          <tbody>
            <?php $lastDay = null; foreach ($rows as $r):
                [$icon, $text, $tone] = $describe($r); ?>
              <?php if ($r['day'] !== $lastDay): $lastDay = $r['day']; ?>
                <tr class="table-group"><th colspan="<?= $person ? 3 : 4 ?>" scope="colgroup"><?= e($dayLabel((string)$r['day'])) ?></th></tr>
              <?php endif; ?>
              <tr>
                <td class="nowrap num" title="<?= e($stamp($r['created_at'])) ?>">
                  <?= e($timeCell($r)) ?>
                  <?php if ((int)$r['ago'] < 86400): ?><span class="cell-sub"><?= e(ui_ago((int)$r['ago'], (string)$r['created_at'])) ?></span><?php endif; ?>
                </td>
                <?php if (!$person): ?><td class="min-w-56"><?= $who($r) ?></td><?php endif; ?>
                <td>
                  <span class="activity-line<?= $tone ? ' is-' . $tone : '' ?>"><?= pf_icon($icon) ?><span><?= e($text) ?></span></span>
                  <span class="cell-sub"><?= e(ACTIVITY_GROUPS[activity_group_of((string)$r['kind'])][0] ?? 'Other') ?></span>
                </td>
                <td class="text-sm text-muted-foreground-2"><?= $where_($r) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <ul class="card-list md:hidden">
        <?php $lastDay = null; foreach ($rows as $r):
            [$icon, $text, $tone] = $describe($r); ?>
          <?php if ($r['day'] !== $lastDay): $lastDay = $r['day']; ?>
            <li class="card-list-day"><?= e($dayLabel((string)$r['day'])) ?></li>
          <?php endif; ?>
          <li class="card-list-item gap-2">
            <span class="activity-line<?= $tone ? ' is-' . $tone : '' ?>"><?= pf_icon($icon) ?><span><?= e($text) ?></span></span>
            <?php if (!$person): ?><?= $who($r) ?><?php endif; ?>
            <span class="text-xs text-muted-foreground-1"><?= e($timeCell($r)) ?> · <?= $where_($r) ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>

    <nav class="mt-4 flex flex-wrap items-center justify-between gap-3" aria-label="Pages">
      <p class="text-sm text-muted-foreground-1"><?= number_format(($pageNo - 1) * PER_PAGE + 1) ?>–<?= number_format(min($total, $pageNo * PER_PAGE)) ?> of <?= number_format($total) ?> events<?= $filtered ? ' matching' : '' ?>. Repeats in a row are shown once with a count. Address and browser are recorded since <?= e(ACTIVITY_CONTEXT_SINCE) ?>.</p>
      <?php if ($pages > 1): ?>
        <div class="flex gap-2">
          <?php if ($pageNo > 1): ?><a class="btn btn-secondary btn-sm" href="<?= e($link(['page' => $pageNo - 1 > 1 ? $pageNo - 1 : null])) ?>">← Newer</a><?php endif; ?>
          <?php if ($pageNo < $pages): ?><a class="btn btn-secondary btn-sm" href="<?= e($link(['page' => $pageNo + 1])) ?>">Older →</a><?php endif; ?>
        </div>
      <?php endif; ?>
    </nav>
  <?php endif; ?>

<?php else: /* people */ ?>
  <form method="get" action="<?= e($self) ?>" class="toolbar" role="search" x-autosubmit>
    <input type="hidden" name="view" value="people">
    <div class="grow search-field">
      <label class="sr-only" for="p-q">Search people</label>
      <?= pf_icon('search') ?>
      <input class="input" id="p-q" name="q" type="search" value="<?= e($pq) ?>" placeholder="Search name or email">
    </div>
    <div>
      <label class="sr-only" for="p-sort">Sort</label>
      <select class="select" id="p-sort" name="sort">
        <?php foreach (['active' => 'Most recently active', 'quiet' => 'Longest inactive', 'logins' => 'Most sign-ins (30 days)', 'failed' => 'Most failed sign-ins', 'name' => 'Name'] as $v => $l): ?>
          <option value="<?= $v ?>"<?= $sort === $v ? ' selected' : '' ?>><?= e($l) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <button class="btn btn-secondary" type="submit">Search</button>
    <?php if ($pq !== ''): ?><a class="btn btn-ghost" href="<?= e($self . '?view=people') ?>">Clear</a><?php endif; ?>
  </form>

  <?php if (!$people): ?>
    <div class="empty mt-2"><h2 class="empty-title">Nobody matches</h2><p>Try another name or email.</p></div>
  <?php else: ?>
    <div class="table-wrap">
      <div class="table-scroll max-md:hidden">
        <table class="table">
          <thead>
            <tr>
              <th scope="col">Person</th>
              <th scope="col">Roles</th>
              <th scope="col">Last active</th>
              <th scope="col">Last sign-in</th>
              <th scope="col" class="num">Sign-ins<span class="cell-sub font-normal">30 days</span></th>
              <th scope="col" class="num">Failed<span class="cell-sub font-normal">30 days</span></th>
              <th scope="col">Last seen from</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($people as $p):
                [$ip, $ua] = array_pad(explode('|', (string)$p['last_place'], 2), 2, ''); ?>
              <tr class="has-row-link<?= $p['deleted_at'] ? ' is-deactivated' : '' ?>">
                <th scope="row">
                  <a class="person row-link font-normal" href="<?= e($self . '?user=' . (int)$p['id']) ?>">
                    <?= ui_avatar(null, (string)$p['name'], 'avatar-sm') ?>
                    <span class="min-w-0"><span class="person-name"><?= e($p['name']) ?></span><span class="person-sub"><?= e($p['email']) ?></span></span>
                  </a>
                </th>
                <td><?= ui_roles($rolesOf[(int)$p['id']] ?? []) ?><?= $p['deleted_at'] ? ' <span class="badge">Deactivated</span>' : '' ?></td>
                <td class="nowrap" title="<?= e($stamp($p['last_active'])) ?>"><?= e($agoOf($p['last_active_ago'], $p['last_active'])) ?></td>
                <td class="nowrap" title="<?= e($stamp($p['last_login'])) ?>"><?= e($agoOf($p['last_login_ago'], $p['last_login'])) ?></td>
                <td class="num"><?= (int)$p['logins30'] ?></td>
                <td class="num<?= (int)$p['failed30'] ? ' font-bold text-danger' : '' ?>"><?= (int)$p['failed30'] ?></td>
                <td class="text-sm text-muted-foreground-2"><?= $ip !== '' ? '<span class="mono">' . e($ip) . '</span>' . (($d = ui_device($ua)) !== '' ? '<span class="cell-sub">' . e($d) . '</span>' : '') : '<span class="faint">–</span>' ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <ul class="card-list md:hidden">
        <?php foreach ($people as $p): ?>
          <li>
            <a class="card-list-item gap-2 text-foreground no-underline hover:bg-layer-hover<?= $p['deleted_at'] ? ' is-deactivated' : '' ?>" href="<?= e($self . '?user=' . (int)$p['id']) ?>">
              <span class="person"><?= ui_avatar(null, (string)$p['name'], 'avatar-sm') ?>
                <span class="min-w-0"><span class="person-name"><?= e($p['name']) ?></span><span class="person-sub"><?= e($p['email']) ?></span></span></span>
              <span class="card-list-meta">
                <span>Active <?= e($agoOf($p['last_active_ago'], $p['last_active'])) ?></span>
                <span><?= (int)$p['logins30'] ?> sign-ins</span>
                <?php if ((int)$p['failed30']): ?><span class="font-semibold text-danger"><?= (int)$p['failed30'] ?> failed</span><?php endif; ?>
              </span>
            </a>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
    <p class="mt-4 text-sm text-muted-foreground-1"><?= count($people) ?> account<?= count($people) === 1 ? '' : 's' ?>. Click a person for their full history.</p>
  <?php endif; ?>
<?php endif; ?>
</div>
<?php require __DIR__ . '/../../src/includes/footer.php'; ?>
