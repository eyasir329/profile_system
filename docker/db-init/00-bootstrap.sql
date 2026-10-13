-- Database bootstrap for the official mariadb:11 image.
-- Anything in /docker-entrypoint-initdb.d/*.sql is auto-run on the FIRST start
-- of a fresh data volume. On subsequent restarts this directory is ignored,
-- so this is the right place to put the database+user creation.
--
-- The application database itself is created by the web entrypoint (which
-- has to wait for the server to be ready), so we keep this file minimal.

-- Ensure the app database exists with the right charset.
-- (Idempotent. The web entrypoint also does this, but having it here
--  means even a manual `docker exec mariadb ... < something.sql` works.)
CREATE DATABASE IF NOT EXISTS profile_system
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- The application connects as root. If you want a non-root user instead,
-- create one here and set DB_USER / DB_PASSWORD in your .env file:
--
--   CREATE USER IF NOT EXISTS 'profile_app'@'%' IDENTIFIED BY 'change-me';
--   GRANT ALL PRIVILEGES ON profile_system.* TO 'profile_app'@'%';
--   FLUSH PRIVILEGES;
