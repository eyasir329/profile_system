<?php
/**
 * Profile editor (view mode + per-field CRUD).
 *
 * Route:  GET/POST /profile.php
 * Auth:   require_login()
 * CSRF:   verify_csrf() on POST
 * Photo:  JPG/PNG/WEBP/GIF, ≤2 MB, MIME-checked, random 32-hex filename.
 *         On replace, the previous file is unlinked from disk.
 * Sections: per-row insert/update/delete driven by $SECTIONS in
 *         includes/profile_sections.php.
 * GET:    renders the profile read-only: identity header with a completion
 *         checklist, the "About" fields, then one card per section. Each
 *         value is edited in place by assets/js/app.js (PS.initProfile);
 *         there is no page-wide <form> and no save bar.
 * POST:
 *   - With `op` field set, the JSON-returning op-router handles a single
 *     CRUD action (update_basics, update_photo, clear_photo, add_row,
 *     update_row, duplicate_row, delete_row).
 *   - Without `op`, the legacy bulk-form handler runs (preserved for
 *     the smoke + CRUD tests; renders the page with errors as before).
 *
 * On bulk-form success: 302 → /profile.php?updated=1
 */
declare(strict_types=1);
require_once __DIR__ . '/../src/includes/auth.php';
require_once __DIR__ . '/../src/includes/db.php';
require_once __DIR__ . '/../src/includes/url.php';
require_once __DIR__ . '/../src/includes/validate.php';
require_once __DIR__ . '/../src/includes/profile_sections.php';
require_once __DIR__ . '/../src/includes/ui.php';
require_login();

$userId = (int)current_user_id();

// Load user + basic profile
$ust = $pdo->prepare('SELECT degree_type FROM users WHERE id = :id');
$ust->execute(['id' => $userId]);
$userRow = $ust->fetch();
$degreeType = normalize_degree_type($userRow['degree_type'] ?? 'bachelor');

$pst = $pdo->prepare('SELECT phone, address, profession, bio, photo FROM profiles WHERE user_id = :id LIMIT 1');
$pst->execute(['id' => $userId]);
$profile = $pst->fetch();
if (!$profile) {
    $pdo->prepare('INSERT INTO profiles (user_id) VALUES (:id)')->execute(['id' => $userId]);
    $profile = ['phone' => '', 'address' => '', 'profession' => '', 'bio' => '', 'photo' => null];
}

// Load the username + name for the page title and avatar — name/email/
// account fields are managed from /settings.php, never the editor.
$uAll = $pdo->prepare('SELECT username, name FROM users WHERE id = :id');
$uAll->execute(['id' => $userId]);
$userMeta = $uAll->fetch() ?: ['username' => '', 'name' => ''];

// Load existing section rows
$sections = load_sections_for_user($pdo, $userId, $degreeType);

$errors = [];
$form = $profile;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // ---- Op-router: short-circuit to a single JSON-returning op. ----
    if (isset($_POST['op']) && is_string($_POST['op']) && $_POST['op'] !== '') {
        handle_profile_op($pdo, $userId, $degreeType, $profile, $sections, $SECTIONS);
        exit; // handle_profile_op always exits.
    }

    verify_csrf();

    // -------- core profile fields --------
    $vPhone      = validate_phone((string)($_POST['phone']      ?? ''));
    $vAddress    = validate_address((string)($_POST['address']    ?? ''));
    $vProfession = validate_profession((string)($_POST['profession'] ?? ''));
    $vBio        = validate_bio((string)($_POST['bio']        ?? ''));

    $form['phone']      = $vPhone['value']      ?? '';
    $form['address']    = $vAddress['value']    ?? '';
    $form['profession'] = $vProfession['value'] ?? '';
    $form['bio']        = $vBio['value']        ?? '';

    $errors = array_merge($errors, $vPhone['errors'], $vAddress['errors'], $vProfession['errors'], $vBio['errors']);

    // photo
    $uploadDetails = null;
    $clearPhoto = !empty($_POST['photo_clear']);
    if (!$clearPhoto && isset($_FILES['photo']) && is_array($_FILES['photo']) && ($_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
        $file = $_FILES['photo'];
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            $errors['photo'] = 'Photo upload failed. Please try another image.';
        } elseif (!isset($file['tmp_name'], $file['size']) || !is_uploaded_file($file['tmp_name'])) {
            $errors['photo'] = 'Invalid uploaded file.';
        } elseif ((int)$file['size'] > 2 * 1024 * 1024) {
            $errors['photo'] = 'Photo must be 2 MB or smaller.';
        } else {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($file['tmp_name']);
            $info = @getimagesize($file['tmp_name']);
            $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
            if (!$info || !isset($allowed[$mime])) {
                $errors['photo'] = 'Choose a valid JPG, PNG, WEBP, or GIF image.';
            } else {
                $uploadDetails = ['tmp_name' => $file['tmp_name'], 'extension' => $allowed[$mime]];
            }
        }
    }
    $newPhoto = $profile['photo'];
    $newPhotoAbsPath = null;
    if (!$errors) {
        if ($clearPhoto) {
            $newPhoto = null;
        } elseif ($uploadDetails !== null) {
            $dir = __DIR__ . '/uploads/profiles';
            if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
                $errors['photo'] = 'Upload directory could not be created.';
            } else {
                $filename = bin2hex(random_bytes(16)) . '.' . $uploadDetails['extension'];
                $dest = $dir . '/' . $filename;
                if (!move_uploaded_file($uploadDetails['tmp_name'], $dest)) {
                    $errors['photo'] = 'Could not save the uploaded photo.';
                } else {
                    $newPhotoAbsPath = $dest;
                    $newPhoto = 'uploads/profiles/' . $filename;
                }
            }
        }
    }

    // -------- section rows --------
    $posted = $_POST['sections'] ?? [];
    if (!is_array($posted)) { $posted = []; }

    $plan = []; // sectionKey => ['rows' => [...], 'deletes' => [id,...]]
    foreach (visible_sections($degreeType) as $sectionKey) {
        $secInput = $posted[$sectionKey] ?? [];
        if (!is_array($secInput)) { $secInput = []; }

        $rows = [];
        $deletes = [];

        foreach ($secInput as $rowKey => $rowInput) {
            if (!is_array($rowInput)) { continue; }
            $rowKey = (string)$rowKey;

            // Marked for deletion?
            if (!empty($rowInput['_delete'])) {
                if (ctype_digit($rowKey)) {
                    $deletes[] = (int)$rowKey;
                }
                continue;
            }
            // Skip completely empty rows (no field set).
            // sort_order is a control field, not content; check only the real data fields.
            $hasContent = false;
            foreach ($SECTIONS[$sectionKey]['fields'] as $f) {
                $fname = $f[0];
                if (isset($rowInput[$fname]) && (is_string($rowInput[$fname]) ? trim($rowInput[$fname]) !== '' : !empty($rowInput[$fname]))) {
                    $hasContent = true; break;
                }
            }
            if (!$hasContent) { continue; }

            // Validate each field.
            // _origId is set only if the rowKey matches an existing DB id.
            // Real existing rows use their integer DB id; new rows use 'new_...' prefixed
            // keys generated client-side.
            $row = ['_origId' => null];
            if (ctype_digit($rowKey) && strpos($rowKey, 'new_') !== 0) {
                $row['_origId'] = (int)$rowKey;
            }
            $rowErr = [];
            foreach ($SECTIONS[$sectionKey]['fields'] as $f) {
                [$name, $type, $label, $required, $max] = $f;
                $val = isset($rowInput[$name]) && is_string($rowInput[$name]) ? trim($rowInput[$name]) : '';
                if ($type === 'check') {
                    $row[$name] = !empty($rowInput[$name]) ? 1 : 0;
                    continue;
                }
                if ($val === '' && $required) {
                    $rowErr[] = $label . ' is required.';
                    continue;
                }
                if ($val !== '' && $max > 0 && mb_strlen($val, 'UTF-8') > $max) {
                    $rowErr[] = $label . ' must be ' . $max . ' characters or fewer.';
                    continue;
                }
                if (($type === 'year' || $type === 'date') && $val !== '' && !preg_match('/^\d{4}(-\d{2}(-\d{2})?)?$/', $val)) {
                    $rowErr[] = $label . ' has an invalid format.';
                    continue;
                }
                $row[$name] = $val === '' ? null : $val;
            }
            if ($rowErr) {
                $errors[$sectionKey . '_' . $rowKey] = $rowErr;
            }
            $row['sort_order'] = (int)($rowInput['sort_order'] ?? 0);
            $rows[] = $row;
        }

        $plan[$sectionKey] = ['rows' => $rows, 'deletes' => $deletes];
    }

    if (!$errors) {
        try {
            $pdo->beginTransaction();

            // 1) basic profile (photo + contact + bio). Account fields and
            //    privacy/theme toggles live on /settings.php — this editor
            //    is content-only.
            $upd = $pdo->prepare(
                'UPDATE profiles SET phone=:phone, address=:address, profession=:profession, bio=:bio, photo=:photo'
                . ' WHERE user_id=:u'
            );
            $upd->execute([
                'phone'      => $form['phone']      !== '' ? $form['phone']      : null,
                'address'    => $form['address']    !== '' ? $form['address']    : null,
                'profession' => $form['profession'] !== '' ? $form['profession'] : null,
                'bio'        => $form['bio']        !== '' ? $form['bio']        : null,
                'photo'      => $newPhoto,
                'u'          => $userId,
            ]);

            // 2) sections
            foreach ($plan as $sectionKey => $p) {
                $cols = section_columns($sectionKey);
                foreach ($p['deletes'] as $delId) {
                    $pdo->prepare("DELETE FROM `$sectionKey` WHERE id = :id AND user_id = :u")
                        ->execute(['id' => $delId, 'u' => $userId]);
                }
                foreach ($p['rows'] as $row) {
                    $origId = $row['_origId'];
                    unset($row['_origId']);
                    if ($origId) {
                        $set = []; $params = [];
                        foreach ($cols as $c) {
                            $set[] = "`$c` = :$c";
                            $params[$c] = $row[$c] ?? null;
                        }
                        $params['id'] = $origId; $params['u'] = $userId;
                        $pdo->prepare("UPDATE `$sectionKey` SET " . implode(', ', $set) . " WHERE id = :id AND user_id = :u")
                            ->execute($params);
                    } else {
                        $cols2 = array_filter($cols, fn($c) => $c !== 'sort_order');
                        $allCols = array_merge(['user_id'], $cols2, ['sort_order']);
                        $placeholders = array_map(fn($c) => ":$c", $allCols);
                        $params = ['user_id' => $userId, 'sort_order' => $row['sort_order'] ?? 0];
                        foreach ($cols2 as $c) {
                            $params[$c] = $row[$c] ?? null;
                        }
                        $pdo->prepare("INSERT INTO `$sectionKey` (" . implode(',', array_merge(['user_id'], $cols2, ['sort_order'])) . ") VALUES (" . implode(',', $placeholders) . ")")
                            ->execute($params);
                    }
                }
            }

            $pdo->commit();

            // Clean up the previous photo file (if any) when changed
            if (!empty($profile['photo']) && $profile['photo'] !== $newPhoto) {
                $old = __DIR__ . '/' . $profile['photo'];
                if (is_file($old)) { @unlink($old); }
            }
            log_activity($userId, 'profile_update', null);
            header('Location: ' . url_profile() . '?updated=1');
            exit;
        } catch (PDOException $ex) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            if ($newPhotoAbsPath && is_file($newPhotoAbsPath)) { @unlink($newPhotoAbsPath); }
            error_log('Profile update error: ' . $ex->getMessage());
            $errors[] = 'Your profile could not be saved. Please try again.';
        }
    }
}

$page_title = 'My profile · @' . $userMeta['username'];
$page_id    = 'profile';
if (isset($_GET['updated']) && !$errors) {
    $flash = [['type' => 'success', 'body' => 'Profile saved.']];
}
require __DIR__ . '/../src/includes/header.php';

$usernameStr = (string)($userMeta['username'] ?? '');
$displayName = trim((string)($userMeta['name'] ?? '')) ?: $usernameStr;
$nameInitial = strtoupper(mb_substr($displayName, 0, 1, 'UTF-8'));
$hasPhoto    = !empty($profile['photo']);
$visibleKeys = visible_sections($degreeType);

// The four free-text basics. One definition drives the server-rendered
// cards, the JS inline editor and the completion checklist.
$BASICS = [
    'profession' => [
        'label' => 'Profession', 'type' => 'text', 'max' => 100, 'rows' => 0,
        'placeholder' => 'e.g. Software Engineer',
        'empty' => 'Add a headline so people know what you do.',
        'hint'  => 'Shown under your name.',
    ],
    'bio' => [
        'label' => 'About me', 'type' => 'textarea', 'max' => 3000, 'rows' => 5,
        'placeholder' => 'A few sentences about your background and interests.',
        'empty' => 'Tell people a little about yourself.',
        'hint'  => '',
    ],
    'phone' => [
        'label' => 'Phone', 'type' => 'tel', 'max' => 20, 'rows' => 0,
        'placeholder' => '+1 555 123 4567',
        'empty' => 'No phone number yet.',
        'hint'  => 'Include the country code.',
    ],
    'address' => [
        'label' => 'Address', 'type' => 'textarea', 'max' => 2000, 'rows' => 2,
        'placeholder' => 'Street, city, country',
        'empty' => 'No address yet.',
        'hint'  => '',
    ],
];
$basicValues = [];
foreach ($BASICS as $bKey => $_) {
    $basicValues[$bKey] = trim((string)($profile[$bKey] ?? ''));
}

// Completion checklist (same one the dashboard shows).
$steps = profile_completion_steps($profile, array_map('count', $sections), $degreeType);
$stepsDone   = count(array_filter($steps, fn($s) => $s['done']));
$stepsTotal  = count($steps);
$completePct = (int)round($stepsDone / $stepsTotal * 100);
$basicsDone  = count(array_filter($basicValues, fn($v) => $v !== ''));

// JS config: PS_VIEW_MODE carries everything the inline editor needs: field
// metadata to build a form for any section, and the current row values to
// pre-fill it.
$viewSections = [];
$viewRows     = [];
foreach ($visibleKeys as $sKey) {
    $def = $SECTIONS[$sKey];
    $fields = [];
    foreach ($def['fields'] as $f) {
        [$name, $type, $label, $required, $max, $options, $help, $placeholder, $attrs, $rowsAttr] = array_pad($f, 10, null);
        $opts = [];
        foreach (($options ?: []) as $optVal => $optLabel) {
            $opts[] = [(string)$optVal, (string)$optLabel];
        }
        $fields[] = [
            'name'        => $name,
            'type'        => $type,
            'label'       => $label,
            'required'    => (bool)$required,
            'max'         => (int)$max,
            'options'     => $opts,
            'help'        => (string)($help ?? ''),
            'placeholder' => (string)($placeholder ?? ''),
            'attrs'       => (object)($attrs ?: []),
            'rows'        => (int)($rowsAttr ?? 0),
        ];
    }
    $viewSections[$sKey] = [
        'title'    => $def['title'],
        'singular' => $def['singular'],
        'tags'     => pf_is_tag_section($sKey),
        'fields'   => $fields,
    ];
    $viewRows[$sKey] = [];
    foreach ($sections[$sKey] ?? [] as $row) {
        $viewRows[$sKey][(string)$row['id']] = $row;
    }
    $viewRows[$sKey] = (object)$viewRows[$sKey];
}
$viewCfg = [
    'csrf'        => csrf_token(),
    'userId'      => $userId,
    'degreeType'  => $degreeType,
    'sections'    => $viewSections,
    'rows'        => $viewRows,
    'basics'      => ['fields' => $BASICS, 'values' => $basicValues],
    'hasPhoto'    => $hasPhoto,
    'nameInitial' => $nameInitial,
];
$jsonFlags = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_INVALID_UTF8_SUBSTITUTE;
?>
<script>window.PS_VIEW_MODE = <?= json_encode($viewCfg, $jsonFlags) ?>;</script>

<div class="page-wide" data-profile>

  <?php if (!empty($errors) && !array_is_list($errors)): ?>
    <?= ui_alert('error', '<strong>Please fix the highlighted fields.</strong>') ?>
  <?php elseif (!empty($errors)): ?>
    <div class="alert alert-error" role="alert">
      <?= pf_icon('alert') ?>
      <ul><?php foreach ($errors as $err): ?><li><?= e(is_array($err) ? implode(' ', $err) : (string)$err) ?></li><?php endforeach; ?></ul>
    </div>
  <?php endif; ?>

  <!-- ====================== Identity + completion ====================== -->
  <header class="identity">
    <div class="photo-zone" data-photo-zone>
      <img class="avatar avatar-xl" alt="Your profile photo" data-header-avatar
           <?= $hasPhoto ? 'src="' . e(url_for((string)$profile['photo'])) . '"' : 'hidden' ?>>
      <span class="avatar avatar-xl" aria-hidden="true" data-header-avatar-placeholder<?= $hasPhoto ? ' hidden' : '' ?>><?= e($nameInitial) ?></span>
      <button type="button" class="photo-zone-btn" data-photo-btn data-value-action="edit-photo"
              aria-label="<?= $hasPhoto ? 'Change photo' : 'Upload a photo' ?>"
              title="JPG, PNG, WEBP or GIF, up to 2 MB. You can also drop an image here.">
        <?= pf_icon('camera') ?>
      </button>
      <input type="file" name="photo" accept="image/jpeg,image/png,image/webp,image/gif" hidden data-photo-input>
    </div>

    <div class="identity-body">
      <h1 class="identity-name"><?= e($displayName) ?></h1>
      <p class="identity-meta">
        <span>@<?= e($usernameStr) ?></span>
        <span class="badge"><?= e(degree_label($degreeType)) ?></span>
      </p>
      <p class="identity-headline" data-hero-profession<?= $basicValues['profession'] === '' ? ' hidden' : '' ?>><?= e($basicValues['profession']) ?></p>
      <div class="identity-actions">
        <button type="button" class="btn btn-secondary btn-sm" data-photo-upload data-value-action="edit-photo">
          <?= pf_icon('camera') ?> <span data-photo-label><?= $hasPhoto ? 'Change photo' : 'Upload photo' ?></span>
        </button>
        <button type="button" class="btn btn-ghost btn-sm" data-value-action="clear-photo"<?= $hasPhoto ? '' : ' hidden' ?>>Remove photo</button>
        <a class="btn btn-ghost btn-sm" href="<?= e(url_settings()) ?>">Account settings</a>
      </div>
    </div>

    <div class="completion" data-completion>
      <div class="completion-head">
        <span id="pf-progress-label">Profile completeness</span>
        <strong data-overall-text><?= $completePct ?>%</strong>
      </div>
      <?= ui_progress($completePct, 'pf-progress-label', ' data-overall-bar', ' data-overall-fill') ?>
      <p class="completion-detail" data-overall-detail>
        <?php if ($stepsDone === $stepsTotal): ?>
          Every section is filled in. Nice work.
        <?php else: ?>
          <?= $stepsDone ?> of <?= $stepsTotal ?> done. Suggested next:
        <?php endif; ?>
      </p>
      <div class="step-list" data-steps>
        <?php $shown = 0; foreach ($steps as $step):
            $show = !$step['done'] && $shown < 3;
            if ($show) { $shown++; }
        ?>
          <button type="button" class="step" data-step="<?= e($step['key']) ?>"<?= $show ? '' : ' hidden' ?>>
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
        <a class="side-nav-item<?= $basicsDone === count($BASICS) ? ' is-complete' : '' ?>" data-section-link="basics" href="#sec-basics" aria-current="true">
          <span>About</span>
          <span class="side-nav-count" data-nav-count><?= $basicsDone ?>/<?= count($BASICS) ?></span>
        </a>
        <?php foreach ($visibleKeys as $sectionKey):
            $n = count($sections[$sectionKey] ?? []);
        ?>
          <a class="side-nav-item<?= $n ? ' is-complete' : '' ?>" data-section-link="<?= e($sectionKey) ?>" href="#sec-<?= e($sectionKey) ?>">
            <span><?= e($SECTIONS[$sectionKey]['title']) ?></span>
            <span class="side-nav-count" data-nav-count<?= $n ? '' : ' hidden' ?>><?= $n ?></span>
          </a>
        <?php endforeach; ?>
      </nav>
      <p class="profile-nav-note">
        Sections follow your profile type
        (<strong class="font-medium text-muted-foreground-2"><?= e(degree_label($degreeType)) ?></strong>).
        Change it in <a href="<?= e(url_settings()) ?>">Settings</a>.
      </p>
    </aside>

    <!-- ============== Main column ============== -->
    <div class="min-w-0 max-w-3xl">

      <!-- ===== About (headline, bio, contact) ===== -->
      <section class="section" data-profile-section data-section="basics" id="sec-basics" data-section-key="" aria-labelledby="h-basics">
        <header class="section-head" data-section-head>
          <div>
            <h2 class="section-title" id="h-basics">About</h2>
            <p class="section-desc">Your headline, bio and contact details. Each one saves on its own.</p>
          </div>
        </header>

        <div data-basics-stack>
          <?php foreach ($BASICS as $bKey => $bDef):
              $val = $basicValues[$bKey];
          ?>
            <div class="value-card" data-value-card data-section="basics" data-field="<?= e($bKey) ?>"<?= $val === '' ? ' data-empty' : '' ?>>
              <div class="value-card-label" id="lbl-<?= e($bKey) ?>"><?= e($bDef['label']) ?></div>
              <div class="value-card-body" data-value-body>
                <span data-value-display<?= $val === '' ? ' class="muted"' : '' ?>><?= e($val !== '' ? $val : $bDef['empty']) ?></span>
              </div>
              <div data-value-actions>
                <button type="button" class="btn btn-ghost btn-sm -my-1.5" data-value-action="edit"
                        aria-label="<?= $val === '' ? 'Add' : 'Edit' ?> <?= e(strtolower($bDef['label'])) ?>">
                  <?= pf_icon('edit') ?> <span data-action-label><?= $val === '' ? 'Add' : 'Edit' ?></span>
                </button>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      </section>

      <!-- ===== Repeating sections ===== -->
      <?php foreach ($visibleKeys as $sectionKey):
          $def    = $SECTIONS[$sectionKey];
          $rows   = $sections[$sectionKey] ?? [];
          $isTags = pf_is_tag_section($sectionKey);
          $n      = count($rows);
      ?>
        <section class="section"
                 data-profile-section
                 data-section="<?= e($sectionKey) ?>"
                 data-section-key="<?= e($sectionKey) ?>"
                 id="sec-<?= e($sectionKey) ?>"
                 data-section-name="<?= e($def['title']) ?>"
                 aria-labelledby="h-<?= e($sectionKey) ?>">
          <header class="section-head" data-section-head>
            <div>
              <h2 class="section-title" id="h-<?= e($sectionKey) ?>">
                <?= e($def['title']) ?>
                <span class="section-count" data-section-count="<?= e($sectionKey) ?>"<?= $n ? '' : ' hidden' ?>><?= $n ?> <?= $n === 1 ? 'entry' : 'entries' ?></span>
              </h2>
              <?php if (!empty($def['description'])): ?>
                <p class="section-desc"><?= e($def['description']) ?></p>
              <?php endif; ?>
            </div>
            <button class="btn btn-secondary btn-sm" type="button"
                    data-value-action="add" data-section="<?= e($sectionKey) ?>"
                    aria-label="Add <?= e(strtolower($def['singular'])) ?>">
              <?= pf_icon('plus') ?> Add
            </button>
          </header>

          <div class="entry-list" data-row-list data-section="<?= e($sectionKey) ?>" id="row-list-<?= e($sectionKey) ?>"<?= $isTags ? ' data-layout="tags"' : '' ?>>
            <?php foreach ($rows as $row): ?>
              <?= render_value_row($sectionKey, $row, $def) ?>
            <?php endforeach; ?>
          </div>

          <button type="button" class="section-empty" data-section-empty="<?= e($sectionKey) ?>"
                  data-value-action="add" data-section="<?= e($sectionKey) ?>"<?= $n ? ' hidden' : '' ?>>
            <?= pf_icon('plus') ?>
            <span>Add your first <?= e(strtolower($def['singular'])) ?></span>
          </button>
        </section>
      <?php endforeach; ?>

    </div>

  </div>
</div>

<?php require __DIR__ . '/../src/includes/footer.php'; ?>

<?php
// ============================================================================
// View helpers. Row markup is rendered here for the first paint AND returned
// by the op-router as `html`, so the page has a single row renderer.
// ============================================================================

/** One complete `.entry` (summary + action buttons). */
function render_value_row(string $sectionKey, array $row, array $def): string
{
    $isTags = pf_is_tag_section($sectionKey);
    $p = row_summary_parts($sectionKey, $row, $def);
    $name = $p['title'] !== '' ? $p['title'] : strtolower($def['singular']);
    $id = (int)($row['id'] ?? 0);

    $btn = fn(string $action, string $label, string $icon, string $extra = ''): string =>
        '<button type="button" class="entry-action' . $extra . '" data-value-action="' . $action . '"'
        . ' aria-label="' . e($label . ' ' . $name) . '" title="' . $label . '">' . pf_icon($icon) . '</button>';

    return '<div class="entry" data-value-row data-section="' . e($sectionKey) . '"'
        . ' data-row-id="' . $id . '" data-row-n="' . $id . '" data-row-name="' . e($name) . '">'
        . '<div class="entry-body" data-value-body>' . row_summary_html($sectionKey, $row, $def, $isTags) . '</div>'
        . '<div class="entry-actions" data-value-actions>'
        . $btn('edit', 'Edit', 'edit')
        . ($isTags ? '' : $btn('duplicate', 'Duplicate', 'copy'))
        . $btn('delete', 'Delete', 'trash', ' is-danger')
        . '</div></div>';
}

function handle_profile_op(PDO $pdo, int $userId, string $degreeType, array $profile, array $sections, array $SECTIONS): void
    {
        verify_csrf();
        header('Content-Type: application/json; charset=utf-8');

        $op = (string)($_POST['op'] ?? '');

        try {
            switch ($op) {
                case 'update_basics':
                    op_update_basics($pdo, $userId, $profile);
                    return;
                case 'update_photo':
                    op_update_photo($pdo, $userId, $profile);
                    return;
                case 'clear_photo':
                    op_clear_photo($pdo, $userId, $profile);
                    return;
                case 'add_row':
                    op_add_row($pdo, $userId, $degreeType, $SECTIONS);
                    return;
                case 'update_row':
                    op_update_row($pdo, $userId, $degreeType, $SECTIONS);
                    return;
                case 'duplicate_row':
                    op_duplicate_row($pdo, $userId, $degreeType, $SECTIONS);
                    return;
                case 'delete_row':
                    op_delete_row($pdo, $userId, $degreeType, $SECTIONS);
                    return;
            }
        } catch (Throwable $e) {
            error_log('profile op error: ' . $e->getMessage());
            http_response_code(500);
            echo json_encode(['ok' => false, 'error' => 'Server error. Please try again.']);
            return;
        }

        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Unknown op.']);
    }

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

    // -------- update_basics --------
    function op_update_basics(PDO $pdo, int $userId, array $profile): void
    {
        $field = (string)($_POST['field'] ?? '');
        if (!in_array($field, ['phone', 'address', 'profession', 'bio'], true)) {
            op_json_error('Unknown basics field.');
            return;
        }
        $raw = (string)($_POST['value'] ?? '');

        $validators = [
            'phone'      => 'validate_phone',
            'address'    => 'validate_address',
            'profession' => 'validate_profession',
            'bio'        => 'validate_bio',
        ];
        $r = $validators[$field]($raw);
        if (!empty($r['errors'])) {
            op_json_field_errors($r['errors']);
            return;
        }
        $value = $r['value']; // null if empty
        $pdo->prepare("UPDATE profiles SET `$field` = :v WHERE user_id = :u")
            ->execute(['v' => $value, 'u' => $userId]);
        log_activity($userId, 'profile_update', 'basics:' . $field);
        op_json_ok(['value' => $value ?? '']);
    }

    // -------- update_photo --------
    function op_update_photo(PDO $pdo, int $userId, array $profile): void
    {
        $file = $_FILES['photo'] ?? null;
        if (!is_array($file) || ($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            op_json_error('No file uploaded.');
            return;
        }
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            op_json_error('Photo upload failed. Please try another image.');
            return;
        }
        if (!isset($file['tmp_name'], $file['size']) || !is_uploaded_file($file['tmp_name'])) {
            op_json_error('Invalid uploaded file.');
            return;
        }
        if ((int)$file['size'] > 2 * 1024 * 1024) {
            op_json_field_errors(['photo' => 'Photo must be 2 MB or smaller.']);
            return;
        }
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']);
        $info = @getimagesize($file['tmp_name']);
        $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];
        if (!$info || !isset($allowed[$mime])) {
            op_json_field_errors(['photo' => 'Choose a valid JPG, PNG, WEBP, or GIF image.']);
            return;
        }

        $dir = __DIR__ . '/uploads/profiles';
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            op_json_error('Upload directory could not be created.');
            return;
        }
        $filename = bin2hex(random_bytes(16)) . '.' . $allowed[$mime];
        $dest = $dir . '/' . $filename;
        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            op_json_error('Could not save the uploaded photo.');
            return;
        }
        $rel = 'uploads/profiles/' . $filename;

        $pdo->prepare('UPDATE profiles SET photo = :p WHERE user_id = :u')
            ->execute(['p' => $rel, 'u' => $userId]);

        if (!empty($profile['photo']) && $profile['photo'] !== $rel) {
            $old = __DIR__ . '/' . $profile['photo'];
            if (is_file($old)) { @unlink($old); }
        }
        log_activity($userId, 'profile_update', 'photo');
        op_json_ok(['value' => $rel]);
    }

    // -------- clear_photo --------
    function op_clear_photo(PDO $pdo, int $userId, array $profile): void
    {
        $pdo->prepare('UPDATE profiles SET photo = NULL WHERE user_id = :u')
            ->execute(['u' => $userId]);
        if (!empty($profile['photo'])) {
            $old = __DIR__ . '/' . $profile['photo'];
            if (is_file($old)) { @unlink($old); }
        }
        log_activity($userId, 'profile_update', 'photo_clear');
        op_json_ok(['value' => null]);
    }

    // -------- helpers for section row ops --------
    function op_normalize_section_row_input(string $sectionKey, array $SECTIONS, ?int $rowId, array $rowInput): array
    {
        // Returns ['row' => assoc, 'errors' => assoc-of-field => msg].
        $row = ['_origId' => $rowId];
        $errors = [];
        if (!isset($SECTIONS[$sectionKey]['fields'])) {
            return ['row' => $row, 'errors' => ['__' => 'Unknown section.']];
        }
        foreach ($SECTIONS[$sectionKey]['fields'] as $f) {
            [$name, $type, $label, $required, $max] = $f;
            $val = isset($rowInput[$name]) && is_string($rowInput[$name]) ? trim($rowInput[$name]) : '';
            if ($type === 'check') {
                $row[$name] = !empty($rowInput[$name]) ? 1 : 0;
                continue;
            }
            if ($val === '' && $required) {
                $errors[$name] = $label . ' is required.';
                continue;
            }
            if ($val !== '' && $max > 0 && mb_strlen($val, 'UTF-8') > $max) {
                $errors[$name] = $label . ' must be ' . $max . ' characters or fewer.';
                continue;
            }
            if (($type === 'year' || $type === 'date') && $val !== '' && !preg_match('/^\d{4}(-\d{2}(-\d{2})?)?$/', $val)) {
                $errors[$name] = $label . ' has an invalid format.';
                continue;
            }
            $row[$name] = $val === '' ? null : $val;
        }
        if (!isset($rowInput['sort_order']) || !is_numeric($rowInput['sort_order'])) {
            $row['sort_order'] = 0;
        } else {
            $row['sort_order'] = (int)$rowInput['sort_order'];
        }
        return ['row' => $row, 'errors' => $errors];
    }

    function op_insert_section_row(PDO $pdo, int $userId, string $sectionKey, array $SECTIONS, array $row): int
    {
        $cols = section_columns($sectionKey);
        $cols2 = array_filter($cols, fn($c) => $c !== 'sort_order');
        $allCols = array_merge(['user_id'], $cols2, ['sort_order']);
        $placeholders = array_map(fn($c) => ":$c", $allCols);
        $params = ['user_id' => $userId, 'sort_order' => $row['sort_order'] ?? 0];
        foreach ($cols2 as $c) { $params[$c] = $row[$c] ?? null; }
        $pdo->prepare("INSERT INTO `$sectionKey` (" . implode(',', array_merge(['user_id'], $cols2, ['sort_order'])) . ") VALUES (" . implode(',', $placeholders) . ")")
            ->execute($params);
        return (int)$pdo->lastInsertId();
    }

    function op_select_section_row(PDO $pdo, int $userId, string $sectionKey, int $rowId): ?array
    {
        $st = $pdo->prepare("SELECT * FROM `$sectionKey` WHERE id = :id AND user_id = :u");
        $st->execute(['id' => $rowId, 'u' => $userId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }

    // -------- add_row --------
    function op_add_row(PDO $pdo, int $userId, string $degreeType, array $SECTIONS): void
    {
        $section = (string)($_POST['section'] ?? '');
        if (!in_array($section, visible_sections($degreeType), true)) {
            op_json_error('Unknown section.');
            return;
        }
        $input = $_POST['fields'] ?? [];
        if (!is_array($input)) { $input = []; }
        // Optional `mode` = "blank" (default) or "fill" (use posted values).
        // `fill` is used by the Undo flow: re-insert a deleted row's old values.
        $mode = (string)($_POST['mode'] ?? 'blank');

        // Compute sort_order = MAX + 1 for the section.
        $max = (int)$pdo->query("SELECT COALESCE(MAX(sort_order), 0) FROM `$section` WHERE user_id = " . (int)$userId)->fetchColumn();
        $sortOrder = $max + 1;

        if ($mode === 'fill') {
            $norm = op_normalize_section_row_input($section, $SECTIONS, null, $input);
            if (!empty($norm['errors'])) { op_json_field_errors($norm['errors']); return; }
            $row = $norm['row'];
            // Undo passes the deleted row's sort_order so it goes back
            // where it was; a new entry is appended.
            $posted = $_POST['sort_order'] ?? null;
            $row['sort_order'] = (is_string($posted) && ctype_digit($posted)) ? (int)$posted : $sortOrder;
        } else {
            // Blank row: insert with a placeholder for each required text
            // field so the NOT NULL constraints pass. The JS opens the row
            // in edit mode immediately after creation, so the user fills
            // in real values within seconds.
            $row = ['sort_order' => $sortOrder];
            foreach ($SECTIONS[$section]['fields'] as $f) {
                [$name, $type, , $required] = $f;
                if ($required && $type !== 'check') {
                    $row[$name] = $row[$name] ?? '—';
                }
            }
        }

        try {
            $pdo->beginTransaction();
            $id = op_insert_section_row($pdo, $userId, $section, $SECTIONS, $row);
            $pdo->commit();
        } catch (PDOException $ex) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            throw $ex;
        }

        $fresh = op_select_section_row($pdo, $userId, $section, $id) ?: [];
        log_activity($userId, 'profile_update', 'add:' . $section);
        op_json_ok(['id' => $id, 'row' => $fresh, 'section' => $section,
            'html' => render_value_row($section, $fresh, $SECTIONS[$section])]);
    }

    // -------- update_row --------
    function op_update_row(PDO $pdo, int $userId, string $degreeType, array $SECTIONS): void
    {
        $section = (string)($_POST['section'] ?? '');
        if (!in_array($section, visible_sections($degreeType), true)) {
            op_json_error('Unknown section.');
            return;
        }
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) { op_json_error('Missing row id.'); return; }

        $input = $_POST['fields'] ?? [];
        if (!is_array($input)) { $input = []; }
        $existing = op_select_section_row($pdo, $userId, $section, $id);
        if (!$existing) { op_json_error('Row not found.', 404); return; }
        $norm = op_normalize_section_row_input($section, $SECTIONS, $id, $input);
        if (!empty($norm['errors'])) { op_json_field_errors($norm['errors']); return; }
        $row = $norm['row'];
        if (!isset($input['sort_order'])) { $row['sort_order'] = (int)$existing['sort_order']; }

        $cols = section_columns($section);
        $set = []; $params = [];
        foreach ($cols as $c) { $set[] = "`$c` = :$c"; $params[$c] = $row[$c] ?? null; }
        $params['id'] = $id; $params['u'] = $userId;
        $pdo->prepare("UPDATE `$section` SET " . implode(', ', $set) . " WHERE id = :id AND user_id = :u")
            ->execute($params);

        $fresh = op_select_section_row($pdo, $userId, $section, $id) ?: [];
        log_activity($userId, 'profile_update', 'update:' . $section);
        op_json_ok(['id' => $id, 'row' => $fresh, 'section' => $section,
            'html' => render_value_row($section, $fresh, $SECTIONS[$section])]);
    }

    // -------- duplicate_row --------
    function op_duplicate_row(PDO $pdo, int $userId, string $degreeType, array $SECTIONS): void
    {
        $section = (string)($_POST['section'] ?? '');
        if (!in_array($section, visible_sections($degreeType), true)) {
            op_json_error('Unknown section.');
            return;
        }
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) { op_json_error('Missing row id.'); return; }

        $orig = op_select_section_row($pdo, $userId, $section, $id);
        if (!$orig) { op_json_error('Row not found.', 404); return; }

        // Compute sort_order: original + 1 (so the new row sits right after
        // the source). Caller decides whether to manually re-sort later.
        $cols = section_columns($section);
        $row = [];
        foreach ($cols as $c) {
            if ($c === 'id') continue;
            $row[$c] = $orig[$c] ?? null;
        }
        $row['sort_order'] = ((int)($orig['sort_order'] ?? 0)) + 1;

        try {
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE `$section` SET sort_order = sort_order + 1 WHERE user_id = :u AND sort_order >= :s")
                ->execute(['u' => $userId, 's' => $row['sort_order']]);
            $newId = op_insert_section_row($pdo, $userId, $section, $SECTIONS, $row);
            $pdo->commit();
        } catch (PDOException $ex) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            throw $ex;
        }

        $fresh = op_select_section_row($pdo, $userId, $section, $newId) ?: [];
        log_activity($userId, 'profile_update', 'duplicate:' . $section);
        op_json_ok(['id' => $newId, 'row' => $fresh, 'section' => $section,
            'html' => render_value_row($section, $fresh, $SECTIONS[$section])]);
    }

    // -------- delete_row --------
    function op_delete_row(PDO $pdo, int $userId, string $degreeType, array $SECTIONS): void
    {
        $section = (string)($_POST['section'] ?? '');
        if (!in_array($section, visible_sections($degreeType), true)) {
            op_json_error('Unknown section.');
            return;
        }
        $id = (int)($_POST['id'] ?? 0);
        if ($id <= 0) { op_json_error('Missing row id.'); return; }

        // Return the deleted row's contents so the Undo flow can restore.
        $orig = op_select_section_row($pdo, $userId, $section, $id);

        $pdo->prepare("DELETE FROM `$section` WHERE id = :id AND user_id = :u")
            ->execute(['id' => $id, 'u' => $userId]);

        log_activity($userId, 'profile_update', 'delete:' . $section);
        op_json_ok(['id' => $id, 'section' => $section, 'row' => $orig ?: null]);
    }