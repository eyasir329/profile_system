# Profile System

A small, self-hosted profile / account app built with vanilla PHP on the server and Tailwind CSS v4 + Alpine.js in the browser, themed with Preline UI's design tokens. The built CSS and JS are committed, so running it needs no Node toolchain.

It started as a XAMPP-style starter project and was redesigned end-to-end: a new design system, public profile pages, account settings, a password-reset flow, and a proper admin panel. Everything runs in two Docker containers (PHP+Apache, MariaDB) on `localhost:8080`.

> **Status:** Learning / starter project. Safe to run locally. The default
> config also includes a hardening pass so it can be exposed to the
> public internet via a [Cloudflare tunnel](docs/TUNNEL.md) — see that
> doc for the security trade-offs and the quick-tunnel workflow.

---

## Table of contents

- [Features](#features)
- [Quick start (Docker)](#quick-start-docker)
- [Quick start (XAMPP / native PHP)](#quick-start-xampp--native-php)
- [Public exposure (Cloudflare tunnel)](#public-exposure-cloudflare-tunnel)
- [Hardening](#hardening)
- [Environment variables](#environment-variables)
- [Default credentials](#default-credentials)
- [Visualize the database (Adminer)](#visualize-the-database-adminer)
- [User guide](#user-guide)
  - [Register and log in](#register-and-log-in)
  - [Build your profile](#build-your-profile)
  - [Your public page](#your-public-page)
  - [Settings](#settings)
  - [Forgot password](#forgot-password)
  - [Delete your account](#delete-your-account)
- [Admin guide](#admin-guide)
  - [Search, filter, sort](#search-filter-sort)
  - [Promote / demote a user](#promote--demote-a-user)
  - [Change a user&#39;s profile type](#change-a-users-profile-type)
  - [Delete a user](#delete-a-user)
  - [Activity log](#activity-log)
- [Architecture](#architecture)
  - [Stack](#stack)
  - [File layout](#file-layout)
  - [Database schema (full)](#database-schema-full)
  - [URL routing](#url-routing)
  - [Profile types &amp; sections](#profile-types--sections)
  - [Design system](#design-system)
  - [JavaScript utilities](#javascript-utilities)
  - [PHP helpers](#php-helpers)
- [Developer notes](#developer-notes)
  - [Adding a new page](#adding-a-new-page)
  - [Adding a new field to the profile](#adding-a-new-field-to-the-profile)
  - [Adding a new profile section](#adding-a-new-profile-section)
  - [Adding a new migration](#adding-a-new-migration)
  - [Conventions](#conventions)
  - [Useful one-liners](#useful-one-liners)
- [Backup &amp; restore](#backup--restore)
- [Upgrading](#upgrading)
- [Troubleshooting](#troubleshooting)
- [Security notes](#security-notes)
- [What was redesigned](#what-was-redesigned)
- [Project files](#project-files)
- [License](#license)

---

## Features

**For everyone (no login required)**

- Browse any user's public profile at `/u/<username>`
- Clean, modern UI with a focus on typography and whitespace

**For account holders**

- Register with a unique username, email, and password
- Join through an **invitation link** from a teacher or admin; the link decides your role(s), so nobody can pick their own (there is no open sign-up)
- A single user can hold **multiple roles** simultaneously (e.g. a grad student who also teaches)
- Log in / log out with CSRF-protected forms
- Edit your profile: photo (with drag/drop and live preview), phone, address, profession, bio
- Public profile link that's easy to share (`/u/<your-username>`)
- Profile-completion progress bar on the dashboard
- Recent-activity feed (logins, profile updates, password changes, etc.)
- Change your name, email, username, or password any time
- Reset your password via a tokenized email-link flow
- Delete your account permanently

**For admins**

- See all users in a searchable, filterable, sortable table
- View any user's full profile
- Promote / demote any user (with a guard against self-demotion)
- Manage **multi-role** membership — each user can hold any combination of `admin`, `teacher`, and `student` roles
- Delete any user (with confirmation; their photo is cleaned up from disk)
- Edit the **xlsx-style Lab Students fields** per user (course, student ID, name JP/EN, university email, research title, enrolled year/month, next step, previous univ/level, completed thesis, journal/conference papers) — these are the columns from the original *Information of Lab Students* workbook, now stored on each user's `profiles` row and visible in the admin user list
- Filter the user list by **course** (D1/D2/D3/M1/M2/B3/B4/R/Other)
- Mobile-friendly: the user table collapses to cards on small screens

**For teachers**

- Browse a **read-only student directory** at `/teacher/` — lists every user with the `student` role, with their lab-students fields and a link to the public profile at `/u/<username>`
- Cannot edit or delete any user (no edit controls in the teacher view)

**For students**

- Maintain your own profile (photo, contact, profession, bio, academic sections, lab-students fields, settings, etc.)
- Public profile at `/u/<your-username>` is visible to anyone (logged in or not)

**Quality of life**

- Skip-to-content link, `aria-current` highlighting, `prefers-reduced-motion` respected
- Inline field validation with `aria-invalid` and focus-on-first-error
- Password show/hide toggle on every password field
- Photo preview + drag/drop + remove button (with auto-deletion of the old file)
- Live character counters on the bio and other text fields
- Toast notifications for success / error feedback
- `<dialog>`-based confirm modal for destructive actions
- Mobile-friendly topbar with a hamburger menu
- Custom error pages (400 / 403 / 404) and admin-guarded routes

---

## Quick start (Docker)

> **Recommended.** Two containers orchestrated by `compose.yml`:
> `profile_db` (MariaDB 11) and `profile_web` (custom PHP 8.3 + Apache with `pdo_mysql`, `gd`, `fileinfo`, `mod_rewrite`).

### Prerequisites

- **Docker** — any recent version. Docker Desktop on Windows/macOS, Docker Engine on Linux.
- **Docker Compose v2** — bundled with Docker Desktop; on Linux install the `docker-compose-plugin` package.
- **GNU make** — preinstalled on Linux and macOS. On Windows, use **Git Bash** (bundled with [Git for Windows](https://git-scm.com/download/win)), which ships with GNU make.
- That's it. No PHP, no MySQL, no Composer, no Node, no XAMPP, no PATH edits, no apt installs.

> ### Zero machine dependencies
>
> Every action — starting the stack, linting PHP, opening a database shell, backing up, restoring — is run by a single command that calls `docker compose run --rm` under the hood. The `Makefile` is the canonical entry point; it works identically on Linux, macOS, and Windows (Git Bash). The only host tools you need are **Docker** and **`make`**. See the full target list at `make help`.

### One-time setup

```bash
# 1. Copy the env template and edit if you want to change defaults
cp .env.example .env

# 2. Build the web image and start everything
make up

# 3. Tail the web container boot (it waits for the DB, runs schema + migrations)
make logs
```

The first run takes ~1-2 minutes: Docker pulls MariaDB, builds the PHP image, the DB initializes, and the web entrypoint runs the schema and all migrations. On subsequent runs it starts in a few seconds.

👉 **http://localhost:8080**

### Day-to-day (via the Makefile)

```bash
make help          # show every available target
make up            # build (if needed) and start the stack
make down          # stop the stack, keep data
make restart       # restart the web service (re-applies any new migrations)
make logs          # tail logs from all services
make ps            # show container status
make rebuild       # force a clean rebuild of the web image
make shell         # open a bash shell inside the web container
make db-shell      # open a mariadb shell inside the db container
make lint          # run `php -l` on every .php file (no host PHP needed)
make backup        # snapshot DB + uploads to ./backups/YYYYMMDD-HHMMSS/
make restore BACKUP=./backups/20251011-220000    # restore from a snapshot
make reset CONFIRM=yes                           # nuke DB + uploads (DESTRUCTIVE)
make prune         # remove dangling Docker images + build cache
```

Under the hood, the Makefile is a thin wrapper around `docker compose`. If you prefer the raw commands:

```bash
docker compose up -d --build       # equivalent to: make up
docker compose down                # equivalent to: make down
docker compose logs -f             # equivalent to: make logs
docker compose exec web bash       # equivalent to: make shell
```

### Apply code edits

PHP, CSS, and JS files in `public/` and `src/` are bind-mounted, so most edits are live on the next request — no rebuild. To apply a new migration: drop the `.sql` file in `database/migrations/` and run `make restart`.

# Open a MariaDB shell

docker compose exec db mariadb -uroot -prootpass profile_system

# Open a shell inside the web container

docker compose exec web bash

# Stop (preserves data)

docker compose down

# Start again

docker compose up -d

# Nuke everything including the database (DESTRUCTIVE)

docker compose down -v

```

> **Data persistence:** two named Docker volumes survive `down`:
> - `profile_system_db` — the MariaDB data directory
> - `profile_system_uploads` — uploaded photos
>
> `docker compose down -v` deletes both. If you want a fresh start without losing your photos, run `docker compose down -v && docker compose up -d`.

### Sharing the app on the public internet

Once `make up` is healthy, run `make tunnel` to get a public
`https://<random>.trycloudflare.com` URL. See
[`docs/TUNNEL.md`](docs/TUNNEL.md) for the full workflow, the
security caveats, and how to rotate the DB password.

### Running on Windows or macOS

The setup is identical: clone/copy the project, `cd` into it, `docker compose up -d --build`. Docker Desktop handles the rest.

If port 8080 is in use on your machine, edit `.env` and set `WEB_PORT=9000` (or anything free). The web server will be on `http://localhost:9000`.

If port 8081 is in use (Adminer's default), set `ADMINER_PORT=9001` instead. See [Visualize the database](#visualize-the-database-adminer) below.

---

## Quick start (without Docker)

If you really want to run outside Docker (e.g. you already have a local LAMP stack):

### Prerequisites

- PHP **8.1+** with `pdo_mysql`, `gd`, `fileinfo`, and Apache `mod_rewrite` enabled
- MySQL 8 or MariaDB 10.5+

### Setup

1. Copy the project into your web root (e.g. `C:\xampp\htdocs\profile_system\`) and **point Apache's DocumentRoot at the `public/` subdirectory** (or alias it). All browser-facing files live in `public/`; the rest of the tree (`src/`, `database/`, `docker/`) is not web-served.
2. Create the database. Either:
   - Open `http://localhost/phpmyadmin` → **Import** → upload `database/schema.sql`, then each file in `database/migrations/` **in name order**, **or**
   - From the terminal: `mysql -u root -p < database/schema.sql && for f in database/migrations/*.sql; do mysql -u root -p profile_system < "$f"; done`
3. Set the DB connection env vars before starting PHP:
   - **Linux/macOS:** `export DB_HOST=localhost DB_USER=root DB_PASSWORD=yourpw`
   - **Windows (PowerShell):** `$env:DB_HOST="localhost"; $env:DB_USER="root"; $env:DB_PASSWORD="yourpw"`
4. Make `public/uploads/profiles/` writable by the web server:
   ```bash
   chmod -R 775 public/uploads/
   chown -R www-data:www-data public/uploads/   # or the user Apache runs as
```

5. Open `http://localhost/profile_system/public/` (or whatever URL maps to the `public/` directory).

> **Note on `.htaccess`:** the `.htaccess` inside `public/` uses `mod_rewrite` to route `/u/<username>` to `u/index.php`. On Apache this works out of the box. On nginx you'll need an equivalent rewrite rule.

---

## Public exposure (Cloudflare tunnel)

To let other people reach your local app from the public internet,
follow **[`docs/TUNNEL.md`](docs/TUNNEL.md)**. Two flavors:

- **Quick tunnel** (no account) — `make tunnel` gives you a
  `https://<random>.trycloudflare.com` URL that lives as long as the
  process is running. URL is random per restart. **This is the
  default and the recommended starting point.**
- **Named tunnel** (free Cloudflare account + your own domain) — gives
  you a stable subdomain like `https://profile.yourdomain.com`. See
  the linked doc for the trade-offs and the step-by-step.

The hardening below makes the quick-tunnel option safe enough for a
friends-and-family demo. Anything beyond that should use a named
tunnel and a real reverse proxy.

---

## Hardening

This pass is what makes "share the app via a quick tunnel" a
reasonable thing to do. Every change has an entry in the
`schema_migrations` table or a clearly-commented section in the
source; nothing is hidden.

| Change | Where | Why |
| ------ | ----- | --- |
| Strong, unique `DB_PASSWORD` (replaces `rootpass`) | `.env` | The shipped default was a known shared password. |
| Dedicated `profile_app` DB user | `.env` + `docker/web-entrypoint.sh` | App no longer connects as root; web entrypoint creates the user on first boot. |
| Adminer behind a `tools` Compose profile | `compose.yml` | `make up` no longer starts Adminer on port 8081. Start it locally with `make tools`. |
| Secure session cookie behind proxy | `src/includes/auth.php` | Behind `cloudflared` the origin sees plain HTTP, so we now also honor `X-Forwarded-Proto` when `TRUST_PROXY=1`. |
| Rate limiting on login / register / forgot-password | `src/includes/ratelimit.php` + `database/migrations/018_login_attempts.sql` | 5 fails / 15 min per email, 20 fails / 10 min per IP, 5 / hour per IP for the other two. |
| `APP_ENV=production` hides the dev reset link | `public/forgot_password.php` | The dev "copy this link" banner was the single biggest pre-publication risk. |
| Pinned `mariadb:11.4` and `adminer:4.8.1` | `compose.yml` | `:latest` and floating tags are reproducibility risks. |
| `make tunnel` / `make tunnel-url` / `make tunnel-stop` / `make tunnel-restart` | `Makefile` | One-liner start, status, stop, restart of the Cloudflare quick tunnel. |
| `make tools` / `make tools-down` | `Makefile` | On-demand Adminer start/stop. |

The full security audit and the remaining caveats live in
[Security notes](#security-notes) below.

---

## Environment variables

All configuration is via environment variables. In Docker, set them in `.env` (copied from `.env.example`) — Compose automatically injects them into the web container. Outside Docker, set them in your shell before starting PHP.

### Web / app container

| Variable                   | Default             | Read by                                                                | Purpose                                                                                                      |
| -------------------------- | ------------------- | ---------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------ |
| `DB_HOST`                | `profile_db`      | `src/includes/db.php`, `docker/web-entrypoint.sh`, `adminer`     | MariaDB host. Must match the`db` service name in `compose.yml` (or `localhost` for native PHP).        |
| `DB_PORT`                | `3306`            | `src/includes/db.php`                                                | MariaDB port.                                                                                                |
| `DB_NAME`                | `profile_system`  | `src/includes/db.php`, `docker/web-entrypoint.sh`, `compose.yml` | Application database.                                                                                        |
| `DB_USER`                | `root`            | `src/includes/db.php`                                                | MariaDB user the app connects as.                                                                            |
| `DB_PASSWORD`            | `rootpass`        | `src/includes/db.php`, `compose.yml` (DB root password)            | MariaDB password.**Change in `.env` for anything beyond local dev.**                                 |
| `WEB_PORT`               | `8080`            | `compose.yml`                                                        | Host port mapped to the web container's port 80.                                                             |
| `ADMINER_PORT`           | `8081`            | `compose.yml`                                                        | Host port mapped to the Adminer container's port 8080.                                                       |
| `ADMINER_DEFAULT_SERVER` | `profile_db`      | `compose.yml`                                                        | Pre-fills the "Server" field on Adminer's login page.                                                        |
| `ADMINER_DESIGN`         | `pepa-linha-dark` | `compose.yml`                                                        | Adminer theme. Others:`pepa-linha-light`, `pepa-linha-admin`, `brentlegg`, `parachute`, `galkaev`. |

### MariaDB container

The official `mariadb:11` image reads its own env vars from `compose.yml`:

| Variable                  | Default                        | Purpose                                                            |
| ------------------------- | ------------------------------ | ------------------------------------------------------------------ |
| `MARIADB_ROOT_PASSWORD` | `${DB_PASSWORD:-rootpass}`   | Sets the root password on first boot. Ignored on subsequent boots. |
| `MARIADB_DATABASE`      | `${DB_NAME:-profile_system}` | Hint to the init scripts; the web entrypoint also creates the DB.  |

> **Production:** create a dedicated `profile_app` user with limited privileges, and update `docker/db-init/00-bootstrap.sql` plus `.env` accordingly. See the commented-out `CREATE USER` lines in `00-bootstrap.sql`.

---

## Default credentials

There are **no default users**, and there is **no open sign-up**: accounts are created from invitation links only.

- **First run.** While the `users` table is empty, `/register.php` lets the very first person create an account without an invitation. That account becomes the admin (it also gets the teacher and student roles).
- **Everyone after that** needs a link from `/invites.php`:
  - a **teacher** can create links that make **student** accounts;
  - an **admin** can create links for any role (a link that grants admin always works once only).
  A link can be limited to one email address, allowed for several people (e.g. a class), and expires after 1 to 30 days. Only a hash of the token is stored, so the link is shown once, when it is created. A link stops working if it is cancelled, used up, expired, or if its creator is deactivated or loses the right to invite.
- Admins can also create an account directly from **Users → New user**.

To grant admin by hand (e.g. after restoring a database):

```bash
docker compose exec db mariadb -uroot -prootpass profile_system \
  -e "INSERT IGNORE INTO user_roles (user_id, role) SELECT id, 'admin' FROM users WHERE email='YOU@example.com';"
```

Log out and back in afterwards so the new role is loaded into your session.

---

## Visualize the database (Adminer)

A third service, [Adminer](https://www.adminer.org/), is included in the stack so you can browse tables, run ad-hoc SQL, and edit rows in a browser. No extra host software required.

**Start it (Docker):**

```bash
docker compose up -d adminer
```

Then open [http://localhost:8081](http://localhost:8081) in your browser. Login form:

| Field    | Value                                                          |
| -------- | -------------------------------------------------------------- |
| System   | MySQL (pre-selected)                                           |
| Server   | `profile_db` (pre-filled via `ADMINER_DEFAULT_SERVER`)     |
| Username | `root`                                                       |
| Password | `rootpass` (or whatever `DB_PASSWORD` is in your `.env`) |
| Database | `profile_system` (optional — leave blank to list all)       |

Click **Login** → you'll see the list of all 14 tables (`users`, `profiles`, `educations`, `journal_articles`, `conference_papers`, `awards`, `skills`, `certifications`, `research_interests`, `languages`, `work_experiences`, `password_resets`, `activity`, `schema_migrations`).

**Things you can do there:**

- Run an ad-hoc query: top menu → **SQL command** → paste any `SELECT`/`UPDATE`/`INSERT` → **Execute**.
- Browse a table: click its name in the left column.
- Edit a row: click the row → modify fields → **Save**.
- Export: top menu → **Export** → choose SQL/CSV/etc. and download.
- Watch migrations apply in real time: refresh the `schema_migrations` table.

**Without Docker:** install Adminer (a single PHP file) on your own PHP server and point it at your local MySQL/MariaDB instance with the same credentials from `src/includes/db.php`.

> **Security:** Adminer has full DB root access. Do not expose port `8081` on a public network. If you change the theme, `pepa-linha-dark` is just one of several built-ins — others: `pepa-linha-light`, `pepa-linha-admin`, `brentlegg`, `parachute`, `galkaev`.

---

## User guide

### Register and log in

1. Click **Sign up** on the home page.
2. Enter your name, a username (3-40 chars, letters/numbers/hyphens/underscores), email, and a password (8+ chars).
3. Click **Create account** — you'll be sent to the login page.
4. Log in with your email and password.

**Password tips:** The form has a show/hide toggle. Use something unique; 8+ characters minimum.

### Build your profile

After logging in, the dashboard shows a **profile-completion** progress bar (0-100%). Click **Complete your profile** to fill in:

- **Photo:** click to choose or drag/drop an image. JPG / PNG / WEBP / GIF, max 2 MB. The preview appears instantly. Use **Remove photo** to clear it.
- **Phone, address, profession, bio:** all optional, all have character counters.
- Click **Save changes**. A green toast confirms the save, and you're redirected to your profile.

### Your public page

Every user has a public page at **`/u/<username>`** that anyone — even people not logged in — can view. It shows your avatar, name, profession, bio, and contact info.

From your private **Profile** page, click **Copy link** to put your public URL on the clipboard, or **Open** to view it in a new tab.

### Settings

**Settings** (top-right nav) has three tabs:

- **Account** — change your name, username, and email. The public profile URL changes immediately when you change your username.
- **Security** — change your password. You'll be asked for your current password first.
- **Danger zone** — permanently delete your account. You'll need to type `delete my account` exactly and re-enter your password to confirm.

All changes show a success toast.

### Forgot password

On the login page, click **Forgot password?** and enter your email.

Because this app doesn't actually send email (it has no SMTP config), the page will show a yellow dev banner with the reset link. **Copy** it and open it in a new tab. The link is valid for 30 minutes and can only be used once.

### Delete your account

Settings → Danger zone → type `delete my account` exactly + enter your password → confirm. Your account, profile, and uploaded photo are permanently removed. You'll be logged out and sent to the home page.

---

## Admin guide

Only users with `role = 'admin'` see the **Admin** link in the top nav. Admins land on `/admin/index.php`, a searchable / filterable / sortable user table.

> **First admin:** see [Default credentials](#default-credentials) above.

### Search, filter, sort

- The **search box** matches name, email, username, student ID, research title, English/Japanese name, university email, and next step (LIKE, debounced 250ms).
- The **course dropdown** filters by lab course (D1/D2/D3/M1/M2/B3/B4/R/Other).
- The **profile type dropdown** filters by Bachelor / M.Sc. / PhD.
- The **role dropdown** filters to admins or non-admins.
- Click any column header to **sort** by it. Click again to reverse. The current sort is shown with ↑ / ↓.
- On mobile, the table collapses to a **card view**.

### Edit the xlsx-style lab fields

On a user's detail page, the **Lab Students fields** card lists the columns from the original *Information of Lab Students* spreadsheet. These fields are stored on the user's `profiles` row (see migration `003_profile_lab_fields.sql`) and are visible to admins in the user list. The form validates `course` against the lab enum, `enrolled_month` against the 12 months, `enrolled_year` as a 4-digit year (1900–2100), and `university_email` as a valid email. Saving is logged as `lab_fields_update` in the `activity` table.

### Promote / demote a user

1. Click **View** on a user (or click their name) to open their detail page.
2. In the right-hand **Role** card, pick the new role and click **Update role**.
3. A `<dialog>` confirms the change. Confirm, and the change takes effect immediately.

You **cannot demote yourself** — the form blocks it. (Use Settings if you want to do that.)

### Change a user's profile type

On the user's detail page, the **Profile type** card lets you switch them between Bachelor / M.Sc. / PhD.

- The change is confirmed with a `<dialog>` before applying.
- Which sections render on that user's profile changes immediately on next page load.
- Existing rows in sections that are no longer visible are **preserved** (they come back if the user switches back).
- The action is logged as `degree_change` in the `activity` table.

### Delete a user

1. On the user's detail page, scroll to **Danger zone**.
2. Click **Delete user** → confirm in the `<dialog>`.
3. The user, their profile, and their uploaded photo file are all removed. They won't be able to log in.

You **cannot delete your own admin account** from here. Use Settings.

### Activity log

Most admin and account actions write a row to the `activity` table. Each user sees their own recent activity on their dashboard. (A global admin activity stream is a future feature.)

---

## Architecture

### Stack

| Layer          | Choice                                                                                      |
| -------------- | ------------------------------------------------------------------------------------------- |
| Language       | PHP 8.3                                                                                     |
| Web server     | Apache 2.4 with`mod_rewrite`                                                              |
| Database       | MariaDB 10.5+ (MySQL 8 also works)                                                          |
| Frontend       | Tailwind CSS v4 + Alpine.js 3, themed with Preline UI tokens. Source in `resources/`, built to `public/assets/` |
| Build step     | `make assets` (or `npm run build`). Only needed after editing `resources/` or using new Tailwind classes; built files are committed |
| PHP extensions | `pdo_mysql`, `gd`, `fileinfo`                                                         |
| Container      | Custom image based on`php:8.3-apache` (see `Dockerfile`)                                |
| DB GUI (dev)   | [Adminer](https://www.adminer.org/) (optional service in `compose.yml`, port 8081)         |

### File layout

```
profile_system/
├── public/                    ← Apache DocumentRoot (web-served; everything below is NOT)
│   ├── .htaccess              Routes /u/<x> → u/index.php, disables directory listing
│   ├── index.php              Landing page (hero + "what you get" cards)
│   ├── login.php              Login form
│   ├── register.php           Registration form (collects username + profile type)
│   ├── forgot_password.php    Request a password-reset link
│   ├── reset_password.php     Set a new password via token
│   ├── dashboard.php          Welcome + completion progress + recent activity
│   ├── profile.php            Edit form with photo preview + character counters + section editor (the merged profile view)
│   ├── settings.php           Account / Security / Danger tabs
│   ├── logout.php             POST-based logout with CSRF
│   │
│   ├── u/
│   │   └── index.php          Public profile at /u/<username> (via .htaccess rewrite)
│   │
│   ├── teacher/
│   │   └── index.php          Teacher-only student directory (read-only)
│   │
│   ├── admin/
│   │   ├── index.php          User table (search + course/degree/role filters + sortable columns incl. the xlsx-style lab fields)
│   │   ├── user.php           Single-user management (role + degree_type + Lab Students fields editor + delete)
│   │   └── view_user.php      Back-compat shim → 302 redirects to user.php
│   │
│   ├── assets/
│   │   ├── css/app.css        The whole design system (~800 lines)
│   │   └── js/app.js          Toasts, modals, photo preview, password toggle, ...
│   │
│   └── uploads/               User-uploaded photos (mounted as a Docker volume)
│       ├── .htaccess          Denies PHP execution in case a malicious file slips through
│       └── profiles/          Random-filename photos
│
├── src/                       Application PHP (not web-served)
│   └── includes/
│       ├── auth.php           Session config, e(), CSRF, require_login, require_admin
│       ├── db.php             PDO connection (reads DB_HOST/PORT/NAME/USER/PASSWORD from env)
│       ├── url.php            URL helpers + username_slug()
│       ├── validate.php       Server-side validators + log_activity()
│       ├── profile_sections.php  Section registry (what each profile type shows)
│       ├── render_sections.php   Section renderers for public/private profile pages
│       ├── header.php         Shared <head> + topbar partial
│       └── footer.php         Shared </main> + footer + toast stack
│
├── database/                  All SQL together
│   ├── schema.sql             Original two-table schema (users + profiles); idempotent
│   └── migrations/            SQL files, applied in name order on web container start
│       ├── 001_redesign.sql           username, password_resets, activity
│       ├── 002_academic_profile.sql   users.degree_type + 9 child tables
│       ├── 003_profile_lab_fields.sql  xlsx-style lab fields on profiles (course, student_id, name_jp/en, university_email, research_title, enrolled year/month, next_step, previous_univ_level, completed_thesis, journal_papers, conference_papers)
│       └── 004_user_roles.sql          multi-role join table + idempotent backfill from users.role (column dropped in 016)
│
├── docker/                    Docker-specific files
│   ├── web-entrypoint.sh      Wait for DB, apply schema + migrations, exec CMD
│   └── db-init/               First-boot SQL run by the official mariadb image
│       └── 00-bootstrap.sql   CREATE DATABASE IF NOT EXISTS
│
├── docs/
│   └── DIRECTORY.md           File-by-file tour of every PHP, SQL, CSS, JS file
│
├── Dockerfile                 Custom PHP 8.3 + Apache image (uses web-entrypoint.sh)
├── compose.yml                Three-service stack: db + web + adminer, with healthchecks
├── .env.example               Template for the .env file (copy to .env)
├── .dockerignore              Files excluded from the Docker build context
├── .gitignore
├── LICENSE                    All rights reserved (learning project; no warranty)
├── CHANGELOG.md
├── CONTRIBUTING.md
└── README.md                  You are here
```

### Database schema (full)

The full schema lives across five files, applied in this order on first boot:

- `database/schema.sql` — original two tables (idempotent)
- `database/migrations/001_redesign.sql` — `users.username`, `password_resets`, `activity`
- `database/migrations/002_academic_profile.sql` — `users.degree_type` + 9 academic child tables
- `database/migrations/003_profile_lab_fields.sql` — xlsx-style lab fields on `profiles` (course, student_id, name_jp/en, university_email, research_title, enrolled year/month, next_step, previous_univ_level, completed_thesis, journal_papers, conference_papers)
- `database/migrations/004_user_roles.sql` — `user_roles` join table for multi-role auth; backfills from the legacy `users.role` ENUM (no data loss)

The entrypoint tracks applied migrations in a `schema_migrations(filename, applied_at)` table, so re-runs are no-ops.

#### users (1 row per account)

| Column            | Type                                             | Notes                                                  |
| ----------------- | ------------------------------------------------ | ------------------------------------------------------ |
| `id`            | `INT AUTO_INCREMENT PRIMARY KEY`               |                                                        |
| `name`          | `VARCHAR(100) NOT NULL`                        | Display name                                           |
| `username`      | `VARCHAR(40) NOT NULL UNIQUE`                  | Public URL slug, lowercase,`[a-z0-9_-]` (3-40 chars) |
| `email`         | `VARCHAR(150) NOT NULL UNIQUE`                 |                                                        |
| `password_hash` | `VARCHAR(255) NOT NULL`                        | bcrypt via`password_hash(PASSWORD_DEFAULT)`          |
| `degree_type`   | `ENUM('bachelor','msc','phd') NULL`            | Controls which sections render on the profile          |
| `created_at`    | `TIMESTAMP DEFAULT CURRENT_TIMESTAMP`          |                                                        |

#### user_roles (many-to-many; 1 user can hold multiple roles)

| Column         | Type                                                                                  | Notes                                                                  |
| -------------- | ------------------------------------------------------------------------------------- | ---------------------------------------------------------------------- |
| `user_id`    | `INT NOT NULL`                                                                      | FK →`users.id` (`ON DELETE CASCADE`)                              |
| `role`       | `ENUM('admin','teacher','student') NOT NULL`                                       | Allowed values                                                          |
| `created_at` | `TIMESTAMP DEFAULT CURRENT_TIMESTAMP`                                             |                                                                        |
| Primary key   | `(user_id, role)`                                                                  | A user can hold each role at most once                                |
| Index         | `(role, user_id)`                                                                  | Fast reverse lookup — "everyone with role=student"                   |

**The 3 roles and what they can do:**

| Role     | What they can do                                                                              |
| -------- | ---------------------------------------------------------------------------------------------- |
| `student` | Maintain their own profile only. The default for new signups.                                |
| `teacher` | Read-only access to the **student directory** at `/teacher/`. Cannot edit/delete anyone.       |
| `admin`   | Full CRUD on every user. Includes teacher + student abilities. **Cannot self-assign** — must be granted by an existing admin. |

The legacy single-value `users.role` column was dropped in migration 016; `user_roles` is the only place roles are stored.

#### profiles (1-to-1 with users, FK ON DELETE CASCADE)

| Column                  | Type                                                                                                | Notes                                                                |
| ----------------------- | --------------------------------------------------------------------------------------------------- | -------------------------------------------------------------------- |
| `user_id`             | `INT PRIMARY KEY`                                                                                 | FK →`users.id`                                                    |
| `phone`               | `VARCHAR(20) NULL`                                                                                |                                                                      |
| `address`             | `TEXT NULL`                                                                                       |                                                                      |
| `profession`          | `VARCHAR(100) NULL`                                                                               |                                                                      |
| `course`              | `ENUM('D1','D2','D3','M1','M2','B3','B4','R','Other') NULL`                                    | Lab course (from xlsx)                                              |
| `student_id`          | `VARCHAR(64) NULL`                                                                                |                                                                      |
| `name_jp`             | `VARCHAR(255) NULL`                                                                               | Full name (Japanese)                                                 |
| `name_en`             | `VARCHAR(255) NULL`                                                                               | Full name (English)                                                  |
| `university_email`    | `VARCHAR(255) NULL`                                                                               |                                                                      |
| `research_title`      | `TEXT NULL`                                                                                       |                                                                      |
| `enrolled_year`       | `SMALLINT NULL`                                                                                   | 1900–2100                                                            |
| `enrolled_month`      | `ENUM('January',...'December') NULL`                                                            |                                                                      |
| `next_step`           | `VARCHAR(255) NULL`                                                                               | 進路                                                                  |
| `previous_univ_level` | `VARCHAR(255) NULL`                                                                               |                                                                      |
| `completed_thesis`    | `TEXT NULL`                                                                                       |                                                                      |
| `journal_papers`      | `TEXT NULL`                                                                                       | Format: `{published}({submitted})`                                   |
| `conference_papers`   | `TEXT NULL`                                                                                       | Format: `{published}({submitted})`                                   |
| `bio`                 | `TEXT NULL`                                                                                       | Up to ~3000 chars                                                    |
| `photo`               | `VARCHAR(255) NULL`                                                                               | Relative path:`uploads/profiles/<random>.<ext>`                 |
| `updated_at`          | `TIMESTAMP ... ON UPDATE CURRENT_TIMESTAMP`                                                       |                                                                      |

#### password_resets (token-based password reset)

| Column                                                            | Type                                    | Notes                              |
| ----------------------------------------------------------------- | --------------------------------------- | ---------------------------------- |
| `id`                                                            | `INT AUTO_INCREMENT PRIMARY KEY`      |                                    |
| `user_id`                                                       | `INT NOT NULL`                        | FK →`users.id` (CASCADE)        |
| `token_hash`                                                    | `CHAR(64) NOT NULL`                   | sha256 of the random 32-byte token |
| `expires_at`                                                    | `DATETIME NOT NULL`                   | 30 minutes from creation           |
| `used_at`                                                       | `DATETIME NULL`                       | Set on successful reset            |
| `created_at`                                                    | `TIMESTAMP DEFAULT CURRENT_TIMESTAMP` |                                    |
| Indexes:`idx_pr_user (user_id)`, `idx_pr_token (token_hash)`. |                                         |                                    |

#### activity (audit log; per-user feed on the dashboard)

| Column                                                     | Type                                    | Notes                                             |
| ---------------------------------------------------------- | --------------------------------------- | ------------------------------------------------- |
| `id`                                                     | `BIGINT AUTO_INCREMENT PRIMARY KEY`   |                                                   |
| `user_id`                                                | `INT NULL`                            | NULL for system-level events (e.g. self-deletion) |
| `kind`                                                   | `VARCHAR(40) NOT NULL`                | See kinds below                                   |
| `detail`                                                 | `VARCHAR(255) NULL`                   | Free-form context, e.g.`eyasir-ahamed -> admin` |
| `created_at`                                             | `TIMESTAMP DEFAULT CURRENT_TIMESTAMP` |                                                   |
| Indexes:`(user_id, created_at)`, `(kind, created_at)`. |                                         |                                                   |

**`activity.kind` values written by the app:**

| Kind                | When                                                   | `detail`                     |
| ------------------- | ------------------------------------------------------ | ------------------------------ |
| `register`        | Successful registration                                | `degree=bachelor\|msc\|phd`    |
| `login`           | Successful login                                       | `NULL`                       |
| `profile_update`  | Profile saved (basics + sections)                      | `NULL`                       |
| `password_change` | User changed password from Settings                    | `NULL`                       |
| `password_reset`  | Reset link used                                        | `NULL`                       |
| `account_update`  | Settings → Account saved (name/email/username/degree) | `name/email/username/degree` |
| `role_change`     | Admin changed a user's role                            | `<username> -> <role>`       |
| `degree_change`   | Admin changed a user's degree_type                     | `<username> -> <type>`       |
| `lab_fields_update` | Admin saved the xlsx-style lab fields on a user       | `<username>`                 |
| `account_delete`  | Account deleted (self or by admin)                     | `<username>`                 |

#### Academic section tables (9 tables, same shape: `id, user_id (FK CASCADE), section-specific columns, sort_order, created_at, updated_at`)

All nine tables share the same `id, user_id, sort_order, created_at, updated_at` columns and a `user_id` FK with `ON DELETE CASCADE`. The differences are the editable fields:

| Table                  | Key columns                                                                                                   |
| ---------------------- | ------------------------------------------------------------------------------------------------------------- |
| `educations`         | `institution`, `degree`, `field_of_study`, `start_year`, `end_year`, `description`                |
| `work_experiences`   | `organization`, `role_title`, `location`, `start_date`, `end_date`, `is_current`, `description` |
| `journal_articles`   | `title`, `authors`, `journal`, `year`, `volume`, `issue`, `pages`, `doi`, `url`             |
| `conference_papers`  | `title`, `authors`, `conference`, `year`, `location`, `doi`, `url`                              |
| `awards`             | `title`, `organization`, `year`, `description`                                                        |
| `skills`             | `name`, `level` (ENUM beginner/intermediate/advanced/expert)                                              |
| `certifications`     | `name`, `issuing_org`, `issue_date`, `expiry_date`, `credential_id`, `url`                        |
| `research_interests` | `name`                                                                                                      |
| `languages`          | `name`, `proficiency` (ENUM elementary/limited_working/professional_working/full_professional/native)     |

All have `INDEX (user_id, sort_order)` so a per-user list is one fast query.

#### schema_migrations (created by the entrypoint)

| Column         | Type                                    | Notes                    |
| -------------- | --------------------------------------- | ------------------------ |
| `filename`   | `VARCHAR(255) PRIMARY KEY`            | e.g.`001_redesign.sql` |
| `applied_at` | `TIMESTAMP DEFAULT CURRENT_TIMESTAMP` |                          |

### URL routing

Apache routes everything through these files (no front controller):

| URL pattern       | File                                                      |
| ----------------- | --------------------------------------------------------- |
| `/`             | `index.php`                                             |
| `/login.php`    | `login.php`                                             |
| `/u/<username>` | rewritten by`.htaccess` to `u/index.php?u=<username>` |
| `/admin/...`    | `admin/index.php`, `admin/user.php`, etc.             |
| `/assets/...`   | served directly by Apache (CSS, JS)                       |
| `/uploads/...`  | served directly (photos)                                  |

Anything that doesn't match a file or directory returns Apache's default 404.

### Profile types & sections

Every user picks one of three profile types at registration. Each type shows a different set of sections. The source of truth is `src/includes/profile_sections.php` (one `$SECTIONS` array keyed by table name; each entry has a `visible_for` closure that decides which degree types see it).

| Section (table)                             | Bachelor | M.Sc. | PhD |
| ------------------------------------------- | :------: | :---: | :-: |
| Education (`educations`)                  |    ✓    |  ✓  | ✓ |
| Work experience (`work_experiences`)      |    ✓    |  ✓  | ✓ |
| Journal articles (`journal_articles`)     |    —    |  ✓  | ✓ |
| Conference papers (`conference_papers`)   |    —    |  ✓  | ✓ |
| Research interests (`research_interests`) |    —    |  ✓  | ✓ |
| Awards (`awards`)                         |    ✓    |  ✓  | ✓ |
| Skills (`skills`)                         |    ✓    |  ✓  | ✓ |
| Certifications (`certifications`)         |    ✓    |  ✓  | ✓ |
| Languages (`languages`)                   |    ✓    |  ✓  | ✓ |

**Users can change their profile type any time** in Settings → Account. Existing rows in sections that are no longer visible are preserved (they come back if the user switches back). New sections simply appear on the next page load.

**Tag-style sections** (`skills`, `research_interests`, `languages`) are rendered as compact chips on both private and public profiles. The others render as a list of cards.

### Design system

The UI was rebuilt on Tailwind CSS v4, Alpine.js and Preline UI's theme
tokens. It is documented in three files:

| Doc | What it covers |
| --- | --- |
| [`docs/DESIGN.md`](docs/DESIGN.md) | Stack decisions, research, colour / type / spacing tokens, layout and behaviour rules, patterns deliberately avoided |
| [`docs/COMPONENTS.md`](docs/COMPONENTS.md) | Every component class and PHP helper, with markup to copy |
| [`docs/FRONTEND.md`](docs/FRONTEND.md) | Build commands, file layout, Alpine stores / directives / components, how to add a component |

In short: warm paper neutrals with one ink-blue accent, Source Serif 4 for
titles and IBM Plex Sans for everything else (both self-hosted), hairline
rules instead of shadows, light and dark themes from the same tokens.

### JavaScript

Source is in `resources/js/` and is bundled into `public/assets/js/app.js`:

- `ui.js`: Alpine stores (`toasts`, `confirm`), directives (`x-confirm`,
  `x-copy`, `x-autosubmit`) and components (`dropdown`, `dirtyForm`,
  `themePicker`, `passwordForm`, `sheet`).
- `profile-editor.js`: the inline editor on `/profile.php`.
- `window.PS.toast()` and `window.PS.confirm()` are available to inline scripts.

See [`docs/FRONTEND.md`](docs/FRONTEND.md) for the full reference.

### PHP helpers

All shared code lives in `src/includes/`. There are no classes — just functions you call.

#### `src/includes/auth.php` — session & auth

| Function                                                                          | Purpose                                                                                                             |
| --------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------------- |
| `e(mixed $v): string`                                                           | Escape output. Always use for any user input. Wraps`htmlspecialchars(..., ENT_QUOTES \| ENT_SUBSTITUTE, 'UTF-8')`. |
| `text_length(string $v): int`                                                   | Multi-byte-safe string length.                                                                                      |
| `post_string(string $key): string` | Read `$_POST[$key]` as a trimmed string. |                                                                                                                     |
| `csrf_token(): string`                                                          | Per-session token. Render as a hidden input in every form.                                                          |
| `verify_csrf(): void`                                                           | Call at the top of every POST handler. Exits with 419 on mismatch.                                                  |
| `require_login(): void`                                                         | Redirects to`login.php` if not logged in.                                                                         |
| `require_admin(): void`                                                         | Calls`require_login()` then 403s if the session role isn't `admin`.                                             |
| `is_logged_in(): bool`                                                          |                                                                                                                     |
| `current_user_id(): ?int`                                                       |                                                                                                                     |
| `current_user_role(): string`                                                   | `''` if not logged in                                                                                             |
| `current_user_name(): string`                                                   |                                                                                                                     |

Session config (in the same file): `session.use_strict_mode=1`, `use_only_cookies=1`, `httponly`, `secure` when HTTPS, `samesite=Lax`.

#### `src/includes/db.php` — PDO connection

Reads `DB_HOST / DB_PORT / DB_NAME / DB_USER / DB_PASSWORD` from env. Uses `PDO::ERRMODE_EXCEPTION`, `FETCH_ASSOC`, `EMULATE_PREPARES = false`. Retries up to 10× (0.5s apart) to handle the cold-start race, then 500s with a generic error message.

#### `src/includes/url.php` — URL & session helpers

| Function                                                                                                                                                                        | Returns                                                                                                      |
| ------------------------------------------------------------------------------------------------------------------------------------------------------------------------------- | ------------------------------------------------------------------------------------------------------------ |
| `url_for($path)`                                                                                                                                                              | Root-relative URL, e.g.`url_for('login.php')` → `/login.php`                                            |
| `url_home() / url_login() / url_register() / url_logout() / url_dashboard() / url_profile() / url_settings() / url_admin() / url_forgot() / url_reset()` | Same for each well-known page                                                                                |
| `is_logged_in() / current_user_id() / current_user_role() / current_user_name()`                                                                                              | (re-declared from`auth.php` for convenience)                                                               |
| `username_slug(string $name): string`                                                                                                                                         | URL-safe slug (lowercase, hyphenated, ASCII-transliterated, ≤40 chars). Used as a fallback during backfill. |

#### `src/includes/validate.php` — server-side validation

All validators return `['value' => $normalized, 'errors' => ['field' => 'message']]`. Views render the errors inline.

| Function                                                                                       | Field                                                                         |
| ---------------------------------------------------------------------------------------------- | ----------------------------------------------------------------------------- |
| `validate_name()`                                                                            | Full name (≤100 chars)                                                       |
| `validate_username($value, $excludeUserId = null)`                                           | 3-40 chars,`[a-z0-9_-]`, not start/end with separator, unique in DB         |
| `validate_email()`                                                                           | RFC-ish via`filter_var`, ≤150 chars                                        |
| `validate_password($value, $required = true)`                                                | ≥8 chars (when present), ≤4096                                              |
| `validate_password_confirm($pw, $confirm)`                                                   | Equality                                                                      |
| `validate_degree_type()`                                                                     | One of`bachelor` / `msc` / `phd`                                        |
| `validate_phone()` / `validate_address()` / `validate_profession()` / `validate_bio()` | Length caps only (all optional)                                               |
| `log_activity(?int $userId, string $kind, ?string $detail = null)`                           | Insert a row into`activity`; silently no-ops if the table doesn't exist yet |

#### `src/includes/profile_sections.php` + `src/includes/render_sections.php` — section registry

| Function                                                                     | Purpose                                                         |
| ---------------------------------------------------------------------------- | --------------------------------------------------------------- |
| `DEGREE_TYPES`                                                             | Constant:`bachelor`, `msc`, `phd` (with display labels)   |
| `normalize_degree_type(?string $raw): string`                              | Coerces any value to one of the three (defaults to`bachelor`) |
| `degree_label(?string $type): string`                                      | `'PhD'`, `'M.Sc.'`, `'Bachelor'`                          |
| `visible_sections(string $degreeType): array`                              | Section keys visible for a given type                           |
| `load_sections_for_user(PDO $pdo, int $userId, string $degreeType): array` | `[sectionKey => rows[]]`                                      |
| `section_columns(string $sectionKey): array`                               | Whitelist of editable columns for that section                  |
| `render_sections($degreeType, $sections)`                                  | Echoes the section HTML for all visible sections                |
| `render_section_row($sectionKey, $row)`                                    | Echoes one row in the appropriate layout                        |
| `render_section_tags($sectionKey, $rows)`                                  | Compact chip layout for skills/languages/research_interests     |

To add a new section, edit `$SECTIONS` in `profile_sections.php` and the corresponding SQL migration. See [Adding a new profile section](#adding-a-new-profile-section) below.

## Developer notes

### Adding a new field to the profile

1. **DB:** create a new migration file: `database/migrations/NNN_add_xyz.sql` (use the next available number) with `ALTER TABLE profiles ADD COLUMN xyz ...`
2. **DB:** on the next `docker compose restart web`, the entrypoint will see the new file, apply it, and record it in the `schema_migrations` table. You can also apply it manually:
   ```bash
   docker compose exec -T db mariadb -uroot -prootpass profile_system < database/migrations/NNN_add_xyz.sql
   ```
3. **Validator:** add `validate_xyz()` in `src/includes/validate.php`
4. **Form:** add the field to `public/profile.php` (and `public/register.php` if it should be set on signup)
5. **Display:** add it to `public/profile.php` and the admin list page
6. **Dashboard completion:** if it should count toward the completion bar, add the column name to the `$completionFields` array in `public/dashboard.php`

### Adding a new profile section

E.g. a new "Patents" section that shows for PhDs only.

1. **DB:** create `database/migrations/003_patents.sql`:
   ```sql
   START TRANSACTION;
   CREATE TABLE IF NOT EXISTS patents (
     id INT AUTO_INCREMENT PRIMARY KEY,
     user_id INT NOT NULL,
     title VARCHAR(300) NOT NULL,
     patent_number VARCHAR(100) NULL,
     year SMALLINT NULL,
     url VARCHAR(500) NULL,
     description TEXT NULL,
     sort_order INT NOT NULL DEFAULT 0,
     created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
     updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
     INDEX idx_pa_user (user_id, sort_order),
     CONSTRAINT fk_pa_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
   ) ENGINE=InnoDB;
   COMMIT;
   ```
2. **Restart web:** `docker compose restart web`. The entrypoint will apply the new file.
3. **Registry:** add a `patents` entry to `$SECTIONS` in `src/includes/profile_sections.php` with `visible_for: fn($t) => $t === 'phd'`.
4. **Renderer (optional):** if the default list-of-rows layout isn't what you want, add a `case 'patents':` to `render_section_row()` in `src/includes/render_sections.php`.
5. **That's it.** The edit form, dashboard, and public profile will pick it up automatically because they iterate `visible_sections($degreeType)`.

### Adding a new migration

1. Pick the next number (`003`, `004`, ...). Use zero-padded numbers to keep file ordering clean.
2. Create `database/migrations/NNN_short_description.sql`. Wrap DDL in `START TRANSACTION; ... COMMIT;` for atomicity.
3. Use `IF NOT EXISTS` / `IF EXISTS` where possible — the entrypoint will refuse to re-apply a file whose filename is already in `schema_migrations`, but defensive SQL is good hygiene.
4. **Never edit a migration after it's been applied to a shared environment.** Instead, write a new migration that makes the change.
5. Test locally: `docker compose restart web` and check `docker compose logs web` for the apply line.
6. Verify with `docker compose exec db mariadb -uroot -prootpass profile_system -e "SELECT * FROM schema_migrations ORDER BY filename;"`.

### Adding a new page

1. Create the PHP file inside `public/` (e.g. `public/reports.php`).
2. At the top:
   ```php
   <?php
   declare(strict_types=1);
   require_once __DIR__ . '/../src/includes/auth.php';
   require_once __DIR__ . '/../src/includes/db.php';
   require_once __DIR__ . '/../src/includes/url.php';
   require_login();                  // or require_admin(), or nothing
   ```
3. Set the page title and id, then include the partials:
   ```php
   $page_title = 'Reports';
   $page_id    = 'reports';
   require __DIR__ . '/../src/includes/header.php';
   ?>
   <!-- your HTML here -->
   <?php require __DIR__ . '/../src/includes/footer.php'; ?>
   ```
4. To highlight the new page in the top nav, edit `src/includes/header.php`.

### Conventions

- **Strict types everywhere:** every PHP file starts with `declare(strict_types=1);`
- **Output escaping:** always use the `e()` helper. Never `echo` unescaped user input.
- **SQL:** all queries are prepared statements with `PDO::ATTR_EMULATE_PREPARES = false`. Never interpolate user input.
- **CSRF:** every POST form must include `<input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">` and call `verify_csrf()` at the top of the POST handler.
- **Flash messages:** use the `$flash` array. Setting it before `require __DIR__ . '/../src/includes/header.php';` makes the toast appear on page load. For inline errors on a form, use the `$errors` array keyed by field name.
- **Validation:** server-side is mandatory. Client-side `pattern` and `minlength` are nice-to-have hints, not security.
- **Naming:** files are lowercase (`dashboard.php`), functions are `snake_case` (`require_admin`), CSS classes are BEM-ish (`.user-table`, `.role-badge`).
- **Migrations:** every schema change is a new file in `database/migrations/`, named `NNN_description.sql`, applied with `docker exec -i profile_db mariadb ...`. Never edit a migration after it's been run.

### Useful one-liners

(These assume Docker Compose. Outside Docker, use the equivalent `mysql` / `php` commands.)

```bash
# Generate a bcrypt hash for a new password
docker compose exec web php -r 'echo password_hash("NEW_PASSWORD", PASSWORD_DEFAULT), PHP_EOL;'

# Reset a user's password
docker compose exec db mariadb -uroot -prootpass profile_system \
  -e "UPDATE users SET password_hash='\$2y\$10\$...' WHERE email='USER@example.com';"

# Promote a user to admin
docker compose exec db mariadb -uroot -prootpass profile_system \
  -e "INSERT IGNORE INTO user_roles (user_id, role) SELECT id, 'admin' FROM users WHERE email='USER@example.com';"

# Wipe all users (DANGEROUS)
docker compose exec db mariadb -uroot -prootpass profile_system \
  -e "DELETE FROM users;"

# Tail the latest activity for a user
docker compose exec db mariadb -uroot -prootpass profile_system \
  -e "SELECT * FROM activity WHERE user_id=2 ORDER BY id DESC LIMIT 20;"

# Check which migrations have been applied
docker compose exec db mariadb -uroot -prootpass profile_system \
  -e "SELECT * FROM schema_migrations ORDER BY filename;"

# Who's hammering the login form right now? (per-bucket attempt counts)
docker compose exec db mariadb -uroot -p"$${DB_PASSWORD:-rootpass}" profile_system \
  -e "SELECT bucket, COUNT(*) AS attempts, MIN(created_at) AS first, MAX(created_at) AS last
        FROM login_attempts
       WHERE created_at >= (NOW() - INTERVAL 1 HOUR)
    GROUP BY bucket
    ORDER BY attempts DESC LIMIT 10;"

# Top failing IPs in the last 24h (good signal for a brute-force scan)
docker compose exec db mariadb -uroot -p"$${DB_PASSWORD:-rootpass}" profile_system \
  -e "SELECT ip, COUNT(*) AS failures
        FROM login_attempts
       WHERE success = 0 AND created_at >= (NOW() - INTERVAL 24 HOUR)
    GROUP BY ip
    ORDER BY failures DESC LIMIT 10;"

# End-to-end smoke test (login + admin pages + lab-fields save/filter)
bash docker/smoke.sh

# Manually re-run an entrypoint (e.g. after editing migrations/)
docker compose restart web && docker compose logs -f web
```

---

## Backup & restore

The data that matters lives in **two named Docker volumes**: `profile_system_db` (MariaDB data dir) and `profile_system_uploads` (uploaded photos). Back up both.

### Backup (one-liner)

```bash
mkdir -p ./backups/$(date +%Y%m%d)

# Database: dump to a single .sql file using mariadb-dump.
docker compose exec -T db mariadb-dump -uroot -prootpass \
  --single-transaction --quick --routines --triggers \
  profile_system > "./backups/$(date +%Y%m%d)/db.sql"

# Uploads: tar the named volume.
docker run --rm \
  -v profile_system_uploads:/source:ro \
  -v "$(pwd)/backups/$(date +%Y%m%d):/backup" \
  alpine tar czf /backup/uploads.tgz -C /source .
```

### Restore

```bash
BACKUP=./backups/20250115  # adjust

# Stop the web app so it doesn't write during the restore.
docker compose stop web

# Restore the DB. Drop & recreate to ensure a clean state.
docker compose exec -T db mariadb -uroot -prootpass -e "DROP DATABASE profile_system; CREATE DATABASE profile_system CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
docker compose exec -T db mariadb -uroot -prootpass profile_system < "$BACKUP/db.sql"

# Restore the uploads volume.
docker run --rm \
  -v profile_system_uploads:/target \
  -v "$(pwd)/$BACKUP:/backup:ro" \
  alpine sh -c "rm -rf /target/* && tar xzf /backup/uploads.tgz -C /target"

# Bring the web app back up.
docker compose start web
```

### Backup tips

- Automate with a cron job that writes to a remote location (S3, Backblaze, rsync to a NAS). **Local-only backups are not backups.**
- The `mariadb-dump` flags above are chosen for consistency (`--single-transaction`) and large-table safety (`--quick`).
- Test your restore procedure at least once. Untested backups are wishes.

---

## Upgrading

The app is intentionally simple. There are **no version numbers in the database** — the migration system is forward-only, and each `migrations/NNN_*.sql` file is the changelog.

### To pull new code (Docker)

```bash
# 1. Get the new code
git pull                      # or download / copy

# 2. Rebuild only if the Dockerfile, compose.yml, or .dockerignore changed
docker compose build          # safe to always run; no-op if nothing relevant changed

# 3. Bring up the new containers
docker compose up -d

# 4. Watch the entrypoint apply any new migrations
docker compose logs -f web
```

The web entrypoint compares `migrations/*.sql` filenames against `schema_migrations` and applies anything new in name order. It's safe to run multiple times.

### To pull new code (without Docker)

1. Replace the project files.
2. For each new `migrations/NNN_*.sql` (in name order), run:
   ```bash
   mysql -u root -p profile_system < migrations/NNN_xxx.sql
   ```
3. Reload PHP / Apache.

### Pre-flight checklist

- [ ] `.env` matches the new `.env.example` (any new vars?)
- [ ] `docker compose pull db` if you want the latest MariaDB patch version
- [ ] Back up the DB before upgrading (see [Backup &amp; restore](#backup--restore))
- [ ] Read the [Changelog](CHANGELOG.md) for breaking changes

---

---

## Troubleshooting

### "Could not connect to the database"

The web container can't reach the database. Check:

```bash
docker compose ps        # both profile_db and profile_web should be "Up" / "healthy"
docker compose logs db   # MariaDB init logs
docker compose logs web  # web entrypoint output (waits for DB, runs schema)
```

On a fresh first run, MariaDB can take 10-20 seconds to initialize. The web entrypoint waits up to 60 seconds before giving up. If you see the web container restarting, check `docker compose logs web` for the specific error.

### "Invalid email or password" on login

- The password is case-sensitive. Try with caps lock off.
- If you've forgotten the password, use **Settings → Security** (if logged in elsewhere) or **Forgot password** on the login page.
- To reset from the terminal:
  ```bash
  docker compose exec db mariadb -uroot -prootpass profile_system \
    -e "UPDATE users SET password_hash='\$2y\$10\$NEW_BCRYPT_HASH' WHERE email='YOU@example.com';"
  ```

### Photo upload fails silently

- Max 2 MB; only JPG, PNG, WEBP, GIF.
- Inside Docker the uploads volume is owned by `www-data`; this is handled by the entrypoint. If you bind-mount `./uploads` manually, `chmod -R 777` it (dev only).

### `/u/<username>` returns 404

- The `.htaccess` rewrite requires `mod_rewrite`. The Docker image enables it via `a2enmod rewrite`. If you're running outside Docker without Apache, use `/u/index.php?u=eyasir-ahamed` instead.
- The username is **case-insensitive** in the database but **lowercase** in the URL. `eyasir-ahamed`, not `Eyasir-Ahamed`.

### Port 8080 is already in use on my machine

Edit `.env` and set `WEB_PORT=9000` (or anything free), then `docker compose up -d`. The web server will be on `http://localhost:9000`.

### Photo preview shows "—" instead of the current photo

You probably clicked **Remove photo** and then navigated away without saving. The next save will actually delete the file.

### Toasts don't appear

- Make sure `assets/js/app.js` is being served (no 404 in the network tab).
- Make sure the `<body>` has `data-flash='[...]'` after a successful form submission. The flash is consumed once — a page refresh won't replay it.

### Admin link is missing from the top nav

Your session has the old role. **Log out, then log back in.** The role is only read from the DB at login.

### Port 8081 (Adminer) is already in use

Edit `.env` and set `ADMINER_PORT=9001` (or any free port), then `docker compose up -d adminer`. The container will restart on the new port.

### "Forbidden — admin access required" on an admin page

Either you're not an admin (check `SELECT r.role FROM user_roles r JOIN users u ON u.id = r.user_id WHERE u.email='YOUR_EMAIL';`) or your session lost the role. Log in again.

### `make tunnel` says "cloudflared is not installed"

`cloudflared` is the Cloudflare tunnel client. Install it from
<https://developers.cloudflare.com/cloudflare-one/connections/connect-networks/downloads/>
(`brew install cloudflared` on macOS, `apt install cloudflared` on
Debian/Ubuntu). The Makefile will not start the tunnel until it
finds the binary on your `$PATH`.

### `make tunnel-url` says "Tunnel is NOT running"

The tunnel was killed without `make tunnel-stop` (crash, reboot, or
`kill` from another terminal) and `cloudflared.log` still has the
old URL. The target deliberately refuses to print a dead URL.
Start a fresh tunnel with `make tunnel`.

### I can reach the app on `http://localhost:8080` but the tunnel URL returns 530 / connection refused

`cloudflared` is running but the local web app isn't reachable on
`localhost:8080`. Check `docker compose ps` — both `profile_db` and
`profile_web` must be `Up`/`healthy`. If `profile_web` is restarting,
look at `docker compose logs web` for the cause (usually a missing
`login_attempts` table after a partial restore — see the next entry).

### `login_attempts` table doesn't exist

You restored a backup taken before migration 018 (rate limiting).
The rate-limiter code is safe to run on a DB without the table — it
fails open — but the lockout will never trigger. Re-apply the
migration:

```bash
docker compose exec -T db mariadb -uroot -p"$DB_PASSWORD" profile_system \
  < database/migrations/018_login_attempts.sql
docker compose exec -T db mariadb -uroot -p"$DB_PASSWORD" profile_system \
  -e "INSERT IGNORE INTO schema_migrations (filename) VALUES ('018_login_attempts.sql');"
```

### Reset everything (DESTRUCTIVE)

```bash
docker compose down -v   # removes the named volumes (db + uploads)
docker compose up -d --build
```

This wipes the database and uploaded photos. Use only when you want a clean slate.

---

## Security notes

This is a **learning / starter project**. It's been written with reasonable care, but it has not been audited. Before exposing it to the public internet, address the following:

### Known acceptable-for-dev choices

- **DB password in `.env`.** `src/includes/db.php` reads `DB_PASSWORD` from the environment with a default of `rootpass`. The default lives in `.env.example`; override it in your local `.env` (which is gitignored) for anything beyond local dev. Never commit a real `.env` to version control.
- **Password reset "email" is shown on screen** *only when `APP_ENV=development`.* In `APP_ENV=production` (the default for any tunnel exposure) the raw token is never returned to the browser. The reset link is generated and stored in the DB but never emailed because there is no SMTP. Wire up a real mailer (PHPMailer + SMTP, or a transactional email service) before going public.
- **Rate limiting is in place but minimal.** 5 failed logins per 15 minutes per email, 20 per 10 minutes per IP, 5/hour per IP for register and forgot-password. See [`src/includes/ratelimit.php`](src/includes/ratelimit.php). Tighten these further before any real public exposure.
- **No 2FA.** Single-factor auth only (the schema has `users.totp_required` and a per-user TOTP flow, but it is opt-in, not required).
- **No CSRF token rotation.** Tokens are stable per session. This is fine for normal use, but consider rotating on privilege change.
- **No file upload virus scanning.** Photo uploads are validated by MIME type and `getimagesize()`, but the file is not scanned for malicious content. Don't accept uploads from untrusted users without further hardening.
- **Sessions stored on the filesystem.** If you scale to multiple app servers, switch to a shared session backend (Redis, database).
- **HTTP allowed.** Cookies aren't marked `Secure` when the site is on plain HTTP. Force HTTPS and the `secure` cookie flag will kick in automatically.

### Things we did right

- `password_hash()` with `PASSWORD_DEFAULT` (currently bcrypt, cost 10)
- `password_verify()` for logins
- `session_regenerate_id(true)` on successful login
- `httponly` + `samesite=Lax` session cookies
- All SQL uses prepared statements with `EMULATE_PREPARES = false`
- All output goes through `e()` (htmlspecialchars with `ENT_QUOTES`)
- CSRF tokens on every state-changing form
- Photo uploads: real MIME check via `finfo`, 2 MB cap, randomized filenames, `is_uploaded_file()` guard, `.htaccess` denies PHP execution in `uploads/`
- `caste_digit` validation on numeric URL params (e.g. `?id=2`)
- User-input reflected only after re-fetching from the DB (so even a stale admin promotion can't fake fields)
- Self-demote / self-delete guards on admin actions
- Rate limiting (5 fails / 15 min per email, 20 / 10 min per IP for login; lighter caps on register and forgot-password) with a dedicated `login_attempts` table
- Secure session cookie forced on behind a Cloudflare tunnel via `TRUST_PROXY=1` + `X-Forwarded-Proto`

### What "do not publish publicly" means in practice

If you put this on the internet tomorrow, an attacker could:

- Brute-force passwords are slowed by the rate limiter (5 fails / 15 min per email, 20 / 10 min per IP) but still possible against a weak password.
- Reset anyone's password by knowing their email — the link is generated and stored in the DB, but never emailed (and never shown on screen when `APP_ENV=production`). Anyone with DB access could read it.
- Upload a 1.99 MB polyglot file that gets served to other users (no content scanning).
- ...

**Don't put this on the public internet as-is.** Run it locally, on a LAN, behind a VPN, or harden it first.

---

## What was redesigned

The app started as a XAMPP-style starter project with these files: `index.php`, `login.php`, `register.php`, `logout.php`, `dashboard.php`, `profile.php`, plus a `style.css`. It had a basic admin panel added later.

The redesign replaced almost everything:

| Area                              | Before                                           | After                                                                                                                                        |
| --------------------------------- | ------------------------------------------------ | -------------------------------------------------------------------------------------------------------------------------------------------- |
| CSS                               | One small`style.css` with a blue theme         | Full design system (`app.css`, ~700 lines): Linear/Stripe-inspired neutral palette, single accent, refined shadows, full component library |
| JavaScript                        | None                                             | `app.js` with toasts, modals, photo preview, password toggle, mobile menu, character counters, dirty-form guard                            |
| Auth pages                        | Single shared`style.css`, no inline errors     | New layout, inline field errors with`aria-invalid`, password show/hide, forgot-password link                                               |
| Dashboard                         | Welcome card + 3 buttons + read-only list        | Welcome + completion progress bar + recent activity feed + role/username/joined stats + admin tools section                                  |
| Profile (private)                 | Read-only DL                                     | Avatar header + copy-link button + same DL with per-field "add one" prompts                                                                  |
| Edit profile                      | Stacked errors at top, no preview                | Photo preview with drag/drop, character counters, remove-photo, inline errors, deletes old photo file on replace                             |
| **Public profile**          | Did not exist                                    | New`/u/<username>` page (URL rewrite in `.htaccess`)                                                                                     |
| **Settings**                | Did not exist                                    | New`settings.php` with Account / Security / Danger tabs                                                                                    |
| **Forgot / reset password** | Did not exist                                    | Token-based flow using a new`password_resets` table                                                                                        |
| Admin                             | User list with no actions; detail page read-only | Search/filter/sort, mobile card view, single-user page with role change + delete + photo cleanup                                             |
| Topbar                            | Inconsistent (logout in different places)        | Shared partial (`src/includes/header.php`), mobile hamburger, `aria-current` highlighting                                                |
| Errors                            | Generic PHP errors on the page                   | `aria-invalid` + inline `field-error` per field; `<dialog>` confirm for destructive actions                                            |
| DB schema                         | `users` + `profiles`                         | +`users.username` (with backfill) + `password_resets` + `activity`                                                                     |
| Photo upload                      | Stacked errors, no preview                       | Live preview, drag/drop, type/size validation, randomized filename, old file cleanup on replace                                              |

The original files (`database/schema.sql`, `Dockerfile`, `logout.php`, `public/uploads/profiles/.htaccess`) are kept as-is. The old `style.css` was deleted; the new design system is in `app.css`.

---

## Project files

- **[CHANGELOG.md](CHANGELOG.md)** — release notes, one entry per notable change
- **[CONTRIBUTING.md](CONTRIBUTING.md)** — dev workflow, code style, how to submit changes
- **[docs/DIRECTORY.md](docs/DIRECTORY.md)** — file-by-file deep-dive, every PHP / SQL / CSS / JS file explained
- **[docs/TUNNEL.md](docs/TUNNEL.md)** — exposing the app via a Cloudflare quick tunnel (the `make tunnel` workflow)
- **[README.txt](README.txt)** — short text version, kept for the original XAMPP-style quick start

### Public profile privacy

Everything in `profiles` and all academic sections is **public by default** — anyone (logged in or not) can view it at `/u/<username>`. There is no "hide this section" toggle. If you need fine-grained privacy controls, the natural place to add them is a per-section `is_public BOOLEAN` column plus a filter in `render_sections.php` and `u/index.php`.

### What gets logged

The `activity` table records: register, login, profile_update, password_change, password_reset, account_update, role_change, degree_change, account_delete. A user sees their own last 5 events on the dashboard. Admins do not (yet) have a global feed.

---

## License

This is a learning project — no license is granted by default. Use it however you like; if you ship it, do your own security review first.
