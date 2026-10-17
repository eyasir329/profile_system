-- 019: a profile is the lab details only.
--
-- The profile page now holds exactly the columns of the lab's
-- "Information of Lab Students" workbook (see src/includes/lab_fields.php).
-- Everything else a profile used to carry is removed:
--   * the nine repeating sections (education, work, papers, awards, ...)
--   * phone, address, profession, bio and photo
--   * the Bachelor / M.Sc. / PhD "profile type", which only decided which
--     sections were shown
-- and the log rows that described those removed things.
--
-- DESTRUCTIVE and not reversible from here: take `make backup` first.
-- Every statement is idempotent, so re-running is harmless.

DROP TABLE IF EXISTS educations;
DROP TABLE IF EXISTS work_experiences;
DROP TABLE IF EXISTS journal_articles;
DROP TABLE IF EXISTS conference_papers;
DROP TABLE IF EXISTS awards;
DROP TABLE IF EXISTS skills;
DROP TABLE IF EXISTS certifications;
DROP TABLE IF EXISTS research_interests;
DROP TABLE IF EXISTS languages;

ALTER TABLE profiles
    DROP COLUMN IF EXISTS phone,
    DROP COLUMN IF EXISTS address,
    DROP COLUMN IF EXISTS profession,
    DROP COLUMN IF EXISTS bio,
    DROP COLUMN IF EXISTS photo;

ALTER TABLE users
    DROP COLUMN IF EXISTS degree_type;

-- Activity rows about things that no longer exist, plus bookkeeping rows
-- the dashboard never showed.
DELETE FROM activity
 WHERE (kind = 'profile_update' AND (detail IS NULL OR detail NOT LIKE 'lab:%'))
    OR kind IN ('privacy_update', 'role_fallback');

-- Password-reset tokens that can no longer be used.
DELETE FROM password_resets
 WHERE used_at IS NOT NULL OR expires_at < NOW();
