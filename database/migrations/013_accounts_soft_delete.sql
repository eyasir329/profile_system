-- Migration 013: soft delete with 30-day restore window.
--
-- Adds `deleted_at` and `scheduled_purge_at` to `users`. When the user
-- clicks "Delete account" in /settings.php?tab=danger, we set
-- deleted_at = NOW() and scheduled_purge_at = NOW() + 30 days. A daily
-- cron hard-deletes rows where scheduled_purge_at < NOW().
--
-- `require_login()` now checks `deleted_at IS NULL` and redirects to
-- /login.php?deactivated=1 with a banner explaining the situation and
-- a link to /restore_account.php?token=... (the token was emailed at
-- deactivation time and stored in `account_restorations`).


-- Soft-delete columns.
SET @sql := (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'deleted_at') = 0,
  "ALTER TABLE users ADD COLUMN deleted_at DATETIME NULL AFTER totp_required",
  "SELECT 'users.deleted_at already present' AS note"
));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'scheduled_purge_at') = 0,
  "ALTER TABLE users ADD COLUMN scheduled_purge_at DATETIME NULL AFTER deleted_at",
  "SELECT 'users.scheduled_purge_at already present' AS note"
));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Account restoration tokens.
CREATE TABLE IF NOT EXISTS account_restorations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_ar_token (token_hash),
  INDEX idx_ar_user (user_id),
  CONSTRAINT fk_ar_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

