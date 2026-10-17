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
 *   journals            Potential journals (lab_journals): teachers list
 *                       journals worth submitting to, one text block each
 *                       (first line = title); every student sees
 *                       them and can take one or more (say they intend to
 *                       submit there) or release them (lab_journal_takers).
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
 *   add_journal      details   (one text block: everything about it)
 *   edit_journal     id, details
 *   delete_journal   id
 *   take_journal     id        (students: ACTIVE role student)
 *   release_journal  id        (students)
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
    // Students take or release a potential journal; nothing else.
    if ($action === 'take_journal' || $action === 'release_journal') {
        if (active_user_role() !== 'student') {
            http_response_code(403);
            exit('Only students take journals.');
        }
        $id = (int)($_POST['id'] ?? 0);
        $exists = $pdo->prepare('SELECT 1 FROM lab_journals WHERE id = :id');
        $exists->execute(['id' => $id]);
        if ($exists->fetchColumn()) {
            $pdo->prepare($action === 'take_journal'
                ? 'INSERT IGNORE INTO lab_journal_takers (journal_id, user_id) VALUES (:j, :u)'
                : 'DELETE FROM lab_journal_takers WHERE journal_id = :j AND user_id = :u')
                ->execute(['j' => $id, 'u' => $userId]);
            log_activity($userId, 'resources_update', $action);
        }
        header('Location: ' . url_resources() . '?tab=journals&done=' . ($action === 'take_journal' ? 'taken' : 'released') . '#journal-' . $id);
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
        $details = trim(str_replace(["\r\n", "\r"], "\n", (string)($_POST['details'] ?? '')));
        $posted = ['details' => $details];
        if ($details === '') { $errors['details'] = 'Write the journal details.'; }
        elseif (mb_strlen($details, 'UTF-8') > 5000) { $errors['details'] = 'Keep it under 5,000 characters.'; }
        if (!$errors) {
            if ($action === 'add_journal') {
                $pdo->prepare('INSERT INTO lab_journals (details, sort_order, created_by, updated_by)
                               SELECT :d, COALESCE(MAX(sort_order), 0) + 1, :c, :u FROM lab_journals')
                    ->execute(['d' => $details, 'c' => $userId, 'u' => $userId]);
            } else {
                $pdo->prepare('UPDATE lab_journals SET details = :d, updated_by = :u WHERE id = :id')
                    ->execute(['d' => $details, 'u' => $userId, 'id' => $id]);
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

// Potential journals, each with who took it (takers: [user_id => name]).
$journals = $pdo->query(
    'SELECT j.id, j.details, j.updated_at, u.name AS editor
       FROM lab_journals j LEFT JOIN users u ON u.id = j.updated_by
      ORDER BY j.sort_order, j.id'
)->fetchAll();
$takers = [];
foreach ($pdo->query(
    "SELECT t.journal_id, t.user_id, COALESCE(NULLIF(TRIM(p.name_en), ''), u.name) AS name
       FROM lab_journal_takers t JOIN users u ON u.id = t.user_id LEFT JOIN profiles p ON p.user_id = u.id
      WHERE u.deleted_at IS NULL
      ORDER BY t.taken_at"
) as $t) {
    $takers[(int)$t['journal_id']][(int)$t['user_id']] = (string)$t['name'];
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
                 'taken' => 'Journal taken. Your teachers can see it.', 'released' => 'Journal released.'];
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
<div class="page" x-data="sheet">
  <header class="page-header">
    <div>
      <h1 class="page-title">Lab resources</h1>
      <p class="page-desc">
        <?php if (isset($SHEETS[$tab])): ?>
          Everyone’s <?= e(strtolower($SHEETS[$tab]['title'])) ?> in the lab. Students add their own on My profile.
        <?php elseif ($tab === 'journals'): ?>
          <?= $canEdit
              ? 'Journals worth submitting to. Every student sees this list and can take a journal they intend to submit to.'
              : 'Journals your teachers suggest. Take the ones you intend to submit to; your teachers and labmates can see who took what.' ?>
        <?php elseif ($canEdit): ?>
          Templates and open data shared with every student in the lab. You can add, edit and delete them; students can only read.
        <?php else: ?>
          Templates and open data shared by your teachers. Copy a template, or open a link.
        <?php endif; ?>
      </p>
    </div>
    <?php if (isset($SHEETS[$tab]) && $sheetRows[$tab]): ?>
      <button type="button" class="btn btn-ghost btn-sm" x-ref="enter" @click="set(true)"><?= pf_icon('expand') ?> Full screen</button>
    <?php endif; ?>
  </header>

  <?php
  // The tab row: above the content, and again in the full-screen bar.
  $tabNav = function (string $class = '') use ($TABS, $tab, $tabCount): string {
      $html = '<nav class="sheet-tabs' . ($class === '' ? ' is-wrap' : $class) . '" aria-label="Resources">';
      foreach ($TABS as $t => $label) {
          $html .= '<a href="' . e(url_resources() . '?tab=' . $t) . '"' . ($tab === $t ? ' aria-current="page"' : '') . '>'
              . e($label) . ' <span class="tab-count">' . $tabCount($t) . '</span></a>';
      }
      return $html . '</nav>';
  };
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
    <?php else: ?>
      <div class="table-wrap sheet-frame" :class="full && 'is-full'" @keydown.escape.window="full && set(false)">
        <!-- Shown only in full-screen mode. -->
        <div class="sheet-bar">
          <?= $tabNav(' is-compact') ?>
          <span class="faint"><?= count($rows) ?> <?= count($rows) === 1 ? 'row' : 'rows' ?></span>
          <button type="button" class="btn btn-secondary btn-sm" @click="set(false)"><?= pf_icon('collapse') ?> Exit full screen <kbd class="kbd">Esc</kbd></button>
        </div>
        <div class="sheet-scroll" tabindex="0" role="region" aria-label="<?= e($sDef['sheet']) ?>" x-ref="scroll">
          <table class="table sheet">
            <thead>
              <tr>
                <th scope="col" class="pin">Student</th>
                <?php foreach ($sDef['fields'] as $f): ?>
                  <th scope="col" class="whitespace-pre-line!"><?= e($f['heading'] ?? $f['label']) ?></th>
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
      </div>
    <?php endif; ?>

  <?php else: ?>
  <div class="max-w-3xl">
  <?php if ($tab === 'journals'):
      // The journal form, shared by Add and each card's Edit. $fid = 'add' or the id.
      $journalFields = fn(string $fid, string $stored) =>
          '<label class="sr-only" for="j-details-' . $fid . '">Journal details</label>'
          . '<textarea class="textarea" id="j-details-' . $fid . '" name="details" rows="6" maxlength="5000"'
          . ' placeholder="' . e("Journal name (first line is the title)\nPublisher, impact factor, link, scope, deadline, tips…") . '"'
          . ($fid !== 'add' ? ' x-ref="details"' : '') . $invalid($fid, 'details') . '>'
          . e($val($fid, 'details', $stored)) . '</textarea>' . $err($fid, 'details');
      $isStudent = active_user_role() === 'student'; ?>
    <!-- ===== Potential journals: listed by teachers, taken by students ===== -->
    <?php if (!$journals && !$canEdit): ?>
      <div class="empty"><h2 class="empty-title">No journals yet</h2><p>Your teachers have not suggested any journals.</p></div>
    <?php endif; ?>

    <div class="flex flex-col gap-4">
      <?php foreach ($journals as $j):
          $fid   = (string)$j['id'];
          $open  = $formId === $fid;
          $who   = $takers[(int)$j['id']] ?? [];
          $mine  = isset($who[$userId]);
          // First line is the title; the rest is the body.
          [$jTitle, $jBody] = array_pad(explode("\n", (string)$j['details'], 2), 2, '');
          $jTitle = trim($jTitle); ?>
        <article class="card<?= $mine ? ' ring-2 ring-primary/40' : '' ?>" id="journal-<?= (int)$j['id'] ?>"
                 x-data="{ editing: <?= $open ? 'true' : 'false' ?> }" aria-labelledby="j-<?= (int)$j['id'] ?>-h">
          <div x-show="!editing"<?= $open ? ' x-cloak' : '' ?>>
            <div class="flex items-start justify-between gap-4">
              <div class="min-w-0">
                <h2 class="card-title break-words" id="j-<?= (int)$j['id'] ?>-h">
                  <span><?= $linkify($jTitle) ?></span>
                  <?php if ($mine): ?><span class="badge badge-success">You took this</span><?php endif; ?>
                </h2>
              </div>
              <div class="flex shrink-0 gap-1">
                <?php if ($isStudent): ?>
                  <form method="post" action="<?= e(url_resources()) ?>">
                    <?= $csrf ?>
                    <input type="hidden" name="action" value="<?= $mine ? 'release_journal' : 'take_journal' ?>">
                    <input type="hidden" name="id" value="<?= (int)$j['id'] ?>">
                    <?php if ($mine): ?>
                      <button class="btn btn-ghost btn-sm" type="submit">Release</button>
                    <?php else: ?>
                      <button class="btn btn-primary btn-sm" type="submit"><?= pf_icon('check') ?> Take this journal</button>
                    <?php endif; ?>
                  </form>
                <?php elseif ($canEdit): ?>
                  <button type="button" class="btn btn-ghost btn-sm" @click="editing = true; $nextTick(() => $refs.details.focus())"><?= pf_icon('edit') ?> Edit</button>
                  <form method="post" action="<?= e(url_resources()) ?>" x-confirm
                        data-confirm-title="Delete “<?= e(mb_strimwidth($jTitle, 0, 60, '…', 'UTF-8')) ?>”?"
                        data-confirm-body="<?= $who ? 'It is removed for everyone, including the ' . count($who) . ' ' . (count($who) === 1 ? 'student who took it' : 'students who took it') . '.' : 'Students will no longer see it.' ?>"
                        data-confirm-ok="Delete" data-confirm-cancel="Keep it" data-confirm-tone="danger">
                    <?= $csrf ?>
                    <input type="hidden" name="action" value="delete_journal">
                    <input type="hidden" name="id" value="<?= (int)$j['id'] ?>">
                    <button class="btn btn-danger-ghost btn-sm" type="submit" aria-label="Delete <?= e($jTitle) ?>"><?= pf_icon('trash') ?></button>
                  </form>
                <?php endif; ?>
              </div>
            </div>
            <?php if (trim($jBody) !== ''): ?><p class="mt-2 max-w-[70ch] text-[0.9375rem] leading-relaxed break-words whitespace-pre-line"><?= $linkify(trim($jBody)) ?></p><?php endif; ?>
            <div class="mt-4 flex flex-wrap items-center gap-1.5 border-t border-layer-line pt-3 text-sm">
              <span class="font-semibold">Taken by</span>
              <?php if (!$who): ?>
                <span class="text-muted-foreground-1">no one yet</span>
              <?php else: foreach ($who as $uid => $name): ?>
                <span class="badge<?= $uid === $userId ? ' badge-success' : '' ?>"><?= e($uid === $userId ? $name . ' (you)' : $name) ?></span>
              <?php endforeach; endif; ?>
            </div>
            <?php if ($canEdit): ?><p class="mt-2 text-sm text-muted-foreground-1"><?= e($edited($j)) ?></p><?php endif; ?>
          </div>

          <?php if ($canEdit): ?>
            <form method="post" action="<?= e(url_resources()) ?>" x-show="editing"<?= $open ? '' : ' x-cloak' ?> @keydown.escape="editing = false">
              <?= $csrf ?>
              <input type="hidden" name="action" value="edit_journal">
              <input type="hidden" name="id" value="<?= (int)$j['id'] ?>">
              <?= $journalFields($fid, (string)$j['details']) ?>
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
      <section class="card mt-6" aria-labelledby="j-add-h">
        <h2 class="card-title" id="j-add-h"><?= pf_icon('plus') ?> Add a potential journal</h2>
        <form method="post" action="<?= e(url_resources()) ?>" class="mt-3">
          <?= $csrf ?>
          <input type="hidden" name="action" value="add_journal">
          <?= $journalFields('add', '') ?>
          <div class="form-actions mt-3">
            <button type="submit" class="btn btn-primary">Add journal</button>
          </div>
        </form>
      </section>
    <?php endif; ?>

  <?php elseif ($tab === 'templates'): ?>
    <!-- ===== Templates: one text block each ===== -->
    <?php if (!$templates && !$canEdit): ?>
      <div class="empty"><h2 class="empty-title">No templates yet</h2><p>Your teachers have not added any templates.</p></div>
    <?php endif; ?>

    <div class="flex flex-col gap-4">
      <?php foreach ($templates as $i => $t):
          $fid = (string)$t['id'];
          $open = $formId === $fid; ?>
        <article class="card" x-data="{ editing: <?= $open ? 'true' : 'false' ?> }" aria-labelledby="tpl-<?= (int)$t['id'] ?>-h">
          <div class="flex items-start justify-between gap-4">
            <h2 class="card-title" id="tpl-<?= (int)$t['id'] ?>-h">Template <?= $i + 1 ?></h2>
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
                  <button class="btn btn-danger-ghost btn-sm" type="submit" aria-label="Delete template <?= $i + 1 ?>"><?= pf_icon('trash') ?></button>
                </form>
              <?php endif; ?>
            </div>
          </div>

          <div x-show="!editing"<?= $open ? ' x-cloak' : '' ?>>
            <p class="mt-3 text-[0.9375rem] leading-relaxed break-words whitespace-pre-line"><?= $linkify($t['body']) ?></p>
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
