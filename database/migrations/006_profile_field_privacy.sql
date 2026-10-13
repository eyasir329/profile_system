-- Migration 006: per-field privacy level on profiles.
--
-- Adds `privacy_level` ENUM('public','logged_in','owner_only') to
-- profiles. Default 'public' = current behavior. Affects how the
-- public-side renderer filters rows: 'logged_in' hides from anonymous
-- viewers; 'owner_only' hides from anyone except the owner.


SET @sql := (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'profiles' AND COLUMN_NAME = 'privacy_level') = 0,
  "ALTER TABLE profiles ADD COLUMN privacy_level ENUM('public','logged_in','owner_only') NOT NULL DEFAULT 'public' AFTER photo",
  "SELECT 'profiles.privacy_level already present' AS note"
));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

