#!/bin/bash
# Container entrypoint for the web service.
#
# Responsibilities (in order):
#   1. Wait until the database is reachable.
#   2. Ensure the database exists (CREATE DATABASE IF NOT EXISTS).
#   3. Apply the base schema (database/schema.sql) - idempotent.
#   4. Apply any migrations in /var/www/database/migrations/*.sql that haven't
#      been applied yet. We track applied migrations in a `schema_migrations`
#      table inside the application database.
#   5. Hand off to the original container command (apache2-foreground).
#
# This means: on a fresh `docker compose up`, you get a fully-initialized
# database with no manual SQL steps.
#
# The script is idempotent: every step uses IF NOT EXISTS / safe ALTER and
# tracks applied migrations, so re-running is a no-op.

set -uo pipefail

# Where the app lives in the image.
APP_DIR="${APP_DIR:-/var/www/html}"

# Schema and migrations live in /var/www/database (not under the doc root).
# These can be overridden by env vars for unusual deployments; the defaults
# match the standard build.
SCHEMA_SQL="${SCHEMA_SQL:-/var/www/database/schema.sql}"
MIG_DIR="${MIGRATIONS_DIR:-/var/www/database/migrations}"

# DB connection params - same env vars the app uses.
DB_HOST="${DB_HOST:-profile_db}"
DB_PORT="${DB_PORT:-3306}"
DB_USER="${DB_USER:-root}"
DB_PASSWORD="${DB_PASSWORD:-rootpass}"
DB_NAME="${DB_NAME:-profile_system}"

# Path to the mariadb client. The official php:apache image is Debian-based
# and the Dockerfile installs mariadb-client. Fall back to "mysql" if
# mariadb is missing.
MARIADB_CMD=""
if command -v mariadb >/dev/null 2>&1; then
    MARIADB_CMD="$(command -v mariadb)"
elif command -v mysql >/dev/null 2>&1; then
    MARIADB_CMD="$(command -v mysql)"
fi

if [ -z "$MARIADB_CMD" ]; then
    echo "[entrypoint] FATAL: neither 'mariadb' nor 'mysql' client found in image." >&2
    exit 1
fi
echo "[entrypoint] Using DB client: ${MARIADB_CMD}"

# Helper: run a one-off SQL statement. -N -B for clean output.
db_exec() {
    local _sql="$1"
    "${MARIADB_CMD}" \
        --host="${DB_HOST}" \
        --port="${DB_PORT}" \
        --user="${DB_USER}" \
        --password="${DB_PASSWORD}" \
        -N -B \
        -e "${_sql}"
}

echo "[entrypoint] Waiting for database at ${DB_HOST}:${DB_PORT}..."

# Wait for the DB to be reachable. Each attempt uses a 1s timeout so we
# don't block forever if the DB is misconfigured.
attempt=0
max_attempts=60
while [ $attempt -lt $max_attempts ]; do
    if db_exec "SELECT 1" >/dev/null 2>&1; then
        echo "[entrypoint] Database is up."
        break
    fi
    attempt=$((attempt + 1))
    # Don't spam the log on the very first attempts.
    if [ $((attempt % 5)) -eq 0 ]; then
        echo "[entrypoint] Still waiting for database (attempt ${attempt}/${max_attempts})..."
    fi
    sleep 1
done

if [ $attempt -eq $max_attempts ]; then
    echo "[entrypoint] FATAL: database did not become reachable in ${max_attempts}s." >&2
    exit 1
fi

# Make sure the target database exists.
db_exec "CREATE DATABASE IF NOT EXISTS \`${DB_NAME}\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;"

# If the operator wants the application to connect as a dedicated
# non-root user (recommended for any non-local deployment), create it
# here. APP_DB_USER and DB_APP_PASSWORD are passed through from the
# `web` service's environment in compose.yml. The block is skipped
# when the app user is unset OR matches the connection user we are
# already using (so single-user dev setups still work).
APP_DB_USER="${APP_DB_USER:-${DB_USER}}"
DB_APP_PASSWORD="${DB_APP_PASSWORD:-${DB_PASSWORD}}"
if [ -n "${APP_DB_USER}" ] && [ "${APP_DB_USER}" != "${DB_USER}" ]; then
    echo "[entrypoint] Ensuring application user '${APP_DB_USER}' exists on '${DB_NAME}'..."
    # CREATE USER IF NOT EXISTS is MariaDB 10.0.2+; safe on 11.x.
    db_exec "CREATE USER IF NOT EXISTS '${APP_DB_USER}'@'%' IDENTIFIED BY '${DB_APP_PASSWORD}';"
    db_exec "GRANT ALL PRIVILEGES ON \`${DB_NAME}\`.* TO '${APP_DB_USER}'@'%';"
    db_exec "FLUSH PRIVILEGES;"
    echo "[entrypoint] Application user ready."
fi

# Apply the base schema (idempotent - uses CREATE TABLE IF NOT EXISTS).
if [ -f "${SCHEMA_SQL}" ]; then
    echo "[entrypoint] Applying base schema ($(basename "${SCHEMA_SQL}"))..."
    "${MARIADB_CMD}" \
        --host="${DB_HOST}" \
        --port="${DB_PORT}" \
        --user="${DB_USER}" \
        --password="${DB_PASSWORD}" \
        < "${SCHEMA_SQL}" || {
        echo "[entrypoint] FATAL: $(basename "${SCHEMA_SQL}") failed to apply." >&2
        exit 1
    }
fi

# Migrations table: track which migration files have been applied.
db_exec "CREATE TABLE IF NOT EXISTS \`${DB_NAME}\`.schema_migrations (
    filename VARCHAR(255) NOT NULL PRIMARY KEY,
    applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);"

# Apply any migrations/*.sql that haven't been applied yet, in name order.
if [ -d "$MIG_DIR" ]; then
    for mig_file in $(ls "$MIG_DIR"/*.sql 2>/dev/null | sort); do
        mig_name="$(basename "$mig_file")"
        already="$(db_exec "SELECT COUNT(*) FROM \`${DB_NAME}\`.schema_migrations WHERE filename='${mig_name}';")"
        if [ "$already" = "0" ]; then
            echo "[entrypoint] Applying migration: ${mig_name}..."
            "${MARIADB_CMD}" \
                --database="${DB_NAME}" \
                --host="${DB_HOST}" \
                --port="${DB_PORT}" \
                --user="${DB_USER}" \
                --password="${DB_PASSWORD}" \
                < "$mig_file" || {
                echo "[entrypoint] FATAL: migration ${mig_name} failed." >&2
                exit 1
            }
            db_exec "INSERT INTO \`${DB_NAME}\`.schema_migrations (filename) VALUES ('${mig_name}');"
        else
            echo "[entrypoint] Migration already applied: ${mig_name}"
        fi
    done
fi

# Make sure uploads dir is writable by the web server. APP_DIR is the
# Apache doc root (/var/www/html), so this lands at /var/www/html/uploads/profiles.
mkdir -p "${APP_DIR}/uploads/profiles"
chown -R www-data:www-data "${APP_DIR}/uploads" 2>/dev/null || true

echo "[entrypoint] Database ready. Starting Apache..."

# exec so Apache becomes PID 1 and gets signals.
exec "$@"
