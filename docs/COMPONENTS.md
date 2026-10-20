# Components

Every named class the templates use, with the markup to copy. Styles are
in `resources/css/components/`; PHP helpers are in `src/includes/ui.php`.
For layout (grids, spacing, widths) use Tailwind utilities directly.

Contents: [Page](#page) · [Buttons](#buttons) · [Forms](#forms) ·
[Surfaces](#surfaces) · [Feedback](#feedback) · [Navigation](#navigation) ·
[Data](#data) · [Overlays](#overlays) · [Profile](#profile) · [Emails](#emails) · [Charts](#charts) · [Home page](#home-page) ·
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

**Switch** (on/off toggle; a real checkbox, so it posts like one):

```php
<label class="switch">
  <input type="checkbox" role="switch" name="mail_enabled" value="1" aria-labelledby="...">
  <span class="switch-track"></span>
</label>
```

## Surfaces

| Class | Use |
| --- | --- |
| `.card` | Bordered panel for a form or a self-contained summary. Never nested. Consecutive cards space themselves. |
| `.card-head`, `.card-title`, `.card-desc`, `.card-link` | Card heading row, title, one-line description, small link in the head |
| `.card-danger` | Red border and title, for destructive areas |
| `.card-head` (in a card) | A grey header strip ruled off from the card body (dashboard cards) |
| `.section`, `.section-head`, `.section-title`, `.section-count`, `.section-desc` | A panel with a grey header strip and a blue marker: profile sections, a person's page, About, Help, Privacy, the invitation list. `.section-head` outside a `.section` is a plain ruled heading |
| `.panel-tint` | A tinted box inside a card for something to act on now (new backup codes) |
| `.doc` | Reading text in a panel: paragraph spacing, bulleted lists |
| `.faq` | Help: `<details>` questions that open to show the answer (`.faq-caret` icon) |
| `.error-card`, `.error-code`, `.error-actions`, `.error-help` | The error page (rendered by `render_error_page()`; don't build one by hand) |
| `.toolbar`, `.sheet-tools` | Search, filters, export and full screen in one tinted strip above a table, apart from the data |
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
  `.nav-drawer`): rendered only by `src/includes/header.php`. A solid
  ink-blue band (`--appbar*` tokens); the current page is a lighter tab
  with an amber rule. To add a destination, add a row to
  `$NAV_ITEMS_BY_ROLE` and `$NAV_HREF_FOR` there. Each destination appears
  once per page: don't add an in-page button that repeats a tab.
- **Menu** (`.menu-wrap`, `.menu-trigger`, `.menu`, `.menu-item`,
  `.menu-label`, `.menu-sep`): a dropdown driven by `x-data="dropdown"`.
  Copy the account menu in `header.php`.
- **Side navigation** (`.side-nav`, `.side-nav-item`): the settings
  sections and the profile's "On this page". A vertical list from `lg`, a
  scrolling strip below. Mark the current item with `aria-current="page"`
  (or `"true"`): blue text, tool tint, a bar on the left (red for
  `.is-danger`). Add `.is-panel` to make a standalone list a panel from
  `lg` up (Settings).
- **Segmented switch** (`.segmented`): two views of the same data inside a
  toolbar; the current link has `aria-current="true"` and is solid blue.
- **View tabs** (`.view-tabs`, `.view-tabs-count`): two or three groups of
  the same list, above its toolbar (Students: Current / Completed). A
  segmented control on the tool tint; the current link
  (`aria-current="page"`) is a raised white tab with a blue count. Equal
  widths on phones; `.is-compact` in the full-screen bar.
- **Sheet tabs** (`.sheet-tabs`): links styled as underlined tabs, current
  one `aria-current="page"`, with an optional `.tab-count`. Add
  `.is-wrap` when there are many (they wrap as pills, none hidden) and
  `.is-compact` inside the full-screen bar (current tab raised like
  `.view-tabs`). Used by `/resources.php`. Wrap several groups in
  `.tab-groups` > `.tab-group` with a `.tab-group-label` each: one tinted
  panel, each label on the same line as its tabs (Resources: "Shared
  resources" and "Lab publications").
- **Footer links** (`.site-footer-links`): About, Help, Privacy, rendered
  by `src/includes/footer.php`.
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
| `.tab-list`, `.tab-count`, `.tab-panel-head` | Tabs inside a section (Publications & awards); `role="tablist"` buttons driven by the profile editor. Same look as `.view-tabs`: the selected tab is raised with a blue count |
| `.section-empty` | "Add your first …" button while a section has no entries |

The fields themselves come from `lab_field_defs()` in
`src/includes/lab_fields.php`; do not hard-code them in a template.

## Emails

Used by `/admin/emails.php`; styles in `resources/css/components/mail.css`.

| Class | Use |
| --- | --- |
| `.stat-strip`, `.stat-note` | A row of four numbers above a log (`<dl>`, one `<div>` per number; a link in `<dd>` makes it a filter; `.is-danger` paints it red) |
| `tr.table-group` | A day heading row inside a `.table` (`<th colspan>`); `.card-list-day` is the same in the phone list |
| `tr.has-row-link` + `.row-link` | The whole row opens the link in its first cell |
| `.cell-sub`, `.cell-error` | Second line in a cell; a one-line error under a badge |
| `.pick`, `.pick-head`, `.pick-list`, `.pick-row`, `.pick-empty`, `.pick-foot` | Search box over a scrolling list of people with checkboxes |
| `.chip-list`, `.chip` | Picked items as pills with a remove button |
| `.send-bar`, `.send-bar-note` | The bottom row of a send form: who it goes to, then the buttons |
| `.setting-row`, `.setting-body`, `.setting-title`, `.setting-meta`, `.setting-stat`, `.setting-off` | One setting with a `.switch` on the right; `.is-muted` fades it (not its `.setting-off` warning) |
| `.server-facts` | Read-only label / value pairs in monospace (mail server settings) |
| `.user-summary` (with `.completion`) | Admin › one user: label / value summary inside the identity panel |
| `.save-bar` (`.is-dirty`) | A form's Save row that sticks to the bottom of the screen while editing |
| `.section.is-danger` | A section with a red marker (Remove access) |
| `.bulk-bar`, `.bulk-bar-count`, `.pick-all`, `.pick-one`, `tr.is-selected` | Admin › Users: select rows, then act on them (Alpine `bulkSelect`) |
| `.attention`, `.attention-item` (`.is-danger`, `.is-warning`, `.is-info`), `.attention-icon` | Dashboard: "Needs attention" rows with one action each |
| `.palette-trigger`, `.palette-backdrop`, `.palette`, `.palette-search`, `.palette-list`, `.palette-item`, `.palette-kind`, `.palette-kbd`, `.palette-foot` | The admin command palette (Alpine `palette`, Ctrl/⌘ + K) |
| `.stat-strip.is-2col` | The stat strip as two by two, for a narrower column |
| `.mail-preview`, `.mail-head`, `.mail-canvas`, `.mail-brand`, `.mail-logo`, `.mail-body`, `.mail-button`, `.mail-foot`, `.mail-placeholder` | An email as the recipient sees it, in the same layout as `mail_render_html()` |

## Charts

HTML/CSS charts from `src/includes/charts.php` (styles in
`resources/css/components/chart.css`), used by Admin › Activity and
Admin › Emails. Each returns a `<figure class="chart">` with a title, a
legend when there are two or more series, a hover/focus tooltip (the
`x-chart` directive) and a **Show as table** twin.

```php
<?= chart_columns(['sent' => ['Sent', '--series-1'], 'failed' => ['Failed', '--series-alert']],
                  [['label' => 'Mon, Oct 12', 'tick' => 'Oct 12', 'values' => ['sent' => 4, 'failed' => 1]]],
                  ['title' => 'Emails per day', 'desc' => 'Last 30 days.', 'unit' => 'emails']) ?>
<?= chart_heatmap($grid /* [0..6 Mon..Sun][0..23] */, ['title' => 'When people are active', 'unit' => 'events']) ?>
<?= chart_bars([['label' => 'Aiko', 'value' => 42, 'href' => '...']], ['title' => 'Most active people']) ?>
```

Colour roles (never raw hex in templates): `--series-1` … `--series-3`
(categorical, in this order, never cycled; validated all-pairs for
colour-blind and normal vision on the card surface in both themes),
`--series-alert` (the status red, only beside `--series-1`: emphasis
form), `--series-muted` (de-emphasis grey), `--heat-0` … `--heat-7` (one
blue ramp). Do not put red and green side by side: they fail the
colour-blind check. Text never takes a series colour.

| Class | Use |
| --- | --- |
| `.chart`, `.chart-head`, `.chart-title`, `.chart-desc`, `.chart-legend`, `.chart-key` | The card, its title block and legend |
| `.chart-plot`, `.chart-grid`, `.chart-cols`, `.chart-col`, `.chart-stack`, `.chart-x` (`.is-dense`) | Stacked columns: hairline grid, columns ≤ 24 px with a 4 px rounded top and 2 px gaps |
| `.heat`, `.heat-hour`, `.heat-day`, `.heat-cell[data-step]`, `.heat-scale` | Weekday × hour heatmap and its Less–More scale |
| `.bars`, `.bars-label`, `.bars-track`, `.bars-fill`, `.bars-value` | Horizontal bars with the value at the tip |
| `.chart-table` | The `<details>` table twin |
| `.chart-tip` | The one tooltip, created on `<body>` by `x-chart` |
| `.chart-grid-2` | Two charts side by side from `lg` |
| `.activity-line` (`.is-warning`, `.is-danger`) | One activity event: icon and sentence (Admin › Activity) |

## Home page

`resources/css/components/landing.css`, used only by `public/index.php`.
One column on phones, two on tablets, the full layout from `lg`.

| Class | What |
| --- | --- |
| `.landing` | Wrapper inside `.page-wide`: the page's top and bottom space |
| `.landing-hero`, `.landing-hero-text`, `.landing-sample` | Headline and actions beside the sample profile (stacked below `lg`) |
| `.landing-eyebrow` | Small pill above the headline ("Private to the lab · by invitation") |
| `.landing-title`, `.landing-lead` | Fluid serif headline (`clamp()`, 32 to 52 px) and its paragraph |
| `.landing-actions`, `.landing-note` | Button row (full-width buttons on phones) and the line under it |
| `.landing-section`, `.landing-section-head`, `.landing-h2`, `.landing-sub` | A ruled section with its heading and one line of context |
| `.landing-split` | Heading on the left, content on the right from `lg` ("How to get an account") |
| `.feature-grid` > `.feature` (`.feature-icon`, `.feature-title`, `.feature-text`) | Feature cards: 1, 2 or 3 per row |
| `.role-grid` > `.role-card` (`.role-card-summary`, `.role-card-list`) | One card per role with a tick list |
| `.landing-help`, `.landing-help-links` | Tinted strip with links to Help, Privacy and About |

```html
<ul class="feature-grid">
  <li class="feature">
    <span class="feature-icon" aria-hidden="true"><?= pf_icon('edit') ?></span>
    <h3 class="feature-title">Fill it in where you read it</h3>
    <p class="feature-text">Every field saves on its own.</p>
  </li>
</ul>
```

The home page is public: use invented sample data only, never a real
member, count, lab name or address.

## PHP helpers

All in `src/includes/ui.php`. Each returns an HTML string.

| Helper | Returns |
| --- | --- |
| `pf_icon($name, $class = '')` | A 16 px stroke icon. Names: `edit copy trash plus camera link chevron caret back check close menu search user users settings lock shield login logout swap palette alert info mail dot pulse expand collapse cap book folder download` |
| `ui_avatar($photo, $name, $size = '')` | Initial avatar (pass `null` for the photo) |
| `ui_role($role)`, `ui_roles($roles)` | Role with its dot; a list, or a "No role" badge |
| `ui_alert($type, $html)` | Inline alert |
| `ui_pw_toggle()` | Show / hide button for a password field |
| `ui_progress($percent, $labelledBy = '')` | Progress bar |
| `ui_multi($items)` | One item as text, several as a numbered list with dividers |
| `ui_ago($seconds, $absolute)` | "5 minutes ago" |
| `ui_activity($kind, $detail, $count)` | `[icon, sentence]` for an activity row (second person, for the member's dashboard) |
| `ui_activity_admin($kind, $detail)` | `[icon, sentence, tone]` for Admin › Activity (third person; tone `warning` / `danger` for events worth a look) |
| `activity_group_of($kind)` | The `ACTIVITY_GROUPS` area of a kind (Sign-ins, Security, Account, …) |
| `ui_device($userAgent)` | "Chrome on Windows" |

Icons are decorative (`aria-hidden`). Pair them with text, or put an
`aria-label` on the button.

## Server helpers

| Helper | File | Use |
| --- | --- | --- |
| `render_error_page($code, $detail = '', minimal: false)` | `src/includes/error_page.php` | Send an error status and the error page, then stop. `minimal: true` when the session or database may be unavailable |
| `mail_custom($to, $name, $subject, $paras, $sentBy)` | `src/includes/mail_templates.php` | An admin-written email (logged in full, kind `custom`) |
| `mail_types()`, `mail_type_enabled($kind)`, `mail_off_reason($kind)`, `mail_setting($name, $default)`, `mail_settings_save($pdo, $values, $by)` | `src/includes/mailer.php` | Every kind of email and what switching it off means; whether one will be sent (server set up, master switch, its own switch) and why not; read and save Emails › Settings |
| `mail_invitation()`, `mail_password_reset()`, `mail_password_changed()`, `mail_email_changed()`, `mail_two_factor_off()`, `mail_backup_code_used()`, `mail_verify_new_email()`, `mail_account_deactivated()` | `src/includes/mail_templates.php` | Send one email; true when the server accepted it. Never throws |
| `mail_send($to, $name, $msg, $meta = [])`, `mail_enabled()`, `mail_link($path)` | `src/includes/mailer.php` | Low-level sending (one HTML + text layout), which also writes every attempt to `email_log` (links in system emails are stored as `[link hidden]`); whether email is set up; an absolute link from `APP_URL` |
| `request_email_change($pdo, $userId, $name, $newEmail)` | `src/includes/email_change.php` | Change your own sign-in email: `'sent'` (confirmation emailed), `'changed'` (no email set up) or `'failed'` |
| `second_factor_check($userId, $input)` | `src/includes/auth.php` | The two-factor step: `'totp'`, `'backup'`, `'locked'` or `''` |
| `session_epoch_bump($pdo, $userId, $keepCurrent = true)` | `src/includes/auth.php` | Sign out every other session of an account (call after any password or two-factor change) |
| `app_timezone()`, `app_timezone_label($tz = null)` | `src/includes/db.php` | The site time zone (Admin › Site settings), applied to PHP and the DB connection on every request; "Asia/Dhaka (UTC+06:00)". Store moments as `TIMESTAMP`, never `DATETIME` |
| `activity_retention($pdo)`, `activity_purge($pdo)` | `src/includes/housekeeping.php` | How long activity and its IP/browser are kept (Admin › Site settings); apply it now |
| `housekeeping_run($pdo)` | `src/includes/housekeeping.php` | Delete what is past its keep-by date (also `make purge`) |
