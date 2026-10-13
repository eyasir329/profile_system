-- Migration 010: two-factor authentication (TOTP).
--
-- Adds a `two_factor` table for per-user TOTP secret + backup codes.
-- Adds `users.totp_required` so we can detect the requirement at login
-- without a join. The actual TOTP verification happens via the new
-- /login_totp.php endpoint after password authentication.


-- Per-user TOTP secret + backup codes. One row per user.
CREATE TABLE IF NOT EXISTS two_factor (
  user_id INT PRIMARY KEY,
  secret VARCHAR(32) NOT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 0,
  backup_codes TEXT NULL,
  last_used_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_tf_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Master switch: is 2FA required at login for this user? Set by the
-- security panel when the user enables 2FA.
SET @sql := (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'totp_required') = 0,
  "ALTER TABLE users ADD COLUMN totp_required TINYINT(1) NOT NULL DEFAULT 0 AFTER degree_type",
  "SELECT 'users.totp_required already present' AS note"
));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

