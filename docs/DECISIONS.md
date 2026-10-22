# Decisions

Why Profile System is built the way it is: the stack, the infrastructure,
the data model, security, and every feature. Each entry says what was
decided, what else was on the table, why, and what it costs.

Read this before proposing a large change. If you overturn a decision,
add a new entry that says so and why, rather than rewriting the old one.

**Sources.** The reasons come from what was written down when each
decision was made: [`CHANGELOG.md`](../CHANGELOG.md), the other docs
(especially [`DESIGN.md`](DESIGN.md) §2 and [`SECURITY.md`](SECURITY.md)),
and the comments in the code. Where no reason was recorded and the
entry reconstructs one, it is marked *(inferred)*.

## Contents

- [How decisions are made here](#how-decisions-are-made-here)
- [How the project got here](#how-the-project-got-here)
- [A. Stack](#a-stack) — A1–A10
- [B. Running and shipping](#b-running-and-shipping) — B1–B9
- [C. Data](#c-data) — C1–C8
- [D. Accounts and security](#d-accounts-and-security) — D1–D16
- [E. Features](#e-features) — E1–E15
- [F. Interface](#f-interface) — F1–F9
- [G. Process](#g-process) — G1–G5

## How decisions are made here

The same handful of rules decided almost every entry below:

1. **Follow the lab's real records.** The fields, column order, header
   text and choices (course codes, April/October intakes) come from the
   lab's *Information of Lab Students* workbooks. When the app and the
   workbook disagree, the workbook wins (E2, E3).
2. **Don't put working, hardened code at risk for no visible gain.** The
   back end was kept when the front end was rebuilt (A1).
3. **Fewest moving parts that do the job.** One container per service, no
   framework, one PHP dependency, no Node at run time, no cron (A1, A7,
   B4, D13).
4. **Only Docker on the host.** Anyone with Docker can run, build, back up
   and lint it without installing a language toolchain (B1, B3).
5. **One definition per concept.** Lab fields, entry sections, URLs,
   permissions and design tokens each live in one place that everything
   else reads (C3, C4, F2).
6. **Each role sees only its own work** (E1, E5).
7. **Measure, then decide.** Library choices were made by installing and
   reading the candidates (bundle size, what they ship); layout was
   checked at nine screen sizes; security by black-box and white-box
   audits (A5, F8, G3).
8. **Private by default.** It holds students' personal data, so nothing
   is public, no third-party requests are made, and data is deleted when
   promised (C7, D15, E14).

## How the project got here

| When | Step | Main decisions |
| --- | --- | --- |
| before Oct 2025 | XAMPP-style starter: a few PHP pages, two tables, hard-coded DB credentials | – |
| 11 Oct 2025 | Docker rework, academic profile with nine sections, public profiles at `/u/<username>`, admin panel | B1, B2, C2 |
| up to 13 Oct 2026 | Hardening for public exposure: Cloudflare tunnel, rate limiting, least-privilege DB user | B6, D6, D8 |
| 13 Oct 2026 | UI rebuilt on Tailwind v4 + Alpine.js + Preline tokens; back end unchanged | A1, A4–A6 |
| Oct 2026 | Profile cut down to the lab workbook's columns; usernames and public profiles removed; course history | E2, E3, E4, D2 |
| 17 Oct 2026 | Entries, Resources, completed courses | C4, E6–E9 |
| 20 Oct 2026 | Email, About/Help/Privacy and error pages, admin pages, two security audits, responsive pass | A7, D9–D16, E10–E15, F8 |

The full detail is in the changelog.

---

## A. Stack

### A1. Vanilla PHP, no framework

- **Decision:** plain PHP 8.3, one file per page, shared code in
  `src/includes/`. No Laravel, Symfony or micro-framework.
- **Considered:** Laravel, at the time of the UI redesign.
- **Why:** the back end already worked and was hardened (CSRF, rate
  limiting, 2FA, invitations, soft delete). A framework rewrite would put
  all of that at risk for no visible gain; the redesign was a front-end
  change (DESIGN.md §2). The app is small (≈ 25 pages), has three roles
  and no API; a framework's router, ORM and container would add more
  code than they remove. A file per page also maps directly to a URL,
  which keeps it easy to read for a lab where maintainers change.
- **Costs:** the conventions a framework would enforce are manual:
  every POST handler calls `verify_csrf()`, every output goes through
  `e()`, every query is a prepared statement. CONTRIBUTING.md lists
  them, and the security audits checked them.
- **Where:** `public/*.php`, `src/includes/`.

### A2. PHP 8.3

- **Decision:** PHP 8.3, `declare(strict_types=1)` in every file.
- **Why:** the newest supported PHP line when the image was built, and
  the official `php:8.3-apache` image exists for amd64 and arm64.
  Strict types catch wrong-type bugs at the call instead of later.
  *(inferred, except strict types: CONTRIBUTING.md)*

### A3. MariaDB 11.4

- **Decision:** MariaDB, pinned to the 11.4 LTS image.
- **Considered:** MySQL, PostgreSQL, SQLite.
- **Why:** the project started as a XAMPP starter, which ships MariaDB,
  so the SQL and `pdo_mysql` code carried over unchanged. The official
  image includes `healthcheck.sh`, which `compose.yml` uses to start the
  web container only once the database is really ready. `GET_LOCK` gives
  the named locks the rate limiter needs (D7). 11.4 is a long-term
  support release; the tag is pinned so a rebuild never jumps a major
  version (B5). SQLite was not a fit for concurrent writes from Apache
  workers and named locks. *(inferred, except pinning and GET_LOCK)*

### A4. Apache with mod_php

- **Decision:** the official `php:8.3-apache` image: Apache runs PHP
  in-process.
- **Considered:** nginx + PHP-FPM.
- **Why:** one process in one container, no FastCGI wiring, and Apache's
  `ErrorDocument`, `Header` and `<Directory>` rules cover the security
  headers, error pages and the "no code in uploads" rule in one config
  file. nginx + FPM would mean two processes or two containers for no
  benefit at this traffic. *(inferred)*
- **Note:** `.htaccess` files are **not** read (`AllowOverride None`);
  all rules are in `docker/apache/app.conf`, found and fixed in the first
  audit.

### A5. Tailwind CSS v4 with named component classes

- **Decision:** Tailwind CSS v4 (CLI build, no JS config), with anything
  that repeats as a named class (`.btn`, `.input`, `.card`) built with
  `@apply`.
- **Considered:** the hand-written stylesheet it replaced; utility-only
  Tailwind.
- **Why:** utilities for layout, named classes for repeated components,
  because templates are plain PHP with no component system: the same
  fifteen-utility button would otherwise be copied across 18 PHP files
  and one JS file and drift. v4 keeps all config in CSS.
- **Where:** `resources/css/`, [`COMPONENTS.md`](COMPONENTS.md).

### A6. Alpine.js for behaviour

- **Decision:** Alpine.js 3, core only.
- **Considered:** Preline's JS, Flowbite's JS, vanilla JS, a SPA
  framework.
- **Why:** it fits server-rendered pages: state lives in the markup it
  affects. Preline's and Flowbite's scripts do the same jobs (menus,
  dialogs, tabs), and two behaviour layers would mean two ways to open a
  menu. Measured: Preline's bundle ≈ 430 KB; ours, with Alpine, ≈ 77 KB.
  A SPA would need an API the app does not have.
- **Costs:** Alpine's standard build evaluates expressions at run time, so
  the Content-Security-Policy still allows `'unsafe-eval'`. Moving to
  `@alpinejs/csp` would remove that (SECURITY.md §4).

### A7. Preline UI as a token system only

- **Decision:** use Preline 5's `theme.css` semantic tokens
  (`bg-layer`, `text-foreground`, `bg-primary`, …), re-pointed to our own
  colours; don't load Preline's JavaScript.
- **Considered:** Flowbite.
- **Why:** both were installed and read (`preline@5.0.0`,
  `flowbite@4.0.2`). Preline's tokens carry light and dark values, so
  re-pointing them re-themes everything and dark mode costs nothing per
  component. Flowbite's tokens are narrower and its defaults (blue,
  Inter) are the look being avoided (F1).

### A8. esbuild for JavaScript

- **Decision:** esbuild bundles `resources/js/app.js` into one minified
  file.
- **Why:** one fast binary, no config file, does exactly bundling and
  minifying. Vite or webpack would add a dev server and config for a
  three-file bundle. *(inferred)*

### A9. Self-hosted fonts: Source Serif 4 and IBM Plex Sans

- **Decision:** two font families from `@fontsource`, copied into
  `public/assets/fonts/`.
- **Why:** no third-party requests from a private site (Google Fonts
  would tell Google who opens it), and no layout shift from a slow CDN.
  A serif for titles and a plain sans for text, instead of Inter
  everywhere (F1). Japanese names fall back to the system CJK font.

### A10. PHPMailer as the only PHP dependency

- **Decision:** send email over SMTP with PHPMailer 6.10.0, installed by
  Composer into `src/vendor/` and **committed**.
- **Considered:** PHP's `mail()`; a provider's HTTP API; Symfony Mailer.
- **Why:** `mail()` needs a local MTA in the container and gives no error
  detail. A provider API ties the lab to one company; SMTP works with the
  university's server or any provider. PHPMailer is a single,
  dependency-free, long-maintained library. Committing `vendor/` keeps
  the "nothing to install" rule (B3).

---

## B. Running and shipping

### B1. Docker Compose for everything

- **Decision:** the app runs as containers defined in `compose.yml`; the
  host needs only Docker (and optionally make).
- **Considered:** XAMPP / a local PHP install (the original starter); a
  plain server install.
- **Why:** the XAMPP starter only ran where XAMPP was installed and set
  up the same way, with hard-coded credentials. Containers give the same
  PHP version, extensions, Apache config and database on Windows, macOS
  and Linux, on Intel and ARM, and the same stack in development and
  production. See [`INSTALL.md`](INSTALL.md).

### B2. The database is migrated by the web container on start

- **Decision:** `docker/web-entrypoint.sh` applies `schema.sql` and any
  unapplied migration every time the web container starts.
- **Considered:** running migrations by hand; MariaDB's
  `docker-entrypoint-initdb.d` (only runs on an empty volume).
- **Why:** a fresh `make up` gives a working database with no manual SQL,
  and after `git pull` a restart is all an update needs. `initdb.d` would
  never apply later migrations. The script is idempotent: re-running is
  a no-op.

### B3. Built assets and vendor code are committed

- **Decision:** `public/assets/` (CSS, JS, fonts) and `src/vendor/` are
  in Git.
- **Why:** running the app needs no Node and no Composer, on any
  machine, and the image needs no build stage. Only people who change
  `resources/` rebuild, and they do it in a throwaway Node container
  (`make assets`).
- **Costs:** a change to `resources/` and its build output must be
  committed together. `.dockerignore` keeps `resources/` and `node_modules`
  out of the image.

### B4. Make as the single entry point

- **Decision:** a `Makefile` whose targets only call `docker compose` (or
  a one-off container).
- **Why:** one memorable command per job (`make up`, `make backup`,
  `make restore`, `make lint`, `make assets`) with safety prompts on
  destructive ones, and `make help` as the list. Every action runs in a
  container, so `make lint` needs no host PHP and `make backup` no host
  MariaDB client.
- **Costs:** Windows has no `make` by default: use WSL, or the plain
  commands in INSTALL.md §9.

### B5. Pinned image versions

- **Decision:** `mariadb:11.4`, `adminer:4.8.1`, `axllent/mailpit:v1.27.10`,
  and exact npm/Composer versions; never `:latest`.
- **Why:** a rebuild months later must give the same thing. A floating
  tag can bring a major-version database upgrade on an unrelated
  restart.

### B6. Dev tools behind a Compose profile, on localhost only

- **Decision:** Adminer and Mailpit are on the `tools` profile (not
  started by `make up`) and bind to `127.0.0.1`.
- **Why:** Adminer has full database root access; it must never be
  reachable from the network or the tunnel. The first audit moved both
  to localhost.

### B7. Read-only code at run time

- **Decision:** in development, `public/`, `src/` and `database/` are
  bind-mounted **read-only**; in the image the code is owned by root.
  Only `uploads/` is writable by Apache.
- **Why:** a bug that lets someone write a file cannot plant a script in
  the code, on the host or in the image (second audit).

### B8. Cloudflare quick tunnel for sharing

- **Decision:** `make tunnel` publishes the local app at a random
  `https://….trycloudflare.com` address.
- **Considered:** port forwarding; ngrok; a hosted server.
- **Why:** HTTPS with no account, no router changes and no open port on
  the machine; good enough to let the lab try it. It forced the
  proxy-aware parts of the app (`TRUST_PROXY`, real client IP, `Secure`
  cookies behind a proxy). A named tunnel or a server is the route to
  permanence ([`TUNNEL.md`](TUNNEL.md)).

### B9. Settings in `.env`, never in the code

- **Decision:** every secret and environment switch is an environment
  variable, documented in `.env.example`; `.env` is git- and
  docker-ignored.
- **Why:** the original starter had hard-coded credentials. One file per
  installation, same code everywhere.

---

## C. Data

### C1. Numbered SQL migration files

- **Decision:** `database/migrations/NNN_name.sql`, applied in name
  order, recorded in `schema_migrations`; never edited after they have
  run.
- **Why:** plain SQL is readable without a tool and runs from the shell
  script; the number gives the order. Each file is written to be safe to
  re-run (`IF NOT EXISTS`) where it can be.

### C2. No ORM, prepared statements only

- **Decision:** PDO with `ATTR_EMULATE_PREPARES = false`; queries are
  written by hand.
- **Why:** with real prepared statements user input never becomes SQL.
  The queries are few and specific (the Students sheet, charts); an ORM
  would hide them without saving code.

### C3. The lab fields are defined once

- **Decision:** `lab_field_defs()` in `src/includes/lab_fields.php` lists
  every lab field: label, workbook header, type, choices, and whether the
  owner may edit it (`owner_edit`).
- **Why:** the profile page, the inline editor, the server-side
  permission check, the admin form, the Students sheet and the CSV
  export all read the same list, so adding or locking a field is one
  change ([`PERMISSIONS.md`](PERMISSIONS.md), last section).

### C4. Repeating items are entries, rebuilt into workbook text

- **Decision:** theses, journal papers, conference papers, publications
  and awards are rows (`lab_entries`, `lab_publications`, `lab_awards`),
  defined once in `lab_entry_defs()`. The sheet's text columns are
  rebuilt from them in the workbook's format (`[Level: Title]`,
  `{published}(submitted)`).
- **Considered:** free-text cells, as in the workbook.
- **Why:** students add, edit and delete one item at a time instead of
  editing a formatted blob, while teachers still read the format they
  know. Table and column names come from the definitions, never from
  the request.

### C5. One course at a time, with a frozen copy on completion

- **Decision:** `course_enrollments` keeps every course; the newest row
  is current. Marking it completed stores a JSON snapshot of the
  student's details; the next course can only be added after that.
- **Why:** a student moves M → D in the lab, and the teachers need both
  a current list and a record of who completed what, as it was then.
  The snapshot makes the Completed tab stable even when the student
  later edits their details. Undo is allowed only while the course is
  still current, so history can't be rewritten.

### C6. `utf8mb4` everywhere

- **Decision:** database, tables and connection in `utf8mb4`.
- **Why:** full names in Japanese are a lab field; MySQL's old `utf8` is
  3-byte and loses some characters.

### C7. Moments stored as `TIMESTAMP`, shown in one site time zone

- **Decision:** migration `036` turned eleven `DATETIME` columns into
  `TIMESTAMP`; an admin picks the site's zone, which PHP and every
  database connection use.
- **Why:** `TIMESTAMP` is UTC inside, so changing the zone never moves an
  expiry. One zone for everyone (the lab is in one place) keeps "today",
  charts and expiry checks in agreement between PHP and SQL.

### C8. Soft delete for accounts

- **Decision:** deactivating an account sets a deletion date 30 days
  ahead; the person can restore it by an emailed link until then;
  housekeeping deletes it afterwards, with everything it owns
  (foreign keys cascade).
- **Why:** a mistaken deactivation is recoverable, and "deleted means
  deleted" is still true after 30 days, as Settings and Privacy promise.

---

## D. Accounts and security

The full audit write-ups are in [`SECURITY.md`](SECURITY.md); this is
the why of each protection.

### D1. Invitation-only registration; the first account is admin

- **Decision:** `/register.php` works only with an invitation link,
  except while the database has no users, when the first account becomes
  the admin (checked again inside the insert transaction).
- **Why:** it's a private register of real people; nobody should be able
  to sign up from the internet. The first-run rule removes the need for a
  seed script or default password. Teachers may invite students only;
  admins any role (`invite_grantable_roles()`).

### D2. The email is the identity; no usernames

- **Decision:** one email per account, which is also the workbook's
  "University email"; usernames were removed (migration `020`).
- **Why:** usernames existed only for public profile URLs, which were
  removed (E4). Two addresses (sign-in and university) were the same in
  practice and drifted. People are identified by name, email and
  student ID.

### D3. Several roles per account, one acting role at a time

- **Decision:** `user_roles` holds any of student, teacher, admin; the
  user picks which one they are acting as (at sign-in and in the top
  bar), and permissions follow the acting role.
- **Why:** real people overlap: a teacher who administers, a student who
  assists. Acting as one role at a time keeps each screen focused on that
  job (E1) and makes "what may I do now" one value to check.

### D4. Passwords

- **Decision:** `password_hash()` with PHP's default algorithm; users
  reset their own password by an emailed one-time link (hashed token).
  An admin can email someone a reset link, or, where there is no email,
  set a new password on the user's page; either ends that user's other
  sessions (D9) and is logged.
- **Why:** standard hashing that upgrades itself when PHP's default
  changes. The admin route exists because without a mail server there is
  no other way back into a locked account.

### D5. Two-factor sign-in with TOTP and hashed backup codes

- **Decision:** optional TOTP (RFC 6238, 32-character base32 keys) with
  single-use backup codes stored hashed; 5 wrong codes per 10 minutes;
  admins can reset someone's 2FA.
- **Why:** authenticator apps work offline and need no SMS provider.
  Backup codes are hashed so a database leak doesn't hand them out. Keys
  are now proper base32 of 20 random bytes because Google Authenticator
  rejected the earlier 20-character keys.

### D6. Rate limiting in the database

- **Decision:** failed attempts in `login_attempts`, by email, account
  and IP: sign-in 5 per 15 min per email and 20 per 10 min per IP,
  registration and reset per IP, 3 reset emails per address per hour.
- **Why:** stops password guessing and email flooding. A database table
  works across Apache workers without adding Redis.

### D7. Rate-limit checks serialised with `GET_LOCK`

- **Decision:** `rate_limit_lock()` takes a MariaDB named lock per bucket
  before checking.
- **Why:** the second audit showed that many requests sent at once all
  passed the "5 tries" check before any failure was recorded.

### D8. Proxy-aware client IP and HTTPS detection

- **Decision:** with `TRUST_PROXY=1`, the IP comes from
  `CF-Connecting-IP`, else the rightmost `X-Forwarded-For` entry; HTTPS
  from `X-Forwarded-Proto`. `WEB_BIND=127.0.0.1` makes the proxy the only
  way in.
- **Why:** behind the tunnel every request comes from the proxy; per-IP
  limits would otherwise be global, or dodgeable by writing your own
  header (first audit).

### D9. Sessions: hardened cookie, idle and absolute limits, epoch

- **Decision:** `HttpOnly`, `SameSite=Lax`, `Secure` and the `__Host-`
  prefix over HTTPS; end after 2 h idle or 7 days; `users.session_epoch`
  ends all other sessions on a password change, reset or 2FA reset;
  roles and account status re-read on every request.
- **Why:** a stolen or forgotten session has a short life; a revoked role
  or deleted account stops working at once; `__Host-` prevents a sibling
  site under the same parent domain (another tunnel) planting a cookie.

### D10. CSRF tokens on every state change

- **Decision:** every POST carries `csrf_token` and the handler calls
  `verify_csrf()` first; logout is POST-only; an expired form gets 403
  (not 419, which Apache turned into 500).
- **Why:** cookies are sent with cross-site requests in some cases;
  `SameSite=Lax` alone isn't the whole defence.

### D11. Same answer and timing for unknown emails

- **Decision:** sign-in and reset take the same time and say the same
  thing whether the email exists or not.
- **Why:** no account enumeration (first audit).

### D12. Least-privilege database user

- **Decision:** with `APP_DB_USER` set, the app connects as a user with
  `SELECT, INSERT, UPDATE, DELETE` only; migrations run as root in the
  entrypoint, which then removes root's password from Apache's
  environment.
- **Why:** SQL injection or a code bug can't drop tables or read other
  databases, and PHP can't read the root password.

### D13. Security headers in Apache

- **Decision:** CSP (only this site's scripts, styles, fonts, images; no
  framing), HSTS on HTTPS, `Referrer-Policy: same-origin`, `nosniff`,
  `X-Frame-Options: DENY`, `Permissions-Policy`; no server version.
- **Why:** reset, invitation and restore links carry tokens in the URL,
  so the referrer must never leave the site; the rest is standard
  defence in depth. CSP still allows `unsafe-inline`/`unsafe-eval` for
  Alpine (A6).

### D14. Errors logged, never shown

- **Decision:** `display_errors = Off`; errors go to the container log;
  visitors get the app's error page for every status.
- **Why:** stack traces showed paths and queries to visitors, and weren't
  logged (fixed with the error pages).

### D15. Retention without cron

- **Decision:** `housekeeping_maybe_run()` runs at most every 6 hours
  from an ordinary page load; `make purge` runs it now.
- **Considered:** cron in the container; a separate scheduler container.
- **Why:** a promise like "deleted after 30 days" must hold even where
  nobody set up cron. A lab site gets page loads daily; a missed window
  just runs on the next one.

### D16. Personal data stays out of Git and images

- **Decision:** `.gitignore` and `.dockerignore` exclude `.env`,
  `backups/`, spreadsheets (`*.xlsx`, `*.csv`), test scripts with
  credentials and tunnel logs; the student workbooks were removed from
  the repository.
- **Why:** an image or repository can be shared; personal data and
  passwords must never travel with it. The workbooks remain in Git
  history; SECURITY.md §4 says how to purge them.

---

## E. Features

### E1. Each role sees only its own work

- **Decision:** students get My profile and Resources; teachers get the
  Students sheet, Resources and Invitations; admins get Users,
  Invitations, Emails, Activity and Site settings. Admin is **account
  management only**: no Students sheet, no Resources (an admin who
  teaches switches to Teacher).
- **Why:** each screen is shorter and the permission model is simpler;
  teachers and admins have no lab details, so showing them empty ones
  was noise.

### E2. The profile is exactly the workbook's columns

- **Decision:** the nine academic sections, phone, address, bio, photo and
  degree type were removed (migration `019`). The profile is the
  *Information of Lab Students* columns.
- **Why:** that sheet is what the lab actually keeps. Everything else was
  unused data to look after and protect.

### E3. The Students sheet looks like the workbook

- **Decision:** same header text and column order, rows grouped by
  course, frozen first columns, Current and Completed tabs, **Download
  CSV** with the workbook's headers (UTF-8 with BOM for Excel, formula
  characters neutralised).
- **Why:** teachers already think in that sheet; the app replaces passing
  spreadsheets around, it shouldn't make them learn a new layout. CSV
  injection protection because the export opens in Excel.

### E4. No public profiles

- **Decision:** `/u/<username>` was removed (migration `014`).
- **Why:** the register is private; students' details are for the lab.

### E5. Students edit their own details in place

- **Decision:** each field saves on its own (`op=update_lab`, JSON), with
  the server checking `owner_edit` and binding to the session's user id.
- **Why:** inline editing, one thing at a time, is quicker than a long
  form for something updated a few times a term; the server, not the
  page, decides what may be written.

### E6. Enrolment year and month from fixed choices

- **Decision:** year 2022 to next year; month April, October or Other;
  pre-filled with the invitation (or course-added) date and editable by
  the student.
- **Why:** those are the lab's intakes; a date picker allowed nonsense.
  Pre-filling makes most students' entries correct without effort.

### E7. Resources: one shared page for the lab

- **Decision:** `/resources.php` has a tab per workbook sheet: Templates
  and Open data and code (teachers edit), Potential journals (everyone
  edits one shared list), and read-only views of everyone's
  publications and awards.
- **Why:** these were sheets in the lab's second workbook; one page
  replaces it. The journals list became shared (migration `031`) because
  journals are suggested for the whole lab, not owned by one student.

### E8. Invitations by link

- **Decision:** an invitation is a link with a hashed token, roles, a
  use limit and an expiry; when made for one address it is also emailed;
  the link is always shown to copy.
- **Why:** works with or without email set up; hashed so a database leak
  doesn't hand out working links.

### E9. Email confirmation for a new sign-in address

- **Decision:** when email is set up, a self-service email change waits
  for a link sent to the new address (24 h), then tells the old one; an
  admin's change applies directly and tells the old address; a change
  ends open reset links.
- **Why:** the sign-in address is the identity (D2); a typo or a
  hijacked session must not be able to move it silently.

### E10. Email that is optional and auditable

- **Decision:** email is off until `MAIL_HOST` is set; links use
  `APP_URL` only in production; every send is logged in `email_log` (with
  token links stored as `[link hidden]`); each kind of email has an
  on/off switch; admins can write to people with limits (100 per send,
  200 per hour).
- **Why:** the app must work in development and in a lab without a mail
  server. A forged `Host` header must never turn a reset email into a
  link elsewhere. The log answers "did my email go out?" without
  exposing working tokens.

### E11. Activity log with retention the admin controls

- **Decision:** every meaningful action is logged with IP, browser and
  acting role; admins see timelines, people and charts; the admin picks
  how long events and IP/browser data are kept.
- **Why:** accountability for a system of personal data, and the answer
  to "who changed this?". Retention is configurable because the right
  period is the lab's policy, not the code's. The Privacy page states
  the configured periods.

### E12. Admin dashboard: "Needs attention"

- **Decision:** health numbers and a list of things to act on (failed
  sign-ins and emails, admins without 2FA, email off, accounts about to
  be deleted, invitations expiring, time zone not set).
- **Why:** admins open it rarely; it should say what needs doing rather
  than show stat tiles (F1).

### E13. Command palette for admins

- **Decision:** Ctrl/⌘ + K jumps to any admin page or person.
- **Why:** finding one user among many from any page in two keystrokes.

### E14. About, Help and Privacy pages

- **Decision:** footer links on every page; Privacy says what is stored,
  who sees it and for how long, from the live settings.
- **Why:** people whose data is held should be able to read what
  happens to it; Help answers the questions the lab kept asking.

### E15. Error pages for every status

- **Decision:** one design for 400–503 with the right next step;
  database-down has a standalone version that needs neither session nor
  database.
- **Why:** plain-text errors and blank pages left people stuck, and the
  old pages leaked details (D14).

---

## F. Interface

Full rules in [`DESIGN.md`](DESIGN.md).

### F1. A quiet, document-like look, avoiding "template" patterns

- **Decision:** warm paper neutrals, one ink-blue accent, serif titles,
  hairline rules, small radii; no purple gradients, centred hero, stat
  tiles, pill buttons, emoji icons or fade-up animations.
- **Why:** it's a records tool people open a few times a term; those
  patterns read as machine-made in 2026 (sources listed in DESIGN.md §3).

### F2. Semantic tokens only, light and dark

- **Decision:** templates use tokens (`bg-layer`, `text-foreground`),
  never raw palette colours; theme is light, dark or auto per user.
- **Why:** dark mode keeps working without per-component work; text meets
  WCAG AA contrast in both.

### F3. Clear separation of regions

- **Decision:** solid ink-blue top bar, white panels on grey, tinted
  table headers and tool strips, 3:1 control borders.
- **Why:** asked for by the client (October 2026): navigation, sections,
  tables, buttons and tools must each be clearly separable.

### F4. One entry point per destination

- **Decision:** a page never has two controls going to the same place;
  the top bar owns page destinations, the account menu owns Settings and
  Log out.
- **Why:** duplicate buttons made people wonder whether they did
  different things.

### F5. One primary button per view; undo over confirm

- **Decision:** one primary action; small recoverable deletions get undo;
  the confirm dialog is for the irreversible and opens on Cancel.
- **Why:** fewer decisions per screen; confirm dialogs that appear for
  everything get clicked through.

### F6. Forms after GOV.UK patterns

- **Decision:** label above, hint below, error next to its field and
  announced, focus to the first error.
- **Why:** proven accessible patterns; works for keyboard and screen
  readers.

### F7. Server-rendered SVG charts

- **Decision:** charts on admin pages are SVG made in PHP
  (`src/includes/charts.php`), each with a table view; colours checked
  for colour-blind safety.
- **Why:** no chart library to load or keep up to date, nothing loaded
  from another site (the CSP allows only this one), and the charts render
  even before any JavaScript runs.

### F8. Works from 320 px, never scrolls sideways

- **Decision:** every page checked for each role at nine sizes (320 to
  1920 px, landscape, touch); tables become cards on phones, sheets
  scroll inside their frame; tap targets ≥ 24 px.
- **Why:** students open it on phones; the audit found the sheet
  scrolling the whole page by 2,500 px. `tools/responsive-audit.js`
  repeats the check.

### F9. The public home page shows invented data only

- **Decision:** the sample profile on `/` is made up; no names, counts,
  lab name or addresses.
- **Why:** the home page is reachable without signing in.

---

## G. Process

### G1. Changelog with reasons

Every change is described in `CHANGELOG.md` with what it fixes and why,
so later decisions can be traced (this document is built from it).

### G2. Manual testing, documented

There is no automated test suite yet. Changes are checked by running the
stack, `make lint`, clicking through the affected pages for each role, and
for layout `tools/responsive-audit.js`. CONTRIBUTING.md has the checklist.
An automated suite is the obvious next investment.

### G3. Security by audit

Two audits (October 2026), black-box against the running app and
white-box through the code, each written up with what was checked, what
was found and fixed, and the known limits (SECURITY.md).

### G4. Docs live next to the code

Each topic has one document in `docs/`, linked from the README; page
files carry a header comment with route, access and inputs.

### G5. Schema changes only by new migration

Never edit an applied migration; add the next number. Destructive
migrations (`019`, `020`) are called out as such in the changelog.
