<?php
declare(strict_types=1);

/**
 * People directory: the page behind /students.php (teacher: students only,
 * read-only). Admins have their own pages since the admin redesign
 * (/admin/index.php and /admin/user.php); the "users" scope below is kept
 * for compatibility but no page uses it.
 *
 * The including page does the access check and sets $DIR:
 *   scope     'students' | 'users'
 *   base      URL of the including page (links are built from it)
 *   title     page heading, e.g. 'Students'
 *   one, many nouns for counts, e.g. 'student', 'students'
 *
 * Each role gets only what its work needs, all from this file so the
 * shared parts cannot drift apart:
 *   ?id=<id>      one person's page
 *   users scope (admin): a list of every account (roles, status, joined)
 *                 with Edit. Account management only: no workbook sheet,
 *                 no lab-details tracking.
 *   students scope (teacher): the workbook sheet, the columns in
 *                 $XLSX_COLUMNS, with Current / Completed tabs, course
 *                 chips, CSV download and "Full screen" (Alpine `sheet` in
 *                 resources/js/ui.js)
 *
 * Query params:
 *   q            — name / email / lab-field search
 *   users scope:    sort, dir (name | joined, asc | desc),
 *                   role ('admin' | 'teacher' | 'student'),
 *                   status ('active' | 'deactivated')
 *   students scope: group ('completed'), course, export ('csv')
 *
 * The students scope never lists deactivated accounts and has no forms
 * other than the filter. The users scope adds the admin actions, which
 * all post to /admin/user.php.
 */

require_once __DIR__ . '/lab_fields.php';
require_once __DIR__ . '/lab_entries.php';
require_once __DIR__ . '/ui.php';

$isAdmin  = $DIR['scope'] === 'users';
// Teachers and students get the same view of the Students sheet.
$baseUrl  = (string)$DIR['base'];
$editUrl  = fn(int $id): string => url_admin_user() . '?id=' . $id;

/** Who this directory covers. */
$scopeWhere = $isAdmin
    ? '1 = 1'
    : "u.deleted_at IS NULL AND EXISTS (SELECT 1 FROM user_roles sr WHERE sr.user_id = u.id AND sr.role = 'student')";

$personSelect =
    "u.id, u.name, u.email, u.email_verified, u.totp_required,
     u.created_at, u.deleted_at, u.scheduled_purge_at,
     (SELECT GROUP_CONCAT(r.role ORDER BY FIELD(r.role, 'admin', 'teacher', 'student'))
        FROM user_roles r WHERE r.user_id = u.id) AS roles_csv,
     p.*";

$COURSES = LAB_COURSES;

// The sheet shows exactly the columns of the lab's "Information of Lab
// Students" workbook, in the workbook's order, with the workbook's header
// text (line breaks included). key => [heading, cell class]
$XLSX_COLUMNS = [
    'course'              => ["Course\n(D,M,B,R)", 'nowrap'],
    'student_id'          => ['Student ID', 'nowrap'],
    'name_jp'             => ["Full name\n(Japanese)", 'nowrap'],
    'name_en'             => ["Full name\n(English)", 'nowrap'],
    'email'               => ["University\nEmail", 'nowrap'],   // the account's sign-in address
    'research_title'      => ['Current Research Title', 'wide pre'],
    'enrolled_year'       => ["Enrolled\nYear", 'nowrap num'],
    'enrolled_month'      => ["Enrolled\nMonth", 'nowrap'],
    'next_step'           => ["Next step\n(進路)", 'pre'],
    'previous_univ_level' => ['Previous Univ/Level', 'pre'],
    'completed_thesis'    => ["Completed Thesis Titles (If any)\n[Level: Title]", 'wide pre'],
    'journal_papers'      => ["Journal Papers\n{published}({submitted})", 'nowrap num'],
    'conference_papers'   => ["Conference Papers\n{published}({submitted})", 'nowrap num'],
];
// Paper columns hold the two counts the student typed, as "published(submitted)",
// e.g. 3(1) (see paper_display() in lab_entries.php, which also reads older
// records that have the count on the first line and a title list below it).
$PAPER_COLUMNS = LAB_PAPER_COLUMNS;
// The workbook lists people course by course, most senior first.
$COURSE_ORDER = array_flip(['D3', 'D2', 'D1', 'M2', 'M1', 'B4', 'B3', 'R', 'Other']);

$avatar = fn(array $u, string $size = ''): string => ui_avatar(null, (string)$u['name'], $size);
// Lab details belong to students. Other accounts have none to show.
$holdsStudent = fn(array $u): bool => in_array('student', explode(',', (string)($u['roles_csv'] ?? '')), true);
$roleChips = fn(array $u): string => ui_roles(explode(',', (string)($u['roles_csv'] ?? '')));
$statusBadge = fn(array $u): string => !empty($u['deleted_at'])
    ? '<span class="badge badge-danger">Deactivated</span>'
    : '<span class="badge badge-success">Active</span>';

// One-time confirmations after an admin action redirected back here.
$flash = $flash ?? [];
$doneMessages = [
    'created'     => 'User created.',
    'updated'     => 'Changes saved.',
    'deactivated' => 'Account deactivated. It can be restored for 30 days.',
    'restored'    => 'Account restored.',
    'deleted'     => 'User permanently deleted.',
    '2fa_reset'   => 'Two-factor sign-in reset. They have been emailed.',
];
if ($isAdmin && isset($_GET['done'], $doneMessages[(string)$_GET['done']])) {
    $flash[] = ['type' => 'success', 'body' => $doneMessages[(string)$_GET['done']]];
}

// ============================================================================
// One person
// ============================================================================
if (isset($_GET['id'])) {
    $st = $pdo->prepare(
        "SELECT $personSelect FROM users u LEFT JOIN profiles p ON p.user_id = u.id
          WHERE u.id = :id AND $scopeWhere LIMIT 1"
    );
    $st->execute(['id' => (int)$_GET['id']]);
    $s = $st->fetch();

    $page_title = $s ? $s['name'] . ' · ' . $DIR['title'] : ucfirst($DIR['one']) . ' not found';
    if (!$s) { http_response_code(404); }
    require __DIR__ . '/header.php';

    if (!$s): ?>
      <div class="page-narrow">
        <section class="card">
          <h1 class="page-title text-2xl"><?= e(ucfirst($DIR['one'])) ?> not found</h1>
          <p class="page-desc">
            <?= $isAdmin
                ? 'There is no user with that ID. They may have been deleted.'
                : 'There is no active student with that ID. They may have been deactivated or no longer have the student role.' ?>
          </p>
          <a class="btn btn-primary mt-6" href="<?= e($baseUrl) ?>">Back to <?= e($DIR['many']) ?></a>
        </section>
      </div>
      <?php
      require __DIR__ . '/footer.php';
      exit;
    endif;

    // A profile is the lab details only (the workbook's fields).
    $labDefs   = lab_field_defs();
    $labValues = lab_field_values($s);
    $courses   = enrollments_for($pdo, (int)$s['id']);
    $entries   = entries_for($pdo, (int)$s['id']);
    $isSelf        = (int)$s['id'] === (int)current_user_id();
    $isDeactivated = !empty($s['deleted_at']);
    ?>
    <div class="page">
      <a class="back-link" href="<?= e($baseUrl) ?>"><?= pf_icon('back') ?> All <?= e($DIR['many']) ?></a>

      <?php if ($isAdmin && $isDeactivated): ?>
        <?= ui_alert('warning', 'This account is deactivated and cannot sign in.'
            . (!empty($s['scheduled_purge_at']) ? ' Its data is kept until ' . e(date('M j, Y', strtotime((string)$s['scheduled_purge_at']))) . '.' : '')) ?>
      <?php endif; ?>

      <header class="identity">
        <?= $avatar($s, 'avatar-xl') ?>
        <div class="identity-body">
          <h1 class="identity-name"><?= e($s['name']) ?></h1>
          <p class="identity-meta">
            <span><?= e($s['email']) ?></span>
            <?php if ($holdsStudent($s) && $labValues['course'] !== ''): ?><span class="badge"><?= e($labValues['course']) ?></span><?php endif; ?>
            <?php if ($holdsStudent($s) && $labValues['student_id'] !== ''): ?><span>ID <?= e($labValues['student_id']) ?></span><?php endif; ?>
            <?php if ($isAdmin): ?><?= $roleChips($s) ?><?php endif; ?>
            <span>Joined <?= e(date('M j, Y', strtotime((string)$s['created_at']))) ?></span>
          </p>
        </div>
        <div class="flex flex-wrap gap-2">
          <a class="btn btn-secondary" href="mailto:<?= e($s['email']) ?>"><?= pf_icon('mail') ?> Email</a>
          <?php if ($isAdmin): ?>
            <a class="btn btn-primary" href="<?= e($editUrl((int)$s['id'])) ?>"><?= pf_icon('edit') ?> Edit user</a>
          <?php endif; ?>
        </div>
      </header>

      <div class="max-w-3xl">
      <?php if ($isAdmin): ?>
        <section class="section" aria-labelledby="stu-account-h">
          <header class="section-head"><h2 class="section-title" id="stu-account-h">Account</h2></header>
          <dl class="facts">
            <div><dt>Status</dt><dd><?= $statusBadge($s) ?><?= $isSelf ? ' <span class="faint">(this is you)</span>' : '' ?></dd></div>
            <div><dt>Roles</dt><dd><?= $roleChips($s) ?></dd></div>
            <div><dt>Email verified</dt><dd><?= (int)$s['email_verified'] === 1 ? 'Yes' : 'No' ?></dd></div>
            <div><dt>Two-factor sign-in</dt><dd><?= (int)$s['totp_required'] === 1 ? 'On' : 'Off' ?></dd></div>
          </dl>
        </section>
      <?php endif; ?>

      <?php if ($holdsStudent($s)): ?>
      <section class="section" aria-labelledby="stu-lab-h">
        <header class="section-head"><h2 class="section-title" id="stu-lab-h">Lab details</h2></header>
        <dl class="facts is-single is-stacked">
          <?php foreach ($labDefs as $key => $def): ?>
            <div>
              <dt><?= e($def['label']) ?></dt>
              <?php
                  $v = $labValues[$key];
                  if (in_array($key, $PAPER_COLUMNS, true)) { $v = paper_display($v); }  // 3(1)
                  $multi = $key === 'completed_thesis' && str_contains($v, "\n");
                  if ($multi) { $lines = explode("\n", $v); }
              ?>
              <dd class="whitespace-pre-line"><?php if ($v === ''): ?><span class="faint">Not set</span><?php elseif ($multi): ?><?= ui_multi($lines) ?><?php else: ?><?= e($v) ?><?php endif; ?></dd>
            </div>
          <?php endforeach; ?>
        </dl>
      </section>

      <?php // The publications/awards workbook: one section per sheet that has
      // rows. Teachers only: it is lab work, not part of the account.
      foreach ($isAdmin ? [] : lab_entry_defs() as $eKey => $eDef):
          if ($eDef['sheet'] === null || empty($entries[$eKey])) { continue; } ?>
        <section class="section" aria-labelledby="stu-<?= e($eKey) ?>-h">
          <header class="section-head"><h2 class="section-title" id="stu-<?= e($eKey) ?>-h"><?= e($eDef['title']) ?> <span class="section-count"><?= count($entries[$eKey]) ?></span></h2></header>
          <?php foreach ($entries[$eKey] as $r): ?>
            <dl class="facts is-single is-stacked border-b border-layer-line pb-4">
              <?php foreach ($eDef['fields'] as $col => $f):
                  $v = lab_entry_display($f, $r[$col] ?? '');
                  if ($v === '') { continue; } ?>
                <div>
                  <dt><?= e($f['label']) ?></dt>
                  <dd class="whitespace-pre-line<?= $col === 'bibtex' ? ' font-mono text-xs' : '' ?>"><?= e($v) ?></dd>
                </div>
              <?php endforeach; ?>
            </dl>
          <?php endforeach; ?>
        </section>
      <?php endforeach; ?>

      <?php if (count($courses) > 1 || ($courses[0]['completed'] ?? '') !== ''): ?>
        <section class="section" aria-labelledby="stu-courses-h">
          <header class="section-head"><h2 class="section-title" id="stu-courses-h">Courses</h2></header>
          <ul>
            <?php foreach ($courses as $c): ?>
              <li class="flex items-center gap-3 border-b border-layer-line py-3">
                <span class="badge"><?= e($c['course']) ?></span>
                <span class="min-w-0 flex-1 text-sm text-muted-foreground-2"><?= $c['enrolled'] !== '' ? 'Enrolled ' . e($c['enrolled']) : 'Enrolment date not set' ?></span>
                <?php if ($c['completed'] !== ''): ?><span class="badge badge-success">Completed <?= e(date('M Y', strtotime($c['completed']))) ?></span>
                <?php elseif ($c['current']): ?><span class="badge badge-success">Current</span><?php endif; ?>
              </li>
            <?php endforeach; ?>
          </ul>
        </section>
      <?php endif; ?>
      <?php else: ?>
        <p class="mt-8 text-sm text-muted-foreground-1">This account does not have the student role, so it has no lab details.</p>
      <?php endif; ?>
      </div>
    </div>
    <?php
    require __DIR__ . '/footer.php';
    exit;
}

// ============================================================================
// List / sheet
// ============================================================================
$q      = trim((string)($_GET['q'] ?? ''));
$roleFilter   = $isAdmin ? (string)($_GET['role'] ?? '') : '';
$status = $isAdmin ? (string)($_GET['status'] ?? '') : '';
$course = $isAdmin ? '' : (string)($_GET['course'] ?? '');
$sort   = (string)($_GET['sort'] ?? '');
$dir    = strtolower((string)($_GET['dir'] ?? '')) === 'desc' ? 'desc' : 'asc';
if ($sort === 'created') { $sort = 'joined'; }
// Admins get the account list (sortable by name or joined date); teachers
// get the sheet, always in the workbook's order (by course).
$view = $isAdmin ? 'list' : 'sheet';
$sort = $isAdmin ? (in_array($sort, ['name', 'joined'], true) ? $sort : 'name') : 'course';

$where  = [$scopeWhere];
// The sheet is split in two tabs (?group=completed; anything else = current):
//   current    students whose current course is not marked completed
//   completed  one row per completed course, ever: the details frozen
//              when the student marked it completed (course_enrollments
//              .snapshot). A student who went on to a new course is in
//              Current with it, and keeps their earlier rows here.
$group = $view === 'sheet' && (string)($_GET['group'] ?? '') === 'completed' ? 'completed' : 'current';
$groupCounts = [];
if ($view === 'sheet') {
    $base = implode(' AND ', $where);
    $groupCounts['current'] = (int)$pdo->query("SELECT COUNT(*) FROM users u LEFT JOIN profiles p ON p.user_id = u.id WHERE $base AND p.course_completed_at IS NULL")->fetchColumn();
    $groupCounts['completed'] = (int)$pdo->query("SELECT COUNT(*) FROM course_enrollments ce JOIN users u ON u.id = ce.user_id LEFT JOIN profiles p ON p.user_id = u.id WHERE $base AND ce.completed_at IS NOT NULL")->fetchColumn();
    $where[] = $group === 'completed'
        ? 'EXISTS (SELECT 1 FROM course_enrollments ce WHERE ce.user_id = u.id AND ce.completed_at IS NOT NULL)'
        : 'p.course_completed_at IS NULL';
}
$groupLink = function (string $g): string {
    $qs = array_diff_key($_GET, ['done' => 1, 'group' => 1]);
    return '?' . http_build_query(array_merge($qs, $g === 'completed' ? ['group' => 'completed'] : []));
};
$groupTabs = function (string $class = '') use ($group, $groupCounts, $groupLink): string {
    $html = '<nav class="view-tabs' . $class . '" aria-label="Students">';
    foreach (['current' => ['users', 'Current students'], 'completed' => ['cap', 'Completed']] as $g => [$icon, $label]) {
        $html .= '<a href="' . e($groupLink($g)) . '"' . ($group === $g ? ' aria-current="page"' : '') . '>'
            . pf_icon($icon) . '<span>' . e($label) . '</span> <span class="view-tabs-count">' . ($groupCounts[$g] ?? 0) . '</span></a>';
    }
    return $html . '</nav>';
};
$params = [];
if ($q !== '') {
    $cols = ['u.name', 'u.email', 'p.student_id', 'p.name_en', 'p.name_jp', 'p.research_title'];
    $likes = [];
    foreach ($cols as $i => $col) {
        $likes[] = "$col LIKE :q$i";
        $params["q$i"] = '%' . $q . '%';
    }
    $where[] = '(' . implode(' OR ', $likes) . ')';
}
if (in_array($roleFilter, ['admin', 'teacher', 'student'], true)) {
    $where[] = 'EXISTS (SELECT 1 FROM user_roles fr WHERE fr.user_id = u.id AND fr.role = :role)';
    $params['role'] = $roleFilter;
} else {
    $roleFilter = '';
}
if ($status === 'active') {
    $where[] = 'u.deleted_at IS NULL';
} elseif ($status === 'deactivated') {
    $where[] = 'u.deleted_at IS NOT NULL';
} else {
    $status = '';
}
// Course filter (sheet only). Applied to the rows after they are built
// (below), so it also covers the Completed tab's frozen rows and can offer
// "none" (no course yet).
if (!in_array($course, $COURSES, true) && $course !== 'none') {
    $course = '';
}

$st = $pdo->prepare(
    "SELECT $personSelect FROM users u LEFT JOIN profiles p ON p.user_id = u.id
      WHERE " . implode(' AND ', $where) . '
      ORDER BY u.name ASC, u.id ASC'
);
$st->execute($params);
$students = $st->fetchAll();
if ($group === 'completed') {
    // One row per completed course: the person's account columns, with the
    // lab details replaced by what was frozen at completion.
    $people = array_column($students, null, 'id');
    $students = [];
    foreach (completed_courses_for($pdo, array_keys($people)) as $c) {
        $students[] = array_merge($people[$c['user_id']], $c, ['course_completed_at' => $c['completed_at']]);
    }
}

$total = (int)$pdo->query("SELECT COUNT(*) FROM users u WHERE $scopeWhere")->fetchColumn();

// Sheet: rows per course (for the filter chips, before the course filter),
// then keep only the chosen course. Key 'none' = no course yet.
$courseOf = fn(array $s): string => trim((string)($s['course'] ?? '')) !== '' ? trim((string)$s['course']) : 'none';
$courseCounts = [];
if ($view === 'sheet') {
    foreach ($students as $s) { $courseCounts[$courseOf($s)] = ($courseCounts[$courseOf($s)] ?? 0) + 1; }
    uksort($courseCounts, fn($a, $b) => ($COURSE_ORDER[$a] ?? 99) <=> ($COURSE_ORDER[$b] ?? 99));
    if ($course !== '') {
        $students = array_values(array_filter($students, fn($s) => $courseOf($s) === $course));
    }
}

// The workbook sheet lists people by their English name (falling back to
// the account name), so "sort by name" follows the same value there.
$sortName = fn(array $u): string => !$isAdmin && trim((string)($u['name_en'] ?? '')) !== ''
    ? trim((string)$u['name_en']) : (string)$u['name'];
usort($students, function (array $a, array $b) use ($sort, $dir, $sortName, $COURSE_ORDER): int {
    $cmp = match ($sort) {
        'joined'   => strcmp((string)$a['created_at'], (string)$b['created_at']),
        'course'   => ($COURSE_ORDER[$a['course'] ?? ''] ?? 99) <=> ($COURSE_ORDER[$b['course'] ?? ''] ?? 99),
        default    => strcasecmp($sortName($a), $sortName($b)),
    };
    return ($dir === 'desc' ? -$cmp : $cmp) ?: strcasecmp($sortName($a), $sortName($b));
});

$filtered = $q !== '' || $roleFilter !== '' || $status !== '' || $course !== '';

/** One sheet cell: the value, or blank (as in the workbook) when it is empty. */
$cell = fn(?string $v): string => trim((string)$v) !== '' ? e(trim((string)$v)) : '<span class="sr-only">Not set</span>';
$sortLink = function (string $key, string $label) use ($sort, $dir): string {
    $next = ($sort === $key && $dir === 'asc') ? 'desc' : 'asc';
    $qs   = array_diff_key($_GET, ['done' => 1]);
    $href = '?' . http_build_query(array_merge($qs, ['sort' => $key, 'dir' => $next]));
    $mark = $sort === $key ? ($dir === 'asc' ? ' ↑' : ' ↓') : '';
    return '<a class="sort-link" href="' . e($href) . '">' . e($label) . $mark . '</a>';
};
$ariaSort = fn(string $key): string => $sort === $key ? ' aria-sort="' . ($dir === 'asc' ? 'ascending' : 'descending') . '"' : '';
$clearUrl = $baseUrl;
$noun     = fn(int $n): string => $n . ' ' . ($n === 1 ? $DIR['one'] : $DIR['many']);

// Download the sheet as it is shown (tab, search and course filter), with
// the workbook's column headers. UTF-8 with a BOM so Excel reads Japanese.
if ($view === 'sheet' && ($_GET['export'] ?? '') === 'csv') {
    $file = 'students-' . $group . ($course !== '' ? '-' . $course : '') . '-' . date('Y-m-d') . '.csv';
    log_activity(current_user_id(), 'students_export', 'group=' . $group . ($course !== '' ? ';course=' . $course : '') . ($q !== '' ? ';search' : ''));
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $file . '"');
    // A cell starting with = + - @ would run as a formula in Excel: make it text.
    $safe = fn(string $v): string => preg_match('/^[=+\-@\t\r]/', $v) ? "'" . $v : $v;
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    $head = array_map(fn($c) => $c[0], $XLSX_COLUMNS);
    if ($group === 'completed') { $head[] = 'Completed'; }
    fputcsv($out, $head, ',', '"', '', "\r\n");
    foreach ($students as $s) {
        $row = [];
        foreach ($XLSX_COLUMNS as $key => $_) {
            $v = trim((string)($s[$key] ?? ''));
            $row[] = $safe(in_array($key, $PAPER_COLUMNS, true) ? paper_display($v) : $v);
        }
        if ($group === 'completed') { $row[] = str_replace('-', '/', substr((string)$s['course_completed_at'], 0, 7)); }
        fputcsv($out, $row, ',', '"', '', "\r\n");
    }
    exit;
}
$exportUrl  = '?' . http_build_query(array_merge(array_diff_key($_GET, ['done' => 1]), ['export' => 'csv']));
$courseLink = function (string $c): string {
    $qs = array_diff_key($_GET, ['done' => 1, 'course' => 1, 'export' => 1]);
    return '?' . http_build_query(array_merge($qs, $c !== '' ? ['course' => $c] : []));
};
// Teachers' sheet: the first four workbook columns (course, ID, both names)
// stay at the left while scrolling sideways, like frozen panes in Excel.
$FREEZE = ['course' => 'freeze freeze-1', 'student_id' => 'freeze freeze-2', 'name_jp' => 'freeze freeze-3', 'name_en' => 'freeze freeze-4'];

$page_title = $DIR['title'];
require __DIR__ . '/header.php';
?>
<div class="page-wide" x-data="sheet">
  <header class="page-header">
    <div>
      <h1 class="page-title"><?= e($DIR['title']) ?></h1>
      <p class="page-desc">
        <?php if ($filtered): ?>
          <?= e($noun(count($students))) ?> of <?= $total ?> match your filters.
        <?php else: ?>
          <?php if ($view === 'sheet'): ?>
            Everyone’s lab details, laid out like the lab’s workbook. Click a name for the full profile, or download the sheet for Excel.
          <?php else: ?>
            <?= e($noun($total)) ?>. Open a <?= e($DIR['one']) ?> to see their full profile.
          <?php endif; ?>
        <?php endif; ?>
      </p>
    </div>
    <?php if ($isAdmin): ?>
      <a class="btn btn-primary" href="<?= e(url_admin_user()) ?>"><?= pf_icon('plus') ?> New user</a>
    <?php endif; ?>
  </header>

  <?php if ($view === 'sheet'): ?><?= $groupTabs() ?><?php endif; ?>

  <form method="get" class="toolbar" role="search" x-autosubmit>
    <div class="grow search-field">
      <label class="sr-only" for="stu-q">Search <?= e($DIR['many']) ?></label>
      <?= pf_icon('search') ?>
      <input class="input" id="stu-q" name="q" type="search" value="<?= e($q) ?>"
             placeholder="Name, email or student ID"<?= $q !== '' ? ' autofocus' : '' ?>>
    </div>
    <?php if ($isAdmin): ?>
      <div>
        <label class="sr-only" for="stu-role">Role</label>
        <select class="select" id="stu-role" name="role">
          <option value="">All roles</option>
          <?php foreach (['admin', 'teacher', 'student'] as $r): ?>
            <option value="<?= $r ?>"<?= $roleFilter === $r ? ' selected' : '' ?>><?= ucfirst($r) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
    <?php endif; ?>
    <?php if ($isAdmin): ?>
      <div>
        <label class="sr-only" for="stu-status">Status</label>
        <select class="select" id="stu-status" name="status">
          <option value="">Any status</option>
          <option value="active"<?= $status === 'active' ? ' selected' : '' ?>>Active</option>
          <option value="deactivated"<?= $status === 'deactivated' ? ' selected' : '' ?>>Deactivated</option>
        </select>
      </div>
    <?php endif; ?>
    <?php if ($group === 'completed'): ?><input type="hidden" name="group" value="completed"><?php endif; ?>
    <?php if ($view === 'sheet' && $course !== ''): ?><input type="hidden" name="course" value="<?= e($course) ?>"><?php endif; ?>
    <?php if ($isAdmin && ($sort !== 'name' || $dir !== 'asc')): ?>
      <input type="hidden" name="sort" value="<?= e($sort) ?>">
      <input type="hidden" name="dir" value="<?= e($dir) ?>">
    <?php endif; ?>
    <button class="btn btn-secondary" type="submit">Search</button>
    <?php if ($filtered): ?><a class="btn btn-ghost" href="<?= e($clearUrl) ?>">Clear</a><?php endif; ?>

    <div class="ml-auto flex items-center gap-2">
      <?php if ($view === 'sheet' && $students): ?>
        <a class="btn btn-secondary" href="<?= e($exportUrl) ?>" download><?= pf_icon('download') ?> Download CSV</a>
        <button type="button" class="btn btn-secondary" x-ref="enter" @click="set(true)"><?= pf_icon('expand') ?> Full screen</button>
      <?php endif; ?>
    </div>
  </form>

  <?php if ($view === 'sheet'): ?>
    <?php if (count($courseCounts) > 1 || $course !== ''): ?>
      <nav class="filter-chips" aria-label="Filter by course">
        <span class="filter-chips-label">Course</span>
        <a href="<?= e($courseLink('')) ?>"<?= $course === '' ? ' aria-current="page"' : '' ?>>All <span class="tab-count"><?= array_sum($courseCounts) ?></span></a>
        <?php foreach ($courseCounts as $c => $n): ?>
          <a href="<?= e($courseLink($c)) ?>"<?= $course === $c ? ' aria-current="page"' : '' ?>><?= e($c === 'none' ? 'No course' : $c) ?> <span class="tab-count"><?= $n ?></span></a>
        <?php endforeach; ?>
      </nav>
    <?php endif; ?>
  <?php endif; ?>

  <?php if (!$students && $group === 'completed'): ?>
    <div class="empty">
      <h2 class="empty-title"><?= $filtered ? 'No completed ' . e($DIR['many']) . ' match' : 'No completed ' . e($DIR['many']) . ' yet' ?></h2>
      <p>Students appear here once they mark their current course as completed on their profile.</p>
    </div>

  <?php elseif (!$students): ?>
    <div class="empty">
      <h2 class="empty-title"><?= $filtered ? 'No ' . e($DIR['many']) . ' match' : 'No ' . e($DIR['many']) . ' yet' ?></h2>
      <p><?= $filtered ? 'Try a different search or clear the filters.' : ($isAdmin ? 'Create the first one with “New user”.' : 'Accounts with the student role will be listed here. Create an invitation link under Invitations to get started.') ?></p>
      <?php if ($filtered): ?><a class="btn btn-secondary" href="<?= e($clearUrl) ?>">Clear filters</a><?php endif; ?>
    </div>

  <?php elseif ($view === 'sheet'): ?>
    <div class="table-wrap sheet-frame" :class="full && 'is-full'" @keydown.escape.window="full && set(false)">
      <!-- Shown only in full-screen mode. -->
      <div class="sheet-bar">
        <?= $groupTabs(' is-compact') ?>
        <span class="faint"><?= count($students) ?> shown</span>
        <button type="button" class="btn btn-secondary btn-sm" @click="set(false)"><?= pf_icon('collapse') ?> Exit full screen <kbd class="kbd">Esc</kbd></button>
      </div>
      <div class="sheet-scroll" tabindex="0" role="region" aria-label="All <?= e($DIR['one']) ?> information" x-ref="scroll">
        <!-- The workbook's columns ("Information of Lab Students.xlsx"), in
             its order. -->
        <table class="table sheet">
          <thead>
            <tr>
              <?php foreach ($XLSX_COLUMNS as $key => [$heading, $class]): ?>
                <th scope="col" class="whitespace-pre-line! <?= $FREEZE[$key] ?? '' ?>"><?= e($heading) ?></th>
              <?php endforeach; ?>
              <?php if ($group === 'completed'): ?><th scope="col">Completed</th><?php endif; ?>
            </tr>
          </thead>
          <tbody>
            <?php $prevCourse = null; foreach ($students as $s):
                // A line between course groups (the sheet is ordered by course).
                $startsGroup = $sort === 'course' && $prevCourse !== null && $courseOf($s) !== $prevCourse;
                $prevCourse = $courseOf($s); ?>
              <tr<?= $startsGroup ? ' class="group-start"' : '' ?>>
                <?php foreach ($XLSX_COLUMNS as $key => [$heading, $class]):
                    $value = trim((string)($s[$key] ?? ''));
                    $class .= isset($FREEZE[$key]) ? ' ' . $FREEZE[$key] : '';
                ?>
                  <?php if ($key === 'name_en'): ?>
                    <th scope="row" class="<?= $class ?>">
                      <a href="<?= e($baseUrl . '?id=' . (int)$s['id']) ?>"><?= e($value !== '' ? $value : (string)$s['name']) ?></a>
                    </th>
                  <?php elseif (in_array($key, $PAPER_COLUMNS, true) && $value !== ''): ?>
                    <td class="<?= $class ?>"><?= e(paper_display($value)) ?></td>
                  <?php elseif ($key === 'completed_thesis' && $value !== ''): ?>
                    <td class="<?= $class ?>"><?= ui_multi(explode("\n", $value)) ?></td>
                  <?php elseif ($key === 'email' && $value !== ''): ?>
                    <td class="<?= $class ?>"><a href="mailto:<?= e($value) ?>"><?= e($value) ?></a></td>
                  <?php else: ?>
                    <td class="<?= $class ?>"><?= $cell($value) ?></td>
                  <?php endif; ?>
                <?php endforeach; ?>
                <?php if ($group === 'completed'): ?>
                  <td class="nowrap num"><?= e(str_replace('-', '/', substr((string)$s['course_completed_at'], 0, 7))) ?></td>
                <?php endif; ?>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>

  <?php else: ?>
    <div class="table-wrap">
      <div class="table-scroll max-md:hidden">
        <table class="table">
          <thead>
            <tr>
              <th scope="col"<?= $ariaSort('name') ?>><?= $sortLink('name', ucfirst($DIR['one'])) ?></th>
              <th scope="col">Contact</th>
              <th scope="col">Roles</th>
              <th scope="col">Status</th>
              <th scope="col"<?= $ariaSort('joined') ?>><?= $sortLink('joined', 'Joined') ?></th>
              <th scope="col" class="col-actions"><span class="sr-only">Actions</span></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($students as $s):
                $href = $baseUrl . '?id=' . (int)$s['id'];
                $sub  = array_filter([
                    trim((string)($s['student_id'] ?? '')) !== '' ? 'ID ' . $s['student_id'] : '',
                ]);
            ?>
              <tr<?= !empty($s['deleted_at']) ? ' class="is-deactivated"' : '' ?>>
                <th scope="row">
                  <a class="person" href="<?= e($href) ?>">
                    <?= $avatar($s) ?>
                    <span class="min-w-0">
                      <span class="person-name"><?= e($s['name']) ?></span>
                      <span class="person-sub"><?= e(implode(' · ', $sub)) ?></span>
                    </span>
                  </a>
                </th>
                <td>
                  <a href="mailto:<?= e($s['email']) ?>"><?= e($s['email']) ?></a>
                </td>
                <td><?= $roleChips($s) ?></td>
                <td class="nowrap"><?= $statusBadge($s) ?></td>
                <td class="nowrap"><?= e(date('M j, Y', strtotime((string)$s['created_at']))) ?></td>
                <td class="col-actions">
                  <a class="btn btn-secondary btn-sm" href="<?= e($editUrl((int)$s['id'])) ?>" aria-label="Edit <?= e($s['name']) ?>">Edit</a>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>

      <!-- Small screens: one block per person instead of a table -->
      <ul class="card-list md:hidden">
        <?php foreach ($students as $s): ?>
          <li class="card-list-item<?= !empty($s['deleted_at']) ? ' is-deactivated' : '' ?>">
            <a class="person" href="<?= e($baseUrl . '?id=' . (int)$s['id']) ?>">
              <?= $avatar($s) ?>
              <span class="min-w-0">
                <span class="person-name"><?= e($s['name']) ?></span>
                <span class="person-sub"><?= e($s['email']) ?></span>
              </span>
            </a>
            <div class="card-list-meta">
              <?= $roleChips($s) ?>
              <?= $statusBadge($s) ?>
            </div>
            <a class="btn btn-secondary btn-sm self-start" href="<?= e($editUrl((int)$s['id'])) ?>">Edit</a>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/footer.php'; ?>