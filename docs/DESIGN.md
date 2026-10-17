# Design

How Profile System looks and behaves, and why. Read this before changing
the visual design; read [`COMPONENTS.md`](COMPONENTS.md) for the class
reference and [`FRONTEND.md`](FRONTEND.md) for the build.

## 1. What the product is

A private member register for a research lab. Students keep their own
profile (education, publications, skills, lab details); teachers read all
of them as a directory; admins manage accounts. It is a records tool that
people open a few times a term, not a product they live in.

That decides the tone: quiet, legible, closer to a well-set document than
to a SaaS dashboard. Nothing on a page should be there to impress.

## 2. Stack decisions

| Question | Decision | Why |
| --- | --- | --- |
| Laravel or vanilla PHP? | **Vanilla PHP stays.** | The back end already works and is hardened (CSRF, rate limiting, 2FA, invitations, soft delete). A framework rewrite would put all of that at risk for no visible gain. The redesign is a front-end change. |
| CSS | **Tailwind CSS v4** (CLI build) | Utilities for layout, plus a small layer of named components (below). v4 needs no JS config: everything is in `resources/css`. |
| Behaviour | **Alpine.js 3** | Fits server-rendered pages: state lives in the markup it affects. Core only, no plugins. |
| Preline UI or Flowbite? | **Preline, as a token system and pattern library.** | Preline 5 ships `theme.css`: semantic colour tokens (`bg-layer`, `text-foreground`, `border-layer-line`, `bg-primary`, ...) with light and dark values. Re-pointing those tokens re-themes everything, and dark mode needs no per-component work. Flowbite's tokens are narrower and its defaults (blue, Inter) are the look we are avoiding. |
| Preline's JavaScript? | **Not loaded.** | Preline's and Flowbite's scripts do what Alpine does (dropdowns, modals, tabs). Two behaviour layers means two ways to open a menu. Preline's bundle is ~430 KB; ours, with Alpine, is ~77 KB. |
| Fonts | **Self-hosted** | No third-party requests from a private site, and no layout shift from a slow CDN. |

### Components, not utility soup

Templates are plain PHP with no component system, and the profile editor
builds forms in JavaScript. If every button carried fifteen utilities, the
same string would be repeated in 18 PHP files and one JS file, and would
drift. So anything that repeats is a **named class** (`.btn`, `.input`,
`.card`, `.badge`, ...) defined once in `resources/css/components/` with
Tailwind's `@apply`. Utilities are used for what is specific to one place:
layout, spacing, a one-off width.

## 3. Research and reference points

What was actually checked for this redesign (October 2026):

- **The two candidate libraries, from their published packages**:
  `preline@5.0.0` and `flowbite@4.0.2` were installed and read: what each
  ships, how each themes, bundle sizes, dependencies. That is the basis
  of the table above.
- **Preline's framework guides** (Laravel, Symfony, Hugo, plain HTML) for
  the Tailwind v4 setup: <https://preline.co/docs>
- **Current writing on template-looking and machine-made UI**, to list
  what to avoid:
  [Why your AI keeps building the same purple gradient website](https://prg.sh/ramblings/Why-Your-AI-Keeps-Building-the-Same-Purple-Gradient-Website),
  [AI design slop: overused UI patterns](https://noqta.tn/en/blog/ai-design-slop-overused-ui-patterns-fix-2026),
  [Impeccable: anti-patterns](https://www.mintlify.com/pbakaus/impeccable/concepts/anti-patterns),
  [How to fix AI slop in web design, tell by tell](https://capitalandcompute.net/blog/fix-ai-slop-design/).

The page designs follow conventions that are long established in products
of this kind. These were applied from familiarity, not re-audited page by
page, so treat them as reference points rather than citations:

- **App shell and settings** (GitHub, Linear, Stripe Dashboard): a top bar
  is enough for three destinations; account actions sit in a menu under
  the user's name; settings is a short vertical list of sections with the
  destructive one last and red.
- **Directories** (GitHub's people list, Stripe's customer list): filters
  in one row above the table, sortable headers as links, the person's
  name is the link, a card list replaces the table on phones.
- **Inline editing** (LinkedIn, GitHub profiles): view first, edit in
  place, one thing at a time, save per item, undo instead of "are you
  sure?" for small deletions.
- **Forms** (GOV.UK Design System): label above the field, hint under it,
  the error next to the field it belongs to, one primary button.

### Patterns deliberately avoided

These read as machine-made or template-made in 2026, so none of them
appear here:

| Avoided | What is used instead |
| --- | --- |
| Indigo / purple accent, gradient backgrounds, glows | One ink-blue accent on warm paper neutrals |
| Inter (or system default) for everything | Source Serif 4 for titles, IBM Plex Sans for the rest |
| Centred hero, three feature cards, big CTA | A left-aligned statement, a sample profile built from the real components, and plain text columns |
| Everything in a rounded, shadowed card; cards in cards | Hairline rules for reading pages; cards only for forms and summaries; never nested |
| Dashboard "stat tiles" with big numbers | A list of sections with counts |
| Pill-shaped buttons and badges, large radii | 6 px controls, 8 px cards, 4 px badges |
| Emoji and symbol glyphs as icons (☰ ✓ ⚙ 🗑) | One stroke icon set, drawn at 16 px |
| Coloured badge for every label | Neutral badges; colour only when the state matters |
| Fade-up animation on every block | Motion only where something appears: menus, dialog, toasts |
| Filler copy ("Welcome! Enter your details to continue") | Copy that says what the control does |

## 4. Tokens

All tokens live in `resources/css/theme.css`.

### Colour

Preline maps its semantic tokens onto Tailwind's `gray` scale in light
mode and `neutral` in dark mode. We replace both with one warm scale and
give `primary` an ink-blue scale; every Preline token follows.

| Role | Light | Dark | Utility |
| --- | --- | --- | --- |
| Page | gray-50 (paper) | gray-950 | `bg-background` |
| Card, input | white | gray-900 | `bg-layer`, `bg-card` |
| Hairline | gray-200 | gray-800 | `border-layer-line` |
| Text | gray-900 | gray-100 | `text-foreground` |
| Secondary text | gray-600 | gray-300 | `text-muted-foreground-2` (`.muted`) |
| Hints, meta | gray-500 | gray-400 | `text-muted-foreground-1` (`.faint`) |
| Accent | primary-700 | primary-300 | `bg-primary`, `text-primary` |
| Success / warning / danger / info | `--success`, `--warning`, `--danger`, `--info`, each with `-soft` and `-line` | same names | `text-danger`, `bg-danger-soft`, `border-danger-line`, ... |
| Role dots | `--role-admin`, `--role-teacher`, `--role-student` | same | `.role-admin` ... |

Rules:

- Body and secondary text meet WCAG AA (4.5:1) on both the page and card
  backgrounds. `text-muted-foreground` (gray-400) is for placeholders only.
- The accent marks the primary action, links, focus and the current nav
  item. It is not decoration.
- Role colours appear only as the 8 px dot in `.role`.
- Never use a raw palette colour (`bg-blue-600`, `text-gray-500`) in a
  template. Use a token so dark mode keeps working.

### Type

| Use | Family | Size / weight |
| --- | --- | --- |
| Page title (`.page-title`, `.identity-name`) | Source Serif 4 | 32 px / 700 |
| Landing headline | Source Serif 4 | 40 to 52 px / 700 |
| Section and card titles | IBM Plex Sans | 20 px and 18 px / 700 |
| Body | IBM Plex Sans | 16 px / 400, line-height 1.6 |
| Labels, buttons, table text | IBM Plex Sans | 14 px / 600 (table headers 700) |
| Hints, meta, badges | IBM Plex Sans | 14 px and 12 px |
| Codes, keys, links to copy | system monospace | 0.9 em |

Japanese text (the lab's "Name (JP)" field) falls back to the system CJK
font listed in `--font-sans`. Numbers that change or line up use
`tabular-nums`.

### Shape, depth, motion

- Radius: 4 px badges, 6 px controls, 8 px cards, 10 px dialog. Full
  rounding is for avatars and dots.
- Depth comes from hairlines. The only shadow (`--shadow-pop`) is on
  things that float: menus, the dialog, toasts.
- Transitions are 100 to 150 ms on colour and opacity. Everything honours
  `prefers-reduced-motion`.

### Dark mode

`header.php` reads the `ps_theme` cookie (`light`, `dark`, `auto`; set
from Settings → Appearance) and puts `class="dark"` on `<html>`. For
`auto`, a one-line inline script applies it before first paint, and
`ui.js` follows later changes of the system setting.

## 5. Layout

- Three page widths, one per page: `.page-narrow` (sign-in flow),
  `.page` (reading and forms), `.page-wide` (profile editor, directory).
- Every page starts with `.page-header`: title, one line of context, and
  the page's actions on the right.
- The top bar is sticky. Below `md` the links, the role switcher and the
  account actions collapse into one drawer.
- Tables become a card list below `md`. The wide "full sheet" scrolls
  inside its frame with a pinned first column, and can go full screen.

## 6. Behaviour rules

- **One primary button per view.** Secondary actions are `.btn-secondary`
  or `.btn-ghost`.
- **Save where you edit.** Profile fields save one at a time; settings
  forms have one Save at the bottom and say when there are unsaved changes.
- **Undo beats confirm** for small, recoverable things (deleting a profile
  entry). The confirm dialog is for what cannot be undone, and it opens
  with focus on Cancel.
- **Errors sit next to their field**, are announced (`role="alert"`), and
  the first invalid field gets focus after a failed submit.
- **Toasts** confirm things that already happened. They sit bottom centre
  (not bottom right, where the Save buttons are), pause while hovered, and
  never carry the only copy of important information.
- **Keyboard**: every control is reachable; menus support arrow keys and
  Esc; the skip link is the first focusable element; focus is always
  visible (2 px accent outline).
- **Empty states** say what is missing and offer the next step.

## 7. Known limits

- JavaScript is required for the account menu, the role switcher and the
  profile editor.
- No `Content-Security-Policy` header is sent yet. Alpine's standard build
  evaluates expressions at runtime; adopting a strict CSP later means
  switching to `@alpinejs/csp` and moving inline expressions into `ui.js`.
