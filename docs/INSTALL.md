# Install and run on a new computer

How to get Profile System running on a computer where nothing is
installed yet: Windows, macOS or Linux. You install two programs (Docker
and Git), copy the project, and start it. PHP, MariaDB, Apache, Node and
Composer are **not** installed on your computer: they run inside Docker
containers that are downloaded the first time you start the app.

For why it is built this way, see [`DECISIONS.md`](DECISIONS.md). For a
tour of the files, see [`ARCHITECTURE.md`](ARCHITECTURE.md).

## Contents

1. [What you need](#1-what-you-need)
2. [Install the tools](#2-install-the-tools) ([Windows](#windows-10-and-11), [macOS](#macos), [Linux](#linux))
3. [Get the project](#3-get-the-project)
4. [Configure `.env`](#4-configure-env)
5. [Start it](#5-start-it)
6. [Create the first account](#6-create-the-first-account)
7. [Check that it works](#7-check-that-it-works)
8. [Everyday use](#8-everyday-use)
9. [Without `make`](#9-without-make)
10. [Moving to another computer](#10-moving-to-another-computer)
11. [Running it for real users](#11-running-it-for-real-users)
12. [Troubleshooting](#12-troubleshooting)
13. [Removing it](#13-removing-it)

## 1. What you need

| | Minimum | Notes |
| --- | --- | --- |
| Operating system | Windows 10 22H2 or 11 (64-bit), macOS 13+, any current 64-bit Linux | Intel/AMD or ARM (Apple Silicon, Raspberry Pi 4/5 with 64-bit OS). Every image used is published for both. |
| Memory | 4 GB RAM (8 GB comfortable) | The app itself uses about 300 MB; the rest is Docker and the OS. |
| Disk | About 3 GB free | Images ≈ 1 GB, plus the database and backups. |
| Network | Internet for the first start | Images are downloaded once. After that it runs offline. |
| Ports | 8080 free | Change `WEB_PORT` in `.env` if something else uses it. |
| Programs | **Docker** (with Compose v2), **Git**, and optionally **make** | Installed in step 2. |

What each program is for:

- **Docker** runs the app's containers: the web server (PHP 8.3 +
  Apache) and the database (MariaDB 11.4). This is the only thing the
  app really needs.
- **Git** downloads the project and keeps it up to date. (You can also
  download a ZIP from GitHub instead, see step 3.)
- **make** is a shortcut runner: `make up` instead of
  `docker compose up -d --build`. Optional; step 9 lists the plain
  commands.

## 2. Install the tools

### Windows 10 and 11

The smoothest way on Windows is to run the project **inside WSL 2**
(a Linux system built into Windows). Docker Desktop uses WSL 2 anyway,
and inside it `make`, `bash` and file paths all behave as on Linux.

1. **Turn on virtualisation** if it is off. Open Task Manager →
   Performance → CPU and look for *Virtualization: Enabled*. If it says
   Disabled, enable *Intel VT-x* / *AMD-V (SVM)* in the BIOS/UEFI setup.
2. **Install WSL 2 with Ubuntu.** Open **PowerShell as Administrator**
   and run:

   ```powershell
   wsl --install -d Ubuntu
   ```

   Restart when asked. Ubuntu opens and asks for a Linux user name and
   password (these are new, not your Windows login).
3. **Install Docker Desktop** from
   <https://www.docker.com/products/docker-desktop/>. During setup keep
   *Use WSL 2 instead of Hyper-V* ticked. After it starts, open
   Settings → Resources → WSL integration and switch **Ubuntu** on.
4. **Install Git and make inside Ubuntu.** Open the *Ubuntu* app from
   the Start menu and run:

   ```bash
   sudo apt update && sudo apt install -y git make
   docker --version && docker compose version   # both must print a version
   ```

Do every following step **in the Ubuntu window**, and keep the project
in your Linux home folder (`~/…`), not under `/mnt/c/…`: files on the
Windows drive are slow to read from containers and can lose their Linux
line endings.

> **Without WSL** (PowerShell only): install Docker Desktop and
> [Git for Windows](https://git-scm.com/download/win), then use the
> plain `docker compose` commands in [step 9](#9-without-make). Git for
> Windows does not include `make`; `winget install ezwinports.make`
> adds it, but `make backup` / `make restore` still expect a Linux shell,
> so WSL is the better choice. The repository's `.gitattributes` keeps
> shell scripts in Linux line endings even when Git converts the rest.

### macOS

1. **Install Apple's command line tools** (gives you `git` and `make`).
   Open Terminal and run:

   ```bash
   xcode-select --install
   ```

2. **Install Docker Desktop** from
   <https://www.docker.com/products/docker-desktop/>: pick *Apple
   Silicon* or *Intel* to match your Mac ( → About This Mac). Open it
   once so it finishes setup. (OrbStack or Colima work too; any of them
   provides the `docker` and `docker compose` commands.)
3. Check:

   ```bash
   git --version && make --version && docker compose version
   ```

With Homebrew you can do the same with
`brew install git && brew install --cask docker`.

### Linux

Use Docker Engine with the Compose plugin (Docker Desktop for Linux also
works). On Ubuntu / Debian:

```bash
sudo apt update && sudo apt install -y git make ca-certificates curl
curl -fsSL https://get.docker.com | sudo sh          # Docker's own installer
sudo usermod -aG docker "$USER"                      # use docker without sudo
newgrp docker                                        # or log out and back in
docker compose version                               # must print v2.x
```

Fedora: `sudo dnf install -y git make` and follow
<https://docs.docker.com/engine/install/fedora/>. Arch:
`sudo pacman -S git make docker docker-compose && sudo systemctl enable --now docker`.
For other distributions see <https://docs.docker.com/engine/install/>.

## 3. Get the project

```bash
git clone https://github.com/eyasir329/profile_system.git
cd profile_system
```

The repository is everything the app needs: the PHP code, the database
schema and migrations, the **built** CSS and JavaScript
(`public/assets/`) and the one PHP library, PHPMailer (`src/vendor/`).
Nothing has to be compiled or downloaded with `npm` or `composer`.

No Git? On GitHub choose **Code → Download ZIP**, unzip it and open a
terminal in that folder. (You then update by downloading again instead
of `git pull`.)

## 4. Configure `.env`

All settings live in one file, `.env`, which is never committed. Create
it from the example:

```bash
cp .env.example .env
```

Then open `.env` in any text editor and **set a real database password
before the first start**:

```ini
DB_PASSWORD=<a long random password>
```

Generate one with `openssl rand -base64 24` (macOS, Linux, WSL). It
matters now because MariaDB stores the root password when the database
is created on the first start; changing `.env` afterwards does not
change it (see [TUNNEL.md → Rotate the DB password](TUNNEL.md#rotate-the-db-password)).

Everything else can stay as it is for running on your own computer. The
settings you might change:

| Setting | Default | Change when |
| --- | --- | --- |
| `WEB_PORT` | `8080` | Port 8080 is taken. The app is then at `http://localhost:<port>`. |
| `APP_DB_USER`, `DB_APP_PASSWORD` | unset (app uses root) | Anything beyond your own computer: the app then connects as a user that can only read and write rows. |
| `APP_TIMEZONE` | `UTC` | Until an admin picks a zone in Admin › Site settings. |
| `MAIL_*` | email off | You want emails. For testing, use Mailpit (step 8). |
| `APP_ENV`, `APP_URL`, `COOKIE_SECURE`, `TRUST_PROXY`, `WEB_BIND` | development values | Before other people reach it over a network. See step 11. |

Leave `DB_NAME=profile_system` and `DB_HOST=profile_db` as they are:
`database/schema.sql` creates the database under that name, and
`profile_db` is the container's name on Docker's internal network.

## 5. Start it

```bash
make up
```

The first time this takes a few minutes: Docker downloads the PHP and
MariaDB images and builds the web image. Later starts take seconds.

What happens, in order (all automatic):

1. The **database** container starts and creates an empty data volume.
2. The **web** container waits until the database answers.
3. Its entrypoint (`docker/web-entrypoint.sh`) creates the database,
   creates the app's database user if one is set, applies
   `database/schema.sql`, then every file in `database/migrations/` that
   has not run yet, in name order.
4. Apache starts and serves the app.

Watch it with `make logs` (Ctrl+C stops watching, not the app). The line
`[entrypoint] Database ready. Starting Apache...` means it is ready.

Then open **<http://localhost:8080>**.

## 6. Create the first account

While the database has no users, `/register.php` lets the first person
sign up without an invitation, and that account becomes the **admin**.
Open <http://localhost:8080/register.php>, create it, and sign in.

Everyone after that needs an invitation link: as admin (or teacher, for
students) open **Invitations**, create a link and send it. To try the
other roles yourself, invite your own second email address, or give
your admin account the Teacher and Student roles (Users → Edit) and
switch between them from the top bar.

Then, as admin, open **Site settings** and pick the time zone.

## 7. Check that it works

```bash
make ps     # db and web are "healthy"
make lint   # PHP syntax check on every file, run inside the container
curl -s -o /dev/null -w '%{http_code}\n' http://localhost:8080/   # 200
```

## 8. Everyday use

```bash
make up          # start (also after a reboot, if Docker isn't set to start on login)
make down        # stop; keeps the data
make logs        # follow the logs
make restart     # restart web; applies new migrations after `git pull`
make backup      # database + uploads to ./backups/<date-time>/
make mail        # Mailpit: catches every email at http://localhost:8025
make tools       # Adminer: database browser at http://localhost:8081 (this computer only)
make help        # every command
```

Containers restart on their own (`restart: unless-stopped`) as long as
Docker itself starts. Docker Desktop: Settings → General → *Start Docker
Desktop when you sign in*. Linux: `sudo systemctl enable docker`.

**Updating** to a newer version of the code:

```bash
make backup
git pull
make up          # rebuilds the image if the Dockerfile or config changed
```

Migrations run on their own when the web container starts.

**Trying email locally**: run `make mail`, set these four lines in
`.env`, then `docker compose up -d web`:

```ini
MAIL_HOST=mailpit
MAIL_PORT=1025
MAIL_ENCRYPTION=none
MAIL_FROM_ADDRESS=noreply@example.test
```

Every email the app sends now appears at <http://localhost:8025> and
none reach real people.

**Changing the CSS or JavaScript** (`resources/`): `make assets` rebuilds
`public/assets/` in a throwaway Node container. You still don't need
Node installed. See [`FRONTEND.md`](FRONTEND.md).

## 9. Without `make`

`make` only calls `docker compose`. These work in any terminal,
including PowerShell, from the project folder:

| Instead of | Run |
| --- | --- |
| `make up` | `docker compose up -d --build` |
| `make down` | `docker compose down` |
| `make logs` | `docker compose logs -f --tail=100` |
| `make restart` | `docker compose restart web` |
| `make ps` | `docker compose ps` |
| `make lint` | `docker compose exec web bash -c 'find public src -name "*.php" -print0 \| xargs -0 -n1 php -l \| grep -v "No syntax errors"'` |
| `make db-shell` | `docker compose exec db mariadb -uroot -p profile_system` (asks for `DB_PASSWORD`) |
| `make purge` | `docker compose exec web php /var/www/src/cli/housekeeping.php` |
| `make mail` | `docker compose --profile tools up -d mailpit` |
| `make tools` | `docker compose --profile tools up -d adminer` |
| `make backup` (database part) | `docker compose exec -T db mariadb-dump -uroot -p<password> --single-transaction profile_system > db.sql` |

On Windows, `copy .env.example .env` replaces `cp`.

## 10. Moving to another computer

The code is in Git; the **data** is in two Docker volumes on this
computer (`profile_system_db` and `profile_system_uploads`). To move a
running installation:

1. On the old computer: `make backup`. This writes
   `backups/<date-time>/db.sql` and `uploads.tgz`.
2. Copy that folder and your `.env` to the new computer (they hold
   personal data and passwords: use a USB stick or an encrypted
   channel, not email or a shared drive). `backups/` and `.env` are
   ignored by Git on purpose.
3. On the new computer: install (steps 2 and 3), put `.env` in the
   project folder, `make up`, then
   `make restore BACKUP=./backups/<date-time>` and type `yes`.

The dump holds the app's tables only, not MariaDB's own users: the new
computer's root password is whatever its `.env` says on the first
start, and the app user is created from `.env` again. Sign-in
passwords, two-factor and everything else the people in the app set are
in the tables and come across unchanged.

## 11. Running it for real users

On your own computer the defaults are fine. Before people reach it over
a network or the internet, set in `.env`:

```ini
APP_ENV=production                      # hides development-only reset links, etc.
APP_URL=https://profiles.example.ac.jp  # links in emails are built from this only
APP_DB_USER=profile_app                 # app connects without schema rights
DB_APP_PASSWORD=<another long random password>
COOKIE_SECURE=1                         # if it is always reached over HTTPS
TRUST_PROXY=1                           # only behind a reverse proxy / tunnel
WEB_BIND=127.0.0.1                      # with TRUST_PROXY: nobody can skip the proxy
```

and set up a real mail server (`MAIL_*`), so password resets and
invitations arrive. Then `make up`.

- **Quick sharing** from your own machine: `make tunnel` (Cloudflare
  quick tunnel). Read [`TUNNEL.md`](TUNNEL.md) first.
- **A permanent server**: any Linux server or VM with Docker. Follow
  steps 2 (Linux) to 6 there, put HTTPS in front (a named Cloudflare
  tunnel, or Caddy / nginx as a reverse proxy to `127.0.0.1:8080`), and
  schedule `make backup` (cron) with the backups copied off the machine.
- For production you can also remove the three source bind mounts under
  `web: volumes:` in `compose.yml`, so the container runs only the code
  baked into the image.
- The operator checklist is in [`SECURITY.md`](SECURITY.md#5-checklist-for-whoever-runs-it).

## 12. Troubleshooting

| Problem | Cause and fix |
| --- | --- |
| `docker: command not found`, or `make` says Docker is missing | Docker isn't installed or its app isn't running. Start Docker Desktop and wait until it says *running*. On WSL, check Settings → Resources → WSL integration. |
| `permission denied … /var/run/docker.sock` (Linux) | Your user isn't in the `docker` group: `sudo usermod -aG docker $USER`, then log out and in. |
| `'docker compose' plugin is missing` | You have the old `docker-compose` v1. Install the plugin: `sudo apt install docker-compose-plugin`, or update Docker Desktop. |
| `Bind for 0.0.0.0:8080 failed: port is already allocated` | Something else uses 8080. Set `WEB_PORT=9000` in `.env` and `make up`; the app is then at `http://localhost:9000`. |
| Web container restarts with `/usr/bin/env: 'bash\r'` or `exec format error … web-entrypoint.sh` | The script got Windows line endings (copied through a Windows folder or a ZIP edited on Windows). Run `git add --renormalize . && git checkout -- docker/web-entrypoint.sh`, or in WSL `sed -i 's/\r$//' docker/web-entrypoint.sh`, then `make rebuild && make up`. |
| `[entrypoint] FATAL: database did not become reachable` / `Access denied for user 'root'` | The database volume was created with a different `DB_PASSWORD` than the one now in `.env`. Put the old password back, or, **if there is no data you need**, `make reset` and start again. |
| Page says *temporarily unavailable* (503) | The app can't reach the database. `make ps` and `make logs`; usually the db container is still starting or the password is wrong (above). |
| Blank page or error page after editing PHP | Errors are logged, not shown: `make logs`. |
| A new migration didn't run | Migrations run when the web container starts: `make restart`, then look for `Applying migration:` in the log. |
| Changes to `docker/apache/app.conf` or `docker/php/app.ini` have no effect | They are baked into the image: `make rebuild && make up`. |
| CSS changes in `resources/` don't show | The browser loads the built file: `make assets`, then reload with Ctrl+F5. |
| Very slow pages on Windows | The project is under `/mnt/c/…` or opened from PowerShell. Clone it inside the WSL home folder (`~/profile_system`). |
| `make backup` fails on Windows outside WSL | Its paths need a Linux shell. Use WSL, or the plain `mariadb-dump` command in step 9. |
| Docker Desktop on macOS/Windows uses a lot of memory | Settings → Resources: 2 GB is enough for this app. |

## 13. Removing it

```bash
make down               # stop
make reset              # ALSO deletes the database and uploads (asks first)
docker image rm profile_system-web mariadb:11.4   # free the disk space
```

(The web image is named after the project folder: `<folder>-web`.
`docker image ls` lists them.)

Then delete the project folder. Take a `make backup` first if the data
matters.
