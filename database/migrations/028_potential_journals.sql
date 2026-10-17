-- 028: potential journals, listed by teachers, taken by students.
--
-- Teachers keep a list of journals worth submitting to on /resources.php
-- (tab "Potential journals"). Every student sees it and can take a
-- journal (say they intend to submit there) or release it. Several
-- students can take the same journal.
--   lab_journals          the list (teachers add / edit / delete)
--   lab_journal_takers    who took which journal, and when
--
-- Additive. Idempotent.

CREATE TABLE IF NOT EXISTS lab_journals (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(300) NOT NULL,
    publisher     VARCHAR(200) NULL,
    link          VARCHAR(1000) NULL,
    impact_factor VARCHAR(10) NULL,
    notes         TEXT NULL,
    sort_order    INT NOT NULL DEFAULT 0,
    created_by    INT NULL,
    updated_by    INT NULL,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_lab_journal_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_lab_journal_editor  FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS lab_journal_takers (
    journal_id INT NOT NULL,
    user_id    INT NOT NULL,
    taken_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (journal_id, user_id),
    INDEX idx_lab_journal_takers_user (user_id),
    CONSTRAINT fk_lab_journal_taker_journal FOREIGN KEY (journal_id) REFERENCES lab_journals(id) ON DELETE CASCADE,
    CONSTRAINT fk_lab_journal_taker_user    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
