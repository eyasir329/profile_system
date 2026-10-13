-- Migration 014: remove public profile functionality.
--
-- The app no longer exposes /u/<username> (anonymous) or
-- /profile.php (owner read-only) or any per-row "Show on public
-- profile" toggle. This migration cleans up the now-dead tables
-- and columns.
--
-- Idempotent: each DROP is guarded so re-running on a clean DB
-- is a no-op. The schema_migrations table is recorded by the
-- entrypoint so this file only runs once per fresh container start.
--
-- Drops:
--   Tables  : endorsements, profile_views, username_history
--   Columns on 9 section tables: visible_to_public
--       (educations, work_experiences, journal_articles,
--        conference_papers, awards, skills, certifications,
--        research_interests, languages)
--   Columns on profiles: privacy_level, show_phone_public,
--                        show_address_public, accent_color
--
-- Kept:
--   - profiles.theme (still used by the editor's theme picker)
--   - users.username (used for login + display handle on the editor)


-- 1) Drop the dead tables. (CASCADE not needed — no other table
--    references these.)
DROP TABLE IF EXISTS endorsements;
DROP TABLE IF EXISTS profile_views;
DROP TABLE IF EXISTS username_history;


-- 2) Helper: drop a column from a table only if it exists. We do
--    this via INFORMATION_SCHEMA + dynamic SQL, mirroring the
--    style of migrations 005-007.

-- profiles.privacy_level
SET @sql := (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'profiles' AND COLUMN_NAME = 'privacy_level') > 0,
  "ALTER TABLE profiles DROP COLUMN privacy_level",
  "SELECT 'profiles.privacy_level already absent' AS note"
));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- profiles.show_phone_public
SET @sql := (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'profiles' AND COLUMN_NAME = 'show_phone_public') > 0,
  "ALTER TABLE profiles DROP COLUMN show_phone_public",
  "SELECT 'profiles.show_phone_public already absent' AS note"
));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- profiles.show_address_public
SET @sql := (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'profiles' AND COLUMN_NAME = 'show_address_public') > 0,
  "ALTER TABLE profiles DROP COLUMN show_address_public",
  "SELECT 'profiles.show_address_public already absent' AS note"
));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- profiles.accent_color
SET @sql := (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'profiles' AND COLUMN_NAME = 'accent_color') > 0,
  "ALTER TABLE profiles DROP COLUMN accent_color",
  "SELECT 'profiles.accent_color already absent' AS note"
));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;


-- 3) Drop visible_to_public from each of the 9 section tables.

-- educations.visible_to_public
SET @sql := (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'educations' AND COLUMN_NAME = 'visible_to_public') > 0,
  "ALTER TABLE educations DROP COLUMN visible_to_public",
  "SELECT 'educations.visible_to_public already absent' AS note"
));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- work_experiences.visible_to_public
SET @sql := (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'work_experiences' AND COLUMN_NAME = 'visible_to_public') > 0,
  "ALTER TABLE work_experiences DROP COLUMN visible_to_public",
  "SELECT 'work_experiences.visible_to_public already absent' AS note"
));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- journal_articles.visible_to_public
SET @sql := (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'journal_articles' AND COLUMN_NAME = 'visible_to_public') > 0,
  "ALTER TABLE journal_articles DROP COLUMN visible_to_public",
  "SELECT 'journal_articles.visible_to_public already absent' AS note"
));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- conference_papers.visible_to_public
SET @sql := (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'conference_papers' AND COLUMN_NAME = 'visible_to_public') > 0,
  "ALTER TABLE conference_papers DROP COLUMN visible_to_public",
  "SELECT 'conference_papers.visible_to_public already absent' AS note"
));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- awards.visible_to_public
SET @sql := (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'awards' AND COLUMN_NAME = 'visible_to_public') > 0,
  "ALTER TABLE awards DROP COLUMN visible_to_public",
  "SELECT 'awards.visible_to_public already absent' AS note"
));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- skills.visible_to_public
SET @sql := (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'skills' AND COLUMN_NAME = 'visible_to_public') > 0,
  "ALTER TABLE skills DROP COLUMN visible_to_public",
  "SELECT 'skills.visible_to_public already absent' AS note"
));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- certifications.visible_to_public
SET @sql := (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'certifications' AND COLUMN_NAME = 'visible_to_public') > 0,
  "ALTER TABLE certifications DROP COLUMN visible_to_public",
  "SELECT 'certifications.visible_to_public already absent' AS note"
));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- research_interests.visible_to_public
SET @sql := (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'research_interests' AND COLUMN_NAME = 'visible_to_public') > 0,
  "ALTER TABLE research_interests DROP COLUMN visible_to_public",
  "SELECT 'research_interests.visible_to_public already absent' AS note"
));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- languages.visible_to_public
SET @sql := (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'languages' AND COLUMN_NAME = 'visible_to_public') > 0,
  "ALTER TABLE languages DROP COLUMN visible_to_public",
  "SELECT 'languages.visible_to_public already absent' AS note"
));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
