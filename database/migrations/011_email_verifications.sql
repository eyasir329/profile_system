-- Migration 011: email verification.
--
-- Stores pending email change verifications. `new_email` is the address
-- the user wants to switch to; the link in the confirmation email
-- points to /verify_email.php?token=...; only when consumed does the
-- change to `users.email` take effect. `users.email_verified` is 1
-- once verified; new registrations set it to 1 by default (we don't
-- require email verification to use the site in dev / smoke test).


CREATE TABLE IF NOT EXISTS email_verifications (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  new_email VARCHAR(150) NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_ev_token (token_hash),
  INDEX idx_ev_user (user_id),
  CONSTRAINT fk_ev_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

SET @sql := (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'email_verified') = 0,
  "ALTER TABLE users ADD COLUMN email_verified TINYINT(1) NOT NULL DEFAULT 1 AFTER email",
  "SELECT 'users.email_verified already present' AS note"
));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

