-- 032: sign out other sessions when it matters.
--
-- users.session_epoch is copied into the session at sign-in. Every request
-- compares the two (require_login() in src/includes/auth.php), so raising
-- the number ends every existing session of that account at once. It is
-- raised when the password is changed or reset (by the owner or an admin)
-- and when an admin resets two-factor.
--
-- Idempotent.

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS session_epoch INT UNSIGNED NOT NULL DEFAULT 0 AFTER totp_required;
