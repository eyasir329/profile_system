# Deploying to shared PHP hosting (production)

The live site, **<https://profile-system.kesug.com/>**, runs on free shared
hosting (InfinityFree): Apache + PHP + MariaDB, FTP or a file manager, no
Docker and no shell. This page explains how that build is made from this
repository, how to update it, and what differs from the Docker setup.

For running the app on your own computer, see [`INSTALL.md`](INSTALL.md).

## Contents

1. [What differs from Docker](#1-what-differs-from-docker)
2. [Build the upload folder](#2-build-the-upload-folder)
3. [First deployment](#3-first-deployment)
4. [Updating the live site](#4-updating-the-live-site)
5. [Configuration reference](#5-configuration-reference)
6. [Japanese text (utf8mb4)](#6-japanese-text-utf8mb4)
7. [Limits of free hosting](#7-limits-of-free-hosting)
8. [Verification checklist](#8-verification-checklist)
9. [Troubleshooting](#9-troubleshooting)

## 1. What differs from Docker

| | Docker (development) | Shared hosting (production) |
| --- | --- | --- |
| Layout | `public/` is the web root; `src/` sits beside it, out of reach | One folder, `htdocs/`; `src/` and `database/` inside it, blocked by `.htaccess` |
| Settings | `.env` → container environment → `getenv()` | `hosting_config.php` → `$_ENV`/`$_SERVER` → `app_env()` (hosts disable `putenv()`) |
| Database | MariaDB container, migrations applied by the entrypoint on start | A host-provided database; `install.sql` imported once in phpMyAdmin |
| HTTPS | Reverse proxy or tunnel | Host terminates SSL; `.htaccess` trusts `X-Forwarded-Proto` |
| Email | Mailpit (`make mail`) | SMTP (Gmail app password); some free hosts block outbound SMTP |
| Scheduled cleanup | Container cron every 6 h | The app runs housekeeping itself, at most once per interval (`housekeeping.php`) |

The PHP code is the same in both. Only paths and configuration differ,
and `deploy/infinityfree/build.sh` handles the paths.

## 2. Build the upload folder

```bash
deploy/infinityfree/build.sh            # writes build/htdocs/
```

It copies `public/` and `src/` into one tree, rewrites the `require`
paths (`/../src/` → `/src/`, and `/../../src/` → `/../src/` in `/admin`),
and adds the Apache rules from `deploy/infinityfree/`. It contains **no
secrets** and never modifies your working tree. `build/` is git-ignored.

| File in `deploy/infinityfree/` | Becomes | Purpose |
| --- | --- | --- |
| `htaccess.txt` | `htdocs/.htaccess` | HTTPS redirect, UTF-8, blocks `src/`, `database/`, dotfiles, `composer.json` etc. |
| `src.htaccess.txt` | `src/.htaccess`, `database/.htaccess` | Deny all web access |
| `uploads.htaccess.txt` | `uploads/.htaccess` | No script execution in uploads |
| `robots.txt` | `htdocs/robots.txt` | Private register: `Disallow: /` |
| `install.sql` | `database/install.sql` | Full schema for a new, empty database |
| `037_utf8mb4_japanese.sql` | `database/037_…sql` | Converts an existing database to utf8mb4 |
| `hosting_config.example.php` | – | Template for the secrets file (step 3) |

## 3. First deployment

1. **Create the site and database** in the hosting panel (MySQL
   Databases). Note the host, database name, user and password.
2. **Import the schema.** phpMyAdmin → select the empty database →
   Import → `build/htdocs/database/install.sql`. Import it once only.
3. **Make the settings file.**
   ```bash
   cp deploy/infinityfree/hosting_config.example.php build/htdocs/hosting_config.php
   ```
   Fill in the database values, `APP_URL` (`https://profile-system.kesug.com`,
   no trailing slash) and, for email, the SMTP user and app password.
   **This file holds real credentials. Never commit it** (it is in
   `.gitignore`); it lives only on the server and on your machine.
4. **Upload** the contents of `build/htdocs/` into the site's `htdocs/`
   (FTP client such as FileZilla, or the file manager). Keep the folder
   structure; hidden files (`.htaccess`) must be uploaded too.
5. **Create the first admin.** While there are no users, open
   `/register.php` and register; the first account becomes admin.
6. Run the [checklist](#8-verification-checklist).

## 4. Updating the live site

1. `git pull`, then `deploy/infinityfree/build.sh`.
2. Upload only what changed (compare with the previous build, or
   upload everything except `hosting_config.php` and `uploads/`).
3. If the release adds a file under `database/migrations/`, run its SQL
   in phpMyAdmin (the shared host has no migration runner), in number
   order. Migrations needing a manual run are listed in the
   [`CHANGELOG`](../CHANGELOG.md).
4. **Back up first**: phpMyAdmin → Export → Quick, and download
   `uploads/`.

## 5. Configuration reference

All keys go in `hosting_config.php` (same names as `.env`; see
`.env.example` for the meaning of each).

| Key | Production value |
| --- | --- |
| `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD` | From the hosting panel |
| `APP_URL` | `https://profile-system.kesug.com`: links in emails are built from this only |
| `APP_ENV` | `production`. Only `development`/`local` may ever show a reset link on screen |
| `APP_TIMEZONE` | e.g. `Asia/Dhaka` |
| `COOKIE_SECURE` | `1` (the site is HTTPS-only) |
| `TRUST_PROXY` | `0` unless the host puts a proxy in front and you need the real client IP for rate limiting; test before turning on |
| `MAIL_HOST`, `MAIL_PORT`, `MAIL_ENCRYPTION` | `smtp.gmail.com`, `587`, `tls` |
| `MAIL_USERNAME`, `MAIL_PASSWORD`, `MAIL_FROM_ADDRESS`, `MAIL_FROM_NAME` | A Gmail **app password**, not the account password. Email stays off until these are set |

## 6. Japanese text (utf8mb4)

Free hosts often create databases as `latin1`, and saving Japanese then
fails with "Incorrect string value". Therefore:

- `install.sql` creates everything as `utf8mb4` / `utf8mb4_general_ci`.
- `src/includes/db.php` runs `SET NAMES utf8mb4 COLLATE utf8mb4_general_ci`.
- `037_utf8mb4_japanese.sql` converts an **existing** database. Export a
  backup first, then import it; it is safe to run twice.
- `general_ci` rather than `unicode_ci` keeps ハ, バ and パ distinct in
  searches and unique keys.
- Password length is counted in characters (`mb_strlen`), not bytes, and
  greetings split names on the full-width space (`first_name()`).

## 7. Limits of free hosting

- Outbound SMTP may be blocked or throttled; test password reset
  (`/forgot_password.php`) after deploying. If mail is blocked, an admin
  can set passwords from Admin › Users.
- The database connection has a 3-second timeout and no retries, so an
  outage cannot tie up the few PHP workers.
- Housekeeping uses a per-install marker in the temp directory, because
  the temp directory can be shared with other sites.
- The free tier shows an anti-bot interstitial for some clients and may
  sleep or rate limit; this is a hosting property, not an app bug.
- No shell: no `make`, no Composer. `src/vendor/` (PHPMailer) is uploaded
  as built.

## 8. Verification checklist

After every deployment, from a browser or `curl`:

| Check | Expected |
| --- | --- |
| `http://profile-system.kesug.com/` | Redirects to `https://` |
| `/` and `/login.php` | 200 |
| `/src/includes/db.php`, `/database/install.sql`, `/hosting_config.php`, `/.htaccess` | 403 or 404, never file contents |
| `/uploads/anything.php` | Not executed |
| Sign in, open `/dashboard.php` | Loads, correct name |
| Save a Japanese name in `/profile.php` | Saved and shown intact |
| `/forgot_password.php` with a real address | Email arrives; the link is not shown on the page |
| `/students.php` as a student and as a teacher | 200, same read-only sheet. As admin: 403 |

## 9. Troubleshooting

| Symptom | Cause and fix |
| --- | --- |
| Redirect loop on every page | The host's SSL is in front of Apache. `.htaccess` must keep the `X-Forwarded-Proto` condition; do not remove it |
| White page or "database unavailable" | Wrong values in `hosting_config.php`, or the file was not uploaded. Check `DB_HOST` (it is not `localhost` on most free hosts) |
| `Incorrect string value` when saving Japanese | The database is still `latin1`. Run `037_utf8mb4_japanese.sql` |
| Cannot sign in over HTTP | `COOKIE_SECURE=1` needs HTTPS. Use the `https://` address |
| Reset or invitation emails never arrive | SMTP blocked or wrong app password. Check spam; try the other port (465/ssl) |
| Rate limiting locks everyone out together | Everyone shares one IP. Leave `TRUST_PROXY=0`, or set `1` only if the host sends a trustworthy `X-Forwarded-For` |
| Styles missing | `assets/` not uploaded, or uploaded without the `fonts/` folder |
