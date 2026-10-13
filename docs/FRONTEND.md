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
│       └── profile.css    .identity .completion .value-card .entry .inline-form
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

### Adding a component

1. Check [`COMPONENTS.md`](COMPONENTS.md): it may already exist.
2. Add the class to the matching file in `resources/css/components/`
   with a comment that says what it is for and shows the markup.
3. Use tokens for every colour.
4. `npm run build`, then check it in light and dark, and at 390 px wide.
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

### Components (`x-data="..."`)

| Name | Used for |
| --- | --- |
| `dropdown` | Role switcher and account menu: arrow keys, Esc, click outside, focus return |
| `dirtyForm` | Exposes `dirty`; warns before leaving with unsaved changes |
| `themePicker` | Settings → Appearance: applies and submits on change |
| `passwordForm` | Settings → Security: live length and match hints |
| `sheet` | Full-screen mode of the directory's wide table (remembered per tab) |
| `confirmDialog` | The `<dialog>` in `footer.php` |

One-line state is written inline, for example the password toggle
(`x-data="{ show: false }"`) and the mobile drawer.

### The profile editor

`profile-editor.js` is plain DOM code, not Alpine. It builds a form on
demand from the field metadata in `window.PS_VIEW_MODE`, posts one `op`
at a time to `/profile.php`, and swaps in the HTML the server returns.
Its contract with the markup is a set of `data-*` attributes listed at
the top of the file; it never selects by class name, so restyling cannot
break it.

## Checking a change

There are no automated front-end tests in the repository. Before
shipping a visual change, check by hand:

1. Light and dark (Settings → Appearance).
2. 390 px wide: no sideways scrolling, the drawer opens.
3. Keyboard only: Tab order, visible focus, Esc closes menus and dialogs.
4. The browser console is clean.
5. `make lint` for PHP syntax.
