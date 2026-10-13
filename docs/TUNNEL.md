# Exposing the app via a Cloudflare quick tunnel

This is the shortest path to letting other people reach your local
**Profile System** app from the public internet. It uses Cloudflare's
free, no-account **quick tunnel** feature — a one-line `cloudflared`
command that gives you a `https://<random>.trycloudflare.com` URL.

> ⚠️ **Important limitation.** The quick-tunnel URL is *random per
> process start*. There is no login-free way to get a stable URL.
> If you need a permanent subdomain (e.g. `https://profile.yourdomain.com`)
> you need a free Cloudflare account and your own domain; see
> [Cloudflare's named-tunnel docs](https://developers.cloudflare.com/cloudflare-one/connections/connect-networks/).
> Everything below assumes the quick tunnel.

> ⚠️ A quick tunnel is the entire public internet. The changes in
> this hardening pass are the *minimum* required to do that safely.
> Read the README's [Security notes](../README.md#security-notes)
> before sharing the URL with anyone you don't personally trust.

## Prerequisites

- **Docker** + **Docker Compose v2** + **GNU make** (the project is
  entirely driven by `make`; see the [README quick start](../README.md#quick-start-docker)).
- **`cloudflared`** (the Cloudflare tunnel client). Install from
  <https://developers.cloudflare.com/cloudflare-one/connections/connect-networks/downloads/>
  or `brew install cloudflared` / `apt install cloudflared`.

---

## 1. Install `cloudflared` (if you haven't already)

Pick your platform:

```bash
# macOS
brew install cloudflared

# Debian / Ubuntu
curl -fsSL https://pkg.cloudflare.com/cloudflare-main.gpg \
  | sudo tee /usr/share/keyrings/cloudflare-main.gpg >/dev/null
echo "deb [signed-by=/usr/share/keyrings/cloudflare-main.gpg] https://pkg.cloudflare.com/cloudflared $(lsb_release -cs) main" \
  | sudo tee /etc/apt/sources.list.d/cloudflared.list
sudo apt update && sudo apt install -y cloudflared

# Windows: download from
# https://developers.cloudflare.com/cloudflare-one/connections/connect-networks/downloads/
```

Verify: `cloudflared --version`.

## 2. Make sure your `.env` is hardened

The repo's `.env.example` ships with safe defaults for local dev.
Before exposing the app, copy the production-ready values into
`.env`:

```env
DB_PASSWORD=<long random string>      # NOT rootpass
DB_USER=profile_app                  # dedicated, non-root DB user
DB_APP_PASSWORD=<long random string> # password for profile_app
COOKIE_SECURE=1                      # session cookie gets the Secure flag
TRUST_PROXY=1                        # honor X-Forwarded-Proto from cloudflared
APP_ENV=production                   # hides the on-screen dev reset link
ENABLE_ADMINER=0                     # Adminer container is OFF by default
```

A strong password is, e.g.,
`openssl rand -hex 24`. The web entrypoint creates the `profile_app`
DB user on first boot.

## 3. Start the stack

```bash
make up          # build (if needed) and start db + web
make logs        # watch the entrypoint apply schema + migrations
```

Confirm Adminer is **not** running:

```bash
make ps          # should show profile_db + profile_web, NOT profile_adminer
```

## 4. Start the tunnel

```bash
make tunnel
```

You'll get a URL like `https://something-random.trycloudflare.com`.
Anyone with that link can reach the app at that URL until you press
`Ctrl+C` (or run `make tunnel-stop` from another terminal).

The full log is at `cloudflared.log`; the PID is in `cloudflared.pid`.

## 5. Stop the tunnel

```bash
make tunnel-stop
```

The URL is gone immediately. The local app continues to run.

## Managing a long-running tunnel

Once you run `make tunnel`, the tunnel stays alive in the background
until you explicitly stop it or the machine reboots. The URL is bound
to that running `cloudflared` process — there is no way to keep the
URL alive across a restart without logging into a Cloudflare account.

Four commands cover everything you need:

| Command | What it does |
| ------- | ----------- |
| `make tunnel`        | Start the tunnel. Prints the URL. Runs forever. |
| `make tunnel-url`    | Re-print the URL of the currently-running tunnel. |
| `make tunnel-stop`   | Kill the tunnel. URL is gone. |
| `make tunnel-restart`| `tunnel-stop` then `tunnel`. The new URL will be different from the old one. |

### "I want the URL to survive a reboot"

That needs a **named** tunnel, which requires a free Cloudflare
account and (optionally) your own domain. See
[Cloudflare's docs](https://developers.cloudflare.com/cloudflare-one/connections/connect-networks/)
for `cloudflared tunnel create` / `cloudflared tunnel route dns`. The
quick tunnel cannot do this without a login.

### "I want the URL to be the same every time I restart"

Same answer — login is required. The only login-free option is the
quick tunnel, whose URL is intentionally random per process start.

### "I forgot the URL"

```bash
make tunnel-url
```

It reads the URL from `cloudflared.log`.

### "I started a tunnel a week ago and now I can't tell if it's still up"

```bash
cat cloudflared.pid 2>/dev/null && \
  ps -p $(cat cloudflared.pid) -o pid,etime,cmd 2>/dev/null \
  || echo "Tunnel is not running. Start it with: make tunnel"
```

---

## What changed in this hardening pass

| Change | Where | Why |
| ------ | ----- | --- |
| Strong, unique `DB_PASSWORD` | `.env` | The default `rootpass` was a known shared password. |
| Dedicated `profile_app` DB user | `.env` + `docker/web-entrypoint.sh` | App no longer connects as root. |
| Adminer behind `tools` profile | `compose.yml` | `make up` no longer starts Adminer on port 8081. |
| Secure session cookie behind proxy | `src/includes/auth.php` | Behind `cloudflared` the origin sees plain HTTP, so we now also honor `X-Forwarded-Proto`. |
| Rate limiting on login / register / forgot-password | `src/includes/ratelimit.php` + `database/migrations/018_login_attempts.sql` | 5 fails / 15 min per email, 20 fails / 10 min per IP. |
| `APP_ENV=production` hides the dev reset link | `public/forgot_password.php` | The dev "copy this link" banner was the single biggest pre-publication risk. |
| Pinned `mariadb:11.4` and `adminer:4.8.1` | `compose.yml` | `:latest` and floating tags are reproducibility risks. |
| `make tunnel` / `make tunnel-stop` | `Makefile` | One-liner start/stop of the Cloudflare quick tunnel. |

## Things still on you

These are the limits of "good enough for a friends-and-family demo";
they are NOT appropriate for a real public deployment:

- **No SMTP.** If a user forgets their password in `APP_ENV=production`,
  the reset link is generated and stored in the DB but never emailed.
  Either wire up real email (PHPMailer + SMTP) or accept that
  account recovery has to be manual via the DB.
- **No 2FA enforcement.** Individual users can opt in (the schema has
  `users.totp_required`), but it isn't required.
- **No intrusion detection beyond rate limits.** If someone hammers
  `/forgot_password.php` with 5 reqs/hour for 24 hours, that's still
  120 requests per day.
- **Container images are minor-pinned, not digest-pinned.** Rebuild
  the images with a specific digest for true reproducibility.
- **`cloudflared` quick-tunnel URLs are random.** Use a named tunnel
  (`cloudflared tunnel create …`) for a stable subdomain.
- **No CSRF token rotation** on privilege change.

## Rotate the DB password

```bash
# 1. Generate a new one
NEW=$(openssl rand -hex 24)
echo "new password: $NEW"

# 2. Update .env (both DB_PASSWORD and DB_APP_PASSWORD)
sed -i "s|^DB_PASSWORD=.*|DB_PASSWORD=$NEW|" .env
sed -i "s|^DB_APP_PASSWORD=.*|DB_APP_PASSWORD=$NEW|" .env

# 3. Update the running MariaDB users
docker compose exec db mariadb -uroot -p"$OLDPW" \
  -e "ALTER USER 'root'@'%' IDENTIFIED BY '$NEW'; \
      ALTER USER 'profile_app'@'%' IDENTIFIED BY '$NEW'; \
      FLUSH PRIVILEGES;"

# 4. Restart so the entrypoint sees the new value (idempotent for app user)
docker compose restart web
```

## Re-enable Adminer (locally only)

```bash
make tools       # starts profile_adminer on $ADMINER_PORT (8081)
make tools-down  # stops it
```

NEVER expose port 8081 to the public internet while Adminer is
running — it has full DB root access.

## Troubleshooting

### `make tunnel` says "cloudflared is not installed"

`cloudflared` is not on your `$PATH`. Install it (see [Prerequisites](#prerequisites))
or fix the path.

### `make tunnel` says "A tunnel is already running"

The previous tunnel was not cleanly stopped (e.g. you ran `make tunnel`
in another terminal and never ran `make tunnel-stop`). Either:

```bash
make tunnel-stop     # graceful
```

…or, if the previous process is truly gone and only the pid file
is stale:

```bash
rm -f cloudflared.pid
make tunnel
```

### `make tunnel-url` says "Tunnel is NOT running"

The tunnel was killed (crash, reboot, `kill` from another terminal)
and `cloudflared.log` still has the old URL. The target deliberately
refuses to print a dead URL. Start a fresh tunnel with `make tunnel`.

### The URL works on my phone but I can't see it from my computer

That's expected — the URL is bound to whatever client IP you opened
it from. Test from a different network (cellular, a friend's house)
to confirm the tunnel is actually public. Locally, prefer
`http://localhost:8080`.

### Port 8080 is already in use

Edit `.env` and set `WEB_PORT=9000` (or anything free), then
`make restart`. The tunnel will pick up the new port automatically
on the next start.

### `cloudflared.log` is huge

Cloudflare rotates its own log lines, but the `TUNNEL_URL=` line
that `make tunnel` appends never gets removed. Truncate manually:

```bash
: > cloudflared.log
```

The next `make tunnel-url` will return an error until you restart
the tunnel (the URL only exists in the live process's memory).

### `login_attempts` table is missing

You restored a backup taken before migration 018. Re-apply it
manually (see the README's Troubleshooting section). The
rate-limiter is safe to run without the table — it just fails open.

---

## See also

- `README.md` — full project documentation, including the Security
  Notes section.
- `docs/DIRECTORY.md` — file-by-file tour of the project.
