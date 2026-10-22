# Profile System — single-entry-point dev/ops commands.
#
# The ONLY thing this Makefile does on the host is call `docker compose`.
# Every other action (PHP lint, DB dump, restore, file edits) runs inside
# a container, so you don't need PHP, mariadb-client, or any other
# language toolchain installed on your machine.
#
# Prerequisites:
#   - Docker (Docker Desktop on macOS/Windows; docker.io on Linux)
#   - make (Linux: your package manager; macOS: `xcode-select --install`;
#     Windows: run inside WSL 2, or use the plain `docker compose` commands)
#
# Setting up a new computer from nothing: docs/INSTALL.md.
# Run `make help` to see every target. Run `make up` to start.

# Use bash so we get sane error handling even when invoked from
# /bin/sh on minimal Linux images.
SHELL := /bin/bash

# Default target: the help screen.
.DEFAULT_GOAL := help

# Load .env so $(DB_PASSWORD) etc. are available in targets. `include` is
# the Make-native way; if .env is missing, fall back to empty values.
ifneq (,$(wildcard ./.env))
include .env
export
endif

# ---- helpers ---------------------------------------------------------------

# Print a section banner so the output is skimmable.
define banner
	@printf "\n\033[1;34m▶ $1\033[0m\n"
endef

# Fail fast with a clear message if Docker isn't installed.
.PHONY: require-docker
require-docker:
	@command -v docker >/dev/null 2>&1 || { \
		echo "✖ Docker is not installed or not on PATH."; \
		echo "  Install: https://docs.docker.com/get-docker/"; \
		exit 1; \
	}
	@docker compose version >/dev/null 2>&1 || { \
		echo "✖ 'docker compose' plugin is missing."; \
		echo "  Linux: 'sudo apt-get install docker-compose-plugin' (or your distro's equivalent)."; \
		echo "  macOS/Windows: reinstall Docker Desktop and ensure the v2 plugin is enabled."; \
		exit 1; \
	}

# ---- user-facing targets ---------------------------------------------------

.PHONY: help
help: ## Show this help.
	@printf "\033[1mProfile System — available targets\033[0m\n"
	@printf "Run \033[1mmake <target>\033[0m. All actions run inside Docker; you only need Docker + make on the host.\n\n"
	@awk 'BEGIN {FS = ":.*?## "} /^[a-zA-Z_-]+:.*?## / {printf "  \033[36m%-14s\033[0m %s\n", $$1, $$2}' $(MAKEFILE_LIST)
	@printf "\n"

.PHONY: up
up: require-docker ## Build (if needed) and start the stack in the background.
	$(call banner,Starting the stack)
	docker compose up -d --build
	@printf "\n\033[1;32m✔ Up.\033[0m\n"
	@printf "  Web:     \033[1mhttp://localhost:$${WEB_PORT:-8080}/\033[0m\n"
	@printf "  Adminer: \033[1mmake tools\033[0m, then http://localhost:$${ADMINER_PORT:-8081}/\n\n"

.PHONY: down
down: require-docker ## Stop the stack (keeps data volumes).
	$(call banner,Stopping the stack)
	docker compose down
	@printf "\n\033[1;32m✔ Down (data volumes preserved).\033[0m\n"
	@printf "  Restart with: make up\n\n"

.PHONY: restart
restart: require-docker ## Restart the web service (re-applies any new migrations).
	$(call banner,Restarting web)
	docker compose restart web
	@docker compose logs --tail=20 web

.PHONY: logs
logs: require-docker ## Tail logs from all services (Ctrl+C to stop).
	docker compose logs -f --tail=100

.PHONY: ps
ps: require-docker ## Show container status.
	docker compose ps

.PHONY: rebuild
rebuild: require-docker ## Force a clean rebuild of the web image (no cache).
	$(call banner,Rebuilding web image)
	docker compose build --no-cache web

# ---- shell access ----------------------------------------------------------
# Both of these run the command inside a container, so the host shell
# doesn't need any of the dev tools.

.PHONY: shell
shell: require-docker ## Open a bash shell in the running web container.
	docker compose exec web bash

.PHONY: db-shell
db-shell: require-docker ## Open a mariadb shell against the db container.
	docker compose exec db mariadb -uroot -p"$${DB_PASSWORD:-rootpass}" $${DB_NAME:-profile_system}

.PHONY: root-shell
root-shell: require-docker ## Open a bash shell in the web container as root.
	docker compose exec -u root web bash

# ---- code quality ----------------------------------------------------------

.PHONY: lint
lint: require-docker ## Run `php -l` over every .php file (no host PHP needed).
	$(call banner,Linting PHP)
	@docker compose exec -T web bash -c 'set -e; \
		find public src -name "*.php" -print0 | xargs -0 -n1 php -l | (grep -v "No syntax errors" || true)'

# ---- front-end assets ------------------------------------------------------
# Tailwind CSS + Alpine.js are compiled from resources/ into public/assets/.
# The built files are committed, so you only need these targets after
# editing resources/ or adding new Tailwind classes to a .php file.
# They run in a throwaway Node container: no Node needed on the host.
# (With Node 20+ installed you can run `npm ci && npm run build` directly.)
NODE_IMAGE ?= node:22-alpine
NODE_RUN = docker run --rm -u "$$(id -u):$$(id -g)" -e HOME=/tmp -v "$(CURDIR)":/app -w /app $(NODE_IMAGE)

.PHONY: assets
assets: require-docker ## Build CSS + JS from resources/ into public/assets/ (minified).
	$(call banner,Building front-end assets)
	@$(NODE_RUN) sh -c 'npm ci --no-audit --no-fund && npm run build'

.PHONY: assets-watch
assets-watch: require-docker ## Rebuild CSS + JS on every change (Ctrl+C to stop).
	$(call banner,Watching resources/ public/ src/)
	@docker run --rm -it -u "$$(id -u):$$(id -g)" -e HOME=/tmp -v "$(CURDIR)":/app -w /app $(NODE_IMAGE) sh -c 'npm ci --no-audit --no-fund && npm run dev'

# ---- backup / restore ------------------------------------------------------
# A throwaway `backup` container (image: mariadb:11 — already in use by db)
# runs mariadb-dump. The result lands in ./backups on the host.
# For uploads, we mount the named volume and tar it inside the same
# one-shot container.

.PHONY: backup
backup: require-docker ## Snapshot DB + uploads to ./backups/YYYYMMDD-HHMMSS/.
	$(call banner,Backing up)
	@STAMP=$$(date +%Y%m%d-%H%M%S); \
	DIR=./backups/$$STAMP; \
	mkdir -p "$$DIR"; \
	echo "▶ Dumping database to $$DIR/db.sql ..."; \
	docker compose exec -T db mariadb-dump \
		-uroot -p"$${DB_PASSWORD:-rootpass}" \
		--single-transaction --quick --routines --triggers \
		"$${DB_NAME:-profile_system}" > "$$DIR/db.sql"; \
	echo "▶ Archiving uploads volume to $$DIR/uploads.tgz ..."; \
	docker run --rm \
		-v profile_system_uploads:/source:ro \
		-v "$$(pwd)/$$DIR":/backup \
		alpine:3.20 sh -c "tar czf /backup/uploads.tgz -C /source ."; \
	printf "\n\033[1;32m✔ Backup at $$DIR\033[0m\n\n"

.PHONY: restore
restore: require-docker ## Restore from a backup dir: `make restore BACKUP=./backups/20251011-220000`.
	@if [ -z "$(BACKUP)" ]; then \
		echo "✖ Usage: make restore BACKUP=./backups/20251011-220000"; \
		exit 1; \
	fi
	@if [ ! -d "$(BACKUP)" ]; then \
		echo "✖ Backup dir not found: $(BACKUP)"; \
		exit 1; \
	fi
	@printf "\n\033[1;33m⚠ This will REPLACE the live database and uploads.\033[0m\n"
	@read -p "Type 'yes' to continue: " ans && [ "$$ans" = "yes" ] || { echo "Aborted."; exit 1; }
	$(call banner,Restoring)
	@echo "▶ Stopping web so it doesn't write during restore ..."
	@docker compose stop web
	@echo "▶ Replacing database ..."
	@docker compose exec -T db mariadb -uroot -p"$${DB_PASSWORD:-rootpass}" \
		-e "DROP DATABASE IF EXISTS \`$${DB_NAME:-profile_system}\`; CREATE DATABASE \`$${DB_NAME:-profile_system}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"
	@docker compose exec -T db mariadb -uroot -p"$${DB_PASSWORD:-rootpass}" "$${DB_NAME:-profile_system}" < "$(BACKUP)/db.sql"
	@if [ -f "$(BACKUP)/uploads.tgz" ]; then \
		echo "▶ Replacing uploads volume ..."; \
		docker run --rm \
			-v profile_system_uploads:/target \
			-v "$$(pwd)/$(BACKUP)":/backup:ro \
			alpine:3.20 sh -c "rm -rf /target/* /target/.[!.]* 2>/dev/null || true; tar xzf /backup/uploads.tgz -C /target"; \
	else \
		echo "▶ No uploads.tgz in $(BACKUP), skipping uploads restore."; \
	fi
	@docker compose start web
	@printf "\n\033[1;32m✔ Restore complete.\033[0m\n\n"

# ---- reset (DESTRUCTIVE) ---------------------------------------------------
# Wipes both named volumes. Prompted to prevent accidents.

.PHONY: reset
reset: require-docker ## Nuke DB + uploads (DESTRUCTIVE). Pass CONFIRM=yes to skip the prompt.
	@if [ "$(CONFIRM)" != "yes" ]; then \
		printf "\033[1;31m⚠ This will DELETE the database and uploaded photos.\033[0m\n"; \
		read -p "Type 'yes' to continue: " ans && [ "$$ans" = "yes" ] || { echo "Aborted."; exit 1; }; \
	fi
	$(call banner,Resetting)
	docker compose down -v
	@docker volume rm profile_system_db profile_system_uploads 2>/dev/null || true
	@printf "\n\033[1;32m✔ Volumes removed. Run 'make up' to start fresh.\033[0m\n\n"

# ---- maintenance -----------------------------------------------------------

.PHONY: prune
prune: require-docker ## Remove dangling Docker images, networks, and build cache.
	docker system prune -f

# ---- dev-only tools (Adminer, etc.) ----------------------------------------
# Adminer is on the "tools" Compose profile so it doesn't start with
# `make up`. Bring it up locally with `make tools` when you need a
# browser-based DB GUI; bring it back down with `make tools-down`.

.PHONY: tools
tools: require-docker ## Start the dev-only services (Adminer) on the "tools" profile.
	$(call banner,Starting dev tools)
	docker compose --profile tools up -d adminer
	@printf "\n\033[1;32m✔ Adminer:\033[0m \033[1mhttp://localhost:$${ADMINER_PORT:-8081}/\033[0m\n\n"

.PHONY: purge
purge: require-docker ## Delete deactivated accounts past their 30 days and old sign-in records now (also runs on its own every 6 h).
	$(call banner,Housekeeping)
	docker compose exec -T web php /var/www/src/cli/housekeeping.php

.PHONY: mail
mail: require-docker ## Start Mailpit, a fake mail server that catches every email (inbox: http://localhost:8025).
	$(call banner,Starting Mailpit)
	docker compose --profile tools up -d mailpit
	@printf "\n\033[1;32m✔ Mailpit inbox:\033[0m \033[1mhttp://localhost:$${MAILPIT_PORT:-8025}/\033[0m\n"
	@printf "  In .env: MAIL_HOST=mailpit MAIL_PORT=1025 MAIL_ENCRYPTION=none MAIL_FROM_ADDRESS=noreply@example.test\n"
	@printf "  then: docker compose up -d web\n\n"

.PHONY: tools-down
tools-down: require-docker ## Stop the dev-only services.
	$(call banner,Stopping dev tools)
	docker compose --profile tools stop adminer mailpit
	docker compose --profile tools rm -f adminer

# ---- Cloudflare tunnel -----------------------------------------------------
# Expose the local app at https://<random>.trycloudflare.com so others
# can reach it. The quick tunnel URL is random and only lives as long
# as `make tunnel` is running. The README and docs/TUNNEL.md cover the
# security caveats.

CLOUDFLARED_LOG := ./cloudflared.log
CLOUDFLARED_PID := ./cloudflared.pid

.PHONY: tunnel
tunnel: ## Start a Cloudflare quick tunnel to the local web app (Ctrl+C to stop).
	@command -v cloudflared >/dev/null 2>&1 || { \
		echo "✖ cloudflared is not installed."; \
		echo "  Install: https://developers.cloudflare.com/cloudflare-one/connections/connect-networks/downloads/"; \
		exit 1; \
	}
	@if [ -f "$(CLOUDFLARED_PID)" ] && kill -0 $$(cat "$(CLOUDFLARED_PID)") 2>/dev/null; then \
		echo "✖ A tunnel is already running (pid $$(cat $(CLOUDFLARED_PID))). Run 'make tunnel-stop' first."; \
		exit 1; \
	fi
	$(call banner,Starting Cloudflare quick tunnel)
	@nohup cloudflared tunnel --url http://localhost:$${WEB_PORT:-8080} --no-autoupdate \
		> $(CLOUDFLARED_LOG) 2>&1 & echo $$! > $(CLOUDFLARED_PID)
	@echo "▶ Tunnel PID: $$(cat $(CLOUDFLARED_PID))"
	@echo "▶ Waiting for the URL to be assigned (up to ~10s)..."
	@URL=$$(bash -c 'LOG="$$1"; for i in $$(seq 1 20); do u=$$(grep -oE "https://[a-z0-9-]+\.trycloudflare\.com" "$$LOG" 2>/dev/null | head -n1); if [ -n "$$u" ]; then echo "$$u"; exit 0; fi; sleep 0.5; done; exit 1' _ $(CLOUDFLARED_LOG)); \
	if [ -n "$$URL" ]; then \
		printf "\n\033[1;32m✔ Public URL: \033[1m$$URL\033[0m\n"; \
		printf "✔ This URL stays alive until you run \033[1mmake tunnel-stop\033[0m or reboot the machine.\n"; \
		printf "✔ Forgot the URL? Run \033[1mmake tunnel-url\033[0m to print it again.\n\n"; \
		printf '# %s\n' "$$URL" >> $(CLOUDFLARED_LOG); \
	else \
		echo "✖ Did not see a trycloudflare.com URL in the log. Tail $(CLOUDFLARED_LOG) for details."; \
		exit 1; \
	fi

.PHONY: tunnel-url
tunnel-url: ## Print the public URL of the running tunnel (or "none" if not running).
	@if [ ! -f "$(CLOUDFLARED_LOG)" ]; then \
		echo "✖ No cloudflared.log found — the tunnel has never been started."; \
		echo "  Run: make tunnel"; \
		exit 1; \
	fi
	@if [ ! -f "$(CLOUDFLARED_PID)" ] || ! kill -0 $$(cat $(CLOUDFLARED_PID)) 2>/dev/null; then \
		echo "✖ Tunnel is NOT running (stale log or crashed). Start it with: make tunnel"; \
		exit 1; \
	fi
	@URL=$$(grep -oE 'https://[a-z0-9-]+\.trycloudflare\.com' $(CLOUDFLARED_LOG) 2>/dev/null | head -n1); \
	if [ -z "$$URL" ]; then \
		echo "✖ Tunnel is starting up. Try again in a few seconds, or tail $(CLOUDFLARED_LOG)."; \
		exit 1; \
	else \
		echo "$$URL"; \
	fi

.PHONY: tunnel-stop
tunnel-stop: ## Stop the Cloudflare quick tunnel started by `make tunnel`.
	@if [ ! -f "$(CLOUDFLARED_PID)" ]; then \
		echo "No tunnel pid file at $(CLOUDFLARED_PID). Nothing to stop."; \
		exit 0; \
	fi
	@pid=$$(cat $(CLOUDFLARED_PID)); \
	if kill -0 $$pid 2>/dev/null; then \
		echo "▶ Stopping cloudflared (pid $$pid)..."; \
		kill $$pid; sleep 1; kill -9 $$pid 2>/dev/null || true; \
	else \
		echo "▶ No live process for pid $$pid."; \
	fi
	@rm -f $(CLOUDFLARED_PID)
	@printf "\n\033[1;32m✔ Tunnel stopped.\033[0m\n\n"

.PHONY: tunnel-restart
tunnel-restart: tunnel-stop tunnel ## Restart the tunnel. The URL WILL change.
