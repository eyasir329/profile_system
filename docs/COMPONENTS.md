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
| `.page` | 64 rem | Dashboard, own profile, settings, invitations, admin user form, one person |
| `.page-wide` | 80 rem | Landing, directory |

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
- **Side navigation** (`.side-nav`, `.side-nav-item`): the settings
  sections. A vertical list from `lg`, a scrolling strip below. Mark the
  current item with `aria-current="page"`.
- **Segmented switch** (`.segmented`): two or three views of the same
  data; the current link has `aria-current="true"`.
- **Sheet tabs** (`.sheet-tabs`): links styled as underlined tabs, current
  one `aria-current="page"`, with an optional `.tab-count`. Add
  `.is-wrap` when there are many (they wrap as pills, none hidden) and
  `.is-compact` inside the full-screen bar. Used by the Students sheet and
  `/resources.php`. Wrap several groups in `.tab-groups` > `.tab-group`
  with a `.tab-group-label` each (Resources: "Shared by teachers" and
  "Lab publications").
- **Filter chips** (`.filter-chips`, `.filter-chips-label`): small pill
  links with counts that narrow a list, current one `aria-current="page"`.
  The Students sheet filters by course with them.

## Data

**Avatar**: `<?= ui_avatar(null, $name, 'avatar-sm') ?>` shows the first letter of the name (there are no profile photos). Sizes
`avatar-sm` 28, default 36, `avatar-lg` 56, `avatar-xl` 88.

**Person** (avatar, name, secondary line):

```php
<a class="person" href="...">
  <?= ui_avatar(null, $u['name']) ?>
  <span class="min-w-0">
    <span class="person-name">Aiko Tanaka</span>
    <span class="person-sub">ID 24M1042</span>
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

**Sheet extras**:
- `.pin` keeps the first column at the left and `.pin-end` keeps the last
  (row actions) at the right while the sheet scrolls sideways.
- `.freeze .freeze-1` … `.freeze-4` freeze several leading columns, like
  frozen panes in Excel, from 80rem (1280 px) up. The `sheet` component
  measures each column and sets the offsets (`--fz1` … `--fz4`), so the
  columns may be any width. The teachers' Students sheet freezes Course,
  Student ID and both names.
- `tr.group-start` draws a thicker line where a new group (course) begins.
- `.sheet-tools` is a sheet's own toolbar: a filter box, an optional
  "Only mine" check and a `.sheet-tools-count`. Header buttons
  `.sort-btn` sort the column. Both are driven by `x-data="sheetFilter"`
  (see FRONTEND.md); they work in the browser, with no reload.
- `.sheet` tables use separate (not collapsed) borders so that pinned and
  frozen cells keep their borders while the rest scrolls underneath.

**Alternating rows**: every `.sheet` table stripes even rows in a tint of
the primary colour (`--stripe`, opaque so the pinned first column stays
readable). Rows hidden by a filter are skipped, so the stripes stay even
(`:nth-child(even of :not([hidden]))`). A row with `class="is-mine"` is tinted more, in two shades.
Hover wins over both.

**Several items in one cell** (`.multi`): `ui_multi($items)` returns plain
text for one item and a numbered `<ol class="multi">` with dashed dividers
for several. Use it wherever a cell or field can hold more than one item
(theses, papers, award recipients).

**Tiles** (`.tile-grid`, `.tile`, `.tile-icon`, `.tile-title`,
`.tile-value`): link cards with an icon, a title and a live number; the
student dashboard's "Your lab".

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

Used by `/profile.php`.

| Class | Use |
| --- | --- |
| `.identity`, `.identity-body`, `.identity-name`, `.identity-meta`, `.identity-actions` | Avatar, name, email, course and student ID. Also the header of one person in the directory |
| `.completion`, `.step-list`, `.step` | "Lab details filled in" panel and suggested next fields |
| `.value-card`, `.value-card-label`, `.value-card-body` | One lab field: label, value, and either an Edit button or a "Set by admin" note |
| `.inline-form` | The form the editor builds in place of the value |
| `.entry-list`, `.entry`, `.entry-body`, `.entry-title`, `.entry-sub`, `.entry-meta`, `.entry-text`, `.entry-actions`, `.entry-action` | One entry of a repeating section (a thesis, paper, publication or award) with Edit and Delete |
| `.entry-chips`, `.entry-citation`, `.entry-bibtex` | A publication card: date, type and impact-factor badges, the citation, folded BibTeX with a copy button |
| `.entry-form` | An entry's form: three columns from `sm` up, each field sets `--span` (1 to 3) |
| `.tab-list`, `.tab-count`, `.tab-panel-head` | Tabs inside a section (Publications & awards); `role="tablist"` buttons driven by the profile editor |
| `.section-empty` | "Add your first …" button while a section has no entries |

The fields themselves come from `lab_field_defs()` in
`src/includes/lab_fields.php`; do not hard-code them in a template.

## PHP helpers

All in `src/includes/ui.php`. Each returns an HTML string.

| Helper | Returns |
| --- | --- |
| `pf_icon($name, $class = '')` | A 16 px stroke icon. Names: `edit copy trash plus camera link chevron caret back check close menu search user users settings lock shield login logout swap palette alert info mail dot expand collapse cap book folder download` |
| `ui_avatar($photo, $name, $size = '')` | Initial avatar (pass `null` for the photo) |
| `ui_role($role)`, `ui_roles($roles)` | Role with its dot; a list, or a "No role" badge |
| `ui_alert($type, $html)` | Inline alert |
| `ui_pw_toggle()` | Show / hide button for a password field |
| `ui_progress($percent, $labelledBy = '')` | Progress bar |
| `ui_multi($items)` | One item as text, several as a numbered list with dividers |
| `ui_ago($seconds, $absolute)` | "5 minutes ago" |
| `ui_activity($kind, $detail, $count)` | `[icon, sentence]` for an activity row |

Icons are decorative (`aria-hidden`). Pair them with text, or put an
`aria-label` on the button.
