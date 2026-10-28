# Architecture

How Profile System is put together, starting from the repository root:
what every folder and file is for, how a request travels through the
code, how the database is built, and how the containers fit together.

Related: [`INSTALL.md`](INSTALL.md) (running it), [`DECISIONS.md`](DECISIONS.md)
(why it is built this way), [`PERMISSIONS.md`](PERMISSIONS.md) (who may
do what), [`SECURITY.md`](SECURITY.md), [`FRONTEND.md`](FRONTEND.md),
[`COMPONENTS.md`](COMPONENTS.md), [`DESIGN.md`](DESIGN.md).

## 1. The big picture

```
 Browser ──HTTP──▶ web container (profile_web)            db container (profile_db)
                   ┌──────────────────────────────┐       ┌────────────────────────┐
  :8080 ─────────▶ │ Apache 2.4 + mod_php 8.3      │       │ MariaDB 11.4           │
                   │  /var/www/html  = public/     │  SQL  │  database              │
                   │  /var/www/src   = src/        │──────▶│   profile_system       │
                   │  /var/www/database            │ :3306 │  volume                │
                   │  uploads volume               │       │   profile_system_db    │
                   └──────────────┬───────────────┘       └────────────────────────┘
                                  │ SMTP (optional)
                                  ▼
                     mail server, or Mailpit (dev)        Adminer (dev, on demand)
```

- **One web container** runs Apache with PHP built in. Each page is one
  PHP file under `public/`; there is no router or framework.
- **One database container** runs MariaDB. Only the web container can
  reach it (no port is published to the host).
- **Two optional tools** start only on request (`make mail`,
  `make tools`) and listen on `127.0.0.1` only: Mailpit catches email in
  development, Adminer browses the database.
- **The browser** gets server-rendered HTML, one CSS file and one JS
  file (Tailwind CSS and Alpine.js, built ahead of time). The profile
  editor and the admin search call the server with `fetch` and get JSON back; every
  other form is a normal POST followed by a redirect.

## 2. The repository, from the root

```
profile_system/
├── README.md              What the app does, quick start, pages, tables
├── CHANGELOG.md           What changed, newest first, with the reasons
├── CONTRIBUTING.md        Conventions for changing the code
├── SECURITY.md            How to report a vulnerability (design: docs/SECURITY.md)
├── LICENSE
│
├── Dockerfile             Builds the web image (PHP 8.3 + Apache + extensions)
├── compose.yml            The containers: db, web, and the tools (adminer, mailpit)
├── Makefile               `make up`, `make backup`, ...: every command, all via Docker
├── .env.example           Every setting, documented; copied to .env (never committed)
├── .dockerignore          Keeps secrets, backups, Node files out of the image
├── .gitignore             Keeps .env, backups, uploads, logs out of Git
├── .gitattributes         Forces Linux line endings on scripts and SQL
├── .editorconfig          Charset, indentation and line-ending rules for every editor
├── .github/               CI workflow, Dependabot, PR and issue templates, CODEOWNERS
│
├── public/                Apache's document root: everything a browser can request
├── src/                   PHP that is NOT web-reachable: shared code, CLI, vendor
├── tests/                 Dependency-free unit tests (`make test`)
├── deploy/                Shared-hosting build and config (docs/DEPLOYMENT.md)
├── database/              schema.sql + numbered migrations
├── docker/                Container config: entrypoint, Apache, PHP, DB bootstrap
├── resources/             CSS and JS **source** (Tailwind, Alpine), built into public/assets
├── docs/                  This documentation
├── tools/                 Developer tools outside the app (responsive audit)
│
├── composer.json/.lock    The one PHP dependency (PHPMailer), installed into src/vendor
├── package.json/-lock     The front-end build (Tailwind CLI, esbuild, Alpine, fonts)
│
└── (not in Git)  .env, backups/, node_modules/, cloudflared.log/.pid, test scripts
```

The split that matters most: **`public/` is served, `src/` is not.**
Apache's document root is `public/`, so a file in `src/` can never be
requested by URL; pages `require` it with
`__DIR__ . '/../src/includes/…'`. The same relative path works on the
host and in the container (`/var/www/html` and `/var/www/src` are
siblings).

### `public/`: the pages

One file per page. Each starts with a doc comment giving its route,
who may open it and what it accepts.

| File | Who | Does |
| --- | --- | --- |
| `index.php` | anyone | Home page: what the system is, with invented sample data only |
| `about.php`, `help.php`, `privacy.php` | anyone | Static information pages linked from the footer |
| `error.php` | anyone | Apache's error page for 4xx/5xx (`?code=` previews one) |
| `login.php` | anyone | Sign in; asks which role to act as when the account has several |
| `login_totp.php` | half signed in | Two-factor step: app code or a backup code |
| `logout.php` | signed in | Ends the session (POST with CSRF) |
| `register.php` | invited, or the very first visitor | Creates an account from an invitation; the first account ever becomes admin |
| `forgot_password.php`, `reset_password.php` | anyone | Password reset by emailed one-time link |
| `verify_email.php` | link holder | Confirms a new sign-in email address |
| `restore_account.php` | link holder | Restores a deactivated account within its 30 days |
| `dashboard.php` | signed in | Home after sign-in: one panel per acting role |
| `profile.php` | student | The lab details, entries and courses, edited in place (JSON ops) |
| `students.php` | teacher | The Students sheet (rendered by `src/includes/directory.php`), CSV export |
| `resources.php` | student, teacher | Lab resources: templates, open data, potential journals, everyone's publications and awards |
| `invites.php` | teacher, admin | Create and cancel invitation links |
| `settings.php`, `setup_totp.php` | signed in | Name, email, theme, password, two-factor, deactivate |
| `switch_role.php` | signed in | Changes the role being acted as |
| `admin/index.php` | admin | Users list: filter, sort, bulk actions, CSV |
| `admin/user.php` | admin | Create or edit one user, reset 2FA, send a reset link, sign out everywhere |
| `admin/activity.php` | admin | Activity timeline, people, charts, CSV |
| `admin/emails.php` | admin | Email log, write an email, email settings |
| `admin/settings.php` | admin | Site settings: time zone, activity retention |
| `admin/search.php` | admin | JSON for the Ctrl/⌘+K command palette |
| `assets/` | – | Built CSS and JS, self-hosted fonts (generated, but committed) |
| `uploads/` | – | Writable volume for uploaded files; code there is never executed |
| `robots.txt`, `.htaccess` | – | No indexing. `.htaccess` is **not read** (`AllowOverride None`); its rules live in `docker/apache/app.conf` |

### `src/`: shared PHP

| Path | Holds |
| --- | --- |
| `includes/auth.php` | Session start and cookie flags, CSRF (`csrf_token`, `verify_csrf`), `require_login`, role checks (`require_admin`, `require_teacher`, `active_user_role`), session epoch, TOTP and backup codes. Also installs the error handlers and triggers housekeeping. |
| `includes/hosting_config.php` | Loads `hosting_config.php` (shared hosting) and defines `app_env()`, which reads settings from the environment, `$_ENV` or `$_SERVER`, because free hosts disable `putenv()`. A no-op under Docker |
| `includes/db.php` | The PDO connection (env-driven, retries while MariaDB boots) and the site time zone, applied to PHP and to the connection |
| `includes/url.php` | `url_for()` and named URL helpers, `current_user_id()`/`_name()` |
| `includes/header.php`, `footer.php` | The page frame: `<head>`, top bar, role switcher, navigation per acting role; toasts and confirm dialog |
| `includes/ui.php` | Small rendering helpers: icons (`pf_icon`), avatars, role chips, alerts, progress, relative times, activity lines |
| `includes/validate.php` | Input validators, `log_activity()`, token and TOTP-secret generation |
| `includes/ratelimit.php` | `client_ip()` (proxy-aware), attempt buckets in `login_attempts`, `rate_limit_lock()` (MariaDB `GET_LOCK`) |
| `includes/lab_fields.php` | **The single definition of the lab fields** (`lab_field_defs()`), student ID rules, course history and completion snapshots |
| `includes/lab_entries.php` | **The single definition of the repeating sections** (`lab_entry_defs()`): theses, publications, awards, plus the paper-count helpers (`paper_counts()`, `paper_format()`, `paper_display()`); and rebuilding the workbook text from entries |
| `includes/directory.php` | The Students sheet and Users list: queries, scope (who may see whom), the sheet layout, one person's page |
| `includes/invites.php` | Who may invite which roles; invitation state and checks |
| `includes/email_change.php` | Confirm-by-link flow for a new sign-in email |
| `includes/mailer.php` | SMTP sending through PHPMailer, email settings and kill switches, the email log, HTML + text layout |
| `includes/mail_templates.php` | The text of every email |
| `includes/housekeeping.php` | Deleting what is past its keep-by date; runs at most every 6 h from a page load |
| `includes/charts.php` | Server-rendered SVG charts (columns, bars, heatmap) for the admin pages |
| `includes/error_page.php` | The error page for every status, and the handlers that show it on a crash |
| `cli/housekeeping.php` | `make purge`: housekeeping now (refuses to run over HTTP) |
| `vendor/` | PHPMailer 6.10.0 and Composer's autoloader, committed |

### `database/`

| Path | Holds |
| --- | --- |
| `schema.sql` | The original tables, `CREATE … IF NOT EXISTS`; safe to run on every start |
| `migrations/NNN_name.sql` | Every later change, applied once, in name order (001 … 036) |
| `migrate.php` | The same runner in PHP, for running outside Docker |

### `docker/`

| Path | Holds |
| --- | --- |
| `web-entrypoint.sh` | Runs when the web container starts: wait for DB → create DB → create the least-privilege app user → schema → migrations → drop root credentials from the environment → start Apache |
| `apache/app.conf` | Security headers (CSP, HSTS, …), no directory listings, no code in `uploads/`, error pages, no version banner |
| `php/app.ini` | Errors logged to the container log, never shown in the page |
| `db-init/00-bootstrap.sql` | Runs once on a fresh database volume: creates the database |
| `seed_dummy_profile.php` | Old test seed script (kept outside the web root) |

### `resources/` → `public/assets/`

Source for the front end. `make assets` runs Tailwind's CLI over
`resources/css/app.css` (which imports `theme.css`, `base.css` and one
file per component group) and esbuild over `resources/js/app.js`
(Alpine plus `ui.js` and `profile-editor.js`), and copies the fonts. The
output in `public/assets/` is committed. Details in
[`FRONTEND.md`](FRONTEND.md).

### `tools/`

`responsive-audit.js`: a Playwright script that opens every page for
each role at nine screen sizes and reports sideways scrolling, cut-off
text and small tap targets. Needs Node and Playwright on the host; not
part of the app or the image.

## 3. What happens on a request

Taking `GET /profile.php` as the example:

1. **Apache** maps the URL to `/var/www/html/profile.php` and adds the
   security headers from `app.conf`.
2. The page `require`s **`auth.php`**, which first installs the error
   handlers (so any crash renders the error page), then starts the
   session with hardened cookie settings (`HttpOnly`, `SameSite=Lax`,
   `Secure` and the `__Host-` prefix over HTTPS).
3. **`db.php`** opens the PDO connection (real prepared statements,
   exceptions on error) and sets the time zone. If the database can't be
   reached, the visitor gets the 503 page.
4. **Housekeeping** runs if it hasn't in 6 hours.
5. **`require_login()`** checks the session: signed in, not idle for
   2 h, not older than 7 days, account still exists and is active, and
   the session's epoch matches the account's (a password change ends
   every other session). Roles are re-read from the database, so a
   revoked role stops working at once.
6. The page checks the **acting role** (here: student) and refuses
   otherwise with the 403 page.
7. **GET**: the page queries what it needs, bound to the signed-in
   user's id, and renders HTML between `header.php` and `footer.php`.
   Every value goes through `e()` (HTML escaping).
8. **POST**: `verify_csrf()` first; then the operation (`op=…`) is
   validated, executed, written to the activity log, and answered with
   JSON (`profile.php`) or a redirect back to the page with a status in
   the URL (every other form: POST, then redirect, then GET, so a reload
   never posts twice).

```
Browser ─▶ Apache ─▶ page.php
                       ├─ auth.php ── error handlers, session, housekeeping
                       ├─ db.php ──── PDO, time zone            ─▶ MariaDB
                       ├─ require_login() / role check ── 403 page if not allowed
                       ├─ POST? verify_csrf() → validate → write → log_activity()
                       └─ render: header.php → page markup → footer.php
```

## 4. The data model

Tables, grouped by what they serve (full list in the README):

| Group | Tables | Notes |
| --- | --- | --- |
| Accounts | `users`, `user_roles`, `two_factor` | One email per account, no username. A user holds one or more roles (`student`, `teacher`, `admin`). `users.session_epoch` ends sessions; `scheduled_purge_at` marks a deactivated account for deletion. |
| Lab details | `profiles`, `course_enrollments` | `profiles` holds the lab fields, one row per user. The newest `course_enrollments` row is the current course; a completed one keeps a JSON snapshot of the details at completion. |
| Entries | `lab_entries`, `lab_publications`, `lab_awards` | The repeating sections, one row each, owned by a user, cascade on delete |
| Shared | `lab_templates`, `lab_resources`, `lab_journals` | Lab-wide resources |
| Access | `invites`, `password_resets`, `email_verifications`, `account_restorations` | One-time tokens, stored as SHA-256 hashes (`token_hash`), so a database leak hands out no working link |
| Logs | `activity`, `email_log`, `login_attempts` | Retention handled by housekeeping |
| System | `app_settings`, `schema_migrations` | Site settings (key/value); which migrations ran |

Conventions: `utf8mb4` everywhere (Japanese names), InnoDB, foreign keys
with `ON DELETE CASCADE` for anything a user owns, `TIMESTAMP` for
moments (stored as UTC, shown in the site zone).

### How the schema evolves

```
first start:  schema.sql  →  001 … 036   (each recorded in schema_migrations)
later starts: schema.sql (no-op)  →  only files not yet recorded
```

To change the database, add `database/migrations/037_what_it_does.sql`
and `make restart`. Never edit a migration that has already run
somewhere: it will not run again there.

## 5. The containers in detail

| Service | Image | Ports | Volumes | Starts |
| --- | --- | --- | --- | --- |
| `db` | `mariadb:11.4` | none on the host | `profile_system_db` → `/var/lib/mysql` | `make up` |
| `web` | built from `Dockerfile` (`php:8.3-apache` + `pdo_mysql`, `gd`, `fileinfo`, `mariadb-client`) | `${WEB_BIND}:${WEB_PORT}` → 80 | `profile_system_uploads`; in development `public/`, `src/`, `database/` bind-mounted **read-only** | `make up`, after `db` is healthy |
| `mailpit` | `axllent/mailpit` | `127.0.0.1:8025` | none | `make mail` |
| `adminer` | `adminer:4.8.1` | `127.0.0.1:8081` | none | `make tools` |

Development vs production differ only in configuration:

- The bind mounts make PHP edits visible on the next request without a
  rebuild. Removing them runs the code baked into the image, which is
  owned by root and read-only to Apache.
- `.env` switches cookies, proxy trust, `APP_ENV` and the least-privilege
  database user. See [`INSTALL.md` §11](INSTALL.md#11-running-it-for-real-users).

## 6. Where to make common changes

| To | Edit |
| --- | --- |
| Add or rename a lab field | `lab_field_defs()` in `src/includes/lab_fields.php`, plus a migration for the column |
| Add a repeating section | `lab_entry_defs()` in `src/includes/lab_entries.php`, plus a migration for the table |
| Change who may do something | The check in the page (see the table in [`PERMISSIONS.md`](PERMISSIONS.md)) and that document |
| Add a page | A new file in `public/`, a URL helper in `url.php`, a nav item in `header.php` if it needs one |
| Add an email | A function in `mail_templates.php`, a type in `mail_types()` (`mailer.php`) so it gets an on/off switch |
| Change styling | `resources/css/…`, then `make assets` |
| Change a security header | `docker/apache/app.conf`, then `make rebuild && make up` |
| Add a setting | `.env.example` (documented), `compose.yml` → `web.environment`, read with `getenv()` |
