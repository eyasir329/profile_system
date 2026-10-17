<?php
/**
 * My profile: the lab details, viewed and edited in place.
 *
 * Route:  GET/POST /profile.php
 * Auth:   require_login()
 * CSRF:   verify_csrf() on POST
 *
 * A profile is the lab fields defined in src/includes/lab_fields.php (the
 * columns of the lab's "Information of Lab Students" workbook). They are
 * shown only while acting as a student; acting as teacher or admin gets a
 * short page with name, email and roles, and every POST is refused.
 *
 * GET   renders the fields. The ones the owner may edit open an inline
 *       form (resources/js/profile-editor.js). The enrolment year and
 *       month start as the date the system set and can be changed.
 * POST  (JSON replies; always the signed-in user's own profile)
 *       op=update_lab     field=<key> value=<text>   save one field; an
 *                         empty value clears it unless it is required.
 *                         Editing `course` or the enrolment date
 *                         corrects the current course.
 *       op=add_course     course=<code>   start a new course: it becomes
 *                         the current one, enrolled today. Only when the
 *                         current course is marked completed (or there is
 *                         none yet).
 *       op=remove_course  id=<n>          remove a past course
 *       op=complete_course  done=1|0      mark the current course
 *                         completed (today), or undo it
 *       op=add_entry      section=<key> <fields>   add an entry to a
 *                         repeating section (src/includes/lab_entries.php)
 *       op=update_entry   section=<key> id=<n> <fields>   change one
 *       op=delete_entry   section=<key> id=<n>            remove one
 *                         Entry replies add { list, count } (the
 *                         section's rows, server-rendered).
 *       Responses: { ok: true, value, filled, total, state }   (state =
 *                    current course, enrolled date and the history HTML)
 *                  { ok: false, field_errors: { <key>: message } }   422
 *                  { ok: false, error: message }                     400 / 403
 *
 * Permissions are documented in docs/PERMISSIONS.md.
 */
declare(strict_types=1);
require_once __DIR__ . '/../src/includes/auth.php';
require_once __DIR__ . '/../src/includes/db.php';
require_once __DIR__ . '/../src/includes/url.php';
require_once __DIR__ . '/../src/includes/validate.php';
require_once __DIR__ . '/../src/includes/lab_fields.php';
require_once __DIR__ . '/../src/includes/lab_entries.php';
require_once __DIR__ . '/../src/includes/ui.php';
require_login();

$userId    = (int)current_user_id();
// Lab details are a student's profile: shown only while acting as a
// student. Acting as teacher or admin gives the plain account page, even
// when the account also holds the student role.
$isStudent = active_user_role() === 'student';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    header('Content-Type: application/json; charset=utf-8');
    if (!$isStudent) {
        op_json_error('Lab details can only be edited while acting as a student.', 403);
        exit;
    }
    try {
        match ((string)($_POST['op'] ?? '')) {
            'update_lab'    => op_update_lab($pdo, $userId),
            'add_course'    => op_add_course($pdo, $userId),
            'remove_course' => op_remove_course($pdo, $userId),
            'complete_course' => op_complete_course($pdo, $userId),
            'add_entry'     => op_save_entry($pdo, $userId, null),
            'update_entry'  => op_save_entry($pdo, $userId, (int)($_POST['id'] ?? 0)),
            'delete_entry'  => op_delete_entry($pdo, $userId),
            default         => op_json_error('Unknown op.'),
        };
    } catch (Throwable $e) {
        error_log('profile op error: ' . $e->getMessage());
        op_json_error('Server error. Please try again.', 500);
    }
    exit;
}

$st = $pdo->prepare(
    'SELECT u.name, u.email, p.*
       FROM users u LEFT JOIN profiles p ON p.user_id = u.id
      WHERE u.id = :id'
);
$st->execute(['id' => $userId]);
$me = $st->fetch() ?: [];
if (!isset($me['user_id'])) {
    // Accounts always get a profiles row at creation; repair it if missing.
    $pdo->prepare('INSERT IGNORE INTO profiles (user_id) VALUES (:id)')->execute(['id' => $userId]);
}

$displayName = trim((string)($me['name'] ?? '')) ?: (string)($me['email'] ?? '');

if (!$isStudent) {
    // Teachers and admins have no lab details: show who they are and
    // point to where their account is managed. Nothing to edit here.
    $acc = $pdo->prepare('SELECT email, created_at FROM users WHERE id = :id');
    $acc->execute(['id' => $userId]);
    $acc = $acc->fetch() ?: [];
    $page_title = 'My account';
    $page_id    = 'profile';
    require __DIR__ . '/../src/includes/header.php';
    ?>
    <div class="page">
      <header class="identity">
        <?= ui_avatar(null, $displayName, 'avatar-xl') ?>
        <div class="identity-body">
          <h1 class="identity-name"><?= e($displayName) ?></h1>
          <p class="identity-meta">
            <span><?= e((string)($acc['email'] ?? '')) ?></span>
            <?= ui_roles(array_values(array_intersect(['admin', 'teacher', 'student'], current_user_roles()))) ?>
          </p>
          <div class="identity-actions">
            <a class="btn btn-secondary btn-sm" href="<?= e(url_settings()) ?>"><?= pf_icon('settings') ?> Account settings</a>
          </div>
        </div>
      </header>
      <p class="max-w-prose text-muted-foreground-2">
        Lab details (course, student ID, research title and so on) are kept for students only, so there is nothing to fill in here.
        <?php if (has_role('student')): ?>To edit yours, switch to acting as a student.<?php endif; ?>
        Your name, email and password are in <a href="<?= e(url_settings()) ?>">Settings</a>.
      </p>
    </div>
    <?php
    require __DIR__ . '/../src/includes/footer.php';
    exit;
}

$LAB       = lab_field_defs();
$labValues = lab_field_values($me);
// The sections the lab fields are shown in, in page order. Every key of
// lab_field_defs() appears in exactly one of them.
$GROUPS = [
    'personal'     => ['title' => 'Personal', 'desc' => 'Your name, student ID and university email.',
                       'fields' => ['name_en', 'name_jp', 'student_id', 'email']],
    'study'        => ['title' => 'Study', 'desc' => 'Your current course, when you started it, where you came from and what comes next.',
                       'fields' => ['course', 'enrolled_year', 'enrolled_month', 'previous_univ_level', 'next_step']],
    'research'     => ['title' => 'Research', 'desc' => 'What you are working on now.',
                       'fields' => ['research_title']],
    // Repeating sections (lab_entry_defs()): the three main-sheet columns,
    // then one section per sheet of the publications workbook.
    'completed_thesis'  => ['entries' => true, 'fields' => ['completed_thesis']],
    'journal_papers'    => ['entries' => true, 'fields' => ['journal_papers']],
    'conference_papers' => ['entries' => true, 'fields' => ['conference_papers']],
    // The four sheets of the publications workbook share one tabbed section.
    'publications'      => ['title' => 'Publications & awards', 'desc' => 'Your published work, talks and awards. Everyone in the lab can see them on the Resources page.',
                            'tabs' => ['pub_journals', 'pub_proceedings', 'pub_presentations', 'awards'], 'fields' => []],
];
$ENTRY_DEFS = lab_entry_defs();
foreach ($ENTRY_DEFS as $k => $d) {
    if (isset($GROUPS[$k])) { $GROUPS[$k] += ['title' => $d['title'], 'desc' => $d['desc']]; }
}
$entries   = entries_for($pdo, $userId);
// Single-value fields only: theses and papers are edited as entries.
$editable  = array_diff_key(array_filter($LAB, fn($d) => $d['owner_edit']), $ENTRY_DEFS);
$courses   = enrollments_for($pdo, $userId);

// Completion checklist (same one the dashboard shows): one step per lab field.
$steps       = profile_completion_steps($me);
$stepsDone   = count(array_filter($steps, fn($s) => $s['done']));
$stepsTotal  = count($steps);
$completePct = (int)round($stepsDone / $stepsTotal * 100);

// Config for the inline editor. Only the fields the owner may edit are
// sent as editable; the admin-only ones never reach the editor (and the
// server refuses them anyway). `all` feeds the completeness meter.
$viewCfg = [
    'csrf' => csrf_token(),
    'lab'  => [
        'fields' => $editable,
        'values' => array_intersect_key($labValues, $editable),
        'all'    => $labValues,
    ],
    'groups'  => array_map(fn($g) => $g['fields'], array_diff_key($GROUPS, $ENTRY_DEFS)),
    'entries' => array_map(fn($d) => ['singular' => $d['singular'], 'fields' => $d['fields'], 'form' => $d['form'] ?? null], $ENTRY_DEFS),
    'courses' => LAB_COURSES,
];
$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE;

$page_title = 'My profile';
$page_id    = 'profile';
require __DIR__ . '/../src/includes/header.php';
?>
<script>window.PS_VIEW_MODE = <?= json_encode($viewCfg, $jsonFlags) ?>;</script>

<div class="page" data-profile>

  <header class="identity">
    <?= ui_avatar(null, $displayName, 'avatar-xl') ?>
    <div class="identity-body">
      <h1 class="identity-name"><?= e($displayName) ?></h1>
      <p class="identity-meta">
        <span data-echo="email"><?= e($labValues['email']) ?></span>
        <span class="badge" data-echo="course"<?= $labValues['course'] === '' ? ' hidden' : '' ?>><?= e($labValues['course']) ?></span>
        <span data-echo="student_id"<?= $labValues['student_id'] === '' ? ' hidden' : '' ?>><?= $labValues['student_id'] !== '' ? 'ID ' . e($labValues['student_id']) : '' ?></span>
      </p>
      <div class="identity-actions">
        <a class="btn btn-ghost btn-sm -ml-3" href="<?= e(url_settings()) ?>"><?= pf_icon('settings') ?> Account settings</a>
      </div>
    </div>

    <div class="completion" data-completion>
      <div class="completion-head">
        <span id="pf-progress-label">Lab details filled in</span>
        <strong data-overall-text><?= $completePct ?>%</strong>
      </div>
      <?= ui_progress($completePct, 'pf-progress-label', ' data-overall-bar', ' data-overall-fill') ?>
      <p class="completion-detail" data-overall-detail>
        <?php if ($stepsDone === $stepsTotal): ?>
          Every field is filled in. Nice work.
        <?php else: ?>
          <?= $stepsDone ?> of <?= $stepsTotal ?> done. Suggested next:
        <?php endif; ?>
      </p>
      <div class="step-list" data-steps>
        <?php $shown = 0; foreach ($steps as $step):
            // Steps only an admin can complete count towards the total but
            // are never offered as something to do here.
            $show = !$step['done'] && $step['owner_edit'] && $shown < 3;
            if ($show) { $shown++; }
        ?>
          <button type="button" class="step" data-step="<?= e($step['key']) ?>"<?= $step['owner_edit'] ? '' : ' data-step-locked' ?><?= $show ? '' : ' hidden' ?>>
            <?= pf_icon('plus') ?> <?= e($step['label']) ?>
          </button>
        <?php endforeach; ?>
      </div>
    </div>
  </header>

  <div class="profile-layout">

    <!-- Section list: a scrolling strip on small screens, a sticky column on wide ones. -->
    <aside class="profile-nav" aria-label="Profile sections">
      <p class="side-nav-title">On this page</p>
      <nav class="side-nav" data-section-nav>
        <?php $first = true; foreach ($GROUPS as $gKey => $g):
            if (!empty($g['tabs'])) {
                $n = array_sum(array_map(fn($t) => count($entries[$t]), $g['tabs']));
                $complete = $n > 0;
                $countHtml = '<span class="side-nav-count" data-entry-total="' . e(implode(' ', $g['tabs'])) . '">' . $n . '</span>';
            } elseif (!empty($g['entries'])) {
                $n = count($entries[$gKey]);
                $complete = $n > 0;
                $countHtml = '<span class="side-nav-count" data-entry-count="' . e($gKey) . '">' . $n . '</span>';
            } else {
                $gDone = count(array_filter($g['fields'], fn($f) => $labValues[$f] !== ''));
                $complete = $gDone === count($g['fields']);
                $countHtml = '<span class="side-nav-count" data-group-count="' . e($gKey) . '">' . $gDone . '/' . count($g['fields']) . '</span>';
            }
        ?>
          <a class="side-nav-item<?= $complete ? ' is-complete' : '' ?>" data-section-link="<?= e($gKey) ?>" href="#sec-<?= e($gKey) ?>" aria-current="<?= $first ? 'true' : 'false' ?>">
            <span><?= e($g['title']) ?></span>
            <?= $countHtml ?>
          </a>
        <?php $first = false; endforeach; ?>
        <a class="side-nav-item" data-section-link="courses" href="#sec-courses" aria-current="false">
          <span>Courses</span>
          <span class="side-nav-count" data-nav-course-count><?= count($courses) ?></span>
        </a>
      </nav>
      <p class="profile-nav-note">Teachers see these details in the Students sheet.</p>
    </aside>

    <!-- ============== Main column ============== -->
    <div class="min-w-0 max-w-3xl">
    <!-- ===== Lab fields, one section per entry of $GROUPS =====
         Owner-editable fields save in place (op=update_lab). The
         enrolment date belongs to the current course and is set by
         the system (see enrollment_add() in lab_fields.php). -->
    <?php foreach ($GROUPS as $gKey => $g):
        if (!empty($g['tabs'])): ?>
    <!-- Tabbed section: one tab per sheet of the publications workbook. -->
    <section class="section" data-profile-section data-section="<?= e($gKey) ?>" id="sec-<?= e($gKey) ?>" aria-labelledby="h-<?= e($gKey) ?>">
      <header class="section-head">
        <div>
          <h2 class="section-title" id="h-<?= e($gKey) ?>"><?= e($g['title']) ?></h2>
          <p class="section-desc"><?= e($g['desc']) ?></p>
        </div>
      </header>
      <div class="tab-list" role="tablist" aria-label="<?= e($g['title']) ?>" data-tabs>
        <?php foreach ($g['tabs'] as $i => $t): ?>
          <button type="button" role="tab" id="tab-<?= e($t) ?>" aria-controls="panel-<?= e($t) ?>"
                  aria-selected="<?= $i === 0 ? 'true' : 'false' ?>"<?= $i === 0 ? '' : ' tabindex="-1"' ?> data-tab="<?= e($t) ?>">
            <?= e($ENTRY_DEFS[$t]['tab']) ?>
            <span class="tab-count" data-entry-count="<?= e($t) ?>"><?= count($entries[$t]) ?></span>
          </button>
        <?php endforeach; ?>
      </div>
      <?php foreach ($g['tabs'] as $i => $t):
          $eDef = $ENTRY_DEFS[$t];
          $n    = count($entries[$t]);
      ?>
        <div role="tabpanel" id="panel-<?= e($t) ?>" aria-labelledby="tab-<?= e($t) ?>" data-tab-panel="<?= e($t) ?>"<?= $i === 0 ? '' : ' hidden' ?>>
          <div class="tab-panel-head">
            <p class="text-sm text-muted-foreground-2"><?= e($eDef['desc']) ?></p>
            <button class="btn btn-secondary btn-sm shrink-0" type="button" data-entry-add="<?= e($t) ?>" aria-label="Add <?= e($eDef['singular']) ?>">
              <?= pf_icon('plus') ?> Add
            </button>
          </div>
          <div class="entry-list" data-entry-list="<?= e($t) ?>"><?= render_entry_list($t, $entries[$t]) ?></div>
          <button type="button" class="section-empty" data-entry-add="<?= e($t) ?>" data-section-empty="<?= e($t) ?>"<?= $n ? ' hidden' : '' ?>>
            <?= pf_icon('plus') ?>
            <span>Add your first <?= e($eDef['singular']) ?></span>
          </button>
        </div>
      <?php endforeach; ?>
    </section>
    <?php continue; endif;
        if (!empty($g['entries'])):
            $eDef  = $ENTRY_DEFS[$gKey];
            $n     = count($entries[$gKey]);
            $old   = $n === 0 ? ($labValues[$gKey] ?? '') : '';
    ?>
    <!-- Repeating section: one entry per item, each saved on its own. -->
    <section class="section" data-profile-section data-section="<?= e($gKey) ?>" id="sec-<?= e($gKey) ?>" aria-labelledby="h-<?= e($gKey) ?>">
      <header class="section-head">
        <div>
          <h2 class="section-title" id="h-<?= e($gKey) ?>">
            <?= e($eDef['title']) ?>
            <span class="section-count" data-section-count="<?= e($gKey) ?>"<?= $n ? '' : ' hidden' ?>><?= $n ?> <?= $n === 1 ? 'entry' : 'entries' ?></span>
          </h2>
          <p class="section-desc"><?= e($eDef['desc']) ?></p>
        </div>
        <button class="btn btn-secondary btn-sm" type="button" data-entry-add="<?= e($gKey) ?>"
                aria-label="Add <?= e($eDef['singular']) ?>">
          <?= pf_icon('plus') ?> Add
        </button>
      </header>
      <?php if ($old !== ''): ?>
        <p class="mt-3 text-sm text-muted-foreground-2" data-entry-legacy="<?= e($gKey) ?>">
          Recorded earlier: <span class="whitespace-pre-line font-medium text-foreground"><?= e($old) ?></span>.
          Adding entries replaces it.
        </p>
      <?php endif; ?>
      <div class="entry-list" data-entry-list="<?= e($gKey) ?>"><?= render_entry_list($gKey, $entries[$gKey]) ?></div>
      <button type="button" class="section-empty" data-entry-add="<?= e($gKey) ?>" data-section-empty="<?= e($gKey) ?>"<?= $n ? ' hidden' : '' ?>>
        <?= pf_icon('plus') ?>
        <span>Add your first <?= e($eDef['singular']) ?></span>
      </button>
    </section>
    <?php continue; endif; ?>
    <section class="section" data-profile-section data-section="<?= e($gKey) ?>" id="sec-<?= e($gKey) ?>" aria-labelledby="h-<?= e($gKey) ?>">
      <header class="section-head" data-section-head>
        <div>
          <h2 class="section-title" id="h-<?= e($gKey) ?>"><?= e($g['title']) ?></h2>
          <p class="section-desc"><?= e($g['desc']) ?></p>
        </div>
      </header>

      <div>
        <?php foreach ($g['fields'] as $lKey):
            $lDef = $LAB[$lKey];
            $val  = $labValues[$lKey];
        ?>
          <?php if ($lDef['owner_edit']): ?>
            <div class="value-card" data-value-card data-section="lab" data-field="<?= e($lKey) ?>"<?= $val === '' ? ' data-empty' : '' ?>>
              <div class="value-card-label" id="lbl-<?= e($lKey) ?>"><?= e($lDef['label']) ?></div>
              <div class="value-card-body" data-value-body>
                <span data-value-display<?= $val === '' ? ' class="muted"' : '' ?>><?= e($val !== '' ? $val : $lDef['empty']) ?></span>
              </div>
              <div data-value-actions>
                <button type="button" class="btn btn-ghost btn-sm -my-1.5" data-value-action="edit"
                        aria-label="<?= $val === '' ? 'Add' : 'Edit' ?> <?= e(strtolower($lDef['label'])) ?>">
                  <?= pf_icon('edit') ?> <span data-action-label><?= $val === '' ? 'Add' : 'Edit' ?></span>
                </button>
              </div>
            </div>
          <?php else: ?>
            <div class="value-card" data-readonly-field="<?= e($lKey) ?>">
              <div class="value-card-label"><?= e($lDef['label']) ?></div>
              <div class="value-card-body">
                <span data-value-display<?= $val === '' ? ' class="muted"' : '' ?>><?= e($val !== '' ? $val : 'Not set') ?></span>
              </div>
              <div data-value-actions>
                <span class="inline-flex items-center gap-1.5 text-sm text-muted-foreground-1">
                  <?= pf_icon('lock') ?> Set by an admin
                </span>
              </div>
            </div>
          <?php endif; ?>
        <?php endforeach; ?>
      </div>
    </section>
    <?php endforeach; ?>

    <!-- ===== Courses: everything this person has been enrolled in ===== -->
    <section class="section" data-profile-section data-section="courses" id="sec-courses" aria-labelledby="h-courses">
      <header class="section-head">
        <div>
          <h2 class="section-title" id="h-courses">Courses</h2>
          <p class="section-desc">One course at a time. When you finish it, mark it as completed; then you can add your next one (for example M1 after B4). It becomes your current course, enrolled today, and the earlier ones stay here.</p>
        </div>
        <?php $canAdd = can_add_course($courses); ?>
        <button type="button" class="btn btn-secondary btn-sm" data-course-add<?= $canAdd ? '' : ' disabled aria-describedby="course-add-why"' ?>><?= pf_icon('plus') ?> Add course</button>
      </header>
      <p class="text-sm text-muted-foreground-1" id="course-add-why" data-course-add-why<?= $canAdd ? ' hidden' : '' ?>>
        Mark your current course as completed to add the next one.
      </p>
      <div data-course-form></div>
      <ul data-course-list><?= render_course_history($courses) ?></ul>
    </section>
    </div>
  </div>
</div>

<?php require __DIR__ . '/../src/includes/footer.php'; ?>

<?php
// ============================================================================
// JSON op
// ============================================================================

function op_json_ok(array $extra = []): void
{
    echo json_encode(array_merge(['ok' => true], $extra), JSON_UNESCAPED_UNICODE);
}

function op_json_field_errors(array $errors, int $status = 422): void
{
    http_response_code($status);
    echo json_encode(['ok' => false, 'field_errors' => $errors], JSON_UNESCAPED_UNICODE);
}

function op_json_error(string $msg, int $status = 400): void
{
    http_response_code($status);
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
}

/** The <li> rows of the Courses list (first paint and op replies). */
function render_course_history(array $courses): string
{
    if (!$courses) {
        return '<li class="py-4 text-sm text-muted-foreground-1">No course yet. Add the one you are in now.</li>';
    }
    $html = '';
    foreach ($courses as $c) {
        $html .= '<li class="flex items-center gap-3 border-b border-layer-line py-3" data-course-row>'
            . '<span class="badge">' . e($c['course']) . '</span>'
            . '<span class="min-w-0 flex-1 text-sm text-muted-foreground-2">'
            . ($c['enrolled'] !== '' ? 'Enrolled ' . e($c['enrolled']) : 'Enrolment date not set')
            . ($c['completed'] !== '' ? ' · Completed ' . e(date('F Y', strtotime($c['completed']))) : '') . '</span>';
        if (!$c['current'] && $c['completed'] !== '') {
            // Completed and moved on: fixed, part of the teachers' record.
            $html .= '<span class="badge">Completed</span>';
        } elseif (!$c['current']) {
            $html .= '<button type="button" class="btn btn-ghost btn-icon" data-course-remove="' . (int)$c['id'] . '"'
                . ' aria-label="Remove ' . e($c['course']) . ' from your courses" title="Remove">' . pf_icon('trash') . '</button>';
        } elseif ($c['completed'] !== '') {
            // The current course is done: say so, and allow undoing a mistake.
            $html .= '<span class="badge badge-success">Completed</span>'
                . '<button type="button" class="btn btn-ghost btn-sm" data-course-complete="0">Undo</button>';
        } else {
            $html .= '<span class="badge badge-success">Current</span>'
                . '<button type="button" class="btn btn-secondary btn-sm" data-course-complete="1">' . pf_icon('check') . ' Mark as completed</button>';
        }
        $html .= '</li>';
    }
    return $html;
}

/** What the page needs to redraw after any change: lab values + history. */
function profile_state(PDO $pdo, int $userId): array
{
    $row = $pdo->prepare('SELECT u.email, p.* FROM users u LEFT JOIN profiles p ON p.user_id = u.id WHERE u.id = :u');
    $row->execute(['u' => $userId]);
    $values = lab_field_values($row->fetch() ?: []);
    return [
        'values'  => $values,
        'filled'  => count(array_filter($values, fn($v) => $v !== '')),
        'total'   => count($values),
        'history' => render_course_history($courses = enrollments_for($pdo, $userId)),
        'can_add_course' => can_add_course($courses),
    ];
}

/**
 * One course at a time: a new course can be added only when there is none
 * yet, or the current one is marked completed.
 */
function can_add_course(array $courses): bool
{
    return !$courses || $courses[0]['completed'] !== '';
}

// -------- add_course --------
// Starts a new course for the signed-in user. It becomes the current
// course, enrolled today; earlier courses stay in the history.
function op_add_course(PDO $pdo, int $userId): void
{
    $course = (string)($_POST['course'] ?? '');
    if (!in_array($course, LAB_COURSES, true)) {
        op_json_field_errors(['course' => 'Choose a course from the list.']);
        return;
    }
    $courses = enrollments_for($pdo, $userId);
    if (!can_add_course($courses)) {
        op_json_field_errors(['course' => 'Mark your current course as completed first.']);
        return;
    }
    $pdo->beginTransaction();
    enrollment_add($pdo, $userId, $course);
    $pdo->commit();
    log_activity($userId, 'profile_update', 'lab:course_add=' . $course);
    $state = profile_state($pdo, $userId);
    op_json_ok(['value' => $course, 'filled' => $state['filled'], 'total' => $state['total'], 'state' => $state]);
}

// -------- remove_course --------
// Removes a past course. The current course cannot be removed, only
// corrected (update_lab) or replaced (add_course).
function op_remove_course(PDO $pdo, int $userId): void
{
    if (!enrollment_remove($pdo, $userId, (int)($_POST['id'] ?? 0))) {
        op_json_error('That course cannot be removed.');
        return;
    }
    log_activity($userId, 'profile_update', 'lab:course_remove');
    $state = profile_state($pdo, $userId);
    op_json_ok(['filled' => $state['filled'], 'total' => $state['total'], 'state' => $state]);
}

// -------- complete_course --------
// Marks the current course completed (or not). Teachers then find the
// student under the Completed tab of the Students page.
function op_complete_course(PDO $pdo, int $userId): void
{
    $done = (string)($_POST['done'] ?? '') === '1';
    if (!enrollment_set_completed($pdo, $userId, $done)) {
        op_json_error('Add your course first.');
        return;
    }
    log_activity($userId, 'profile_update', 'lab:course_' . ($done ? 'completed' : 'reopened'));
    $state = profile_state($pdo, $userId);
    op_json_ok(['filled' => $state['filled'], 'total' => $state['total'], 'state' => $state]);
}

// -------- entries (theses, papers, publications, awards) --------
// Section definitions and storage: src/includes/lab_entries.php.

/** The rows of one repeating section (first paint and op replies). */
function render_entry_list(string $section, array $rows): string
{
    $def  = lab_entry_defs()[$section];
    if ($def['sheet'] !== null) { return render_sheet_entries($section, $rows); }
    $html = '';
    foreach ($rows as $r) {
        $data = [];
        foreach ($def['fields'] as $col => $_) { $data[$col] = (string)($r[$col] ?? ''); }
        $title = $data[$def['row_title']];
        $meta  = array_filter(array_map(fn($c) => lab_entry_display($def['fields'][$c], $r[$c] ?? ''), $def['row_meta']), fn($v) => $v !== '');
        $short = mb_strlen($title, 'UTF-8') > 60 ? mb_substr($title, 0, 60, 'UTF-8') . '…' : $title;
        $name  = e($def['singular'] . ' “' . $short . '”');
        $html .= '<div class="entry" data-entry-row="' . (int)$r['id'] . '" data-entry-section="' . e($section) . '"'
            . ' data-entry="' . e(json_encode($data, JSON_UNESCAPED_UNICODE)) . '">'
            . '<div class="entry-body" data-entry-body>'
            . '<div class="entry-title">' . e($title) . '</div>'
            . ($meta ? '<div class="entry-meta">' . e(implode(' · ', $meta)) . '</div>' : '')
            . '</div>'
            . '<div class="entry-actions" data-entry-actions>'
            . '<button type="button" class="entry-action" data-entry-edit aria-label="Edit ' . $name . '" title="Edit">' . pf_icon('edit') . '</button>'
            . '<button type="button" class="entry-action is-danger" data-entry-delete aria-label="Delete ' . $name . '" title="Delete">' . pf_icon('trash') . '</button>'
            . '</div></div>';
    }
    return $html;
}

/** A month or date for people to read: "Mar 2025", "Nov 1, 2024". */
function entry_human_date(array $field, ?string $v): string
{
    $v = trim((string)$v);
    if ($v === '') { return ''; }
    $ts = strtotime($field['type'] === 'month' ? $v . '-01' : $v);
    return $ts ? date($field['type'] === 'month' ? 'M Y' : 'M j, Y', $ts) : $v;
}

/**
 * Rows of a publications/awards tab, as cards: publications show date,
 * type and impact factor above the citation, with the BibTeX folded away;
 * awards show the award, where and when, and who received it.
 */
function render_sheet_entries(string $section, array $rows): string
{
    $def  = lab_entry_defs()[$section];
    $html = '';
    foreach ($rows as $r) {
        $data = [];
        foreach ($def['fields'] as $col => $_) { $data[$col] = (string)($r[$col] ?? ''); }
        $title = $data[$def['row_title']];
        $short = mb_strlen($title, 'UTF-8') > 60 ? mb_substr($title, 0, 60, 'UTF-8') . '…' : $title;
        $name  = e($def['singular'] . ' “' . $short . '”');
        if ($def['table'] === 'lab_awards') {
            $when  = entry_human_date($def['fields']['award_date'], $data['award_date']);
            $where = implode(' · ', array_filter([$data['organization'], $when], fn($v) => $v !== ''));
            $body  = '<div class="entry-title">' . e($title) . '</div>'
                . ($where !== '' ? '<div class="entry-sub">' . e($where) . '</div>' : '')
                . ($data['details'] !== '' ? '<p class="entry-text">' . e($data['details']) . '</p>' : '')
                . ($data['recipients'] !== '' ? '<div class="entry-meta">Recipients: ' . e($data['recipients']) . '</div>' : '');
        } else {
            $chips = array_filter([
                entry_human_date($def['fields']['pub_month'], $data['pub_month']),
                $data['pub_type'],
                $data['impact_factor'] !== '' ? 'IF ' . $data['impact_factor'] : '',
            ], fn($v) => $v !== '');
            $body = ($chips ? '<div class="entry-chips">' . implode('', array_map(fn($c) => '<span class="badge">' . e($c) . '</span>', $chips)) . '</div>' : '')
                . '<p class="entry-citation">' . e($title) . '</p>'
                . ($data['bibtex'] !== ''
                    ? '<details class="entry-bibtex"><summary>BibTeX</summary>'
                      . '<div class="relative"><pre>' . e($data['bibtex']) . '</pre>'
                      . '<button type="button" class="btn btn-ghost btn-sm entry-copy" data-copy-bibtex>' . pf_icon('copy') . ' Copy</button></div></details>'
                    : '');
        }
        $html .= '<div class="entry" data-entry-row="' . (int)$r['id'] . '" data-entry-section="' . e($section) . '"'
            . ' data-entry="' . e(json_encode($data, JSON_UNESCAPED_UNICODE)) . '">'
            . '<div class="entry-body" data-entry-body>' . $body . '</div>'
            . '<div class="entry-actions" data-entry-actions>'
            . '<button type="button" class="entry-action" data-entry-edit aria-label="Edit ' . $name . '" title="Edit">' . pf_icon('edit') . '</button>'
            . '<button type="button" class="entry-action is-danger" data-entry-delete aria-label="Delete ' . $name . '" title="Delete">' . pf_icon('trash') . '</button>'
            . '</div></div>';
    }
    return $html;
}

/** Reply after any entry change: the section's rows plus the page state. */
function entries_reply(PDO $pdo, int $userId, string $section): void
{
    entries_sync_text($pdo, $userId, $section);
    $rows = entries_for($pdo, $userId)[$section];
    op_json_ok(['list' => render_entry_list($section, $rows), 'count' => count($rows), 'state' => profile_state($pdo, $userId)]);
}

/** The section named in the request, or null. With $id: only if that row is the user's. */
function entry_section_of_request(PDO $pdo, int $userId, ?int $id): ?string
{
    $section = (string)($_POST['section'] ?? '');
    $defs = lab_entry_defs();
    if (!isset($defs[$section])) { return null; }
    if ($id === null) { return $section; }
    [$and, $params] = lab_entry_scope($defs[$section]);
    $st = $pdo->prepare("SELECT 1 FROM `{$defs[$section]['table']}` WHERE id = :id AND user_id = :u$and");
    $st->execute(['id' => $id, 'u' => $userId] + $params);
    return $st->fetchColumn() ? $section : null;
}

// Add ($id null) or change one entry. section=<key> is always sent.
function op_save_entry(PDO $pdo, int $userId, ?int $id): void
{
    $section = entry_section_of_request($pdo, $userId, $id);
    if ($section === null) { op_json_error('That entry no longer exists.', 404); return; }
    $def = lab_entry_defs()[$section];

    $errors = [];
    $row = [];
    foreach ($def['fields'] as $name => $f) {
        $v = trim(str_replace(["\r\n", "\r"], "\n", (string)($_POST[$name] ?? '')));
        if ($v === '' && $f['required'])                       { $errors[$name] = $f['label'] . ' is required.'; continue; }
        if ($f['type'] !== 'textarea' && str_contains($v, "\n")) { $errors[$name] = $f['label'] . ' must be a single line.'; continue; }
        if (mb_strlen($v, 'UTF-8') > $f['max'])                 { $errors[$name] = $f['label'] . ' must be ' . $f['max'] . ' characters or fewer.'; continue; }
        if ($v !== '') {
            $bad = match ($f['type']) {
                'select' => !array_key_exists($v, $f['options']),
                'month'  => !preg_match('/^(19|20)\d\d-(0[1-9]|1[0-2])$/', $v),
                'date'   => !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) || !checkdate((int)substr($v, 5, 2), (int)substr($v, 8, 2), (int)substr($v, 0, 4)),
                'number' => !is_numeric($v) || (float)$v < 0,
                default  => false,
            };
            if ($bad) { $errors[$name] = $f['type'] === 'select' ? 'Choose one from the list.' : 'Enter a valid ' . strtolower($f['label']) . '.'; continue; }
        }
        $row[$name] = $v === '' ? null : $v;
    }
    if ($errors) { op_json_field_errors($errors); return; }

    // Table and column names come from lab_entry_defs(), never from input.
    $table = $def['table'];
    [$and, $scope] = lab_entry_scope($def);
    if ($id === null) {
        $n = $pdo->prepare("SELECT COUNT(*), COALESCE(MAX(sort_order), 0) FROM `$table` WHERE user_id = :u$and");
        $n->execute(['u' => $userId] + $scope);
        [$count, $max] = $n->fetch(PDO::FETCH_NUM);
        if ((int)$count >= 50) { op_json_error('You can add up to 50 entries here.'); return; }
        $cols = array_merge(['user_id', 'sort_order'], $def['kind'] !== null ? ['kind'] : [], array_keys($row));
        $vals = ['user_id' => $userId, 'sort_order' => (int)$max + 1] + ($def['kind'] !== null ? ['kind' => $def['kind']] : []) + $row;
        $pdo->prepare("INSERT INTO `$table` (`" . implode('`, `', $cols) . '`) VALUES (:' . implode(', :', $cols) . ')')
            ->execute($vals);
    } else {
        $set = implode(', ', array_map(fn($c) => "`$c` = :$c", array_keys($row)));
        $pdo->prepare("UPDATE `$table` SET $set WHERE id = :id AND user_id = :u")
            ->execute($row + ['id' => $id, 'u' => $userId]);
    }
    log_activity($userId, 'profile_update', 'lab:' . $section);
    entries_reply($pdo, $userId, $section);
}

function op_delete_entry(PDO $pdo, int $userId): void
{
    $id = (int)($_POST['id'] ?? 0);
    $section = entry_section_of_request($pdo, $userId, $id);
    if ($section === null) { op_json_error('That entry no longer exists.', 404); return; }
    $pdo->prepare('DELETE FROM `' . lab_entry_defs()[$section]['table'] . '` WHERE id = :id AND user_id = :u')
        ->execute(['id' => $id, 'u' => $userId]);
    log_activity($userId, 'profile_update', 'lab:' . $section);
    entries_reply($pdo, $userId, $section);
}

// -------- update_lab --------
// Saves one lab field of the signed-in user's own profile.
// The owner may write only the fields marked owner_edit in
// lab_field_defs(); anything else is refused whatever the client sends
// (an admin changes those on /admin/user.php). An empty value clears the
// field unless it is `required`.
function op_update_lab(PDO $pdo, int $userId): void
{
    $defs  = lab_field_defs();
    $field = (string)($_POST['field'] ?? '');
    if (!isset($defs[$field])) {
        op_json_error('Unknown lab field.');
        return;
    }
    $def = $defs[$field];
    if (isset(lab_entry_defs()[$field])) {
        op_json_error('Add or edit “' . $def['label'] . '” as entries.');
        return;
    }
    if (!$def['owner_edit']) {
        log_activity($userId, 'lab_update_denied', 'field=' . $field);
        op_json_error('Only an admin can change “' . $def['label'] . '”.', 403);
        return;
    }
    $value = trim(str_replace(["\r\n", "\r"], "\n", (string)($_POST['value'] ?? '')));
    $fail  = fn(string $msg) => op_json_field_errors([$field => $msg]);

    if ($value === '' && $def['required']) { $fail($def['label'] . ' cannot be empty.'); return; }
    if (mb_strlen($value, 'UTF-8') > $def['max']) { $fail($def['label'] . ' must be ' . $def['max'] . ' characters or fewer.'); return; }
    if ($def['type'] !== 'textarea' && str_contains($value, "\n")) { $fail($def['label'] . ' must be a single line.'); return; }
    if ($def['type'] === 'select' && $value !== '' && !in_array($value, $def['options'], true)) {
        // Keeping the value already stored is fine even when it is not an option.
        if ((profile_state($pdo, $userId)['values'][$field] ?? '') !== $value) { $fail('Choose one from the list.'); return; }
    }

    if ($field === 'email') {
        // The university email is the account's sign-in address.
        $v = validate_email($value);
        if ($v['errors']) { $fail((string)reset($v['errors'])); return; }
        $value = (string)$v['value'];
        $taken = $pdo->prepare('SELECT 1 FROM users WHERE email = :e AND id <> :u');
        $taken->execute(['e' => $value, 'u' => $userId]);
        if ($taken->fetch()) { $fail('That email is already in use.'); return; }
        $pdo->prepare('UPDATE users SET email = :e WHERE id = :u')->execute(['e' => $value, 'u' => $userId]);
        log_activity($userId, 'account_update', 'email');
    } else {
        if ($field === 'student_id' && ($problem = student_id_problem($pdo, $value, $userId)) !== '') { $fail($problem); return; }
        // $field is a key of lab_field_defs(), never raw input.
        $pdo->prepare("UPDATE profiles SET `$field` = :v WHERE user_id = :u")
            ->execute(['v' => $value === '' ? null : $value, 'u' => $userId]);
        // Editing the course or its date here corrects the current course.
        if (in_array($field, ['course', 'enrolled_year', 'enrolled_month'], true)) { enrollment_sync_current($pdo, $userId); }
        log_activity($userId, 'profile_update', 'lab:' . $field);
    }

    $state = profile_state($pdo, $userId);
    op_json_ok(['value' => $value, 'filled' => $state['filled'], 'total' => $state['total'], 'state' => $state]);
}
