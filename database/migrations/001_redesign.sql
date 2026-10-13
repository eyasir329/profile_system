-- Redesign migration: add username, password reset table, activity log.
-- Safe to run once against an existing database.

START TRANSACTION;

-- 1) Add username column, backfill from name, make NOT NULL.
ALTER TABLE users ADD COLUMN username VARCHAR(40) NULL UNIQUE AFTER name;

UPDATE users
SET username = LOWER(
  REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(name, ' ', '-'), '.', ''), '_', '-'), '@', '-'), '!', ''), '?', '')
)
WHERE username IS NULL;

-- Resolve any collisions by appending the user id.
UPDATE users u
JOIN (
  SELECT id, username,
         ROW_NUMBER() OVER (PARTITION BY username ORDER BY id) AS rn,
         COUNT(*) OVER (PARTITION BY username) AS cnt
  FROM users
) x ON x.id = u.id
SET u.username = CONCAT(u.username, '-', u.id)
WHERE x.cnt > 1 AND x.rn > 1;

ALTER TABLE users MODIFY COLUMN username VARCHAR(40) NOT NULL;

-- 2) Password reset tokens.
CREATE TABLE IF NOT EXISTS password_resets (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_pr_user (user_id),
  INDEX idx_pr_token (token_hash),
  CONSTRAINT fk_pr_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- 3) Activity log.
CREATE TABLE IF NOT EXISTS activity (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NULL,
  kind VARCHAR(40) NOT NULL,
  detail VARCHAR(255) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_act_user (user_id, created_at),
  INDEX idx_act_kind (kind, created_at)
) ENGINE=InnoDB;

COMMIT;
