-- Migration 007: dark mode / theme preference + custom accent.
--
-- Adds `theme` ENUM('light','dark','auto') and `accent_color` CHAR(7) to
-- profiles. Default theme = 'auto' (follows prefers-color-scheme).
-- `accent_color` lets the user pick from a small palette of pre-set
-- colors; NULL means "use the default brand accent".


SET @sql := (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'profiles' AND COLUMN_NAME = 'theme') = 0,
  "ALTER TABLE profiles ADD COLUMN theme ENUM('light','dark','auto') NOT NULL DEFAULT 'auto' AFTER privacy_level",
  "SELECT 'profiles.theme already present' AS note"
));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'profiles' AND COLUMN_NAME = 'accent_color') = 0,
  "ALTER TABLE profiles ADD COLUMN accent_color CHAR(7) NULL AFTER theme",
  "SELECT 'profiles.accent_color already present' AS note"
));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

