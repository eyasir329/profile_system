-- 030: potential journals get the lab sheet's columns.
--
-- Journal name | link | IF | CS | APC | Suggested by | Submitted by | Published
--   name, link, impact_factor (IF), cite_score (CS), apc, suggested_by
--       are columns of lab_journals (suggested_by is free text; the form
--       fills in the person adding it).
--   Submitted by  = the students who took the journal (lab_journal_takers).
--   Published     = lab_journal_takers.published: a student marks their own
--                   submission as published.
-- This replaces the single `details` text of migration 029. Existing rows
-- keep their first line as the name, the first web address in the text as
-- the link, and the person who added them as "suggested by". Anything else
-- in the old text is not carried over.
--
-- Idempotent: the fold and the drop only run while `details` exists.

ALTER TABLE lab_journals
    ADD COLUMN IF NOT EXISTS name          VARCHAR(300)  NULL AFTER id,
    ADD COLUMN IF NOT EXISTS link          VARCHAR(1000) NULL AFTER name,
    ADD COLUMN IF NOT EXISTS impact_factor VARCHAR(10)   NULL AFTER link,
    ADD COLUMN IF NOT EXISTS cite_score    VARCHAR(10)   NULL AFTER impact_factor,
    ADD COLUMN IF NOT EXISTS apc           VARCHAR(100)  NULL AFTER cite_score,
    ADD COLUMN IF NOT EXISTS suggested_by  VARCHAR(100)  NULL AFTER apc;

SET @old := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lab_journals' AND COLUMN_NAME = 'details');
SET @sql := IF(@old > 0,
  "UPDATE lab_journals j LEFT JOIN users u ON u.id = j.created_by
      SET j.name         = COALESCE(NULLIF(LEFT(TRIM(SUBSTRING_INDEX(j.details, '\\n', 1)), 300), ''), '(untitled)'),
          j.link         = NULLIF(LEFT(REGEXP_SUBSTR(j.details, 'https?://[^[:space:]]+'), 1000), ''),
          j.suggested_by = LEFT(u.name, 100)
    WHERE j.name IS NULL",
  "SELECT 1");
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

ALTER TABLE lab_journals DROP COLUMN IF EXISTS details;
ALTER TABLE lab_journals MODIFY name VARCHAR(300) NOT NULL;

ALTER TABLE lab_journal_takers
    ADD COLUMN IF NOT EXISTS published TINYINT(1) NOT NULL DEFAULT 0 AFTER user_id;
