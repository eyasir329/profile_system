# Front end

How the CSS and JavaScript are built and organised. For the reasoning see
[`DESIGN.md`](DESIGN.md); for the class reference see
[`COMPONENTS.md`](COMPONENTS.md).

## Toolchain

| Package | Version | Role |
| --- | --- | --- |
| `tailwindcss`, `@tailwindcss/cli` | 4.3.3 | Compiles `resources/css` to one CSS file |
| `@tailwindcss/forms` | 0.5.11 | Resets native form controls (base strategy) |
| `preline` | 5.0.0 | Only `preline/theme.css` is imported (colour tokens). Its JavaScript is not used. |
| `alpinejs` | 3.x | Behaviour, bundled into `app.js` |
| `esbuild` | current | Bundles and minifies `resources/js` |
| `@fontsource-variable/source-serif-4`, `@fontsource/ibm-plex-sans` | current | Font files, copied to `public/assets/fonts` |

All are dev dependencies. The server needs none of them: it serves the
built files in `public/assets/`, which are committed.

## Commands

```bash
make assets          # build once, in a Node container (no Node on the host)
make assets-watch    # rebuild on change

# or, with Node 20+ installed:
npm ci
npm run build        # CSS + JS + fonts, minified
npm run dev          # watch CSS and JS
```

**When to rebuild:** after editing anything in `resources/`, and after
using a Tailwind utility in a `.php` file that was not used anywhere
before. Tailwind only emits the classes it finds in `public/`, `src/` and
`resources/js/`. If a new class has no effect, you have not rebuilt.

`header.php` appends `?v=<file modification time>` to the CSS and JS
URLs, so a new build is picked up without a hard refresh.

## Layout

```
resources/
├── css/
│   ├── app.css            entry: imports, @source paths, dark variant
│   ├── theme.css          design tokens (palette, fonts, radii, status colours)
│   ├── fonts.css          @font-face rules
│   ├── base.css           element defaults, page widths, page header
│   └── components/
│       ├── button.css     .btn and variants
│       ├── form.css       .field .label .input .select .textarea .hint .choice ...
│       ├── surface.css    .card .section .action-row
│       ├── feedback.css   .alert .badge .role .progress .empty .kbd
│       ├── navigation.css .navbar .menu .side-nav .segmented .site-footer
│       ├── data.css       .avatar .person .toolbar .table .sheet .facts .copy-box .steps
│       ├── overlay.css    .modal .toast
│       ├── profile.css    .identity .completion .value-card .inline-form
│       ├── mail.css       .stat-strip .pick .chip .mail-preview (Admin › Emails)
│       ├── chart.css      .chart .chart-col .heat .bars .chart-tip (charts.php)
│       ├── admin.css      .user-summary .save-bar .bulk-bar .attention .palette
│       └── landing.css    .landing-* .feature-grid .role-grid (the public home page)
├── js/
│   ├── app.js             entry: starts Alpine, flash toasts, first-error focus
│   ├── ui.js              Alpine stores, directives, components
│   └── profile-editor.js  inline editor for /profile.php
└── copy-fonts.mjs         copies the woff2 files out of node_modules

public/assets/             BUILD OUTPUT, do not edit by hand
├── css/app.css
├── js/app.js
└── fonts/*.woff2
```

## CSS

- Import order in `app.css` matters: Tailwind, then Preline's
  `theme.css`, then our `theme.css` (which overrides it), then base and
  components.
- Components are written with `@apply` inside `@layer components`, so a
  utility on the same element always wins. `class="btn btn-primary mt-6"`
  works as expected.
- Dark mode is the `dark` variant, bound to the `dark` class on `<html>`.
  Because components use tokens, most need no `dark:` rule at all.
- Colours come from tokens (`bg-layer`, `text-muted-foreground-2`,
  `border-danger-line`). Do not use raw palette classes in templates.

### Responsive rules

Every page must work from a 320 px phone to a 1920 px desktop, in portrait
and landscape, with touch or a mouse. Breakpoints are Tailwind's: `sm`
640, `md` 768, `lg` 1024, `xl` 1280 px. Mobile first: unprefixed classes
are the phone layout, prefixes add columns as the screen grows.

- **Top bar**: the full bar from `lg`; below it (phones *and* tablets)
  the links, role switcher and account move into the drawer. The admin
  bar does not fit a 768 px tablet, so every role switches at the same
  width. The drawer scrolls on its own if it is taller than the screen.
- **No sideways page scroll, ever.** Wide content (tables, the sheet,
  tab strips) scrolls inside its own frame. Any box with `overflow:
  auto | hidden` must also be `position: relative`: otherwise the
  absolutely positioned `.sr-only` labels inside it ("Not set" in empty
  cells) escape the clipping and stretch the whole page (this made the
  Students sheet 2,500 px wide on phones). `.sr-only` also gets `left: 0`
  as a safety net (`base.css`).
- **Grid and flex children shrink.** `base.css` gives grid items
  `min-width: 0`; give flex children that hold text `min-w-0` (or a
  sensible `min-w-[10rem]`) and rows of buttons `flex-wrap`, so a long
  name or a row of actions wraps instead of overflowing. Long unbroken
  words wrap (`overflow-wrap: break-word` on `body`).
- **Tabs**: `.view-tabs` wrap onto a second row on phones; tab strips
  that must stay on one line (`.side-nav`, `.tab-list`, `.sheet-tabs`)
  scroll sideways inside themselves.
- **Touch targets** are at least 24 × 24 px (WCAG 2.5.8): footer links,
  "Show as table", sort headers and small links are padded. On touch
  screens (`pointer: coarse`) checkboxes and radios are 20 px.
- **Type scales with the screen** where it is large: the home headline
  uses `clamp()` so it never fills a phone screen on its own.

### Adding a component

1. Check [`COMPONENTS.md`](COMPONENTS.md): it may already exist.
2. Add the class to the matching file in `resources/css/components/`
   with a comment that says what it is for and shows the markup.
3. Use tokens for every colour.
4. `npm run build`, then check it in light and dark, at 320 px, 768 px
   and 1280 px wide (see "Responsive rules" above).
5. Document it in `COMPONENTS.md`.

## JavaScript

Alpine is started once in `app.js`. `<body>` carries `x-data`, so Alpine
directives work anywhere on a page.

### Stores

| Store | Purpose | Rendered in |
| --- | --- | --- |
| `$store.toasts` | The toast stack | `footer.php` |
| `$store.confirm` | The one confirm dialog | `footer.php` |

From scripts, use the wrappers on `window.PS`:

```js
PS.toast('Saved.', { type: 'success' });
PS.toast({ type: 'error', title: 'Upload failed', body: 'The file is too large.' });
PS.toast('Deleted.', { duration: 8000, actions: [{ label: 'Undo', onClick: restore }] });

const ok = await PS.confirm({ title: 'Delete this?', body: 'It cannot be undone.', okText: 'Delete' });
```

Server-side, set `$flash` before including `header.php` and it is shown
as a toast on load:

```php
$flash[] = ['type' => 'success', 'body' => 'Changes saved.'];
```

### Directives

They take no expression; options are `data-*` attributes, so PHP only
ever has to HTML-escape, never JS-escape.

| Directive | On | Does |
| --- | --- | --- |
| `x-confirm` | `<form>` | Asks before submitting. `data-confirm-title`, `-body`, `-ok`, `-cancel`, `-tone="primary"` |
| `x-copy` | `<button data-copy="...">` | Copies the value and shows a toast |
| `x-autosubmit` | filter `<form>` | Submits when a `<select>` changes |
| `x-chart` | `<figure class="chart">` | Tooltip for marks with `data-tip` JSON, on hover and keyboard focus (built with `textContent`) |

### Components (`x-data="..."`)

| Name | Used for |
| --- | --- |
| `dropdown` | Role switcher and account menu: arrow keys, Esc, click outside, focus return |
| `dirtyForm` | Exposes `dirty`; warns before leaving with unsaved changes |
| `themePicker` | Settings → Appearance: applies and submits on change |
| `passwordForm` | Settings → Security: live length and match hints |
| `sheet` | Full-screen mode of the directory's wide table (remembered per tab) |
| `confirmDialog` | The `<dialog>` in `footer.php` |
| `sectionNav` | Admin › one user: "On this page" follows the section in view; holds `student` (shows Lab details) |
| `bulkSelect` | Admin › Users: ticked rows, select all, the bulk bar and its Email link (`data-compose`) |
| `palette` | Admin command palette: pages from `data-palette`, people from `/admin/search.php` |
| `tzPicker` | Admin › Site settings: live preview of the chosen time zone, "use my computer's zone" |
| `emailComposer` | Admin › Emails › Write an email: people search and picker, live recipient count and preview, `{name}` button, confirm before a real send |

One-line state is written inline, for example the password toggle
(`x-data="{ show: false }"`) and the mobile drawer.

### Sheets

- `sheet` (on the page around a wide `.sheet` table) handles full screen
  and frozen panes: it measures the header cells marked `.freeze` and
  sets `--fz1` … `--fz4` on the table, again on resize, when fonts load
  and when full screen changes.
- `sheetFilter` (around a sheet's toolbar and table, on `/resources.php`)
  filters rows as you type (every word must appear in the row), shows
  only `tr.is-mine` rows when "Only mine" is ticked, counts what is
  showing, and sorts by a column when its header button is clicked
  (numbers numerically, blanks last). Header buttons call `sort(i)`, show
  `arrow(i)` and bind `aria-sort` to `ariaSort(i)`.

### The profile editor

`profile-editor.js` is plain DOM code, not Alpine. Clicking Edit on a lab
field builds a small form from that field's definition in
`window.PS_VIEW_MODE.lab.fields`, posts `op=update_lab` to
`/profile.php`, and updates the value and the completeness meter from
the JSON reply. Its contract with the markup is a set of `data-*`
attributes listed at the top of the file; it never selects by class
name, so restyling cannot break it.

It also drives the rest of My profile: **entries** (`data-entry-*`: add,
edit and delete a thesis, paper, publication or award; every reply carries
the section's rows, rendered by PHP, so there is one renderer), the
**tabs** of Publications & awards (`data-tabs`, arrow-key navigation), the
**course** actions (add, complete, remove; "Add course" is disabled until
the current course is completed), the BibTeX **copy** button, and the
**Fill from BibTeX** helper (`parseBibtex()` builds an IEEE-style citation
and the month). The entry definitions reach it as
`window.PS_VIEW_MODE.entries`, from `lab_entry_defs()`.

## Checking a change

There are no automated front-end tests in the repository. Before
shipping a visual change, check by hand:

1. Light and dark (Settings → Appearance).
2. 320, 390, 768 (portrait tablet), 1024 and 1280 px wide, and a phone
   in landscape (667 × 375): no sideways scrolling, the drawer opens
   below 1024 px, nothing is cut off. `tools/responsive-audit.js` checks
   every page at nine sizes for you (see its header).
3. Keyboard only: Tab order, visible focus, Esc closes menus and dialogs.
4. The browser console is clean.
5. `make lint` for PHP syntax.
