-- 029: a potential journal is one block of text.
--
-- Teachers write everything about a journal (name, publisher, impact
-- factor, link, scope, deadline...) in a single text field. The first line
-- is shown as its title. Any existing rows are folded into that text.
--
-- Idempotent: the fold and the drops only run while the old columns exist.

ALTER TABLE lab_journals ADD COLUMN IF NOT EXISTS details TEXT NULL AFTER id;

SET @old := (SELECT COUNT(*) FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lab_journals' AND COLUMN_NAME = 'name');
SET @sql := IF(@old > 0,
  "UPDATE lab_journals SET details = CONCAT_WS('\\n', name,
       NULLIF(CONCAT_WS(' · ', publisher, IF(impact_factor IS NULL, NULL, CONCAT('IF ', impact_factor))), ''),
       link, notes)
    WHERE details IS NULL",
  "SELECT 1");
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

ALTER TABLE lab_journals
    DROP COLUMN IF EXISTS name,
    DROP COLUMN IF EXISTS publisher,
    DROP COLUMN IF EXISTS link,
    DROP COLUMN IF EXISTS impact_factor,
    DROP COLUMN IF EXISTS notes;

ALTER TABLE lab_journals MODIFY details TEXT NOT NULL;
