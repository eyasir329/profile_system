-- 023: completed theses and papers as repeating entries.
--
-- The workbook columns "Completed Thesis Titles [Level: Title]",
-- "Journal Papers {published}({submitted})" and "Conference Papers
-- {published}({submitted})" can hold several items. Each item is now one
-- row here:
--   thesis      level + title            -> "[Level: Title]"
--   journal     title + status           -> counted in "{published}(submitted)"
--   conference  title + status
-- profiles.completed_thesis / journal_papers / conference_papers stay as
-- the text the Students sheet shows; /profile.php rewrites them from these
-- rows whenever an entry changes.
--
-- Existing thesis text ("[Level: Title]" per line) becomes rows. Paper
-- counts such as "{1}(1)" name no papers, so they stay as text until the
-- person adds their papers.
--
-- Additive. Idempotent.

CREATE TABLE IF NOT EXISTS lab_entries (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    user_id    INT NOT NULL,
    kind       ENUM('thesis','journal','conference') NOT NULL,
    level      VARCHAR(50) NULL,
    status     ENUM('published','submitted') NULL,
    title      VARCHAR(500) NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_lab_entries_user (user_id, kind, sort_order),
    CONSTRAINT fk_lab_entry_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB;

INSERT INTO lab_entries (user_id, kind, level, title, sort_order)
WITH RECURSIVE split AS (
    SELECT user_id, 1 AS n,
           SUBSTRING_INDEX(t, '\n', 1) AS line,
           IF(LOCATE('\n', t) > 0, SUBSTRING(t, LOCATE('\n', t) + 1), NULL) AS rest
      FROM (SELECT user_id, REPLACE(completed_thesis, '\r', '') AS t FROM profiles
             WHERE TRIM(COALESCE(completed_thesis, '')) <> '') src
    UNION ALL
    SELECT user_id, n + 1,
           SUBSTRING_INDEX(rest, '\n', 1),
           IF(LOCATE('\n', rest) > 0, SUBSTRING(rest, LOCATE('\n', rest) + 1), NULL)
      FROM split WHERE rest IS NOT NULL
)
SELECT user_id, 'thesis',
       IF(TRIM(line) REGEXP '^\\[[^:\\]]+:', TRIM(REGEXP_REPLACE(TRIM(line), '^\\[([^:\\]]+):.*$', '\\1')), NULL),
       LEFT(TRIM(REGEXP_REPLACE(TRIM(line), '^\\[[^:\\]]+:\\s*(.*?)\\]?$', '\\1')), 500),
       n
  FROM split
 WHERE TRIM(line) <> ''
   AND NOT EXISTS (SELECT 1 FROM lab_entries e WHERE e.user_id = split.user_id AND e.kind = 'thesis');
