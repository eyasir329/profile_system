# Components

Every named class the templates use, with the markup to copy. Styles are
in `resources/css/components/`; PHP helpers are in `src/includes/ui.php`.
For layout (grids, spacing, widths) use Tailwind utilities directly.

Contents: [Page](#page) · [Buttons](#buttons) · [Forms](#forms) ·
[Surfaces](#surfaces) · [Feedback](#feedback) · [Navigation](#navigation) ·
[Data](#data) · [Overlays](#overlays) · [Profile](#profile) ·
[PHP helpers](#php-helpers)

## Page

Each page body is wrapped in exactly one width class and starts with a
page header.

| Class | Width | Used by |
| --- | --- | --- |
| `.page-narrow` | 27 rem | Log in, register, password reset, 2FA code, notices |
| `.page` | 64 rem | Dashboard, settings, invitations, admin user form, one person's profile |
| `.page-wide` | 80 rem | Landing, own profile, directory |

```php
<div class="page">
  <a class="back-link" href="..."><?= pf_icon('back') ?> All users</a>
  <header class="page-header">
    <div>
      <h1 class="page-title">Invitations</h1>
      <p class="page-desc">One line that says what this page is for.</p>
    </div>
    <div class="flex flex-wrap gap-2"><!-- page actions --></div>
  </header>
  ...
</div>
```

Text helpers: `.muted` (secondary text), `.faint` (hints, placeholders
for missing values), `.mono`.

## Buttons

`.btn` plus one variant. Works on `<button>` and `<a>`.

| Variant | Use |
| --- | --- |
| `.btn-primary` | The one main action of a view |
| `.btn-secondary` | Other actions |
| `.btn-ghost` | Low-emphasis: Cancel, inline Edit |
| `.btn-danger` | Irreversible action (inside a confirm or danger card) |
| `.btn-danger-ghost` | Destructive but minor: Cancel invitation |

Sizes: `.btn-sm` (32 px), default (40 px), `.btn-lg` (44 px).
`.btn-block` is full width. `.btn-icon` is a 32 px square and must have
an `aria-label`.

```php
<button class="btn btn-primary" type="submit">Save changes</button>
<a class="btn btn-secondary btn-sm" href="..."><?= pf_icon('plus') ?> Add</a>
```

## Forms

```php
<div class="field">
  <label class="label" for="email">Email <span class="optional">(optional)</span></label>
  <input class="input" id="email" name="email" type="email"
         <?= isset($errors['email']) ? 'aria-invalid="true"' : '' ?>
         aria-describedby="email-hint email-err">
  <p class="hint" id="email-hint">You sign in with this address.</p>
  <p class="field-error" id="email-err" role="alert">Enter a valid email address.</p>
</div>
```

- `.input`, `.select`, `.textarea`: the three controls. The error look
  comes from `aria-invalid="true"`, not from a class.
- `.hint` (add `.is-ok` / `.is-bad` for live feedback), `.field-error`.
- `.field-under`: hint on the left, `.counter` on the right.
- `.input-affix` with `<span class="affix">@</span>` before the input.
- `.input-code`: six-digit code entry.
- `.form-grid`: two columns from `sm`; add `sm:col-span-2` for a full row.
- `.form-actions`: the submit row, right aligned (`.is-start` for left).
  `.form-note` inside it holds "You have unsaved changes."

**Password field** (show / hide):

```php
<div class="password-field" x-data="{ show: false }">
  <input class="input" type="password" :type="show ? 'text' : 'password'" ...>
  <?= ui_pw_toggle() ?>
</div>
```

**Choice list** (radio or checkbox with a title and explanation):

```php
<div class="choice-list" role="radiogroup" aria-labelledby="...">
  <label class="choice">
    <input type="radio" name="degree_type" value="phd">
    <span>
      <span class="choice-title">PhD</span>
      <span class="choice-desc">Everything in Bachelor, plus journal articles.</span>
    </span>
  </label>
</div>
```

`.choice-list.is-row` lays three options side by side from `sm`.
`.check` is the plain checkbox-with-label row.

## Surfaces

| Class | Use |
| --- | --- |
| `.card` | Bordered panel for a form or a self-contained summary. Never nested. Consecutive cards space themselves. |
| `.card-head`, `.card-title`, `.card-desc`, `.card-link` | Card heading row, title, one-line description, small link in the head |
| `.card-danger` | Red border and title, for destructive areas |
| `.section`, `.section-head`, `.section-title`, `.section-count`, `.section-desc` | Unboxed block with a ruled heading, for long reading pages |
| `.action-row` | Explanation on the left, one action on the right; stack several in a card |

```php
<section class="card" aria-labelledby="x-h">
  <h2 class="card-title" id="x-h">Password</h2>
  <p class="card-desc">Use at least 8 characters.</p>
  ...
</section>
```

## Feedback

**Alert** (belongs to the page: form errors, account state). Use the
helper so icon and ARIA role are right:

```php
<?= ui_alert('error', 'Nothing was saved. Please fix the highlighted field.') ?>
```

Types: `error`, `success`, `warning`, `info`. The second argument is
trusted HTML: escape user data with `e()` yourself.

**Badge** (a short state word): `.badge`, plus `.badge-success`,
`.badge-warning`, `.badge-danger` when the state matters. Profile types
and courses are plain `.badge`.

**Role**: `<?= ui_role('teacher') ?>` or `<?= ui_roles(['admin', 'student']) ?>`.

**Progress**: `<?= ui_progress(43, 'id-of-label') ?>`.

**Empty state**:

```php
<div class="empty">
  <h2 class="empty-title">No invitations yet</h2>
  <p>Create a link and send it to the person who needs an account.</p>
  <a class="btn btn-secondary" href="...">Create link</a>
</div>
```

**Toast** (something already happened): set `$flash` in PHP or call
`PS.toast()` in JS. See [`FRONTEND.md`](FRONTEND.md#stores).

`.kbd` renders a key name.

## Navigation

- **Top bar** (`.navbar`, `.brand`, `.nav-links`, `.nav-link`, `.nav-end`,
  `.nav-drawer`): rendered only by `src/includes/header.php`. To add a
  destination, add a row to `$NAV_ITEMS_BY_ROLE` and `$NAV_HREF_FOR` there.
- **Menu** (`.menu-wrap`, `.menu-trigger`, `.menu`, `.menu-item`,
  `.menu-label`, `.menu-sep`): a dropdown driven by `x-data="dropdown"`.
  Copy the account menu in `header.php`.
- **Side navigation** (`.side-nav`, `.side-nav-item`, `.side-nav-count`):
  a vertical list from `lg`, a scrolling strip below. Mark the current
  item with `aria-current="page"` (or `"true"` for in-page sections).
- **Segmented switch** (`.segmented`): two or three views of the same
  data; the current link has `aria-current="true"`.

## Data

**Avatar**: `<?= ui_avatar($photo, $name, 'avatar-sm') ?>`. Sizes
`avatar-sm` 28, default 36, `avatar-lg` 56, `avatar-xl` 88.

**Person** (avatar, name, secondary line):

```php
<a class="person" href="...">
  <?= ui_avatar($u['photo'], $u['name']) ?>
  <span class="min-w-0">
    <span class="person-name">Aiko Tanaka</span>
    <span class="person-sub">@aiko · ID 24M1042</span>
  </span>
</a>
```

**Toolbar**: `.toolbar` is the filter row; `.grow` takes the spare width;
`.search-field` puts a search icon inside an input.

**Table**:

```php
<div class="table-wrap">
  <div class="table-scroll max-md:hidden">
    <table class="table">...</table>
  </div>
  <ul class="card-list md:hidden">
    <li class="card-list-item">...</li>
  </ul>
</div>
```

Cell helpers: `.nowrap`, `.pre` (keeps line breaks), `.num`,
`.col-actions`. Sortable headers use `.sort-link` and `aria-sort` on the
`<th>`. `.meter` is the progress bar plus percentage in a cell. Add
`.sheet` to the table (and `.sheet-frame`, `.sheet-scroll`, `.pin`,
`.wide`) for the wide view; see `src/includes/directory.php`.

**Facts** (label and value pairs): `<dl class="facts">` with one `<div>`
per pair. `.is-single` forces one column; `.is-stacked` puts long values
under their label.

**Copy box**:

```php
<div class="copy-box">
  <span class="value"><?= e($url) ?></span>
  <button type="button" class="btn btn-secondary btn-sm" x-copy data-copy="<?= e($url) ?>">
    <?= pf_icon('copy') ?> Copy
  </button>
</div>
```

**Steps**: `<ol class="steps">`, each `<li>` with a `<strong>` title and
a `<span>` of explanation.

## Overlays

There is one confirm dialog and one toast stack, both in `footer.php`.
Do not add more dialogs to pages.

```php
<form method="post" x-confirm
      data-confirm-title="Delete this user?"
      data-confirm-body="There is no undo."
      data-confirm-ok="Delete permanently">
```

## Profile

Used by `/profile.php` and, read-only, by the directory.

| Class | Use |
| --- | --- |
| `.identity`, `.identity-body`, `.identity-name`, `.identity-meta`, `.identity-headline`, `.identity-actions` | Photo, name, handle, headline |
| `.photo-zone`, `.photo-zone-btn` | Avatar with camera button and drop target |
| `.completion`, `.step-list`, `.step` | Completeness panel and suggested next steps |
| `.profile-layout`, `.profile-nav` | Section list plus content |
| `.value-card` | One basics field: label, value, Edit |
| `.entry-list`, `.entry`, `.entry-body`, `.entry-title`, `.entry-sub`, `.entry-meta`, `.entry-text`, `.entry-links`, `.entry-actions` | One row of a repeating section |
| `.entry-list[data-layout="tags"]`, `.tag-name`, `.tag-level` | Skills, languages, research interests as chips |
| `.section-empty` | "Add your first ..." button |
| `.inline-form`, `.draft` | The form the editor builds in place |

Entry markup always comes from `row_summary_html()` in `ui.php`; do not
write it by hand.

## PHP helpers

All in `src/includes/ui.php`. Each returns an HTML string.

| Helper | Returns |
| --- | --- |
| `pf_icon($name, $class = '')` | A 16 px stroke icon. Names: `edit copy trash plus camera link chevron caret back check close menu search user users settings lock shield login logout swap palette alert info mail dot expand collapse` |
| `ui_avatar($photo, $name, $size = '')` | Photo or initial |
| `ui_role($role)`, `ui_roles($roles)` | Role with its dot; a list, or a "No role" badge |
| `ui_alert($type, $html)` | Inline alert |
| `ui_pw_toggle()` | Show / hide button for a password field |
| `ui_progress($percent, $labelledBy = '')` | Progress bar |
| `ui_ago($seconds, $absolute)` | "5 minutes ago" |
| `ui_activity($kind, $detail, $count)` | `[icon, sentence]` for an activity row |
| `row_summary_parts()`, `row_summary_html()` | Read-only rendering of a profile entry |

Icons are decorative (`aria-hidden`). Pair them with text, or put an
`aria-label` on the button.
