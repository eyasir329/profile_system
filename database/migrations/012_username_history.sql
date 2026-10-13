-- Migration 012: username history (aliasing for old links).
--
-- On every username change, the old value is recorded here. `u/index.php`
-- does a fallback lookup against this table and 301-redirects to the
-- canonical URL. This means a user's old public link continues to work
-- forever (or until they remove it).


CREATE TABLE IF NOT EXISTS username_history (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  old_username VARCHAR(40) NOT NULL,
  changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_uh_old (old_username),
  INDEX idx_uh_user (user_id, changed_at),
  CONSTRAINT fk_uh_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

