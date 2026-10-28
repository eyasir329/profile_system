# Contributing

Thanks for your interest in the project. This is a small, learning-focused PHP
app, so the contribution bar is intentionally low — but a few conventions keep
the codebase coherent.

> **Before you start:** set it up with [docs/INSTALL.md](docs/INSTALL.md),
> read [docs/ARCHITECTURE.md](docs/ARCHITECTURE.md) for a file-by-file tour
> and [docs/DECISIONS.md](docs/DECISIONS.md) for why things are the way
> they are. A change that overturns a decision adds an entry there.

---

## Workflow

1. **Branch from `main`.** Pick a short, descriptive branch name
   (`fix/admin-search-sql`, `feat/new-section-grants`, ...).
2. **Make focused commits.** One logical change per commit. Imperative mood in
   the subject (`add`, `fix`, `refactor`, not `added` / `fixed`).
3. **Write a migration** for every DB schema change. See [How the schema
   evolves](docs/ARCHITECTURE.md#how-the-schema-evolves).
4. **Update CHANGELOG.md** under the `[Unreleased]` section.
5. **Test end-to-end** by running `docker compose up -d` and clicking through
   every page that touches your change. Migration apply line should appear in
   `docker compose logs web`.
6. **Open a pull request** describing the change, the test plan, and any
   follow-up work.

---

## Code style

### PHP

- **Strict types everywhere:** every file starts with
  `<?php declare(strict_types=1);`.
- **No frameworks.** Stick to the PHP standard library and the project's
  own `src/includes/` helpers. The one Composer package is PHPMailer,
  committed in `src/vendor/`; adding another needs an entry in
  `docs/DECISIONS.md`.
- **Output escaping:** always use the `e()` helper. Never `echo` raw user
  input. Render server-validated errors as `<div class="field-error">` next to
  the field.
- **SQL:** all queries are prepared statements with
  `PDO::ATTR_EMULATE_PREPARES = false`. Never interpolate user input into SQL.
- **CSRF:** every POST form must include `<input type="hidden" name="csrf_token"
  value="<?= e(csrf_token()) ?>">` and call `verify_csrf()` at the top of the
  POST handler.
- **Session writes:** if your action changes the user's role or other
  session-tracked data, either update `$_SESSION` or destroy the session so the
  next login re-reads from the DB. The role is only read from the DB at login
  time.
- **Naming:** files are lowercase (`dashboard.php`), functions are
  `snake_case` (`require_admin`), CSS classes are BEM-ish (`.user-table`,
  `.role-badge`). Constants are `UPPER_SNAKE` (`DEGREE_TYPES`).
- **No dead code.** If you remove a feature, delete the file and remove the
  link from the top nav.

### HTML

- Use the shared partials (`src/includes/header.php`, `src/includes/footer.php`). Don't
  open `<html>` or `<head>` yourself — set `$page_title` and `$page_id`
  variables instead.
- Every form input gets a `<label>`. Every button has clear text. Focus rings
  are always visible (they come from `app.css`; don't override `outline`).
- Use the shared confirm dialog (`x-confirm` on the `<form>`, or
  `PS.confirm()`; see docs/FRONTEND.md). Don't write your own modal.

### CSS

- Source is in `resources/css/` (Tailwind CSS v4). Anything that repeats is
  a named class in `resources/css/components/`; utilities are for one-off
  layout. See [docs/COMPONENTS.md](docs/COMPONENTS.md).
- Use the semantic tokens from `resources/css/theme.css` (`bg-layer`,
  `text-foreground`, ...), never raw palette colours or hex codes, so dark
  mode keeps working.
- Mobile-first, and nothing may make the page scroll sideways from 320 px
  up ([docs/FRONTEND.md](docs/FRONTEND.md), "Responsive rules").
- Run `make assets` and commit the rebuilt `public/assets/` with your
  change.

### JS

- Source is in `resources/js/`: Alpine.js stores and components in `ui.js`,
  the profile editor in `profile-editor.js`, bundled by esbuild into
  `public/assets/js/app.js` (`make assets`).
- Keep behaviour in Alpine; don't add a second UI library.
- Respect `prefers-reduced-motion`.

### SQL

- Use `IF NOT EXISTS` / `IF EXISTS` where the change is additive, so a half-
  applied migration can be re-run safely.
- Wrap multi-statement migrations in `START TRANSACTION; ... COMMIT;`.
- Foreign keys: always include `ON DELETE CASCADE` for user-owned rows so
  account deletion doesn't orphan data.
- Indexes: every per-user table should have `INDEX (user_id, sort_order)`.

---

## Testing

`make test` runs the unit tests in `tests/` (pure helpers: validators, paper counts, names, TOTP; no database needed). CI runs them, `make lint`, and a check that the built CSS/JS match `resources/` on every pull request. Add a case in `tests/run.php` when you change a pure helper.

There are no automated browser or database tests yet, so for pages and queries do these manual smoke tests:

```bash
# 1. Restart the web container so migrations apply.
docker compose restart web && docker compose logs -f web

# 2. Hit each affected URL.
curl -sS -o /dev/null -w "GET /                -> %{http_code}\n" http://localhost:8080/
curl -sS -o /dev/null -w "GET /login.php       -> %{http_code}\n" http://localhost:8080/login.php
curl -sS -o /dev/null -w "GET /register.php    -> %{http_code}\n" http://localhost:8080/register.php
curl -sS -o /dev/null -w "GET /forgot_password.php -> %{http_code}\n" http://localhost:8080/forgot_password.php
curl -sS -o /dev/null -w "GET /reset_password.php -> %{http_code}\n" http://localhost:8080/reset_password.php

# 3. As admin, hit every admin URL.
curl -sS -o /dev/null -w "GET /admin/index.php -> %{http_code}\n" http://localhost:8080/admin/index.php
curl -sS -o /dev/null -w "GET /admin/user.php?id=1 -> %{http_code}\n" http://localhost:8080/admin/user.php?id=1

# 4. Syntax-check every PHP file (inside the container).
make lint
```

Then click through in the browser for any page that renders dynamic content
(profile editor, dashboard, admin user table).

### What "done" means

- [ ] `docker compose up -d` succeeds on a clean clone
- [ ] `docker compose logs web` shows the schema + migrations apply cleanly
- [ ] All pages above return 200 (or 302 redirect to login)
- [ ] No new errors in `docker compose logs web`
- [ ] `make lint` prints nothing and `make test` passes
- [ ] If you changed `resources/`, `make assets` ran and `public/assets/` is
      committed
- [ ] `.env` is **not** committed; `.env.example` **is** updated if any new
      env var was added
- [ ] CHANGELOG.md is updated under `[Unreleased]`
- [ ] If you added a migration, `SELECT * FROM schema_migrations ORDER BY
      filename` includes it
- [ ] If your change touches `login.php`, `register.php`,
      `forgot_password.php`, `reset_password.php`, or any other
      rate-limited endpoint, exercise the rate limiter: deliberately
      trigger 6 failed logins from a fresh IP, then verify that the
      6th request is rejected with HTTP 429 (or the equivalent
      in-app message) and that the attempt shows up in
      `SELECT bucket, COUNT(*) FROM login_attempts GROUP BY bucket`.
      See [Public-exposure development](#public-exposure-development) below
      for why this matters in the tunnel world.

---

## Public-exposure development

The app is designed to be runnable behind a Cloudflare quick tunnel
(`make tunnel`; see [`docs/TUNNEL.md`](docs/TUNNEL.md)). That means
the origin server often sees traffic that *originated* as HTTPS but
arrived as plain HTTP, with a `X-Forwarded-Proto: https` header from
`cloudflared`.

If your change touches any of the following, take the tunnel case into
account:

- **Session cookie / `Secure` flag logic.** The logic lives in
  `src/includes/auth.php` and is governed by `COOKIE_SECURE=1` and
  `TRUST_PROXY=1`. Don't short-circuit it with `!empty($_SERVER['HTTPS'])`
  alone — that's only true on the direct-HTTP path.
- **`client_ip()`** in `src/includes/ratelimit.php` honors
  `TRUST_PROXY=1` + `X-Forwarded-For` to figure out the real remote IP.
  Anything that adds a new rate-limited bucket (or any other
  IP-keyed cache) should reuse `client_ip()`, never
  `$_SERVER['REMOTE_ADDR']` directly.
- **Any "are we on HTTPS?" check.** Prefer the same
  `$cookieSecure` / `$directHttps || $forwardedHttps` pattern that
  `auth.php` already uses. Don't add new conditionals that ignore
  the proxy.
- **`APP_ENV=production`** hides the dev-only "copy this reset link"
  banner on `forgot_password.php`. Don't surface any new
  on-screen-secret feature without also gating it on `APP_ENV` so it
  disappears in production by default.
- **New endpoints that the public can hit.** Even if they're not
  rate-limited today, they should at minimum be CSRF-protected and
  validate their inputs with the existing helpers in
  `src/includes/validate.php`.

---

## Code of conduct

Be kind. This is a learning project. If something in the code is wrong or
confusing, opening an issue is more helpful than a drive-by rewrite.