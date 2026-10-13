-- Migration 017: accounts are created by invitation only.
--
-- `invites` holds one row per invitation link. Only the SHA-256 of the
-- token is stored, so a database leak does not hand out working links.
--   roles       comma-separated roles the new account receives
--   email       optional: only this address may use the link
--   max_uses    how many accounts the link may create (used_count tracks it)
--   expires_at  the link stops working after this moment
--   revoked_at  set when the creator (or an admin) cancels it
--   created_by  the teacher/admin who made it; the link dies with their
--               account or their permission to invite (checked on use)
--
-- `users.invite_id` records which link an account came from.

CREATE TABLE IF NOT EXISTS invites (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    token_hash  CHAR(64) NOT NULL,
    roles       VARCHAR(40) NOT NULL,
    email       VARCHAR(150) NULL,
    note        VARCHAR(150) NULL,
    max_uses    SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    used_count  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    expires_at  DATETIME NOT NULL,
    revoked_at  DATETIME NULL,
    created_by  INT NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_inv_token (token_hash),
    KEY idx_inv_creator (created_by),
    CONSTRAINT fk_inv_creator FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

SET @sql := (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'invite_id') = 0,
  "ALTER TABLE users ADD COLUMN invite_id INT NULL,
     ADD CONSTRAINT fk_users_invite FOREIGN KEY (invite_id) REFERENCES invites (id) ON DELETE SET NULL",
  "SELECT 'users.invite_id already present' AS note"
));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
