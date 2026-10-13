-- Migration 015: the default theme is light.
--
-- Until now profiles.theme defaulted to 'auto' (follow the device), so a
-- profile nobody had touched could render dark. New profiles now start on
-- 'light'; "Match my system" and "Dark" remain available in
-- Settings → Appearance.
--
-- Existing rows still on 'auto' are moved to 'light' as well: at the time
-- of this migration no account had chosen 'auto' on purpose (every
-- theme_update in `activity` ends on light or dark), so they are all
-- untouched defaults.

ALTER TABLE profiles MODIFY COLUMN theme ENUM('light','dark','auto') NOT NULL DEFAULT 'light';

UPDATE profiles SET theme = 'light' WHERE theme = 'auto';
