<?php
/**
 * Lab resources: what the whole lab shares, one tab per workbook sheet.
 *
 * Route:  GET  /resources.php?tab=<tab>
 *         POST /resources.php   (teachers and admins only)
 * Auth:   require_login(). Everyone signed in can read every tab.
 * CSRF:   verify_csrf() on POST
 *
 * Tabs:
 *   templates, data     "Information of Lab Students (2).xlsx": Templates
 *                       (one text block per item, lab_templates) and Open
 *                       data and code (Name + Link, lab_resources). Kept
 *                       by teachers: the ACTIVE role must be teacher or
 *                       admin to change them; students only read.
 *   journals            Potential journals, a sheet with the columns Journal
 *                       name, Link, IF, CS, APC, Suggested by, Submitted by,
 *                       Published. Teachers add, edit and delete journals
 *                       (lab_journals). Students take a journal they
 *                       submit to (that is "Submitted by"), mark their own
 *                       submission as published ("Published") or release
 *                       it (lab_journal_takers).
 *   pub_journals, pub_proceedings, pub_presentations, awards
 *                       The publications workbook's sheets
 *                       (PublishedJournals, Conf.Proceedings,
 *                       Conf.PresentationOnly, Awards): every student's
 *                       rows, read-only here for everyone. Each student
 *                       edits their own on /profile.php.
 *
 * POST action=<op>, then redirects back (?tab=..&done=..):
 *   add_template     body
 *   edit_template    id, body
 *   delete_template  id
 *   add_resource     name, link
 *   edit_resource    id, name, link
 *   delete_resource  id
 *   add_journal      name, link, impact_factor, cite_score, apc, suggested_by
 *   edit_journal     id, + the same fields
 *   delete_journal   id
 *   take_journal     id        (students: ACTIVE role student)
 *   release_journal  id        (students)
 *   publish_journal, unpublish_journal  id   (students: their own submission)
 * On a validation error the page is re-rendered with the message and the
 * typed values kept.
 */
declare(strict_types=1);
require_once __DIR__ . '/../src/includes/auth.php';
require_once __DIR__ . '/../src/includes/db.php';
require_once __DIR__ . '/../src/includes/url.php';
require_once __DIR__ . '/../src/includes/validate.php';
require_once __DIR__ . '/../src/includes/ui.php';
require_once __DIR__ . '/../src/includes/lab_entries.php';
require_login();
ensure_active_role_valid();

$userId  = (int)current_user_id();
$suggesterName  = (string)$pdo->query('SELECT name FROM users WHERE id = ' . (int)current_user_id())->fetchColumn();
$canEdit = in_array(active_user_role(), ['teacher', 'admin'], true);
$TABS    = ['templates' => 'Templates', 'data' => 'Open data and code', 'journals' => 'Potential journals'];
$SHEETS  = array_filter(lab_entry_defs(), fn($d) => $d['sheet'] !== null);
foreach ($SHEETS as $k => $d) { $TABS[$k] = $d['sheet']; }
$tab     = isset($TABS[(string)($_GET['tab'] ?? '')]) ? (string)$_GET['tab'] : 'templates';

$errors = [];      // field => message, for the form that was posted
$posted = [];      // what was typed, to put back after an error
$formId = '';      // which form the errors belong to: 'add' or the item id

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');
    // Students take, release and mark published their own journal rows; nothing else.
    $studentJournalActions = ['take_journal' => 'taken', 'release_journal' => 'released', 'publish_journal' => 'published', 'unpublish_journal' => 'unpublished'];
    if (isset($studentJournalActions[$action])) {
        if (active_user_role() !== 'student') {
            http_response_code(403);
            exit('Only students take journals.');
        }
        $id = (int)($_POST['id'] ?? 0);
        $exists = $pdo->prepare('SELECT 1 FROM lab_journals WHERE id = :id');
        $exists->execute(['id' => $id]);
        // Marking published is only for a journal this student has taken.
        $took = $pdo->prepare('SELECT 1 FROM lab_journal_takers WHERE journal_id = :j AND user_id = :u');
        $took->execute(['j' => $id, 'u' => $userId]);
        if (in_array($action, ['publish_journal', 'unpublish_journal'], true) && !$took->fetchColumn()) {
            header('Location: ' . url_resources() . '?tab=journals&done=takefirst');
            exit;
        }
        if ($exists->fetchColumn()) {
            $sql = [
                'take_journal'      => 'INSERT IGNORE INTO lab_journal_takers (journal_id, user_id) VALUES (:j, :u)',
                'release_journal'   => 'DELETE FROM lab_journal_takers WHERE journal_id = :j AND user_id = :u',
                'publish_journal'   => 'UPDATE lab_journal_takers SET published = 1 WHERE journal_id = :j AND user_id = :u',
                'unpublish_journal' => 'UPDATE lab_journal_takers SET published = 0 WHERE journal_id = :j AND user_id = :u',
            ][$action];
            $pdo->prepare($sql)->execute(['j' => $id, 'u' => $userId]);
            log_activity($userId, 'resources_update', $action);
        }
        header('Location: ' . url_resources() . '?tab=journals&done=' . $studentJournalActions[$action] . '#journal-' . $id);
        exit;
    }
    if (!$canEdit) {
        http_response_code(403);
        exit('Only teachers can change lab resources.');
    }
    $id     = (int)($_POST['id'] ?? 0);
    $tab    = str_ends_with($action, '_template') ? 'templates' : (str_ends_with($action, '_journal') ? 'journals' : 'data');
    $formId = str_starts_with($action, 'add_') ? 'add' : (string)$id;
    $back   = fn(string $done) => header('Location: ' . url_resources() . '?tab=' . $tab . '&done=' . $done);

    if ($action === 'add_template' || $action === 'edit_template') {
        $body = trim(str_replace(["\r\n", "\r"], "\n", (string)($_POST['body'] ?? '')));
        $posted = ['body' => $body];
        if ($body === '') { $errors['body'] = 'Write the template text.'; }
        elseif (mb_strlen($body, 'UTF-8') > 10000) { $errors['body'] = 'Keep it under 10,000 characters.'; }
        if (!$errors) {
            if ($action === 'add_template') {
                $pdo->prepare('INSERT INTO lab_templates (body, sort_order, created_by, updated_by)
                               SELECT :b, COALESCE(MAX(sort_order), 0) + 1, :c, :u FROM lab_templates')
                    ->execute(['b' => $body, 'c' => $userId, 'u' => $userId]);
            } else {
                $pdo->prepare('UPDATE lab_templates SET body = :b, updated_by = :u WHERE id = :id')
                    ->execute(['b' => $body, 'u' => $userId, 'id' => $id]);
            }
            log_activity($userId, 'resources_update', $action);
            $back($action === 'add_template' ? 'added' : 'saved');
            exit;
        }
    } elseif ($action === 'add_resource' || $action === 'edit_resource') {
        $name = trim((string)($_POST['name'] ?? ''));
        $link = trim((string)($_POST['link'] ?? ''));
        $posted = ['name' => $name, 'link' => $link];
        if ($name === '') { $errors['name'] = 'Give it a name.'; }
        elseif (mb_strlen($name, 'UTF-8') > 200) { $errors['name'] = 'Keep the name under 200 characters.'; }
        if ($link !== '' && (mb_strlen($link) > 1000 || !filter_var($link, FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $link))) {
            $errors['link'] = 'Enter a full web address starting with https://';
        }
        if (!$errors) {
            $params = ['n' => $name, 'l' => $link === '' ? null : $link, 'u' => $userId];
            if ($action === 'add_resource') {
                $pdo->prepare('INSERT INTO lab_resources (name, link, sort_order, created_by, updated_by)
                               SELECT :n, :l, COALESCE(MAX(sort_order), 0) + 1, :c, :u FROM lab_resources')
                    ->execute($params + ['c' => $userId]);
            } else {
                $pdo->prepare('UPDATE lab_resources SET name = :n, link = :l, updated_by = :u WHERE id = :id')
                    ->execute($params + ['id' => $id]);
            }
            log_activity($userId, 'resources_update', $action);
            $back($action === 'add_resource' ? 'added' : 'saved');
            exit;
        }
    } elseif ($action === 'add_journal' || $action === 'edit_journal') {
        $j = [];
        foreach (['name' => 300, 'link' => 1000, 'impact_factor' => 10, 'cite_score' => 10, 'apc' => 100, 'suggested_by' => 100] as $f => $max) {
            $j[$f] = trim(preg_replace('/\s+/u', ' ', (string)($_POST[$f] ?? '')));   // one line
            if (mb_strlen($j[$f], 'UTF-8') > $max) { $errors[$f] = 'Keep it under ' . $max . ' characters.'; }
        }
        $posted = $j;
        if ($j['name'] === '') { $errors['name'] = 'Give the journal name.'; }
        if ($j['link'] !== '' && (!filter_var($j['link'], FILTER_VALIDATE_URL) || !preg_match('#^https?://#i', $j['link']))) {
            $errors['link'] = 'Enter a full web address starting with https://';
        }
        foreach (['impact_factor' => 'impact factor', 'cite_score' => 'CiteScore'] as $f => $label) {
            if ($j[$f] !== '' && (!is_numeric($j[$f]) || (float)$j[$f] < 0)) { $errors[$f] = 'Enter a number for the ' . $label . ', e.g. 3.4'; }
        }
        if (!$errors) {
            $v = array_map(fn($x) => $x === '' ? null : $x, $j);
            if ($action === 'add_journal') {
                $pdo->prepare('INSERT INTO lab_journals (name, link, impact_factor, cite_score, apc, suggested_by, sort_order, created_by, updated_by)
                               SELECT :name, :link, :impact_factor, :cite_score, :apc, :suggested_by, COALESCE(MAX(sort_order), 0) + 1, :c, :u FROM lab_journals')
                    ->execute($v + ['c' => $userId, 'u' => $userId]);
            } else {
                $pdo->prepare('UPDATE lab_journals SET name = :name, link = :link, impact_factor = :impact_factor, cite_score = :cite_score,
                                      apc = :apc, suggested_by = :suggested_by, updated_by = :u WHERE id = :id')
                    ->execute($v + ['u' => $userId, 'id' => $id]);
            }
            log_activity($userId, 'resources_update', $action);
            $back($action === 'add_journal' ? 'added' : 'saved');
            exit;
        }
    } elseif (in_array($action, ['delete_template', 'delete_resource', 'delete_journal'], true)) {
        $table = ['delete_template' => 'lab_templates', 'delete_resource' => 'lab_resources', 'delete_journal' => 'lab_journals'][$action];
        $pdo->prepare("DELETE FROM `$table` WHERE id = :id")->execute(['id' => $id]);
        log_activity($userId, 'resources_update', $action);
        $back('deleted');
        exit;
    } else {
        http_response_code(400);
        exit('Unknown action.');
    }
    http_response_code(422);
}

$templates = $pdo->query(
    'SELECT t.id, t.body, t.updated_at, u.name AS editor
       FROM lab_templates t LEFT JOIN users u ON u.id = t.updated_by
      ORDER BY t.sort_order, t.id'
)->fetchAll();
$resources = $pdo->query(
    'SELECT r.id, r.name, r.link, r.updated_at, u.name AS editor
       FROM lab_resources r LEFT JOIN users u ON u.id = r.updated_by
      ORDER BY r.sort_order, r.id'
)->fetchAll();

// Potential journals, each with who took it (takers: journal id => user id => [name, published]).
$journals = $pdo->query(
    'SELECT j.id, j.name, j.link, j.impact_factor, j.cite_score, j.apc, j.suggested_by, j.updated_at, u.name AS editor
       FROM lab_journals j LEFT JOIN users u ON u.id = j.updated_by
      ORDER BY j.sort_order, j.id'
)->fetchAll();
$takers = [];
foreach ($pdo->query(
    "SELECT t.journal_id, t.user_id, t.published, COALESCE(NULLIF(TRIM(p.name_en), ''), u.name) AS name
       FROM lab_journal_takers t JOIN users u ON u.id = t.user_id LEFT JOIN profiles p ON p.user_id = u.id
      WHERE u.deleted_at IS NULL
      ORDER BY t.taken_at"
) as $t) {
    $takers[(int)$t['journal_id']][(int)$t['user_id']] = ['name' => (string)$t['name'], 'published' => (bool)$t['published']];
}

// Rows of every publications/awards sheet (counts for the tabs; the
// open tab's rows for its table).
$sheetRows = [];
foreach ($SHEETS as $k => $_) { $sheetRows[$k] = entries_for_sheet($pdo, $k); }
$tabCount = fn(string $t): int => count(match ($t) { 'templates' => $templates, 'data' => $resources, 'journals' => $journals, default => $sheetRows[$t] });

// Plain text with web addresses made clickable (escaped first).
$linkify = fn(string $text): string => preg_replace_callback('#https?://[^\s<>"\']+#i',
    fn($m) => '<a href="' . $m[0] . '" target="_blank" rel="noopener noreferrer">' . $m[0] . '</a>', e($text));

$flash = [];
$doneMessages = ['added' => 'Added.', 'saved' => 'Changes saved.', 'deleted' => 'Deleted.',
                 'taken' => 'Journal taken. Your teachers can see it.', 'released' => 'Journal released.',
                 'published' => 'Marked as published.', 'unpublished' => 'Marked as not published.',
                 'takefirst' => 'Take the journal first, then you can mark it as published.'];
if (isset($doneMessages[(string)($_GET['done'] ?? '')])) {
    $flash[] = ['type' => 'success', 'body' => $doneMessages[(string)$_GET['done']]];
}

// Error line for one field of the form that was posted.
$err = fn(string $form, string $field): string => $formId === $form && isset($errors[$field])
    ? '<p class="field-error" role="alert">' . e($errors[$field]) . '</p>' : '';
$invalid = fn(string $form, string $field): string => $formId === $form && isset($errors[$field]) ? ' aria-invalid="true"' : '';
// Value to show in a form: what was typed after an error, else the stored one.
$val = fn(string $form, string $field, string $stored = ''): string => $formId === $form && isset($posted[$field]) ? $posted[$field] : $stored;
$edited = fn(array $r): string => 'Updated ' . date('M j, Y', strtotime((string)$r['updated_at'])) . ($r['editor'] ? ' by ' . $r['editor'] : '');
$csrf = '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';

$page_title = 'Lab resources';
$page_id    = 'resources';
require __DIR__ . '/../src/includes/header.php';
?>
<?php // One width for every tab, so the header and tabs never jump when switching; card tabs keep a readable column inside. ?>
<div class="page-wide" x-data="sheet">
  <header class="page-header">
    <div>
      <h1 class="page-title">Lab resources</h1>
      <p class="page-desc">
        <?php if (isset($SHEETS[$tab])): ?>
          Everyone’s <?= e(strtolower($SHEETS[$tab]['title'])) ?> in the lab. Students add their own on My profile.
        <?php elseif ($tab === 'journals'): ?>
          <?= $canEdit
              ? 'Journals worth submitting to, with impact factor (IF), CiteScore (CS) and article processing charge (APC). Students take the ones they submit to and mark them published.'
              : 'Journals your teachers suggest. Take the ones you submit to, and mark them published once your paper is out. Everyone can see who submitted where.' ?>
        <?php elseif ($canEdit): ?>
          Templates and open data shared with every student in the lab. You can add, edit and delete them; students can only read.
        <?php else: ?>
          Templates and open data shared by your teachers. Copy a template, or open a link.
        <?php endif; ?>
      </p>
    </div>
    <?php if ((isset($SHEETS[$tab]) && $sheetRows[$tab]) || ($tab === 'journals' && $journals)): ?>
      <button type="button" class="btn btn-ghost btn-sm" x-ref="enter" @click="set(true)"><?= pf_icon('expand') ?> Full screen</button>
    <?php endif; ?>
  </header>

  <?php
  // The tab row: above the content, and again in the full-screen bar.
  // The tabs come in two kinds, shown as two labelled groups: what teachers
  // share with the lab, and the students' publication sheets. The
  // full-screen bar gets them as one compact row.
  $tabNav = function (string $class = '') use ($TABS, $tab, $tabCount, $SHEETS): string {
      $link = fn(string $t): string => '<a href="' . e(url_resources() . '?tab=' . $t) . '"' . ($tab === $t ? ' aria-current="page"' : '') . '>'
          . e($TABS[$t]) . ' <span class="tab-count">' . $tabCount($t) . '</span></a>';
      if ($class !== '') {
          return '<nav class="sheet-tabs' . $class . '" aria-label="Resources">' . implode('', array_map($link, array_keys($TABS))) . '</nav>';
      }
      $groups = ['Shared by teachers' => ['templates', 'data', 'journals'], 'Lab publications' => array_keys($SHEETS)];
      $html = '<div class="tab-groups">';
      foreach ($groups as $label => $keys) {
          $html .= '<div class="tab-group"><p class="tab-group-label">' . e($label) . '</p>'
              . '<nav class="sheet-tabs is-wrap" aria-label="' . e($label) . '">' . implode('', array_map($link, $keys)) . '</nav></div>';
      }
      return $html . '</div>';
  };
  // A sheet's toolbar: instant filter, "Only mine" (students with rows of
  // their own), and how many rows are showing. Used inside x-data="sheetFilter".
  $sheetTools = function (bool $withMine, string $placeholder): string {
      return '<div class="sheet-tools">'
          . '<div class="search-field"><label class="sr-only" for="sheet-q">Filter rows</label>' . pf_icon('search')
          . '<input class="input" id="sheet-q" type="search" x-model.debounce.150ms="q" placeholder="' . e($placeholder) . '" autocomplete="off"></div>'
          . ($withMine ? '<label class="check"><input type="checkbox" x-model="mine"> Only mine</label>' : '')
          . '<span class="sheet-tools-count" aria-live="polite" x-text="shown === total ? total + (total === 1 ? \' row\' : \' rows\') : \'Showing \' + shown + \' of \' + total"></span>'
          . '</div>';
  };
  // A header cell that sorts the sheet by its column (in the browser).
  $sortTh = fn(int $i, string $label, string $class = ''): string =>
      '<th scope="col" class="' . $class . '" :aria-sort="ariaSort(' . $i . ')"><button type="button" class="sort-btn" @click="sort(' . $i . ')">'
      . e($label) . ' <span aria-hidden="true" class="font-normal" :class="sortCol === ' . $i . ' ? \'text-primary\' : \'opacity-35\'" x-text="arrow(' . $i . ')">↕</span></button></th>';
  // Shown when the filter leaves no rows.
  $noMatch = '<p class="py-8 text-center text-sm text-muted-foreground-2" x-show="shown === 0" x-cloak>No rows match. <button type="button" class="font-semibold text-primary" @click="q = \'\'; mine = false">Clear the filter</button></p>';
  ?>
  <?= $tabNav() ?>

  <?php if (isset($SHEETS[$tab])):
      $sDef = $SHEETS[$tab];
      $rows = $sheetRows[$tab];
      $isStaff = in_array(active_user_role(), ['teacher', 'admin'], true); ?>
    <!-- One sheet of the publications workbook: every student's rows, with
         the workbook's columns. Read-only; students edit theirs on My profile. -->
    <?php if (!$rows): ?>
      <div class="empty">
        <h2 class="empty-title">Nothing here yet</h2>
        <p>Students add these on My profile, under “Publications &amp; awards”.</p>
        <?php if (active_user_role() === 'student'): ?><a class="btn btn-secondary" href="<?= e(url_profile()) ?>#sec-publications">Add yours</a><?php endif; ?>
      </div>
    <?php else:
        $hasMine = active_user_role() === 'student' && in_array($userId, array_map(fn($r) => (int)$r['user_id'], $rows), true); ?>
      <div x-data="sheetFilter">
      <?= $sheetTools($hasMine, 'Filter by student, title, year…') ?>
      <div class="table-wrap sheet-frame" :class="full && 'is-full'" @keydown.escape.window="full && set(false)">
        <!-- Shown only in full-screen mode. -->
        <div class="sheet-bar">
          <?= $tabNav(' is-compact') ?>
          <span class="faint" x-text="shown + ' of ' + total"><?= count($rows) ?></span>
          <button type="button" class="btn btn-secondary btn-sm" @click="set(false)"><?= pf_icon('collapse') ?> Exit full screen <kbd class="kbd">Esc</kbd></button>
        </div>
        <div class="sheet-scroll" tabindex="0" role="region" aria-label="<?= e($sDef['sheet']) ?>" x-ref="scroll">
          <table class="table sheet">
            <thead>
              <tr>
                <?= $sortTh(0, 'Student', 'pin') ?>
                <?php $i = 1; foreach ($sDef['fields'] as $f): ?>
                  <?= $sortTh($i++, $f['heading'] ?? $f['label'], 'whitespace-pre-line!') ?>
                <?php endforeach; ?>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($rows as $r): ?>
                <tr<?= (int)$r['user_id'] === $userId ? ' class="is-mine"' : '' ?>>
                  <th scope="row" class="pin nowrap">
                    <?php if ($isStaff): ?>
                      <a href="<?= e(url_students() . '?id=' . (int)$r['user_id']) ?>"><?= e($r['student_name']) ?></a>
                    <?php else: ?>
                      <?= e($r['student_name']) ?><?= (int)$r['user_id'] === $userId ? ' <span class="badge">You</span>' : '' ?>
                    <?php endif; ?>
                  </th>
                  <?php foreach ($sDef['fields'] as $col => $f):
                      $v = lab_entry_display($f, $r[$col] ?? '');
                      $cls = $f['type'] === 'textarea' ? 'wide pre' . ($col === 'bibtex' ? ' font-mono text-xs' : '') : 'nowrap' . (in_array($f['type'], ['number', 'month', 'date'], true) ? ' num' : '');
                  ?>
                    <?php if ($col === 'recipients' && preg_match('/[,;\n]/', $v)): ?>
                      <td class="<?= $cls ?>"><?= ui_multi(preg_split('/\s*[,;\n]\s*/', $v)) ?></td>
                    <?php else: ?>
                      <td class="<?= $cls ?>"><?= $v !== '' ? e($v) : '<span class="sr-only">Not set</span>' ?></td>
                    <?php endif; ?>
                  <?php endforeach; ?>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?= $noMatch ?>
      </div>
      </div>
    <?php endif; ?>

  <?php else: ?>
  <div class="<?= $tab === 'journals' ? '' : 'max-w-3xl' ?>">
  <?php if ($tab === 'journals'):
      $isStudent = active_user_role() === 'student';
      // Teachers: ?edit=<id> (or a failed save of that journal) opens the form for it.
      $editJournal = null;
      if ($canEdit) {
          $editId = ctype_digit($formId) ? (int)$formId : (int)($_GET['edit'] ?? 0);
          foreach ($journals as $j) { if ((int)$j['id'] === $editId) { $editJournal = $j; } }
      }
      $fid = $editJournal ? (string)$editJournal['id'] : 'add';
      $stored = fn(string $f, string $default = '') => $editJournal ? (string)($editJournal[$f] ?? '') : $default;
      // One input of the journal form: column, label, extra attributes, grid width.
      $jIn = function (string $f, string $label, string $attrs, string $span, string $default = '') use ($fid, $val, $err, $invalid, $stored): string {
          return '<div class="field mb-0 ' . $span . '"><label class="label" for="j-' . $f . '">' . $label . '</label>'
              . '<input class="input" id="j-' . $f . '" name="' . $f . '" ' . $attrs
              . ' value="' . e($val($fid, $f, $stored($f, $default))) . '"' . $invalid($fid, $f) . '>' . $err($fid, $f) . '</div>';
      };
      // The Add / Edit form card (teachers).
      $journalForm = function () use ($csrf, $editJournal, $fid, $jIn, $suggesterName): string {
          return '<section class="card max-w-3xl ' . ($editJournal ? 'mb-6' : 'mt-6') . '" id="journal-form" aria-labelledby="j-form-h">'
              . '<h2 class="card-title" id="j-form-h">' . ($editJournal ? pf_icon('edit') . ' Edit journal' : pf_icon('plus') . ' Add a potential journal') . '</h2>'
              . '<form method="post" action="' . e(url_resources()) . '" class="mt-3">' . $csrf
              . '<input type="hidden" name="action" value="' . ($editJournal ? 'edit_journal' : 'add_journal') . '">'
              . ($editJournal ? '<input type="hidden" name="id" value="' . (int)$editJournal['id'] . '">' : '')
              . '<div class="grid gap-3 sm:grid-cols-6">'
              . $jIn('name', 'Journal name', 'maxlength="300" required placeholder="e.g. IEEE Access"', 'sm:col-span-3')
              . $jIn('link', 'Link', 'type="url" maxlength="1000" placeholder="https://"', 'sm:col-span-3')
              . $jIn('impact_factor', 'IF <span class="font-normal text-muted-foreground-1">(impact factor)</span>', 'maxlength="10" inputmode="decimal" placeholder="e.g. 3.4"', 'sm:col-span-2')
              . $jIn('cite_score', 'CS <span class="font-normal text-muted-foreground-1">(CiteScore)</span>', 'maxlength="10" inputmode="decimal" placeholder="e.g. 9.8"', 'sm:col-span-2')
              . $jIn('apc', 'APC <span class="font-normal text-muted-foreground-1">(publication charge)</span>', 'maxlength="100" placeholder="e.g. $1,950 or Free"', 'sm:col-span-2')
              . $jIn('suggested_by', 'Suggested by', 'maxlength="100"', 'sm:col-span-6', $suggesterName)
              . '</div><div class="form-actions mt-3">'
              . ($editJournal ? '<a class="btn btn-ghost" href="' . e(url_resources() . '?tab=journals') . '">Cancel</a>' : '')
              . '<button type="submit" class="btn btn-primary">' . ($editJournal ? 'Save' : 'Add journal') . '</button>'
              . '</div></form></section>';
      };
      // Small POST button (one journal, one action).
      $jBtn = fn(string $act, int $id, string $label, string $cls = 'btn-secondary') =>
          '<form method="post" action="' . e(url_resources()) . '" class="inline">' . $csrf
          . '<input type="hidden" name="action" value="' . $act . '"><input type="hidden" name="id" value="' . $id . '">'
          . '<button class="btn ' . $cls . ' btn-sm" type="submit">' . $label . '</button></form>'; ?>
    <!-- ===== Potential journals: a sheet. Teachers list them; students take them ===== -->
    <?php if ($editJournal): ?><?= $journalForm() ?><?php endif; ?>

    <?php if (!$journals): ?>
      <div class="empty">
        <h2 class="empty-title">No journals yet</h2>
        <p><?= $canEdit ? 'Add the first one below.' : 'Your teachers have not suggested any journals.' ?></p>
      </div>
    <?php else:
        $hasMine = $isStudent && array_filter($takers, fn($t) => isset($t[$userId])); ?>
      <div x-data="sheetFilter">
      <?= $sheetTools((bool)$hasMine, 'Filter by journal, publisher, person…') ?>
      <div class="table-wrap sheet-frame" :class="full && 'is-full'" @keydown.escape.window="full && set(false)">
        <!-- Shown only in full-screen mode. -->
        <div class="sheet-bar">
          <?= $tabNav(' is-compact') ?>
          <span class="faint" x-text="shown + ' of ' + total"><?= count($journals) ?></span>
          <button type="button" class="btn btn-secondary btn-sm" @click="set(false)"><?= pf_icon('collapse') ?> Exit full screen <kbd class="kbd">Esc</kbd></button>
        </div>
        <div class="sheet-scroll" tabindex="0" role="region" aria-label="Potential journals" x-ref="scroll">
          <table class="table sheet">
            <thead>
              <tr>
                <?= $sortTh(0, 'Journal name', 'pin') ?>
                <?= $sortTh(1, 'Link') ?>
                <?= $sortTh(2, 'IF') ?>
                <?= $sortTh(3, 'CS') ?>
                <?= $sortTh(4, 'APC') ?>
                <?= $sortTh(5, 'Suggested by') ?>
                <?= $sortTh(6, 'Submitted by') ?>
                <?= $sortTh(7, 'Published') ?>
                <th scope="col" class="pin-end max-lg:hidden"><span class="sr-only">Actions</span></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($journals as $j):
                  $jid  = (int)$j['id'];
                  $who  = $takers[$jid] ?? [];
                  $mine = isset($who[$userId]);
                  $nameOf = fn(array $t, int $uid): string => $t['name'] . ($uid === $userId ? ' (you)' : '');
                  $submitted = array_map($nameOf, $who, array_keys($who));
                  $published = [];
                  foreach ($who as $uid => $t) { if ($t['published']) { $published[] = $nameOf($t, $uid); } }
                  $host = $j['link'] ? (parse_url((string)$j['link'], PHP_URL_HOST) ?: (string)$j['link']) : '';
                  $blank = '<span class="sr-only">Not set</span>'; ?>
                <tr id="journal-<?= $jid ?>"<?= $mine ? ' class="is-mine"' : '' ?>>
                  <?php ob_start(); ?>
                    <?php if ($isStudent): ?>
                      <?php if (!$mine): ?>
                        <?= $jBtn('take_journal', $jid, pf_icon('check') . ' Take', 'btn-primary') ?>
                      <?php else: ?>
                        <?= $jBtn($who[$userId]['published'] ? 'unpublish_journal' : 'publish_journal', $jid, $who[$userId]['published'] ? 'Unmark published' : 'Mark published') ?>
                        <?= $jBtn('release_journal', $jid, 'Release', 'btn-ghost') ?>
                      <?php endif; ?>
                    <?php elseif ($canEdit): ?>
                      <a class="btn btn-ghost btn-sm" href="<?= e(url_resources() . '?tab=journals&edit=' . $jid . '#journal-form') ?>"><?= pf_icon('edit') ?> Edit</a>
                      <form method="post" action="<?= e(url_resources()) ?>" class="inline" x-confirm
                            data-confirm-title="Delete “<?= e(mb_strimwidth((string)$j['name'], 0, 60, '…', 'UTF-8')) ?>”?"
                            data-confirm-body="<?= $who ? 'It is removed for everyone, including the ' . count($who) . ' ' . (count($who) === 1 ? 'student who took it' : 'students who took it') . '.' : 'Students will no longer see it.' ?>"
                            data-confirm-ok="Delete" data-confirm-cancel="Keep it" data-confirm-tone="danger">
                        <?= $csrf ?>
                        <input type="hidden" name="action" value="delete_journal">
                        <input type="hidden" name="id" value="<?= $jid ?>">
                        <button class="btn btn-danger-ghost btn-sm" type="submit" aria-label="Delete <?= e($j['name']) ?>"><?= pf_icon('trash') ?></button>
                      </form>
                    <?php endif; ?>
                  <?php $actionsHtml = trim(ob_get_clean()); ?>
                  <th scope="row" class="pin min-w-44 max-w-xs font-semibold sm:min-w-56">
                    <?= e($j['name']) ?>
                    <?php if ($actionsHtml !== ''): ?><div class="mt-2 flex flex-wrap gap-1 font-normal lg:hidden"><?= $actionsHtml ?></div><?php endif; ?>
                  </th>
                  <td class="nowrap">
                    <?php if ($j['link']): ?>
                      <a href="<?= e($j['link']) ?>" target="_blank" rel="noopener noreferrer" title="<?= e($j['link']) ?>"><?= e(preg_replace('/^www\./i', '', $host)) ?></a>
                    <?php else: ?><?= $blank ?><?php endif; ?>
                  </td>
                  <td class="nowrap num"><?= $j['impact_factor'] !== null && $j['impact_factor'] !== '' ? e($j['impact_factor']) : $blank ?></td>
                  <td class="nowrap num"><?= $j['cite_score'] !== null && $j['cite_score'] !== '' ? e($j['cite_score']) : $blank ?></td>
                  <td class="nowrap"><?= $j['apc'] !== null && $j['apc'] !== '' ? e($j['apc']) : $blank ?></td>
                  <td class="nowrap"><?= $j['suggested_by'] !== null && $j['suggested_by'] !== '' ? e($j['suggested_by']) : $blank ?></td>
                  <td class="min-w-40"><?= $submitted ? ui_multi($submitted) : $blank ?></td>
                  <td class="min-w-40"><?= $published ? ui_multi($published) : $blank ?></td>
                  <td class="pin-end max-lg:hidden"><div class="ml-auto flex w-36 flex-wrap justify-end gap-1"><?= $actionsHtml ?></div></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?= $noMatch ?>
      </div>
      </div>
    <?php endif; ?>

    <?php if ($canEdit && !$editJournal): ?><?= $journalForm() ?><?php endif; ?>

  <?php elseif ($tab === 'templates'): ?>
    <!-- ===== Templates: one text block each ===== -->
    <?php if (!$templates && !$canEdit): ?>
      <div class="empty"><h2 class="empty-title">No templates yet</h2><p>Your teachers have not added any templates.</p></div>
    <?php endif; ?>

    <div class="flex flex-col gap-4">
      <?php foreach ($templates as $i => $t):
          $fid = (string)$t['id'];
          $open = $formId === $fid;
          // A template's first line is its title when there is more text below it.
          $tLines = explode("\n", trim((string)$t['body']), 2);
          $tTitle = count($tLines) === 2 && mb_strlen(trim($tLines[0]), 'UTF-8') <= 120 ? trim($tLines[0]) : 'Template ' . ($i + 1);
          $tBody  = $tTitle === trim($tLines[0]) && count($tLines) === 2 ? trim($tLines[1]) : trim((string)$t['body']); ?>
        <article class="card" x-data="{ editing: <?= $open ? 'true' : 'false' ?> }" aria-labelledby="tpl-<?= (int)$t['id'] ?>-h">
          <div class="flex items-start justify-between gap-4">
            <h2 class="card-title break-words" id="tpl-<?= (int)$t['id'] ?>-h"><?= e($tTitle) ?></h2>
            <div class="flex shrink-0 gap-1" x-show="!editing">
              <button type="button" class="btn btn-secondary btn-sm" x-copy data-copy="<?= e($t['body']) ?>"><?= pf_icon('copy') ?> Copy</button>
              <?php if ($canEdit): ?>
                <button type="button" class="btn btn-ghost btn-sm" @click="editing = true; $nextTick(() => $refs.body.focus())"><?= pf_icon('edit') ?> Edit</button>
                <form method="post" action="<?= e(url_resources()) ?>" x-confirm
                      data-confirm-title="Delete this template?" data-confirm-body="Students will no longer see it."
                      data-confirm-ok="Delete" data-confirm-cancel="Keep it" data-confirm-tone="danger">
                  <?= $csrf ?>
                  <input type="hidden" name="action" value="delete_template">
                  <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                  <button class="btn btn-danger-ghost btn-sm" type="submit" aria-label="Delete template <?= e($tTitle) ?>"><?= pf_icon('trash') ?></button>
                </form>
              <?php endif; ?>
            </div>
          </div>

          <div x-show="!editing"<?= $open ? ' x-cloak' : '' ?>>
            <p class="mt-3 text-[0.9375rem] leading-relaxed break-words whitespace-pre-line"><?= $linkify($tBody) ?></p>
            <?php if ($canEdit): ?><p class="mt-3 text-sm text-muted-foreground-1"><?= e($edited($t)) ?></p><?php endif; ?>
          </div>

          <?php if ($canEdit): ?>
            <form method="post" action="<?= e(url_resources()) ?>" class="mt-3" x-show="editing"<?= $open ? '' : ' x-cloak' ?>
                  @keydown.escape="editing = false">
              <?= $csrf ?>
              <input type="hidden" name="action" value="edit_template">
              <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
              <label class="sr-only" for="tpl-body-<?= (int)$t['id'] ?>">Template text</label>
              <textarea class="textarea" id="tpl-body-<?= (int)$t['id'] ?>" name="body" rows="8" maxlength="10000" x-ref="body"<?= $invalid($fid, 'body') ?>><?= e($val($fid, 'body', $t['body'])) ?></textarea>
              <?= $err($fid, 'body') ?>
              <div class="form-actions mt-3">
                <button type="button" class="btn btn-ghost btn-sm" @click="editing = false">Cancel</button>
                <button type="submit" class="btn btn-primary btn-sm">Save</button>
              </div>
            </form>
          <?php endif; ?>
        </article>
      <?php endforeach; ?>
    </div>

    <?php if ($canEdit): ?>
      <section class="card mt-6" aria-labelledby="tpl-add-h">
        <h2 class="card-title" id="tpl-add-h"><?= pf_icon('plus') ?> Add a template</h2>
        <form method="post" action="<?= e(url_resources()) ?>" class="mt-3">
          <?= $csrf ?>
          <input type="hidden" name="action" value="add_template">
          <label class="sr-only" for="tpl-new">Template text</label>
          <textarea class="textarea" id="tpl-new" name="body" rows="6" maxlength="10000"
                    placeholder="Paste or write the template text here."<?= $invalid('add', 'body') ?>><?= e($val('add', 'body')) ?></textarea>
          <?= $err('add', 'body') ?>
          <div class="form-actions mt-3">
            <button type="submit" class="btn btn-primary">Add template</button>
          </div>
        </form>
      </section>
    <?php endif; ?>

  <?php else: ?>
    <!-- ===== Open data and code: name + link ===== -->
    <?php if (!$resources && !$canEdit): ?>
      <div class="empty"><h2 class="empty-title">Nothing here yet</h2><p>Your teachers have not added any data or code links.</p></div>
    <?php elseif ($resources): ?>
      <div class="table-wrap">
        <table class="table">
          <thead>
            <tr>
              <th scope="col">Name</th>
              <th scope="col">Link</th>
              <?php if ($canEdit): ?><th scope="col" class="col-actions"><span class="sr-only">Actions</span></th><?php endif; ?>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($resources as $r):
                $fid = (string)$r['id'];
                $open = $formId === $fid; ?>
              <tr x-data="{ editing: <?= $open ? 'true' : 'false' ?> }">
                <td class="font-semibold" x-show="!editing"<?= $open ? ' x-cloak' : '' ?>><?= e($r['name']) ?></td>
                <td class="max-w-md break-all" x-show="!editing"<?= $open ? ' x-cloak' : '' ?>>
                  <?php if ($r['link']): ?>
                    <a href="<?= e($r['link']) ?>" target="_blank" rel="noopener noreferrer"><?= e($r['link']) ?></a>
                  <?php else: ?>
                    <span class="text-muted-foreground-1">No link yet</span>
                  <?php endif; ?>
                </td>
                <?php if ($canEdit): ?>
                  <td class="col-actions" x-show="!editing"<?= $open ? ' x-cloak' : '' ?>>
                    <button type="button" class="btn btn-ghost btn-sm" @click="editing = true; $nextTick(() => $refs.name.focus())"><?= pf_icon('edit') ?> Edit</button>
                    <form method="post" action="<?= e(url_resources()) ?>" class="inline" x-confirm
                          data-confirm-title="Delete “<?= e($r['name']) ?>”?" data-confirm-body="Students will no longer see it."
                          data-confirm-ok="Delete" data-confirm-cancel="Keep it" data-confirm-tone="danger">
                      <?= $csrf ?>
                      <input type="hidden" name="action" value="delete_resource">
                      <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                      <button class="btn btn-danger-ghost btn-sm" type="submit" aria-label="Delete <?= e($r['name']) ?>"><?= pf_icon('trash') ?></button>
                    </form>
                  </td>
                  <td colspan="3" x-show="editing"<?= $open ? '' : ' x-cloak' ?>>
                    <form method="post" action="<?= e(url_resources()) ?>" class="grid gap-3 sm:grid-cols-[1fr_2fr_auto] sm:items-start" @keydown.escape="editing = false">
                      <?= $csrf ?>
                      <input type="hidden" name="action" value="edit_resource">
                      <input type="hidden" name="id" value="<?= (int)$r['id'] ?>">
                      <div>
                        <label class="label" for="res-name-<?= (int)$r['id'] ?>">Name</label>
                        <input class="input" id="res-name-<?= (int)$r['id'] ?>" name="name" maxlength="200" required x-ref="name"
                               value="<?= e($val($fid, 'name', $r['name'])) ?>"<?= $invalid($fid, 'name') ?>>
                        <?= $err($fid, 'name') ?>
                      </div>
                      <div>
                        <label class="label" for="res-link-<?= (int)$r['id'] ?>">Link</label>
                        <input class="input" id="res-link-<?= (int)$r['id'] ?>" name="link" type="url" maxlength="1000" placeholder="https://"
                               value="<?= e($val($fid, 'link', (string)$r['link'])) ?>"<?= $invalid($fid, 'link') ?>>
                        <?= $err($fid, 'link') ?>
                      </div>
                      <div class="flex gap-2 sm:pt-7">
                        <button type="button" class="btn btn-ghost btn-sm" @click="editing = false">Cancel</button>
                        <button type="submit" class="btn btn-primary btn-sm">Save</button>
                      </div>
                    </form>
                  </td>
                <?php endif; ?>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

    <?php if ($canEdit): ?>
      <section class="card mt-6" aria-labelledby="res-add-h">
        <h2 class="card-title" id="res-add-h"><?= pf_icon('plus') ?> Add data or code</h2>
        <form method="post" action="<?= e(url_resources()) ?>" class="mt-3 grid gap-3 sm:grid-cols-[1fr_2fr]">
          <?= $csrf ?>
          <input type="hidden" name="action" value="add_resource">
          <div>
            <label class="label" for="res-new-name">Name</label>
            <input class="input" id="res-new-name" name="name" maxlength="200" required placeholder="e.g. Simulation code"
                   value="<?= e($val('add', 'name')) ?>"<?= $invalid('add', 'name') ?>>
            <?= $err('add', 'name') ?>
          </div>
          <div>
            <label class="label" for="res-new-link">Link</label>
            <input class="input" id="res-new-link" name="link" type="url" maxlength="1000" placeholder="https://"
                   value="<?= e($val('add', 'link')) ?>"<?= $invalid('add', 'link') ?>>
            <?= $err('add', 'link') ?>
          </div>
          <div class="form-actions sm:col-span-2">
            <button type="submit" class="btn btn-primary">Add</button>
          </div>
        </form>
      </section>
    <?php endif; ?>
  <?php endif; ?>
  </div>
  <?php endif; ?>
</div>
<?php require __DIR__ . '/../src/includes/footer.php'; ?>
