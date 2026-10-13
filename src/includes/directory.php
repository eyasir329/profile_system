<?php
declare(strict_types=1);

/**
 * People directory: the shared page behind
 *   /students.php      (teacher: students only, read-only)
 *   /admin/index.php   (admin: every user, with create / edit / delete)
 *
 * The including page does the access check and sets $DIR:
 *   scope     'students' | 'users'
 *   base      URL of the including page (links are built from it)
 *   title     page heading, e.g. 'Students'
 *   one, many nouns for counts, e.g. 'student', 'students'
 *
 * Three renderings, all from this file so they cannot drift apart:
 *   ?id=<id>      one person's full profile
 *   (default)     compact list, one "View" per person
 *   ?view=sheet   every field of everyone in one wide table, with a
 *                 "Full screen" button (Alpine `sheet` in resources/js/ui.js)
 *
 * Query params (list / sheet):
 *   q            — name / username / email / profession / lab-field search
 *   degree_type  — 'bachelor' | 'msc' | 'phd'
 *   sort, dir    — name | joined | complete, asc | desc
 *   users scope only:
 *   role         — 'admin' | 'teacher' | 'student'
 *   status       — 'active' | 'deactivated'
 *   course       — one of the profiles.course values
 *
 * The students scope never lists deactivated accounts and has no forms
 * other than the filter. The users scope adds the admin actions, which
 * all post to /admin/user.php.
 */

require_once __DIR__ . '/profile_sections.php';
require_once __DIR__ . '/ui.php';

$isAdmin  = $DIR['scope'] === 'users';
$baseUrl  = (string)$DIR['base'];
$editUrl  = fn(int $id): string => url_admin_user() . '?id=' . $id;

/** Who this directory covers. */
$scopeWhere = $isAdmin
    ? '1 = 1'
    : "u.deleted_at IS NULL AND EXISTS (SELECT 1 FROM user_roles sr WHERE sr.user_id = u.id AND sr.role = 'student')";

$personSelect =
    "u.id, u.name, u.username, u.email, u.email_verified, u.totp_required, u.degree_type,
     u.created_at, u.deleted_at, u.scheduled_purge_at,
     (SELECT GROUP_CONCAT(r.role ORDER BY FIELD(r.role, 'admin', 'teacher', 'student'))
        FROM user_roles r WHERE r.user_id = u.id) AS roles_csv,
     p.*";

$LAB_FIELDS = [
    'course'              => 'Course',
    'student_id'          => 'Student ID',
    'name_en'             => 'Name (EN)',
    'name_jp'             => 'Name (JP)',
    'university_email'    => 'University email',
    'research_title'      => 'Research title',
    'enrolled'            => 'Enrolled',
    'next_step'           => 'Next step',
    'previous_univ_level' => 'Previous university / level',
    'completed_thesis'    => 'Completed thesis',
    'journal_papers'      => 'Journal papers',
    'conference_papers'   => 'Conference papers',
];
$COURSES = ['D1', 'D2', 'D3', 'M1', 'M2', 'B3', 'B4', 'R', 'Other'];

$avatar = fn(array $u, string $size = ''): string => ui_avatar($u['photo'] ?? null, (string)$u['name'], $size);
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

    $degreeType = normalize_degree_type($s['degree_type'] ?? 'bachelor');
    $sections   = load_sections_for_user($pdo, (int)$s['id'], $degreeType);
    $s['enrolled'] = trim(($s['enrolled_month'] ?? '') . ' ' . ($s['enrolled_year'] ?? ''));
    $lab = [];
    foreach ($LAB_FIELDS as $key => $label) {
        if (trim((string)($s[$key] ?? '')) !== '') { $lab[$label] = (string)$s[$key]; }
    }
    $contact = array_filter([
        'Email'   => (string)$s['email'],
        'Phone'   => trim((string)($s['phone'] ?? '')),
        'Address' => trim((string)($s['address'] ?? '')),
    ], fn($v) => $v !== '');
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
            <span>@<?= e($s['username']) ?></span>
            <span class="badge"><?= e(degree_label($degreeType)) ?></span>
            <?php if (trim((string)($s['course'] ?? '')) !== ''): ?><span class="badge"><?= e($s['course']) ?></span><?php endif; ?>
            <?php if ($isAdmin): ?><?= $roleChips($s) ?><?php endif; ?>
            <span>Joined <?= e(date('M j, Y', strtotime((string)$s['created_at']))) ?></span>
          </p>
          <?php if (trim((string)($s['profession'] ?? '')) !== ''): ?>
            <p class="identity-headline"><?= e($s['profession']) ?></p>
          <?php endif; ?>
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

      <section class="section" aria-labelledby="stu-about-h">
        <header class="section-head"><h2 class="section-title" id="stu-about-h">About</h2></header>
        <?php if (trim((string)($s['bio'] ?? '')) !== ''): ?>
          <p class="bio"><?= e($s['bio']) ?></p>
        <?php endif; ?>
        <dl class="facts is-single">
          <?php foreach ($contact as $label => $value): ?>
            <div>
              <dt><?= e($label) ?></dt>
              <dd class="whitespace-pre-line"><?php if ($label === 'Email'): ?><a href="mailto:<?= e($value) ?>"><?= e($value) ?></a><?php else: ?><?= e($value) ?><?php endif; ?></dd>
            </div>
          <?php endforeach; ?>
        </dl>
      </section>

      <?php if ($lab): ?>
        <section class="section" aria-labelledby="stu-lab-h">
          <header class="section-head"><h2 class="section-title" id="stu-lab-h">Lab details</h2></header>
          <dl class="facts is-single is-stacked">
            <?php foreach ($lab as $label => $value): ?>
              <div><dt><?= e($label) ?></dt><dd><?= e($value) ?></dd></div>
            <?php endforeach; ?>
          </dl>
        </section>
      <?php endif; ?>

      <?php
      $emptySections = [];
      foreach ($sections as $key => $rows):
          $def = $SECTIONS[$key];
          if (!$rows) { $emptySections[] = $def['title']; continue; }
          $isTags = pf_is_tag_section($key);
      ?>
        <section class="section" aria-labelledby="stu-h-<?= e($key) ?>">
          <header class="section-head">
            <h2 class="section-title" id="stu-h-<?= e($key) ?>">
              <?= e($def['title']) ?>
              <span class="section-count"><?= count($rows) ?> <?= count($rows) === 1 ? 'entry' : 'entries' ?></span>
            </h2>
          </header>
          <div class="entry-list"<?= $isTags ? ' data-layout="tags"' : '' ?>>
            <?php foreach ($rows as $row): ?>
              <div class="entry"><div class="entry-body"><?= row_summary_html($key, $row, $def, $isTags) ?></div></div>
            <?php endforeach; ?>
          </div>
        </section>
      <?php endforeach; ?>

      <?php if ($emptySections): ?>
        <p class="mt-10 text-sm text-muted-foreground-1">Nothing added yet under: <?= e(implode(', ', $emptySections)) ?>.</p>
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
$degree = (string)($_GET['degree_type'] ?? '');
$roleFilter   = $isAdmin ? (string)($_GET['role'] ?? '') : '';
$status = $isAdmin ? (string)($_GET['status'] ?? '') : '';
$course = $isAdmin ? (string)($_GET['course'] ?? '') : '';
$sort   = (string)($_GET['sort'] ?? 'name');
$dir    = strtolower((string)($_GET['dir'] ?? '')) === 'desc' ? 'desc' : 'asc';
if ($sort === 'created') { $sort = 'joined'; }
if (!in_array($sort, ['name', 'joined', 'complete'], true)) { $sort = 'name'; }
$view   = (string)($_GET['view'] ?? '') === 'sheet' ? 'sheet' : 'list';

$where  = [$scopeWhere];
$params = [];
if ($q !== '') {
    $cols = ['u.name', 'u.username', 'u.email', 'p.profession', 'p.student_id',
             'p.name_en', 'p.name_jp', 'p.university_email', 'p.research_title'];
    $likes = [];
    foreach ($cols as $i => $col) {
        $likes[] = "$col LIKE :q$i";
        $params["q$i"] = '%' . $q . '%';
    }
    $where[] = '(' . implode(' OR ', $likes) . ')';
}
if (isset(DEGREE_TYPES[$degree])) {
    // A NULL degree_type is treated as bachelor everywhere else.
    $where[] = $degree === 'bachelor' ? "(u.degree_type = 'bachelor' OR u.degree_type IS NULL)" : 'u.degree_type = :d';
    if ($degree !== 'bachelor') { $params['d'] = $degree; }
} else {
    $degree = '';
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
if (in_array($course, $COURSES, true)) {
    $where[] = 'p.course = :course';
    $params['course'] = $course;
} else {
    $course = '';
}

$st = $pdo->prepare(
    "SELECT $personSelect FROM users u LEFT JOIN profiles p ON p.user_id = u.id
      WHERE " . implode(' AND ', $where) . '
      ORDER BY u.name ASC, u.id ASC'
);
$st->execute($params);
$students = $st->fetchAll();

$total = (int)$pdo->query("SELECT COUNT(*) FROM users u WHERE $scopeWhere")->fetchColumn();

// Entries per section for everyone at once (one query per section), then
// the same completeness checklist each person sees on their own profile.
$counts = [];
foreach (array_keys($SECTIONS) as $key) {
    foreach ($pdo->query("SELECT user_id, COUNT(*) AS n FROM `$key` GROUP BY user_id") as $r) {
        $counts[(int)$r['user_id']][$key] = (int)$r['n'];
    }
}
foreach ($students as &$s) {
    $s['degree']   = normalize_degree_type($s['degree_type'] ?? 'bachelor');
    $steps         = profile_completion_steps($s, $counts[(int)$s['id']] ?? [], $s['degree']);
    $s['percent']  = (int)round(count(array_filter($steps, fn($x) => $x['done'])) / count($steps) * 100);
    $s['enrolled'] = trim(($s['enrolled_month'] ?? '') . ' ' . ($s['enrolled_year'] ?? ''));
}
unset($s);

usort($students, function (array $a, array $b) use ($sort, $dir): int {
    $cmp = match ($sort) {
        'joined'   => strcmp((string)$a['created_at'], (string)$b['created_at']),
        'complete' => $a['percent'] <=> $b['percent'],
        default    => strcasecmp((string)$a['name'], (string)$b['name']),
    };
    return ($dir === 'desc' ? -$cmp : $cmp) ?: strcasecmp((string)$a['name'], (string)$b['name']);
});

$filtered = $q !== '' || $degree !== '' || $roleFilter !== '' || $status !== '' || $course !== '';

// Sheet view: everything about everyone listed. Entries are loaded per
// section for all of them at once. A teacher only gets the lab columns
// that somebody has filled in; an admin (who maintains them) gets them all.
$sheetSections = [];   // sectionKey => title, for sections at least one listed person has
$entries       = [];   // userId => sectionKey => rows
$labColumns    = [];   // key => label
if ($view === 'sheet' && $students) {
    $ids = implode(',', array_map(fn($s) => (int)$s['id'], $students));
    $degreesShown = array_unique(array_column($students, 'degree'));
    foreach ($SECTIONS as $key => $def) {
        if (!array_filter($degreesShown, fn($d) => ($def['visible_for'])($d))) { continue; }
        $sheetSections[$key] = $def['title'];
        foreach ($pdo->query("SELECT * FROM `$key` WHERE user_id IN ($ids) ORDER BY sort_order, id") as $r) {
            $entries[(int)$r['user_id']][$key][] = $r;
        }
    }
    foreach ($LAB_FIELDS as $key => $label) {
        if ($isAdmin || array_filter($students, fn($s) => trim((string)($s[$key] ?? '')) !== '')) {
            $labColumns[$key] = $label;
        }
    }
}

/** One sheet cell: the value, or a muted dash when it is empty. */
$cell = fn(?string $v): string => trim((string)$v) !== '' ? e(trim((string)$v)) : '<span class="faint" aria-label="Not set">–</span>';
$viewLink = function (string $v): string {
    $qs = array_diff_key($_GET, ['done' => 1]);
    return '?' . http_build_query(array_merge($qs, ['view' => $v]));
};
$sortLink = function (string $key, string $label) use ($sort, $dir): string {
    $next = ($sort === $key && $dir === 'asc') ? 'desc' : 'asc';
    $qs   = array_diff_key($_GET, ['done' => 1]);
    $href = '?' . http_build_query(array_merge($qs, ['sort' => $key, 'dir' => $next]));
    $mark = $sort === $key ? ($dir === 'asc' ? ' ↑' : ' ↓') : '';
    return '<a class="sort-link" href="' . e($href) . '">' . e($label) . $mark . '</a>';
};
$ariaSort = fn(string $key): string => $sort === $key ? ' aria-sort="' . ($dir === 'asc' ? 'ascending' : 'descending') . '"' : '';
$clearUrl = $baseUrl . ($view === 'sheet' ? '?view=sheet' : '');
$noun     = fn(int $n): string => $n . ' ' . ($n === 1 ? $DIR['one'] : $DIR['many']);

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
          <?= e($noun($total)) ?>.
          <?= $isAdmin ? '' : 'Read-only.' ?>
          <?= $view === 'sheet' ? 'Scroll sideways to see every field.' : 'Open a ' . e($DIR['one']) . ' to see their full profile.' ?>
        <?php endif; ?>
      </p>
    </div>
    <div class="flex flex-wrap items-center gap-2">
      <?php if ($isAdmin): ?>
        <a class="btn btn-secondary" href="<?= e(url_invites()) ?>"><?= pf_icon('link') ?> Invite by link</a>
        <a class="btn btn-primary" href="<?= e(url_admin_user()) ?>"><?= pf_icon('plus') ?> New user</a>
      <?php else: ?>
        <a class="btn btn-primary" href="<?= e(url_invites()) ?>"><?= pf_icon('plus') ?> Invite students</a>
      <?php endif; ?>
    </div>
  </header>

  <form method="get" class="toolbar" role="search" x-autosubmit>
    <div class="grow search-field">
      <label class="sr-only" for="stu-q">Search <?= e($DIR['many']) ?></label>
      <?= pf_icon('search') ?>
      <input class="input" id="stu-q" name="q" type="search" value="<?= e($q) ?>"
             placeholder="Name, username, email, profession or student ID"<?= $q !== '' ? ' autofocus' : '' ?>>
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
    <div>
      <label class="sr-only" for="stu-degree">Profile type</label>
      <select class="select" id="stu-degree" name="degree_type">
        <option value="">All profile types</option>
        <?php foreach (DEGREE_TYPES as $dKey => $dLabel): ?>
          <option value="<?= e($dKey) ?>"<?= $degree === $dKey ? ' selected' : '' ?>><?= e($dLabel) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php if ($isAdmin): ?>
      <div>
        <label class="sr-only" for="stu-course">Course</label>
        <select class="select" id="stu-course" name="course">
          <option value="">All courses</option>
          <?php foreach ($COURSES as $c): ?>
            <option value="<?= e($c) ?>"<?= $course === $c ? ' selected' : '' ?>><?= e($c) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div>
        <label class="sr-only" for="stu-status">Status</label>
        <select class="select" id="stu-status" name="status">
          <option value="">Any status</option>
          <option value="active"<?= $status === 'active' ? ' selected' : '' ?>>Active</option>
          <option value="deactivated"<?= $status === 'deactivated' ? ' selected' : '' ?>>Deactivated</option>
        </select>
      </div>
    <?php endif; ?>
    <?php if ($view === 'sheet'): ?><input type="hidden" name="view" value="sheet"><?php endif; ?>
    <?php if ($sort !== 'name' || $dir !== 'asc'): ?>
      <input type="hidden" name="sort" value="<?= e($sort) ?>">
      <input type="hidden" name="dir" value="<?= e($dir) ?>">
    <?php endif; ?>
    <button class="btn btn-secondary" type="submit">Search</button>
    <?php if ($filtered): ?><a class="btn btn-ghost" href="<?= e($clearUrl) ?>">Clear</a><?php endif; ?>

    <div class="ml-auto flex items-center gap-2">
      <?php if ($view === 'sheet' && $students): ?>
        <button type="button" class="btn btn-ghost btn-sm" x-ref="enter" @click="set(true)"><?= pf_icon('expand') ?> Full screen</button>
      <?php endif; ?>
      <div class="segmented" role="group" aria-label="View">
        <a href="<?= e($viewLink('list')) ?>"<?= $view === 'list' ? ' aria-current="true"' : '' ?>>List</a>
        <a href="<?= e($viewLink('sheet')) ?>"<?= $view === 'sheet' ? ' aria-current="true"' : '' ?>>Full sheet</a>
      </div>
    </div>
  </form>

  <?php if (!$students): ?>
    <div class="empty">
      <h2 class="empty-title"><?= $filtered ? 'No ' . e($DIR['many']) . ' match' : 'No ' . e($DIR['many']) . ' yet' ?></h2>
      <p><?= $filtered ? 'Try a different search or clear the filters.' : ($isAdmin ? 'Create the first one with “New user”.' : 'Accounts with the student role will be listed here. Invite students to get started.') ?></p>
      <?php if ($filtered): ?><a class="btn btn-secondary" href="<?= e($clearUrl) ?>">Clear filters</a><?php endif; ?>
    </div>

  <?php elseif ($view === 'sheet'): ?>
    <div class="table-wrap sheet-frame" :class="full && 'is-full'" @keydown.escape.window="full && set(false)">
      <!-- Shown only in full-screen mode. -->
      <div class="sheet-bar">
        <strong><?= e($DIR['title']) ?></strong>
        <span class="faint"><?= count($students) ?><?= $filtered ? ' of ' . $total : '' ?> shown</span>
        <button type="button" class="btn btn-secondary btn-sm" @click="set(false)"><?= pf_icon('collapse') ?> Exit full screen <kbd class="kbd">Esc</kbd></button>
      </div>
      <div class="sheet-scroll" tabindex="0" role="region" aria-label="All <?= e($DIR['one']) ?> information" x-ref="scroll">
        <table class="table sheet">
          <thead>
            <tr>
              <th scope="col" class="pin"<?= $ariaSort('name') ?>><?= $sortLink('name', ucfirst($DIR['one'])) ?></th>
              <?php if ($isAdmin): ?><th scope="col">Actions</th><?php endif; ?>
              <th scope="col">Username</th>
              <?php if ($isAdmin): ?>
                <th scope="col">Roles</th>
                <th scope="col">Status</th>
              <?php endif; ?>
              <th scope="col">Email</th>
              <th scope="col">Phone</th>
              <th scope="col">Address</th>
              <th scope="col">Profile type</th>
              <th scope="col">Profession</th>
              <th scope="col" class="wide">About</th>
              <?php foreach ($labColumns as $label): ?><th scope="col"><?= e($label) ?></th><?php endforeach; ?>
              <?php foreach ($sheetSections as $title): ?><th scope="col" class="wide"><?= e($title) ?></th><?php endforeach; ?>
              <th scope="col"<?= $ariaSort('complete') ?>><?= $sortLink('complete', 'Profile') ?></th>
              <th scope="col"<?= $ariaSort('joined') ?>><?= $sortLink('joined', 'Joined') ?></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($students as $s): ?>
              <tr>
                <th scope="row" class="pin nowrap">
                  <a class="person" href="<?= e($baseUrl . '?id=' . (int)$s['id']) ?>">
                    <?= $avatar($s, 'avatar-sm') ?>
                    <span class="person-name"><?= e($s['name']) ?></span>
                  </a>
                </th>
                <?php if ($isAdmin): ?>
                  <td class="nowrap"><a href="<?= e($editUrl((int)$s['id'])) ?>" aria-label="Edit <?= e($s['name']) ?>">Edit</a></td>
                <?php endif; ?>
                <td class="nowrap">@<?= e($s['username']) ?></td>
                <?php if ($isAdmin): ?>
                  <td class="nowrap"><?= $roleChips($s) ?></td>
                  <td class="nowrap"><?= $statusBadge($s) ?></td>
                <?php endif; ?>
                <td class="nowrap"><a href="mailto:<?= e($s['email']) ?>"><?= e($s['email']) ?></a></td>
                <td class="nowrap"><?= $cell($s['phone'] ?? '') ?></td>
                <td class="pre"><?= $cell($s['address'] ?? '') ?></td>
                <td><span class="badge"><?= e(degree_label($s['degree'])) ?></span></td>
                <td><?= $cell($s['profession'] ?? '') ?></td>
                <td class="wide pre"><?= $cell($s['bio'] ?? '') ?></td>
                <?php foreach ($labColumns as $key => $label): ?><td class="pre"><?= $cell((string)($s[$key] ?? '')) ?></td><?php endforeach; ?>
                <?php foreach ($sheetSections as $key => $title):
                    $def  = $SECTIONS[$key];
                    $rows = $entries[(int)$s['id']][$key] ?? [];
                ?>
                  <td class="wide">
                    <?php if (!($def['visible_for'])($s['degree'])): ?>
                      <span class="faint" title="Not part of a <?= e(degree_label($s['degree'])) ?> profile">n/a</span>
                    <?php elseif (!$rows): ?>
                      <span class="faint" aria-label="None">–</span>
                    <?php else: ?>
                      <ul class="cell-list">
                        <?php foreach ($rows as $row):
                            $part  = row_summary_parts($key, $row, $def);
                            $extra = implode(' · ', array_filter([$part['sub'], $part['tag'], $part['meta']], fn($x) => $x !== ''));
                        ?>
                          <li><strong><?= e($part['title'] !== '' ? $part['title'] : 'Untitled') ?></strong><?= $extra !== '' ? ' <span class="cell-extra">' . e($extra) . '</span>' : '' ?></li>
                        <?php endforeach; ?>
                      </ul>
                    <?php endif; ?>
                  </td>
                <?php endforeach; ?>
                <td class="nowrap num"><?= $s['percent'] ?>%</td>
                <td class="nowrap"><?= e(date('M j, Y', strtotime((string)$s['created_at']))) ?></td>
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
              <?php if ($isAdmin): ?><th scope="col">Roles</th><?php endif; ?>
              <th scope="col">Profile type</th>
              <?php if (!$isAdmin): ?><th scope="col">Profession</th><?php endif; ?>
              <th scope="col"<?= $ariaSort('complete') ?>><?= $sortLink('complete', 'Profile') ?></th>
              <th scope="col"<?= $ariaSort('joined') ?>><?= $sortLink('joined', 'Joined') ?></th>
              <?php if ($isAdmin): ?><th scope="col" class="col-actions"><span class="sr-only">Actions</span></th><?php endif; ?>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($students as $s):
                $href = $baseUrl . '?id=' . (int)$s['id'];
                $sub  = array_filter([
                    '@' . $s['username'],
                    trim((string)($s['student_id'] ?? '')) !== '' ? 'ID ' . $s['student_id'] : '',
                    $isAdmin ? trim((string)($s['profession'] ?? '')) : '',
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
                  <?php if (trim((string)($s['phone'] ?? '')) !== ''): ?><span class="person-sub"><?= e($s['phone']) ?></span><?php endif; ?>
                </td>
                <?php if ($isAdmin): ?>
                  <td>
                    <span class="flex flex-wrap items-center gap-x-3 gap-y-1">
                      <?= $roleChips($s) ?>
                      <?php if (!empty($s['deleted_at'])): ?><span class="badge badge-danger">Deactivated</span><?php endif; ?>
                    </span>
                  </td>
                <?php endif; ?>
                <td class="nowrap">
                  <span class="badge"><?= e(degree_label($s['degree'])) ?></span>
                  <?php if (trim((string)($s['course'] ?? '')) !== ''): ?><span class="badge"><?= e($s['course']) ?></span><?php endif; ?>
                </td>
                <?php if (!$isAdmin): ?>
                  <td><?= trim((string)($s['profession'] ?? '')) !== '' ? e($s['profession']) : '<span class="faint">Not set</span>' ?></td>
                <?php endif; ?>
                <td>
                  <span class="meter" title="Profile <?= $s['percent'] ?>% complete">
                    <span class="progress"><span class="progress-bar" style="width: <?= $s['percent'] ?>%"></span></span>
                    <span class="meter-text"><?= $s['percent'] ?>%</span>
                  </span>
                </td>
                <td class="nowrap"><?= e(date('M j, Y', strtotime((string)$s['created_at']))) ?></td>
                <?php if ($isAdmin): ?>
                  <td class="col-actions">
                    <a class="btn btn-secondary btn-sm" href="<?= e($editUrl((int)$s['id'])) ?>" aria-label="Edit <?= e($s['name']) ?>">Edit</a>
                  </td>
                <?php endif; ?>
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
                <span class="person-sub">@<?= e($s['username']) ?> · <?= e($s['email']) ?></span>
              </span>
            </a>
            <div class="card-list-meta">
              <span class="badge"><?= e(degree_label($s['degree'])) ?></span>
              <?php if ($isAdmin): ?>
                <?= $roleChips($s) ?>
                <?php if (!empty($s['deleted_at'])): ?><span class="badge badge-danger">Deactivated</span><?php endif; ?>
              <?php elseif (trim((string)($s['profession'] ?? '')) !== ''): ?>
                <span><?= e($s['profession']) ?></span>
              <?php endif; ?>
              <span class="ml-auto tabular-nums">Profile <?= $s['percent'] ?>%</span>
            </div>
            <?php if ($isAdmin): ?>
              <a class="btn btn-secondary btn-sm self-start" href="<?= e($editUrl((int)$s['id'])) ?>">Edit</a>
            <?php endif; ?>
          </li>
        <?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/footer.php'; ?>
