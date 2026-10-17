-- 024: publications and awards, one row per item.
--
-- The second lab workbook has four sheets. Each sheet becomes a repeating
-- section on the student's profile and a tab on the teachers' Students
-- page (see src/includes/lab_entries.php):
--   PublishedJournals       lab_publications  kind = 'journal'
--   Conf.Proceedings        lab_publications  kind = 'proceedings'
--   Conf.PresentationOnly   lab_publications  kind = 'presentation'
--   Awards                  lab_awards
-- Columns follow the workbook's: publication month (shown "yyyy/mm"),
-- type of publication, citation, BibTeX, impact factor; and recipients,
-- award name, organization/conference, full information, date.
--
-- Additive. Idempotent.

CREATE TABLE IF NOT EXISTS lab_publications (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    user_id       INT NOT NULL,
    kind          ENUM('journal','proceedings','presentation') NOT NULL,
    pub_month     CHAR(7) NULL,
    pub_type      ENUM('Journal','Conference (published)','Conference (presentation)') NOT NULL,
    citation      TEXT NOT NULL,
    bibtex        TEXT NULL,
    impact_factor VARCHAR(10) NULL,
    sort_order    INT NOT NULL DEFAULT 0,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_lab_publications_user (user_id, kind, sort_order),
    CONSTRAINT fk_lab_publication_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS lab_awards (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    user_id      INT NOT NULL,
    recipients   VARCHAR(500) NULL,
    award_name   VARCHAR(300) NOT NULL,
    organization VARCHAR(300) NULL,
    details      TEXT NULL,
    award_date   DATE NULL,
    sort_order   INT NOT NULL DEFAULT 0,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_lab_awards_user (user_id, sort_order),
    CONSTRAINT fk_lab_award_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;
