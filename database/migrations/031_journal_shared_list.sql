-- 031: potential journals are one shared list everyone edits.
--
-- Students now add, edit and delete journals like teachers, so the
-- "take a journal" mechanism is gone. "Submitted by" and "Published"
-- become plain text columns typed into the journal's form, like
-- "Suggested by".
--
-- Existing takes are folded into the text first: every student who took a
-- journal goes into submitted_by, and those who marked it published into
-- published (English name, else account name, comma-separated, in the
-- order they took it). Then lab_journal_takers is dropped.
--
-- Idempotent: the fold only runs while lab_journal_takers exists.

ALTER TABLE lab_journals
    ADD COLUMN IF NOT EXISTS submitted_by VARCHAR(300) NULL AFTER suggested_by,
    ADD COLUMN IF NOT EXISTS published    VARCHAR(300) NULL AFTER submitted_by;

SET @t := (SELECT COUNT(*) FROM information_schema.TABLES
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lab_journal_takers');
SET @sql := IF(@t > 0,
  "UPDATE lab_journals j
     JOIN (SELECT t.journal_id,
                  GROUP_CONCAT(COALESCE(NULLIF(TRIM(p.name_en), ''), u.name) ORDER BY t.taken_at SEPARATOR ', ') AS sub,
                  GROUP_CONCAT(IF(t.published = 1, COALESCE(NULLIF(TRIM(p.name_en), ''), u.name), NULL) ORDER BY t.taken_at SEPARATOR ', ') AS pub
             FROM lab_journal_takers t
             JOIN users u ON u.id = t.user_id
             LEFT JOIN profiles p ON p.user_id = u.id
            GROUP BY t.journal_id) x ON x.journal_id = j.id
      SET j.submitted_by = COALESCE(j.submitted_by, LEFT(x.sub, 300)),
          j.published    = COALESCE(j.published, LEFT(x.pub, 300))",
  "SELECT 1");
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

DROP TABLE IF EXISTS lab_journal_takers;
