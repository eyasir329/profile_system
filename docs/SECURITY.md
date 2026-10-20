# Security

How Profile System protects accounts and data, what the October 2026
audit found and fixed, what is left, and what whoever runs the server
must do. For who may see and change what, see
[`PERMISSIONS.md`](PERMISSIONS.md).

## 1. Audit, October 2026

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

## 2. How the protections work

### Accounts and sign-in

- **Passwords**: `password_hash()` (bcrypt), at least 8 characters.
  Never stored or logged in clear.
- **Rate limits** (`src/includes/ratelimit.php`, table `login_attempts`):
  5 failed sign-ins per email per 15 minutes and 20 per IP per 10 minutes;
  5 wrong two-factor codes per account per 10 minutes; 5 reset requests
  per IP per hour and 3 reset emails per address per hour.
- **Sessions**: HttpOnly cookie, `SameSite=Lax`, `Secure` over HTTPS
  (`COOKIE_SECURE`), strict mode, a new id at sign-in and after two-factor.
  Every request re-checks the account: deleted, deactivated or
  `session_epoch` changed means signed out; roles are read fresh.
- **Two-factor**: TOTP (RFC 6238, ±30 s), each code usable once. Ten
  backup codes, stored with `password_hash()`, shown once, single use; the
  owner is emailed when one is used. An admin can reset two-factor.
- **Security emails** go to the owner when the password changes, the
  sign-in email changes (to the old address), two-factor is turned off or
  reset, a backup code is used, and the account is deactivated.
- **Changing your own email** waits for a link sent to the new address.

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
- `robots.txt` disallows indexing.

### Data kept, and for how long (`src/includes/housekeeping.php`)

Runs every 6 hours from an ordinary page load, or now with `make purge`:
deactivated accounts 30 days after deactivation (with profile and
activity), failed-sign-in records and their log rows after 30 days, used
or expired one-time links after 30 days.

## 3. Known limits

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
- **First run**: while the database has no users at all, the first person
  to register becomes admin without an invitation. Create the first admin
  before the site is reachable from outside.
- **Quick tunnel addresses change** on every restart, so `APP_URL` must be
  updated, and links in emails already sent stop working. Use a named
  tunnel or a fixed domain for real use.

## 4. Checklist for whoever runs it

1. `.env`: `APP_ENV=production`, `COOKIE_SECURE=1`, `TRUST_PROXY=1` and
   `WEB_BIND=127.0.0.1` when published through the tunnel, `APP_URL` set to
   the public address, long random database passwords, `APP_DB_USER` set.
2. Email: a dedicated sending account with an app password, kept only in
   `.env` (never committed; `.env` is git-ignored). Replace it if it is
   ever shared.
3. Leave Adminer and Mailpit stopped unless needed (`make tools-down`).
4. After pulling changes: `make rebuild && make up` (Apache and PHP
   settings are baked into the image; migrations run on start).
5. Backups: `make backup` regularly; the dump contains personal data, so
   store it like the database.
6. Watch `make logs` for `[mail] Failed`, `[housekeeping]` and PHP errors.

## 5. Reporting a problem

Tell an admin of the lab. Do not post details publicly until it is fixed.
