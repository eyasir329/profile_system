-- Migration 009: endorsements.
--
-- Lets any user (except the recipient) endorse another user for a
-- specific skill, with an optional comment. UNIQUE on
-- (recipient, endorser, skill) prevents the same endorser from
-- endorsing the same skill twice on the same recipient.


CREATE TABLE IF NOT EXISTS endorsements (
  id INT AUTO_INCREMENT PRIMARY KEY,
  recipient_user_id INT NOT NULL,
  endorser_user_id  INT NOT NULL,
  skill VARCHAR(100) NOT NULL,
  comment VARCHAR(500) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_endorse (recipient_user_id, endorser_user_id, skill),
  INDEX idx_endorse_recipient (recipient_user_id, created_at),
  INDEX idx_endorse_endorser (endorser_user_id),
  CONSTRAINT fk_endorse_recipient FOREIGN KEY (recipient_user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_endorse_endorser  FOREIGN KEY (endorser_user_id)  REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

