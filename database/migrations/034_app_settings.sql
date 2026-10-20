-- 034: settings an admin changes in the app (not in .env).
--
-- One row per setting; a missing row means the default. Used by
-- Admin › Emails › Settings (see mail_setting() in src/includes/mailer.php):
--   mail.enabled          '1' / '0'   master switch for every email
--   mail.type.<kind>      '1' / '0'   one kind of email (invitation, password_reset, ...)
--   mail.log_days         '0' = keep the email log forever, else days to keep it
--
-- Idempotent.

CREATE TABLE IF NOT EXISTS app_settings (
    name        VARCHAR(64)  NOT NULL PRIMARY KEY,
    value       VARCHAR(255) NOT NULL,
    updated_by  INT NULL,
    updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_settings_user FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
