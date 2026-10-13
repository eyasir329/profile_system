# Directory

A file-by-file tour of the project. Use this when you need to find where
something lives or how a feature is wired end-to-end.

> **Read first:** [README.md](../README.md) for the architecture overview.

---

## Top-level PHP files (the public surface)

| File | Auth | Purpose |
|---|---|---|
| `index.php` | public | Landing page. Redirects to dashboard if already logged in. |
| `login.php` | public | Email + password form. CSRF-protected. Logs `login` activity on success. |
| `register.php` | public | Name + username + email + degree_type + password form. Inserts into `users` and `profiles`, logs `register`. |
| `logout.php` | POST | Destroys the session and the session cookie. CSRF-protected. Redirects to `login.php?logged_out=1`. |
| `forgot_password.php` | public | Email form. Inserts a `password_resets` row with a sha256-hashed token. In dev mode, shows the link on screen. |
| `reset_password.php` | public | Token check (must be un-used and un-expired) + new password form. Marks the token used on success, logs `password_reset`. |
| `dashboard.php` | login | Welcome card, profile-completion progress bar, recent-activity feed, role/username/joined stats, admin shortcut. |
| `profile.php` | login | Full editor + view: photo upload with live preview + drag/drop, basics, and one section block per visible section. The editor IS the profile view — there is no separate read-only page. Handles deletes via `_delete=1` markers. |
| `settings.php` | login | Tabs: `account` (name/email/username/degree), `security` (change password), `danger` (delete account). All forms CSRF-protected. |

### Routing

- `index.php` ← `/`
- `login.php` ← `/login.php`
- `register.php` ← `/register.php`
- … (etc.)
- `/u/<username>` → rewritten by top-level `.htaccess` to `u/index.php?u=<username>`
- `admin/index.php` ← `/admin/` and `/admin/index.php`
- `admin/user.php?id=N` ← single-user management
- `admin/view_user.php?id=N` ← legacy shim that 302-redirects to `user.php?id=N`

---

## `u/` (public profile)

| File | Purpose |
|---|---|
| `u/index.php` | Looks up a user by `?u=<username>` (case-insensitive). 404 with a friendly message if not found. Renders the same sections as `profile.php`, minus the "Edit" button (the button is only shown to the owner). |

The `.htaccess` rewrite is:
```apache
RewriteCond %{REQUEST_URI} ^/u/([A-Za-z0-9_-]+)/?$ [NC]
RewriteCond %{REQUEST_FILENAME} !-f
RewriteCond %{REQUEST_FILENAME} !-d
RewriteRule ^u/([A-Za-z0-9_-]+)/?$ u/index.php?u=%1 [L,QSA]
```

The `!-f` / `!-d` checks prevent the rule from shadowing any future files in
`u/` (e.g. a `u/about.php` page).

---

## `admin/`

| File | Auth | Purpose |
|---|---|---|
| `admin/index.php` | admin | User table with search (LIKE on name/email/username), filter by role + degree, sort by any column, mobile card view. |
| `admin/user.php` | admin | Single-user page. Forms: set role, set degree_type, delete user (with confirm dialog). Self-demote / self-delete are blocked. |
| `admin/view_user.php` | public (compatibility) | 302-redirects to `user.php?id=N` for any incoming `?id=N` link. |

---

## `includes/` (shared PHP)

| File | Purpose |
|---|---|
| `auth.php` | Session config (`use_strict_mode`, `httponly`, `samesite=Lax`, `secure` when HTTPS or `TRUST_PROXY=1` + `X-Forwarded-Proto=https`), helpers `e()`, `text_length()`, `post_string()`, `csrf_token()`, `verify_csrf()`, `require_login()`, `require_admin()`, `is_logged_in()`, `current_user_id()`, `current_user_role()`, `current_user_name()`. Loads `ratelimit.php` so the rate-limit helpers are always in scope. |
| `db.php` | PDO connection from env vars. Reads `APP_DB_USER` (falls back to `DB_USER`) and `DB_APP_PASSWORD` (falls back to `DB_PASSWORD`). 10× retry with 0.5s backoff. 500s on persistent failure. |
| `url.php` | `url_for($path)` + named helpers for every page, plus `url_admin_user($id)`, `url_public_profile($username)`. Also `username_slug($name)`. |
| `validate.php` | Server-side validators returning `['value' => …, 'errors' => […]]` shape, plus `log_activity($userId, $kind, $detail)`. |
| `ratelimit.php` | Backed by the `login_attempts` table (migration 018). Functions: `client_ip()` (honors `TRUST_PROXY=1` + `X-Forwarded-For`), `too_many_attempts($bucket, $max, $windowSeconds)`, `record_attempt($bucket, $userId, $ip, $username, $success)`, `clear_attempts($bucket)`. Used by `login.php`, `register.php`, `forgot_password.php`. |
| `profile_sections.php` | `DEGREE_TYPES` constant, `$SECTIONS` registry (9 entries: educations, work_experiences, journal_articles, conference_papers, awards, skills, certifications, research_interests, languages), each with `title`, `singular`, `fields[]`, `visible_for()` closure. Helpers: `normalize_degree_type()`, `degree_label()`, `visible_sections()`, `load_sections_for_user()`, `section_columns()`. |
| `render_sections.php` | `render_sections($degreeType, $sections)` echoes all visible sections, `render_section_row($sectionKey, $row)` does the per-section layout (switch statement, one case per section), `render_section_tags($sectionKey, $rows)` for compact chip layout. |
| `header.php` | Shared `<head>`, skip-link, topbar, `<main>`. Variables the caller can set: `$page_title`, `$page_id`, `$body_class`, `$flash`, `$hide_topbar`, `$hide_footer`. |
| `footer.php` | Closes `<main>`, renders the footer, renders the empty `<div class="toast-stack">` that `app.js` writes toasts into. |

---

## `assets/`

### `assets/css/app.css` (~800 lines)

Organized as commented sections. Top-to-bottom order:

1. **Design tokens** — colors (`--bg`, `--surface`, `--text`, `--accent`, `--danger`, …), type scale, radius, shadows, motion
2. **Reset & base** — box-sizing, body, links
3. **Layout primitives** — `.container`, `.container-narrow`, `.container-wide`, `.stack`, `.row`, `.grid-2`
4. **Accessibility utilities** — `.skip-link`, `.sr-only`
5. **Topbar** — `.topbar`, `.topbar-inner`, `.brand`, `.nav`, `.menu-toggle` (with mobile media query)
6. **Buttons** — `.btn` + `.btn-primary / secondary / ghost / danger` + `.btn-sm / lg / block`
7. **Cards** — `.card`, `.card-header`, `.card-pad-sm`
8. **Forms** — `.field`, `.input`, `.select`, `.textarea`, `.field-error`, `.field-hint`, `.field-counter`, `.password-wrap`, `.checkbox`, `.form-actions`
9. **Alerts** — `.alert`, `.alert-success / error / warning`
10. **Toasts** — `.toast-stack`, `.toast`, `.toast-success / error / info`, entrance animation
11. **Modal** — `.modal`, `.modal-card`, `.modal-backdrop` (uses native `<dialog>`)
12. **Avatar** — `.avatar` + `.avatar-sm / md / lg / xl` + `.avatar-placeholder`
13. **Badge** — `.badge` + `.badge-user / admin / bachelor / msc / phd`
14. **Progress bar** — `.progress`, `.bar`, `.progress-label`
15. **Empty state** — `.empty`, `.empty-icon`
16. **Tables** — `.data`, `.table-scroll`, `.table-cards`, mobile breakpoint
17. **Toolbar** — `.toolbar`, search + filter rows above tables
18. **Lists** — `.list`, `.list-icon`, `.list-body`, `.list-title`, `.list-meta`
19. **Details** — `.details` (styled `<dl>`)
20. **Tabs** — `.tabs`
21. **Hero** — landing-page hero
22. **Photo uploader** — `.photo-uploader`, `.photo-preview`, drag/drop state
23. **Dashboard widgets** — `.dash-grid`, `.stat`, `.stat-label`, `.stat-value`
24. **Section editor** — `.section-block`, `.section-row`, `.section-row-grid`, `.section-row-actions`
25. **Tag list** — `.tag-list`, `.tag`
26. **Copy box** — `.copy-box`, `.copy-btn`
27. **Footer** — `.footer`
28. **Utilities** — `.muted`, `.tiny`, `.center-text`, `.mt-{0..4}`, motion-reduce query

### `assets/js/app.js` (~280 lines)

Exposes a single global, `window.PS`. Structure:

| Function | What it does |
|---|---|
| `PS.toast({type, title, body, duration})` | Append a toast to `.toast-stack`. Auto-dismisses after `duration` ms (default 4000). Returns a `close()` function. |
| `PS.readFlash()` | Read `data-flash='[...]'` from `<body>` and call `PS.toast()` for each. Called once on DOM ready. |
| `PS.confirm({title, body, okText, cancelText})` | Returns a `Promise<boolean>`. Renders a native `<dialog>` element. |
| `PS.initPasswordToggles(root)` | Finds every `.password-wrap` and appends a Show / Hide button. |
| `PS.initPhotoUploader(root)` | Drag/drop, file-type and size validation, live preview, "Remove" button that sets `photo_clear=1`. |
| `PS.initCopyButtons(root)` | Wires up every `[data-copy]` button to the clipboard, with a success toast. |
| `PS.initMenu()` | Toggles `.nav.is-open` on hamburger click. |
| `PS.initCounters(root)` | Live "X / Y" counter for inputs with `data-counter="<output-id>"`. |
| `PS.focusFirstError()` | Focus and scroll the first field with `aria-invalid="true"`. Called on DOM ready. |
| `PS.guardDirtyForm(form)` | `beforeunload` warning if any input has been touched since page load. |
| `PS.initAdminList()` | Debounced auto-submit for the admin search/filter toolbar. |
| `PS.initConfirmForms(root)` | Wires up `<form data-confirm>` to call `PS.confirm()` before submit. |

`init()` runs on `DOMContentLoaded` (or immediately if the page is already
loaded) and calls every `init*` function above.

---

## `database/migrations/`

| File | What it does |
|---|---|
| `001_redesign.sql` | Adds `users.username` (with backfill and collision-avoidance via `ROW_NUMBER()`), creates `password_resets` and `activity`. Wrapped in a transaction. |
| `002_academic_profile.sql` | Adds `users.degree_type`, backfills to `'bachelor'`, creates the 9 academic section tables. Uses `ADD COLUMN IF NOT EXISTS` and `CREATE TABLE IF NOT EXISTS` for safety. |
| `003_profile_lab_fields.sql` | Extra profile fields used by the lab/researcher profile variant. |
| `004_user_roles.sql` | Creates the `user_roles` join table for multi-role auth (admin/teacher/student). |
| `005_profile_section_visibility.sql` | Adds per-section `is_visible` flags so each user can hide sections they don't want shown publicly. |
| `006_profile_field_privacy.sql` | Per-field privacy flags. |
| `007_profile_theme.sql` | User-selectable theme (light/dark) stored on the profile. |
| `008_profile_views.sql` | Counter for public-profile views. |
| `009_endorsements.sql` | "Endorsements" — peer-issued skill endorsements on a profile. |
| `010_two_factor.sql` | TOTP columns on `users` and required-flag support. |
| `011_email_verifications.sql` | Email-verification token table. |
| `012_username_history.sql` | Stores previous usernames so they can be reserved for a grace period. |
| `013_accounts_soft_delete.sql` | Soft-delete column on `users`; deletes go through here rather than `DELETE`. |
| `014_remove_public_profile.sql` | Drops the legacy public-profile sub-objects in favor of section rows. |
| `015_theme_default_light.sql` | Backfills `theme` to `light` for existing rows. |
| `016_drop_users_role.sql` | Drops the legacy single `users.role` column once `user_roles` is fully populated. |
| `017_invites.sql` | Invite codes / invitation table. |
| `018_login_attempts.sql` | Creates the `login_attempts` table backing `includes/ratelimit.php`. Composite index on `(bucket, created_at)` so the rate-limiter window check is a single index lookup. FK is `fk_lat_user` (named to avoid colliding with the `languages` table's FK). |

**To add a new migration:** see
[README → Adding a new migration](../README.md#adding-a-new-migration).

---

## `docker/`

| File | Purpose |
|---|---|
| `docker/web-entrypoint.sh` | Container entrypoint for the `web` service. In order: detects `mariadb` / `mysql` client, waits for the DB to be reachable (up to 60s), ensures the database exists, applies `database/schema.sql`, ensures `schema_migrations` exists, applies any new `database/migrations/*.sql` in name order, ensures `public/uploads/profiles/` is writable, then `exec "$@"` to launch Apache. |
| `docker/db-init/00-bootstrap.sql` | First-boot SQL run by the official `mariadb:11` image. Creates the `profile_system` database (idempotent) and documents how to create a non-root application user. |

---

## `uploads/`

| File | Purpose |
|---|---|
| `uploads/profiles/` | The destination for uploaded profile photos. Files have random 32-hex-char names. The directory is owned by `www-data` in the image and is mounted as a Docker named volume (`profile_system_uploads`). |
| `uploads/profiles/.htaccess` | `php_flag engine off`, `RemoveHandler .php .phtml .php3`, `RemoveType .php` — denies PHP execution in case a maliciously crafted file is uploaded. (Photo uploads also go through MIME check + `getimagesize()` + `is_uploaded_file()`, but defense in depth.) |

The `uploads/` directory itself is bind-mounted read-write in development
(`./:/var/www/html:rw` in `compose.yml`) but the named volume overlays it so
user photos survive container recreations.

---

## Top-level config files

| File | Purpose |
|---|---|
| `Dockerfile` | `php:8.3-apache` + `libpng/libjpeg/libwebp/libfreetype/libzip` + `mariadb-client` + `bash`, then `pdo_mysql`, `gd`, `fileinfo` extensions, `mod_rewrite` + `mod_headers`, copies the app and the entrypoint, exposes 80. |
| `compose.yml` | Three services: `db` (mariadb:11), `web` (built from `./Dockerfile`), `adminer` (adminer:latest, optional). Two named volumes. Healthchecks. Env-driven config. |
| `.env.example` | Template for `.env`. Lists every env var with a comment. |
| `.env` | Local config. Gitignored. |
| `.dockerignore` | Files excluded from the Docker build context. Keeps `.env` and `.git` out. |
| `.gitignore` | Git ignores: `.env`, `uploads/`, IDE files, OS junk. |
| `.htaccess` | Routes `/u/<username>` to `u/index.php`, disables directory listing. |
| `database/schema.sql` | Original two-table schema (users, profiles). Idempotent (`CREATE … IF NOT EXISTS`). Applied by the web entrypoint on every container start. |

---

## Top-level docs

| File | Purpose |
|---|---|
| `README.md` | Architecture, quick start, user guide, admin guide, developer notes, troubleshooting, security notes. The main doc. |
| `CHANGELOG.md` | Release notes; one entry per notable change. |
| `CONTRIBUTING.md` | Workflow, code style, testing, "definition of done". |
| `docs/DIRECTORY.md` | This file. |
| `docs/TUNNEL.md` | How to expose the app to the public internet via a Cloudflare quick tunnel (`make tunnel`). Covers the hardening checklist, prerequisites, day-to-day tunnel management, rotating the DB password, and troubleshooting. |
| `README.txt` | The original XAMPP-style quick start, kept for historical context. |
| `LICENSE` | No license by default; this is a learning project. |

---

## Tunnel scratch files

`make tunnel` writes two files at the project root. Both are
`.gitignore`-friendly (the `.pid` file is listed explicitly; the `.log`
matches the `*.log` rule):

| File | Purpose |
|---|---|
| `cloudflared.pid` | PID of the running `cloudflared` process. Used by `make tunnel-stop` / `make tunnel-url` to know if the tunnel is alive. |
| `cloudflared.log` | Full `cloudflared` log. `make tunnel` appends `# https://…trycloudflare.com` once the URL has been assigned so `make tunnel-url` can re-print it. |

---

## Where to start when …

- **Adding a page** → see [README → Adding a new page](../README.md#adding-a-new-page) and
  the new-page skeleton in `dashboard.php`.
- **Adding a profile field** → see [README → Adding a new field to the profile](../README.md#adding-a-new-field-to-the-profile).
- **Adding a section** (e.g. Patents) → see [README → Adding a new profile section](../README.md#adding-a-new-profile-section) and edit
  `includes/profile_sections.php`.
- **Changing the DB schema** → new file in `migrations/`, then `docker compose
  restart web`.
- **Styling a new component** → append to `assets/css/app.css` with a comment
  banner.
- **Wiring up a new JS behavior** → append to `assets/js/app.js`, attach as
  `PS.foo`, call from `init()`.
- **Debugging a login issue** → see [README → Troubleshooting](../README.md#troubleshooting).
- **Resetting everything** → `docker compose down -v && docker compose up -d --build`.
- **Exposing the app publicly** → [docs/TUNNEL.md](TUNNEL.md). Quick tunnel is one command (`make tunnel`); named tunnel needs a Cloudflare account.
- **Rotating the DB password** → [docs/TUNNEL.md → Rotate the DB password](TUNNEL.md#rotate-the-db-password).
- **Bringing up Adminer locally** → `make tools` (off by default; the `tools` Compose profile keeps it off the public internet).