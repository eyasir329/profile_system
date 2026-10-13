-- Migration 005: per-section visibility + per-field privacy.
--
-- Adds `visible_to_public` to each of the 9 section tables (default 1 =
-- visible). Adds `show_phone_public` and `show_address_public` to profiles
-- for the "hide phone/address from public" toggle.
--
-- All changes are additive. Idempotent: each ADD COLUMN is guarded.
-- Does not touch existing data.


-- Section visibility. One column per child table; default 1 so existing
-- rows stay visible until the user explicitly toggles them off.
SET @sql := (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'educations' AND COLUMN_NAME = 'visible_to_public') = 0,
  "ALTER TABLE educations ADD COLUMN visible_to_public TINYINT(1) NOT NULL DEFAULT 1 AFTER sort_order",
  "SELECT 'educations.visible_to_public already present' AS note"
));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'work_experiences' AND COLUMN_NAME = 'visible_to_public') = 0,
  "ALTER TABLE work_experiences ADD COLUMN visible_to_public TINYINT(1) NOT NULL DEFAULT 1 AFTER sort_order",
  "SELECT 'work_experiences.visible_to_public already present' AS note"
));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'journal_articles' AND COLUMN_NAME = 'visible_to_public') = 0,
  "ALTER TABLE journal_articles ADD COLUMN visible_to_public TINYINT(1) NOT NULL DEFAULT 1 AFTER sort_order",
  "SELECT 'journal_articles.visible_to_public already present' AS note"
));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'conference_papers' AND COLUMN_NAME = 'visible_to_public') = 0,
  "ALTER TABLE conference_papers ADD COLUMN visible_to_public TINYINT(1) NOT NULL DEFAULT 1 AFTER sort_order",
  "SELECT 'conference_papers.visible_to_public already present' AS note"
));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'awards' AND COLUMN_NAME = 'visible_to_public') = 0,
  "ALTER TABLE awards ADD COLUMN visible_to_public TINYINT(1) NOT NULL DEFAULT 1 AFTER sort_order",
  "SELECT 'awards.visible_to_public already present' AS note"
));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'skills' AND COLUMN_NAME = 'visible_to_public') = 0,
  "ALTER TABLE skills ADD COLUMN visible_to_public TINYINT(1) NOT NULL DEFAULT 1 AFTER sort_order",
  "SELECT 'skills.visible_to_public already present' AS note"
));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'certifications' AND COLUMN_NAME = 'visible_to_public') = 0,
  "ALTER TABLE certifications ADD COLUMN visible_to_public TINYINT(1) NOT NULL DEFAULT 1 AFTER sort_order",
  "SELECT 'certifications.visible_to_public already present' AS note"
));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'research_interests' AND COLUMN_NAME = 'visible_to_public') = 0,
  "ALTER TABLE research_interests ADD COLUMN visible_to_public TINYINT(1) NOT NULL DEFAULT 1 AFTER sort_order",
  "SELECT 'research_interests.visible_to_public already present' AS note"
));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'languages' AND COLUMN_NAME = 'visible_to_public') = 0,
  "ALTER TABLE languages ADD COLUMN visible_to_public TINYINT(1) NOT NULL DEFAULT 1 AFTER sort_order",
  "SELECT 'languages.visible_to_public already present' AS note"
));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Per-field privacy on profiles (phone + address). Default = public.
SET @sql := (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'profiles' AND COLUMN_NAME = 'show_phone_public') = 0,
  "ALTER TABLE profiles ADD COLUMN show_phone_public TINYINT(1) NOT NULL DEFAULT 1 AFTER phone",
  "SELECT 'profiles.show_phone_public already present' AS note"
));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'profiles' AND COLUMN_NAME = 'show_address_public') = 0,
  "ALTER TABLE profiles ADD COLUMN show_address_public TINYINT(1) NOT NULL DEFAULT 1 AFTER address",
  "SELECT 'profiles.show_address_public already present' AS note"
));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

