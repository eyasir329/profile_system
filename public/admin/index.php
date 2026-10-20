<?php
/**
 * Admin: every account.
 *
 * Route:  GET  /admin/                    the list
 *              ?q= &role= &status= &twofa= &sort=   filters (see below)
 *              &export=csv                the filtered list as a CSV file
 *         GET  /admin/?id=<id>            old link to one user: redirects to /admin/user.php?id=<id>
 *         POST /admin/                    action = bulk, op = deactivate | restore, ids[]
 * Auth:   require_admin()
 * CSRF:   verify_csrf() on POST
 *
 * Filters: role (admin | teacher | student), status (active | deactivated),
 * twofa (on | off), sort (name | newest | active = recently active first |
 * inactive = longest inactive first). Status and role show as chips with
 * counts.
 *
 * Bulk actions apply the same safety rules as /admin/user.php: an admin
 * never deactivates themselves or the last active admin; those rows are
 * skipped and reported. "Email" opens Emails › Write with the selection
 * picked. Each change is logged per person, like a single change.
 *
 * Teachers' Students sheet is a different page (src/includes/directory.php).
 */
declare(strict_types=1);
require_once __DIR__ . '/../../src/includes/auth.php';
require_once __DIR__ . '/../../src/includes/db.php';
require_once __DIR__ . '/../../src/includes/url.php';
require_once __DIR__ . '/../../src/includes/validate.php';
require_once __DIR__ . '/../../src/includes/ui.php';
require_admin();

if (isset($_GET['id'])) {
    header('Location: ' . url_admin_user() . '?id=' . (int)$_GET['id'], true, 301);
    exit;
}

$meId    = (int)current_user_id();
$self    = url_admin();
$page_id = 'admin';

// ---------- Bulk actions ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'bulk') {
    verify_csrf();
    $op  = (string)($_POST['op'] ?? '');
    $ids = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['ids'] ?? [])))));
    $ok = $skipped = 0;
    if ($ids && in_array($op, ['deactivate', 'restore'], true)) {
        $in = implode(',', array_fill(0, count($ids), '?'));
        $st = $pdo->prepare("SELECT u.id, u.email, u.deleted_at,
                                    EXISTS (SELECT 1 FROM user_roles r WHERE r.user_id = u.id AND r.role = 'admin') AS is_admin
                               FROM users u WHERE u.id IN ($in)");
        $st->execute($ids);
        $rows = $st->fetchAll();
        $activeAdmins = (int)$pdo->query("SELECT COUNT(*) FROM users u JOIN user_roles r ON r.user_id = u.id AND r.role = 'admin' WHERE u.deleted_at IS NULL")->fetchColumn();
        foreach ($rows as $r) {
            $id = (int)$r['id'];
            if ($op === 'deactivate') {
                // Same rules as one at a time: never yourself, never the last active admin.
                if ($id === $meId || $r['deleted_at'] !== null || ((int)$r['is_admin'] && $activeAdmins <= 1)) { $skipped++; continue; }
                $pdo->prepare('UPDATE users SET deleted_at = NOW(), scheduled_purge_at = DATE_ADD(NOW(), INTERVAL 30 DAY) WHERE id = :id')->execute(['id' => $id]);
                if ((int)$r['is_admin']) { $activeAdmins--; }
                log_activity($meId, 'admin_user_deactivate', (string)$r['email'] . ';bulk');
            } else {
                if ($r['deleted_at'] === null) { $skipped++; continue; }
                $pdo->prepare('UPDATE users SET deleted_at = NULL, scheduled_purge_at = NULL WHERE id = :id')->execute(['id' => $id]);
                log_activity($meId, 'admin_user_restore', (string)$r['email'] . ';bulk');
            }
            $ok++;
        }
    }
    $back = (string)($_POST['back'] ?? '');
    $qs = str_starts_with($back, '?') ? ltrim($back, '?') : '';
    parse_str($qs, $keep);
    header('Location: ' . $self . '?' . http_build_query(array_diff_key($keep, ['done' => 1, 'ok' => 1, 'skipped' => 1]) + ['done' => 'bulk_' . $op, 'ok' => $ok, 'skipped' => $skipped]));
    exit;
}

// ---------- Filters ----------
$f = [
    'q'      => mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100),
    'role'   => in_array($_GET['role'] ?? '', ['admin', 'teacher', 'student'], true) ? (string)$_GET['role'] : '',
    'status' => in_array($_GET['status'] ?? '', ['active', 'deactivated'], true) ? (string)$_GET['status'] : '',
    'twofa'  => in_array($_GET['twofa'] ?? '', ['on', 'off'], true) ? (string)$_GET['twofa'] : '',
    'sort'   => in_array($_GET['sort'] ?? '', ['newest', 'active', 'inactive'], true) ? (string)$_GET['sort'] : '',
];
$link = function (array $over = []) use ($self, $f): string {
    $qs = http_build_query(array_filter(array_merge($f, $over), fn($v) => $v !== '' && $v !== null));
    return $self . ($qs !== '' ? '?' . $qs : '');
};

$where = []; $args = [];
if ($f['q'] !== '') {
    $like = '%' . addcslashes($f['q'], '%_\\') . '%';
    $where[] = '(u.name LIKE :q1 OR u.email LIKE :q2 OR p.student_id LIKE :q3 OR p.name_en LIKE :q4 OR p.name_jp LIKE :q5)';
    $args += ['q1' => $like, 'q2' => $like, 'q3' => $like, 'q4' => $like, 'q5' => $like];
}
if ($f['twofa'] !== '') { $where[] = 'u.totp_required = ' . ($f['twofa'] === 'on' ? 1 : 0); }
// Role and status are counted per chip, so each count ignores its own filter.
$roleSql   = fn(string $r): string => "EXISTS (SELECT 1 FROM user_roles fr WHERE fr.user_id = u.id AND fr.role = '$r')";
$statusSql = fn(string $s): string => $s === 'active' ? 'u.deleted_at IS NULL' : 'u.deleted_at IS NOT NULL';
$count = function (array $extra) use ($pdo, $where, $args): int {
    $w = array_merge($where, $extra);
    $st = $pdo->prepare('SELECT COUNT(*) FROM users u LEFT JOIN profiles p ON p.user_id = u.id' . ($w ? ' WHERE ' . implode(' AND ', $w) : ''));
    $st->execute($args);
    return (int)$st->fetchColumn();
};
$statusBase = $f['role'] ? [$roleSql($f['role'])] : [];
$roleBase   = $f['status'] ? [$statusSql($f['status'])] : [];
$statusCounts = ['' => $count($statusBase), 'active' => $count([...$statusBase, $statusSql('active')]), 'deactivated' => $count([...$statusBase, $statusSql('deactivated')])];
$roleCounts = ['' => $count($roleBase)];
foreach (['admin', 'teacher', 'student'] as $r) { $roleCounts[$r] = $count([...$roleBase, $roleSql($r)]); }

if ($f['role'])   { $where[] = $roleSql($f['role']); }
if ($f['status']) { $where[] = $statusSql($f['status']); }
$w = $where ? ' WHERE ' . implode(' AND ', $where) : '';
$order = match ($f['sort']) {
    'newest'   => 'created_at DESC',
    'active'   => 'last_active IS NULL, last_active DESC',
    'inactive' => 'last_active IS NOT NULL, last_active ASC',
    default    => 'name ASC, id ASC',
};
$st = $pdo->prepare(
    "SELECT * FROM (
        SELECT u.id, u.name, u.email, u.totp_required, u.email_verified, u.created_at, u.deleted_at, u.scheduled_purge_at,
               p.student_id, p.course,
               (SELECT GROUP_CONCAT(r.role ORDER BY FIELD(r.role, 'admin', 'teacher', 'student')) FROM user_roles r WHERE r.user_id = u.id) AS roles_csv,
               (SELECT MAX(a.created_at) FROM activity a WHERE a.user_id = u.id) AS last_active
          FROM users u LEFT JOIN profiles p ON p.user_id = u.id $w
     ) t ORDER BY $order"
);
$st->execute($args);
$users = $st->fetchAll();
$total = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
$filtered = $f['q'] !== '' || $f['role'] !== '' || $f['status'] !== '' || $f['twofa'] !== '';
$nowTs = strtotime((string)$pdo->query('SELECT NOW()')->fetchColumn());

// ---------- CSV ----------
if (($_GET['export'] ?? '') === 'csv') {
    log_activity($meId, 'admin_users_export', 'rows=' . count($users) . ($filtered ? ';filtered' : ''));
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="users-' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    $cell = fn($v): string => preg_match('/^[=+\-@\t\r]/', (string)$v) ? "'" . $v : (string)$v;
    fputcsv($out, ['Name', 'Email', 'Roles', 'Status', 'Two-factor', 'Email verified', 'Student ID', 'Course', 'Joined', 'Last active']);
    foreach ($users as $u) {
        fputcsv($out, array_map($cell, [$u['name'], $u['email'], str_replace(',', ', ', (string)$u['roles_csv']),
            $u['deleted_at'] ? 'Deactivated' : 'Active', (int)$u['totp_required'] ? 'On' : 'Off', (int)$u['email_verified'] ? 'Yes' : 'No',
            $u['student_id'], $u['course'], $u['created_at'], $u['last_active']]));
    }
    exit;
}

$done = (string)($_GET['done'] ?? '');
$ok = (int)($_GET['ok'] ?? 0); $skippedN = (int)($_GET['skipped'] ?? 0);
$plural = fn(int $n, string $w): string => $n . ' ' . $w . ($n === 1 ? '' : 's');
$flash = match ($done) {
    'deleted'         => [['type' => 'success', 'body' => 'User permanently deleted.']],
    'bulk_deactivate' => [['type' => $ok ? 'success' : 'info', 'body' => $plural($ok, 'account') . ' deactivated.' . ($skippedN ? ' ' . $skippedN . ' skipped (you, the last admin, or already deactivated).' : '')]],
    'bulk_restore'    => [['type' => $ok ? 'success' : 'info', 'body' => $plural($ok, 'account') . ' restored.' . ($skippedN ? ' ' . $skippedN . ' skipped (already active).' : '')]],
    default           => [],
};

$ago = function (?string $t) use ($nowTs): string {
    if (!$t) { return 'Never'; }
    return ui_ago(max(0, $nowTs - (int)strtotime($t)), $t);
};
$roleChips = fn(array $u): string => ui_roles(array_filter(explode(',', (string)$u['roles_csv'])));
$backQs = '?' . http_build_query(array_filter($f, fn($v) => $v !== ''));

$page_title = 'Users';
require __DIR__ . '/../../src/includes/header.php';
?>
<div class="page-wide" x-data="bulkSelect" data-compose="<?= e(url_admin_emails() . '?view=compose') ?>">
  <header class="page-header">
    <div>
      <h1 class="page-title">Users</h1>
      <p class="page-desc"><?= $filtered ? e($plural(count($users), 'user')) . ' of ' . $total . ' match.' : e($plural($total, 'account')) . '. Open someone to see and edit everything about them.' ?></p>
    </div>
    <div class="flex flex-wrap gap-2">
      <a class="btn btn-secondary" href="<?= e($link(['export' => 'csv'])) ?>" download><?= pf_icon('download') ?> CSV</a>
      <a class="btn btn-secondary" href="<?= e(url_invites()) ?>"><?= pf_icon('link') ?> Invite</a>
      <a class="btn btn-primary" href="<?= e(url_admin_user()) ?>"><?= pf_icon('plus') ?> New user</a>
    </div>
  </header>

  <nav class="view-tabs" aria-label="Status">
    <?php foreach (['' => ['users', 'All'], 'active' => ['check', 'Active'], 'deactivated' => ['lock', 'Deactivated']] as $s => [$icon, $label]): ?>
      <a href="<?= e($link(['status' => $s])) ?>"<?= $f['status'] === $s ? ' aria-current="page"' : '' ?>><?= pf_icon($icon) ?><span><?= e($label) ?></span> <span class="view-tabs-count"><?= $statusCounts[$s] ?></span></a>
    <?php endforeach; ?>
  </nav>

  <form method="get" action="<?= e($self) ?>" class="toolbar" role="search" x-autosubmit>
    <div class="grow search-field">
      <label class="sr-only" for="u-q">Search users</label>
      <?= pf_icon('search') ?>
      <input class="input" id="u-q" name="q" type="search" value="<?= e($f['q']) ?>" placeholder="Name, email or student ID">
    </div>
    <div>
      <label class="sr-only" for="u-2fa">Two-factor</label>
      <select class="select" id="u-2fa" name="twofa">
        <option value="">Two-factor: any</option>
        <option value="on"<?= $f['twofa'] === 'on' ? ' selected' : '' ?>>Two-factor on</option>
        <option value="off"<?= $f['twofa'] === 'off' ? ' selected' : '' ?>>Two-factor off</option>
      </select>
    </div>
    <div>
      <label class="sr-only" for="u-sort">Sort</label>
      <select class="select" id="u-sort" name="sort">
        <?php foreach (['' => 'Name A–Z', 'newest' => 'Newest first', 'active' => 'Recently active', 'inactive' => 'Longest inactive'] as $v => $l): ?>
          <option value="<?= $v ?>"<?= $f['sort'] === $v ? ' selected' : '' ?>><?= e($l) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php foreach (['role', 'status'] as $k): if ($f[$k] !== ''): ?><input type="hidden" name="<?= $k ?>" value="<?= e($f[$k]) ?>"><?php endif; endforeach; ?>
    <button class="btn btn-secondary" type="submit">Search</button>
    <?php if ($filtered): ?><a class="btn btn-ghost" href="<?= e($self) ?>">Clear</a><?php endif; ?>
  </form>

  <nav class="filter-chips" aria-label="Filter by role">
    <span class="filter-chips-label">Role</span>
    <?php foreach (['' => 'All', 'admin' => 'Admins', 'teacher' => 'Teachers', 'student' => 'Students'] as $r => $label): ?>
      <a href="<?= e($link(['role' => $r])) ?>"<?= $f['role'] === $r ? ' aria-current="page"' : '' ?>><?= e($label) ?> <span class="tab-count"><?= $roleCounts[$r] ?></span></a>
    <?php endforeach; ?>
  </nav>

  <!-- Shown while people are ticked. -->
  <div class="bulk-bar" x-show="ids.length" x-cloak role="region" aria-label="Selected users">
    <span class="bulk-bar-count"><strong x-text="ids.length"></strong> selected</span>
    <a class="btn btn-secondary btn-sm" :href="composeUrl()"><?= pf_icon('mail') ?> Email</a>
    <form method="post" action="<?= e($self) ?>" x-confirm data-confirm-title="Deactivate the selected accounts?"
          data-confirm-body="They are signed out and cannot sign in until restored (30 days). You and the last admin are skipped." data-confirm-ok="Deactivate">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="bulk"><input type="hidden" name="op" value="deactivate">
      <input type="hidden" name="back" value="<?= e($backQs) ?>">
      <template x-for="id in ids" :key="id"><input type="hidden" name="ids[]" :value="id"></template>
      <button class="btn btn-secondary btn-sm" type="submit">Deactivate</button>
    </form>
    <form method="post" action="<?= e($self) ?>">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="bulk"><input type="hidden" name="op" value="restore">
      <input type="hidden" name="back" value="<?= e($backQs) ?>">
      <template x-for="id in ids" :key="id"><input type="hidden" name="ids[]" :value="id"></template>
      <button class="btn btn-secondary btn-sm" type="submit">Restore</button>
    </form>
    <button type="button" class="btn btn-ghost btn-sm ml-auto" @click="clear()">Clear selection</button>
  </div>

  <?php if (!$users): ?>
    <div class="empty mt-2">
      <h2 class="empty-title">Nobody matches</h2>
      <p>Try another search, or clear the filters.</p>
      <a class="btn btn-secondary" href="<?= e($self) ?>">Clear filters</a>
    </div>
  <?php else: ?>
    <div class="table-wrap">
      <div class="table-scroll max-md:hidden">
        <table class="table">
          <thead>
            <tr>
              <th scope="col" class="w-10"><input type="checkbox" class="pick-all" aria-label="Select all shown" :checked="all" :indeterminate="some" @change="toggleAll($event.target.checked)"></th>
              <th scope="col">User</th>
              <th scope="col">Roles</th>
              <th scope="col">Last active</th>
              <th scope="col">Two-factor</th>
              <th scope="col">Joined</th>
              <th scope="col">Status</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($users as $u): $id = (int)$u['id']; ?>
              <tr class="has-row-link<?= $u['deleted_at'] ? ' is-deactivated' : '' ?>" :class="ids.includes(<?= $id ?>) && 'is-selected'">
                <td class="relative z-1"><input type="checkbox" class="pick-one" value="<?= $id ?>" aria-label="Select <?= e($u['name']) ?>" :checked="ids.includes(<?= $id ?>)" @change="toggle(<?= $id ?>)"></td>
                <th scope="row">
                  <a class="person row-link font-normal" href="<?= e(url_admin_user() . '?id=' . $id) ?>">
                    <?= ui_avatar(null, (string)$u['name']) ?>
                    <span class="min-w-0">
                      <span class="person-name"><?= e($u['name']) ?><?= $id === $meId ? ' <span class="badge">You</span>' : '' ?></span>
                      <span class="person-sub"><?= e($u['email']) ?><?= $u['student_id'] ? ' · ID ' . e($u['student_id']) : '' ?></span>
                    </span>
                  </a>
                </th>
                <td><?= $roleChips($u) ?></td>
                <td class="nowrap" title="<?= e((string)$u['last_active']) ?>"><?= e($ago($u['last_active'])) ?></td>
                <td><?= (int)$u['totp_required'] ? '<span class="badge badge-success">On</span>' : '<span class="badge">Off</span>' ?></td>
                <td class="nowrap"><?= e(date('M j, Y', strtotime((string)$u['created_at']))) ?></td>
                <td class="nowrap"><?= $u['deleted_at'] ? '<span class="badge badge-danger">Deactivated</span>' : '<span class="badge badge-success">Active</span>' ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <ul class="card-list md:hidden">
        <?php foreach ($users as $u): $id = (int)$u['id']; ?>
          <li class="card-list-item flex-row items-start gap-3<?= $u['deleted_at'] ? ' is-deactivated' : '' ?>">
            <input type="checkbox" class="pick-one mt-3" aria-label="Select <?= e($u['name']) ?>" :checked="ids.includes(<?= $id ?>)" @change="toggle(<?= $id ?>)">
            <a class="flex min-w-0 flex-1 flex-col gap-2 text-foreground no-underline" href="<?= e(url_admin_user() . '?id=' . $id) ?>">
              <span class="person"><?= ui_avatar(null, (string)$u['name']) ?>
                <span class="min-w-0"><span class="person-name"><?= e($u['name']) ?></span><span class="person-sub"><?= e($u['email']) ?></span></span></span>
              <span class="card-list-meta"><?= $roleChips($u) ?><?= $u['deleted_at'] ? '<span class="badge badge-danger">Deactivated</span>' : '' ?></span>
              <span class="text-xs text-muted-foreground-1">Active <?= e(mb_strtolower($ago($u['last_active']))) ?> · Two-factor <?= (int)$u['totp_required'] ? 'on' : 'off' ?></span>
            </a>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
    <p class="mt-4 text-sm text-muted-foreground-1">Tick people to email, deactivate or restore several at once.</p>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/../../src/includes/footer.php'; ?>
