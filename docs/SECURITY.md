# Security

How Profile System protects accounts and data, what the two October 2026
audits found and fixed, what is left, and what whoever runs the server
must do. For who may see and change what, see
[`PERMISSIONS.md`](PERMISSIONS.md).

## 1. Second audit, 20 October 2026

Done twice, in two ways, after the UI refresh added the admin pages for
activity, emails, search and site settings.

1. **Black-box (outside in, before reading the code).** The running app was
   probed like a stranger would: which paths answer, which headers come
   back, what forms accept, how sign-in reacts to wrong passwords and
   rotated addresses, what a signed-in student can reach. Test accounts
   were created for this and deleted afterwards.
2. **White-box (reading the code).** Every page and include changed since
   the first audit, the auth and session core, the rate limiter, the
   one-time-link pages, every query built from a variable, every
   unescaped output, the scripts, `Dockerfile`, `compose.yml`, the
   entrypoint, `.dockerignore` and the git history.

### Black-box results

| Check | Result |
| --- | --- |
| Hidden files: `.env`, `.git/`, `composer.json`, `src/`, `database/`, `backups/`, `*.bak`, `phpinfo.php` | Not served (404) |
| Directory listings: `/uploads/`, `/assets/`, `/docker/` | Refused (403) |
| Security headers | CSP, `X-Frame-Options: DENY`, `nosniff`, `Referrer-Policy`, `Permissions-Policy`, HSTS over HTTPS; `TRACE` refused |
| Session cookie | `HttpOnly`, `Secure`, `SameSite=Lax`, and `no-store` on pages |
| POST without the CSRF token | Refused (403) |
| Wrong email vs wrong password | Same message, same timing: no account enumeration |
| Brute force, one request at a time, with a different `X-Forwarded-For` each | Locked after 5; the fake header is ignored |
| Odd input (quotes, `token[]=` arrays) on reset, verify and register links | Clean "invalid link" pages, no PHP errors or stack traces |
| `?next=` / `?redirect=` to another site | Ignored: no open redirect |
| Student opening admin pages, the Students sheet or invitations | 403 |
| Student asking for another person's profile (`profile.php?id=`) | Always shown their own: no IDOR |
| **Brute force, many requests at the same moment** | **Got past the limit: finding 1 (confirmed with the white-box reading, fixed, tested)** |

### What was found and fixed

| # | Severity | Finding | Fix |
| --- | --- | --- | --- |
| 1 | High | **Rate limits could be raced.** Each limit was "count failures, check the password (≈70 ms of bcrypt), record the failure". Requests sent in parallel all counted *before* any failure was recorded, so "5 tries per account" allowed as many guesses as fit in one burst. The same applied to the two-factor code (5 tries per 10 minutes, which is what makes a 6-digit code safe) and to password-reset emails. | `rate_limit_lock()` (`src/includes/ratelimit.php`) takes a MariaDB `GET_LOCK` per bucket before the check, held until the request ends, so attempts on one email, account or IP run one at a time. Used by sign-in (email, then IP), `second_factor_check()` and `forgot_password.php`. A request that waits more than 10 s for the lock counts as over the limit. Tested: 12 parallel wrong passwords now give exactly 5 "Invalid" answers and 7 lock-outs. |
| 2 | Medium | **Signing in reset the per-IP counter.** A successful sign-in cleared the IP's failed attempts, so anyone with an account could spray passwords at other accounts from one address without limit, signing into their own account every 19 tries. A successful registration cleared it too (the `ip:` bucket is shared). | Success now clears only that account's own bucket; the IP's count expires on its own (`login.php`, `register.php`). |
| 3 | Medium | **Two-factor and backup codes could be used twice at once.** The "code already used" check and the backup-code list were read, then written. Two requests together could both pass. | The authenticator code's time step is claimed in one conditional `UPDATE`, which succeeds only once. The per-account lock (fix 1) also serialises backup-code use. Tested: the same code is accepted once, then refused. |
| 4 | Medium | **Reset links outlived an email change.** Reset links already sent to the old address stayed valid after the email was changed, by the user or an admin. If the old mailbox is the reason for the change, it could still take the account over. | `email_change_applied()` (`src/includes/email_change.php`) marks every open reset link used. Called from `verify_email.php`, the instant change path and `admin/user.php`. |
| 5 | Medium | **Silent email change.** When the "confirm new email" message is switched off (Emails › Settings), the change applied at once and **nobody was told**. Someone at an unlocked screen, or with a stolen session, could move the account to their address and then reset the password. | The instant path now emails the old address (`mail_email_changed`), like every other path. |
| 6 | Medium | **The root database password was visible to PHP.** The entrypoint needs it to run migrations, but it stayed in the environment Apache inherits, so any PHP bug that reads the environment (`getenv()`, `/proc/self/environ`) exposed full database control. | With a separate app user, `docker/web-entrypoint.sh` unsets `DB_PASSWORD`, `DB_USER` and `MARIADB_ROOT_PASSWORD` before starting Apache. Checked: Apache's environment now holds only the app user's credentials. |
| 7 | Medium | **The app's database user had `ALL PRIVILEGES`**, so an SQL injection could `DROP` or `ALTER` tables or create routines. | It now has `SELECT, INSERT, UPDATE, DELETE` only, revoked and regranted on every start, and the password follows `.env`. The app never changes the schema; migrations run as the operator user. |
| 8 | Medium | **Database dumps and a script with real credentials were baked into the image.** `COPY . /var/www` with an incomplete `.dockerignore` copied `backups/` (full dumps: personal data) and `crud_test.sh` (a real login) into every image built. | `.dockerignore` now excludes `backups/`, dumps, spreadsheets, CSVs, both smoke/test scripts, `public/docker/` and the tunnel files. Checked in the rebuilt container. |
| 9 | Low | **The web server could rewrite the app.** Code was owned by `www-data` in the image and bind-mounted read-write, so a bug that lets someone write a file could plant PHP in the project on the host. | Code in the image is owned by root and not writable; the bind mounts in `compose.yml` are `:ro`; only `uploads/` (its own volume, PHP disabled) is writable. |
| 10 | Low | **Sessions never expired** while they were used, and idle ones disappeared at random (PHP's 24-minute garbage collection). A session left open on a shared lab PC, or a stolen cookie, stayed valid for ever. | `require_login()` ends a session after 2 hours without a request and 7 days after sign-in (`SESSION_IDLE_SECONDS`, `SESSION_ABSOLUTE_SECONDS` in `auth.php`); `session.gc_maxlifetime` matches. The login page explains why. |
| 11 | Low | **Session cookie could be planted from a sibling site.** It was the default `PHPSESSID`. The quick tunnel shares `trycloudflare.com` with everyone else's tunnels, and any subdomain could set a cookie for the parent domain (cookie tossing / session fixation). | Over HTTPS the cookie is `__Host-ps_session`: browsers accept it only from this exact host, with `Secure`, `Path=/` and no `Domain`. Over plain HTTP (local) it is `ps_session`. |
| 12 | Low | **The per-request account check failed open.** If the database errored, `require_login()` ignored the error, and a deleted or signed-out session went on with its cached roles. | It now shows the 503 page instead. |
| 13 | Info | **Student spreadsheets in the repository.** Three `Information of Lab Students*.xlsx` files (names, IDs: personal data) were committed. | Removed from the tree in this commit. **They are still in git history**; see §4. |

### Checked and found safe

- **SQL**: every query built from a variable was traced. Tables, columns and
  `IN (…)` lists come from fixed maps or are cast to integers; values are
  bound. The new admin search and activity filters bind everything and
  escape `LIKE` wildcards.
- **XSS**: new admin pages print through `e()`; the editor's `innerHTML`
  writes only server-escaped fragments; the command palette uses text
  nodes; email HTML escapes every paragraph and link.
- **CSV exports** (users, activity, students): cells starting with
  `= + - @`, tab or CR are prefixed with `'`, so they can't run as Excel
  formulas.
- **Admin emails**: one message per person (no exposed recipients), header
  fields go through PHPMailer, a resend repeats only admin-written emails,
  never a reset or invitation link, and links in system emails are not
  stored in the log.
- **Role switch**: roles are re-read from the database; the redirect back
  only accepts this site's own address.
- **Site settings**: the time zone must be one from PHP's own list before
  it is used in `SET time_zone`.

## 2. First audit, October 2026

The whole application was reviewed: every page in `public/`, every
include in `src/includes/`, the JavaScript in `resources/js/`, the Apache
and PHP settings, `compose.yml` and the Dockerfile. Each finding below
was fixed and then tested against the running app.

### What was checked

| Area | How | Result |
| --- | --- | --- |
| SQL injection | Every query that builds SQL from a variable was traced to its source | Safe. Values are always bound parameters; the only interpolated names (tables, columns) come from the code's own lists (`lab_entry_defs()`, `lab_field_defs()`, fixed action maps) or are cast to integers |
| Cross-site scripting | Every `<?= … ?>` that does not go through `e()`, every `innerHTML` in the scripts, links built from user input | Safe. Output is escaped with `e()`; the editor only inserts server-escaped fragments; saved links must be `http(s)://`, and `$linkify` works on already-escaped text; inline JSON uses `JSON_HEX_*` flags |
| Cross-site request forgery | Every page that accepts POST | Safe. All call `verify_csrf()`; state changes are POST-only (logout, role switch, two-factor) |
| Access control | Every page's guard, every query that takes an id from the request | Safe after fix 3. Profile and entry changes are bound to the signed-in user; invitations are scoped to their creator; admin pages need the admin role |
| Sign-in, sessions, two-factor | Rate limits, session handling, password reset, backup codes | Fixes 1, 2, 3, 6, 7, 8 below |
| One-time links | Reset, invitation, email confirmation, account restore | Safe. Random 256-bit tokens, stored only as SHA-256, single use, expiring; registration re-checks the invitation under a row lock |
| Email | Header injection, links in emails | Safe. PHPMailer encodes headers; links use `APP_URL` only, never the request's Host header, in production |
| Server and containers | Apache and PHP settings, exposed ports, secrets | Fixes 4, 9 below. Database passwords are long and random; the app connects as a least-privilege database user |
| Privacy promises | Retention stated on `/privacy.php` and in Settings | Fix 10 below |

### What was found and fixed

| # | Severity | Finding | Fix |
| --- | --- | --- | --- |
| 1 | High | Behind the tunnel (`TRUST_PROXY=1`) the client address came from the **leftmost** `X-Forwarded-For` entry, which the visitor writes. A different fake address per request got round every per-IP limit (sign-in, password reset, registration). | `client_ip()` (`src/includes/ratelimit.php`) uses Cloudflare's `CF-Connecting-IP`, else the **rightmost** `X-Forwarded-For` entry. And with `WEB_BIND=127.0.0.1` nobody on the network can skip the tunnel and send their own header. |
| 2 | High | Changing or resetting a password did not sign out other sessions, and a session of a deleted account stayed open. | `users.session_epoch` (migration `032`) is stored in the session at sign-in and compared on every request by `require_login()`. It is raised by a password change (other devices end, this one stays), a reset link (all end), an admin setting a password, and an admin resetting two-factor. A session whose account no longer exists is ended. |
| 3 | High | Admin and teacher checks trusted the role cached in the session: an admin whose role was removed could still use admin pages until they signed out. | `require_login()` re-reads the roles from the database on every request (`ensure_active_role_valid()`), so a revoked role stops working on the next click. |
| 4 | Medium | Adminer (full database access) and Mailpit listened on every network interface. | Both are bound to `127.0.0.1` in `compose.yml`. |
| 5 | Medium | Password-reset emails were limited per IP only: from many addresses, one person's inbox could be flooded through the lab's Gmail account. | At most 3 reset emails per address per hour, from anywhere (`forgot_password.php`). The page answers the same way either way. |
| 6 | Low | Signing in with an unknown email skipped the password check, so the faster answer showed which emails have accounts. | A check against a decoy hash runs for unknown emails, so both take the same time (`login.php`). |
| 7 | Low | An authenticator code could be used again within its 30-second window. | The step of the last code used is recorded (`two_factor.last_used_at`); a code at or before it is refused (`second_factor_check()`). |
| 8 | Low | The session id stayed the same through the two-factor step. | `complete_totp_login()` issues a new session id once the account is fully signed in. |
| 9 | Low | No HSTS header. | `Strict-Transport-Security` (one year) on HTTPS responses (`docker/apache/app.conf`). |
| 10 | Low | Failed-sign-in rows in the activity log (typed email, IP) were kept for ever, unlike what `/privacy.php` says. | Housekeeping deletes them after 30 days. |

Earlier in the same month, before this audit, these were also fixed
(see `CHANGELOG.md`): `.htaccess` files were never read so their rules
moved into Apache's config; PHP showed errors to visitors; a seed script
was reachable from the web; backup codes did not work and were stored in
plain text; the two-factor step had no rate limit; admins could not reset
two-factor; deactivated accounts were never deleted; email changes were
not confirmed; there were no security headers.

## 3. How the protections work

### Accounts and sign-in

- **Passwords**: `password_hash()` (bcrypt), at least 8 characters.
  Never stored or logged in clear.
- **Rate limits** (`src/includes/ratelimit.php`, table `login_attempts`):
  5 failed sign-ins per email per 15 minutes and 20 per IP per 10 minutes;
  5 wrong two-factor codes per account per 10 minutes; 5 reset requests
  per IP per hour and 3 reset emails per address per hour.
  Each check takes a lock on its bucket first (`rate_limit_lock()`), so
  parallel requests are counted one by one. A successful sign-in clears
  only that account's failures, never the IP's.
- **Sessions**: HttpOnly cookie, `SameSite=Lax`, `Secure` over HTTPS
  (`COOKIE_SECURE`), strict mode, a new id at sign-in and after two-factor.
  The cookie is `__Host-ps_session` over HTTPS. Every request re-checks
  the account: deleted, deactivated or `session_epoch` changed means
  signed out; roles are read fresh; if the database can't answer, the
  request stops (503). A session ends after 2 hours idle and 7 days after
  sign-in.
- **Two-factor**: TOTP (RFC 6238, ±30 s), each code usable once (claimed
  atomically, so not even twice at the same moment). Ten
  backup codes, stored with `password_hash()`, shown once, single use; the
  owner is emailed when one is used. An admin can reset two-factor.
- **Security emails** go to the owner when the password changes, the
  sign-in email changes (to the old address), two-factor is turned off or
  reset, a backup code is used, and the account is deactivated.
- **Changing your own email** waits for a link sent to the new address
  (or, with that email switched off, applies at once and tells the old
  address). Any email change ends open password-reset links.

### Requests

- **CSRF**: a per-session token in every form, checked by `verify_csrf()`
  on every POST. An expired form gets the "Page expired" page (403).
- **Output**: everything printed goes through `e()`.
- **Database**: PDO with bound parameters only.
- **Errors**: logged (`make logs`), never shown; visitors get the error
  pages in `src/includes/error_page.php`.

### Server (`docker/apache/app.conf`, `docker/php/app.ini`)

- Content-Security-Policy: everything from this site only, no framing,
  forms post here only.
- `Referrer-Policy: same-origin` (links with tokens are never sent to
  other sites), `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`,
  `Permissions-Policy`, HSTS over HTTPS.
- No directory listings; no scripts run from `uploads/`; `public/docker/`
  denied; Apache and PHP versions not advertised.
- Containers: the app's database user can only read and write rows;
  Apache's environment holds no root database password; the code is
  read-only to the web server; the image contains no backups, test
  scripts or spreadsheets (`.dockerignore`).
- `robots.txt` disallows indexing.

### Data kept, and for how long (`src/includes/housekeeping.php`)

Runs every 6 hours from an ordinary page load, or now with `make purge`:
deactivated accounts 30 days after deactivation (with profile and
activity), failed-sign-in records and their log rows after 30 days, used
or expired one-time links after 30 days.

## 4. Known limits

- **CSP allows `'unsafe-inline'` and `'unsafe-eval'`** for scripts.
  Alpine.js's standard build evaluates `x-data` and `@click` expressions.
  Moving to Alpine's CSP build (expressions as registered components) and
  the two inline scripts into files would allow a strict policy. Escaping
  is the main XSS defence today, and the policy still blocks scripts from
  other sites.
- **The two-factor secret is stored in clear** in `two_factor.secret`;
  the server needs it to check codes. Database access therefore means
  two-factor bypass: keep the database private.
- **Teachers may edit and delete any template or data link, and everyone
  may edit any potential journal.** This is by design (shared lists); the
  activity log and the "Updated by" line record who did what.
- **Student spreadsheets are still in git history.** The three
  `Information of Lab Students*.xlsx` files are deleted from the tree, but
  every clone of the repository still holds them in earlier commits. The
  repository is private (checked: not visible without signing in to
  GitHub), so keep it private. To remove them for good, rewrite the
  history (`git filter-repo --path-glob 'Information of Lab Students*.xlsx'
  --invert-paths`), force-push, and have everyone re-clone. That rewrites
  every commit id, so it is left to the owner to decide.
- **`public/docker/smoke.sh` and `crud_test.sh` contain real sign-in
  credentials** on the developer's disk. They are git-ignored, kept out of
  the image, and `public/docker/` is denied by Apache, but the safest
  place is outside `public/`, with the password read from an environment
  variable.
- **Admins can switch off security emails** (Emails › Settings), including
  the confirmation of a new email address. That is deliberate, but every
  switch weakens what the owner is told; leave the security ones on.
- **First run**: while the database has no users at all, the first person
  to register becomes admin without an invitation. Create the first admin
  before the site is reachable from outside.
- **Quick tunnel addresses change** on every restart, so `APP_URL` must be
  updated, and links in emails already sent stop working. Use a named
  tunnel or a fixed domain for real use.

## 5. Checklist for whoever runs it

1. `.env`: `APP_ENV=production`, `COOKIE_SECURE=1`, `TRUST_PROXY=1` and
   `WEB_BIND=127.0.0.1` when published through the tunnel, `APP_URL` set to
   the public address, long random database passwords, `APP_DB_USER` set.
2. Email: a dedicated sending account with an app password, kept only in
   `.env` (never committed; `.env` is git-ignored). Replace it if it is
   ever shared.
3. Leave Adminer and Mailpit stopped unless needed (`make tools-down`).
   `ENABLE_ADMINER=0` does not stop a container that is already running:
   check `docker ps`.
4. After pulling changes: `make rebuild && make up` (Apache and PHP
   settings and the entrypoint are baked into the image; migrations run
   on start). After the 20 October 2026 audit this also narrows the app
   database user's grants and signs everyone out once (new cookie name).
5. Backups: `make backup` regularly; the dump contains personal data, so
   store it like the database.
6. Watch `make logs` for `[mail] Failed`, `[housekeeping]` and PHP errors.

## 6. Reporting a problem

Tell an admin of the lab. Do not post details publicly until it is fixed.
