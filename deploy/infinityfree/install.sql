-- Profile System shared-hosting installer (InfinityFree / Byet.host)
-- Select your newly created database in phpMyAdmin before importing this file.
-- Import only once into a brand-new, empty database.
SET NAMES utf8mb4 COLLATE utf8mb4_general_ci;
-- Japanese everywhere: the database default and every table are utf8mb4.
ALTER DATABASE CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role ENUM('user', 'admin') NOT NULL DEFAULT 'user', -- legacy; backfilled into user_roles by 004, dropped by 016
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS profiles (
    user_id INT PRIMARY KEY,
    phone VARCHAR(20) NULL,
    address TEXT NULL,
    profession VARCHAR(100) NULL,
    bio TEXT NULL,
    photo VARCHAR(255) NULL,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_profile_user FOREIGN KEY (user_id)
      REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
CREATE TABLE IF NOT EXISTS schema_migrations (
  filename VARCHAR(255) PRIMARY KEY,
  applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ===== Migration: 001_redesign.sql =====
-- Redesign migration: add username, password reset table, activity log.
-- Safe to run once against an existing database.

START TRANSACTION;

-- 1) Add username column, backfill from name, make NOT NULL.
ALTER TABLE users ADD COLUMN username VARCHAR(40) NULL UNIQUE AFTER name;

UPDATE users
SET username = LOWER(
  REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(name, ' ', '-'), '.', ''), '_', '-'), '@', '-'), '!', ''), '?', '')
)
WHERE username IS NULL;

-- Resolve any collisions by appending the user id.
UPDATE users u
JOIN (
  SELECT id, username,
         ROW_NUMBER() OVER (PARTITION BY username ORDER BY id) AS rn,
         COUNT(*) OVER (PARTITION BY username) AS cnt
  FROM users
) x ON x.id = u.id
SET u.username = CONCAT(u.username, '-', u.id)
WHERE x.cnt > 1 AND x.rn > 1;

ALTER TABLE users MODIFY COLUMN username VARCHAR(40) NOT NULL;

-- 2) Password reset tokens.
CREATE TABLE IF NOT EXISTS password_resets (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_pr_user (user_id),
  INDEX idx_pr_token (token_hash),
  CONSTRAINT fk_pr_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 3) Activity log.
CREATE TABLE IF NOT EXISTS activity (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NULL,
  kind VARCHAR(40) NOT NULL,
  detail VARCHAR(255) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_act_user (user_id, created_at),
  INDEX idx_act_kind (kind, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

COMMIT;
INSERT INTO schema_migrations (filename) VALUES ('001_redesign.sql');

-- ===== Migration: 002_academic_profile.sql =====
-- Migration 002: academic profile sections + degree type.
-- Adds a `degree_type` column to `users` and one child table per profile section.
-- All child tables share the same shape: id, user_id (FK), title, optional detail
-- columns specific to the section, sort_order, timestamps.
-- Safe to run once against an existing database (uses IF NOT EXISTS where possible).

START TRANSACTION;

-- ---------------------------------------------------------------------------
-- 1) degree_type on users. NULL means "not chosen yet" (legacy rows).
-- ---------------------------------------------------------------------------
ALTER TABLE users
  ADD COLUMN degree_type ENUM('bachelor','msc','phd') NULL AFTER role;

-- Backfill legacy users so the rest of the system has a stable value to render.
-- Default to 'bachelor' for any user that doesn't have one yet.
UPDATE users SET degree_type = 'bachelor' WHERE degree_type IS NULL;

-- ---------------------------------------------------------------------------
-- 2) Education
--    Each row is one degree/diploma/course of study.
--    institution + degree + field + start/end year + description.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS educations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  institution VARCHAR(200) NOT NULL,
  degree VARCHAR(150) NOT NULL,
  field_of_study VARCHAR(150) NULL,
  start_year SMALLINT NULL,
  end_year   SMALLINT NULL,
  description TEXT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_edu_user (user_id, sort_order),
  CONSTRAINT fk_edu_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------------
-- 3) Work experience
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS work_experiences (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  organization VARCHAR(200) NOT NULL,
  role_title VARCHAR(150) NOT NULL,
  location VARCHAR(150) NULL,
  start_date DATE NULL,
  end_date   DATE NULL,
  is_current TINYINT(1) NOT NULL DEFAULT 0,
  description TEXT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_we_user (user_id, sort_order),
  CONSTRAINT fk_we_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------------
-- 4) Journal articles
--    title, authors, journal name, year, volume/issue/pages, doi/url.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS journal_articles (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  title VARCHAR(300) NOT NULL,
  authors VARCHAR(500) NULL,
  journal VARCHAR(200) NULL,
  year SMALLINT NULL,
  volume VARCHAR(40) NULL,
  issue  VARCHAR(40) NULL,
  pages  VARCHAR(40) NULL,
  doi    VARCHAR(150) NULL,
  url    VARCHAR(500) NULL,
  sort_order INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_ja_user (user_id, sort_order),
  CONSTRAINT fk_ja_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------------
-- 5) Conference papers / presentations
--    title, authors, conference, year, location, doi/url.
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS conference_papers (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  title VARCHAR(300) NOT NULL,
  authors VARCHAR(500) NULL,
  conference VARCHAR(200) NULL,
  year SMALLINT NULL,
  location VARCHAR(150) NULL,
  doi VARCHAR(150) NULL,
  url VARCHAR(500) NULL,
  sort_order INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_cp_user (user_id, sort_order),
  CONSTRAINT fk_cp_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------------
-- 6) Awards
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS awards (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  title VARCHAR(200) NOT NULL,
  organization VARCHAR(200) NULL,
  year SMALLINT NULL,
  description TEXT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_aw_user (user_id, sort_order),
  CONSTRAINT fk_aw_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------------
-- 7) Skills (single-line tags)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS skills (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  name VARCHAR(100) NOT NULL,
  level ENUM('beginner','intermediate','advanced','expert') NULL,
  sort_order INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_sk_user (user_id, sort_order),
  CONSTRAINT fk_sk_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------------
-- 8) Certifications
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS certifications (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  name VARCHAR(200) NOT NULL,
  issuing_org VARCHAR(200) NULL,
  issue_date DATE NULL,
  expiry_date DATE NULL,
  credential_id VARCHAR(100) NULL,
  url VARCHAR(500) NULL,
  sort_order INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_ce_user (user_id, sort_order),
  CONSTRAINT fk_ce_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------------
-- 9) Research interests (free-form short tags)
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS research_interests (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  name VARCHAR(150) NOT NULL,
  sort_order INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_ri_user (user_id, sort_order),
  CONSTRAINT fk_ri_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- ---------------------------------------------------------------------------
-- 10) Languages
-- ---------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS languages (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  name VARCHAR(80) NOT NULL,
  proficiency ENUM('elementary','limited_working','professional_working','full_professional','native') NULL,
  sort_order INT NOT NULL DEFAULT 0,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_la_user (user_id, sort_order),
  CONSTRAINT fk_la_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

COMMIT;
INSERT INTO schema_migrations (filename) VALUES ('002_academic_profile.sql');

-- ===== Migration: 003_profile_lab_fields.sql =====
-- Migration 003: xlsx-style "Lab Students" fields on profiles.
--
-- Adds to the existing `profiles` table the columns from the original
-- "Information of Lab Students" spreadsheet so admins can record and
-- view that data per user. All new columns are NULLable so existing
-- rows are unaffected — the migration is purely additive.
--
-- The `course` column uses the same ENUM as the original sheet
-- (D1..D3, M1..M2, B3..B4, R, Other) and `enrolled_month` is the
-- 12-month ENUM. Long-text columns (research_title, completed_thesis,
-- journal_papers, conference_papers) are TEXT.
--
-- Idempotent: every ADD COLUMN is guarded with IF NOT EXISTS so
-- re-running is a no-op.

-- 1) Course
SET @col := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = 'profiles'
               AND COLUMN_NAME  = 'course');
SET @sql := IF(@col = 0,
  "ALTER TABLE profiles ADD COLUMN course ENUM('D1','D2','D3','M1','M2','B3','B4','R','Other') NULL AFTER profession",
  "SELECT 'profiles.course already present' AS note");
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 2) Student ID
SET @col := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = 'profiles'
               AND COLUMN_NAME  = 'student_id');
SET @sql := IF(@col = 0,
  "ALTER TABLE profiles ADD COLUMN student_id VARCHAR(64) NULL AFTER course",
  "SELECT 'profiles.student_id already present' AS note");
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 3) Name (Japanese) and Name (English)
SET @col := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = 'profiles'
               AND COLUMN_NAME  = 'name_jp');
SET @sql := IF(@col = 0,
  "ALTER TABLE profiles ADD COLUMN name_jp VARCHAR(255) NULL AFTER student_id",
  "SELECT 'profiles.name_jp already present' AS note");
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @col := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = 'profiles'
               AND COLUMN_NAME  = 'name_en');
SET @sql := IF(@col = 0,
  "ALTER TABLE profiles ADD COLUMN name_en VARCHAR(255) NULL AFTER name_jp",
  "SELECT 'profiles.name_en already present' AS note");
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 4) University email
SET @col := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = 'profiles'
               AND COLUMN_NAME  = 'university_email');
SET @sql := IF(@col = 0,
  "ALTER TABLE profiles ADD COLUMN university_email VARCHAR(255) NULL AFTER name_en",
  "SELECT 'profiles.university_email already present' AS note");
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 5) Current research title
SET @col := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = 'profiles'
               AND COLUMN_NAME  = 'research_title');
SET @sql := IF(@col = 0,
  "ALTER TABLE profiles ADD COLUMN research_title TEXT NULL AFTER university_email",
  "SELECT 'profiles.research_title already present' AS note");
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 6) Enrolled year / month
SET @col := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = 'profiles'
               AND COLUMN_NAME  = 'enrolled_year');
SET @sql := IF(@col = 0,
  "ALTER TABLE profiles ADD COLUMN enrolled_year SMALLINT NULL AFTER research_title",
  "SELECT 'profiles.enrolled_year already present' AS note");
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @col := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = 'profiles'
               AND COLUMN_NAME  = 'enrolled_month');
SET @sql := IF(@col = 0,
  "ALTER TABLE profiles ADD COLUMN enrolled_month ENUM('January','February','March','April','May','June','July','August','September','October','November','December') NULL AFTER enrolled_year",
  "SELECT 'profiles.enrolled_month already present' AS note");
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 7) Next step
SET @col := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = 'profiles'
               AND COLUMN_NAME  = 'next_step');
SET @sql := IF(@col = 0,
  "ALTER TABLE profiles ADD COLUMN next_step VARCHAR(255) NULL AFTER enrolled_month",
  "SELECT 'profiles.next_step already present' AS note");
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 8) Previous University / Level
SET @col := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = 'profiles'
               AND COLUMN_NAME  = 'previous_univ_level');
SET @sql := IF(@col = 0,
  "ALTER TABLE profiles ADD COLUMN previous_univ_level VARCHAR(255) NULL AFTER next_step",
  "SELECT 'profiles.previous_univ_level already present' AS note");
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 9) Completed Thesis Titles
SET @col := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = 'profiles'
               AND COLUMN_NAME  = 'completed_thesis');
SET @sql := IF(@col = 0,
  "ALTER TABLE profiles ADD COLUMN completed_thesis TEXT NULL AFTER previous_univ_level",
  "SELECT 'profiles.completed_thesis already present' AS note");
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 10) Journal Papers
SET @col := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = 'profiles'
               AND COLUMN_NAME  = 'journal_papers');
SET @sql := IF(@col = 0,
  "ALTER TABLE profiles ADD COLUMN journal_papers TEXT NULL AFTER completed_thesis",
  "SELECT 'profiles.journal_papers already present' AS note");
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- 11) Conference Papers
SET @col := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = DATABASE()
               AND TABLE_NAME   = 'profiles'
               AND COLUMN_NAME  = 'conference_papers');
SET @sql := IF(@col = 0,
  "ALTER TABLE profiles ADD COLUMN conference_papers TEXT NULL AFTER journal_papers",
  "SELECT 'profiles.conference_papers already present' AS note");
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
INSERT INTO schema_migrations (filename) VALUES ('003_profile_lab_fields.sql');

-- ===== Migration: 004_user_roles.sql =====
-- Migration 004: many-to-many user roles.
--
-- Adds a `user_roles` join table so a single user can hold multiple roles
-- (admin / teacher / student) at the same time — e.g. a grad student who
-- also teaches.
--
-- Hard constraint: do not touch existing data. The legacy single-value
-- `users.role` ENUM column is LEFT UNCHANGED (kept for back-compat with
-- any code / dashboards that still read it). The new code paths use
-- `user_roles` exclusively; `users.role` is only read for legacy
-- single-value checks and is automatically kept in sync by the
-- registration and admin set_roles flows.
--
-- The three INSERT IGNORE statements below backfill `user_roles` from
-- the existing `users.role` values so the new permission checks work
-- against today's data with zero manual intervention. INSERT IGNORE
-- makes the whole migration idempotent: re-running it is a no-op.
--
-- Permission model (enforced in PHP):
--   admin   — full CRUD on all users; can grant/revoke any role
--   teacher — can view all student profiles (read-only) via /teacher/
--   student — can maintain their own profile only

START TRANSACTION;

-- 1) Join table.
CREATE TABLE IF NOT EXISTS user_roles (
    user_id    INT NOT NULL,
    role       ENUM('admin','teacher','student') NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, role),
    INDEX idx_ur_role (role, user_id),
    CONSTRAINT fk_ur_user FOREIGN KEY (user_id)
        REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- 2) Backfill from legacy users.role. Idempotent.
INSERT IGNORE INTO user_roles (user_id, role)
    SELECT id, 'admin' FROM users WHERE role = 'admin';

INSERT IGNORE INTO user_roles (user_id, role)
    SELECT id, 'student' FROM users WHERE role = 'user';

-- 3) Admins also get the student role so they can use student-facing
--    features (e.g. a teacher page that lists students). Idempotent.
INSERT IGNORE INTO user_roles (user_id, role)
    SELECT id, 'student' FROM users WHERE role = 'admin';

COMMIT;
INSERT INTO schema_migrations (filename) VALUES ('004_user_roles.sql');

-- ===== Migration: 005_profile_section_visibility.sql =====
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
INSERT INTO schema_migrations (filename) VALUES ('005_profile_section_visibility.sql');

-- ===== Migration: 006_profile_field_privacy.sql =====
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
INSERT INTO schema_migrations (filename) VALUES ('006_profile_field_privacy.sql');

-- ===== Migration: 007_profile_theme.sql =====
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
INSERT INTO schema_migrations (filename) VALUES ('007_profile_theme.sql');

-- ===== Migration: 008_profile_views.sql =====
-- Migration 008: profile view analytics.
--
-- New table `profile_views` records every public profile render. Used
-- by the owner's dashboard to show "profile views this week" and a
-- sparkline of views over the last 30 days. `viewer_user_id` is NULL
-- for anonymous views; no FK is set so anonymous views can be logged
-- even if the viewer later creates / deletes an account.
-- `viewed_user_id` has FK CASCADE so profile deletion cleans up.


CREATE TABLE IF NOT EXISTS profile_views (
  id BIGINT AUTO_INCREMENT PRIMARY KEY,
  viewer_user_id INT NULL,
  viewed_user_id INT NOT NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_pv_viewed (viewed_user_id, created_at),
  CONSTRAINT fk_pv_viewed FOREIGN KEY (viewed_user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
INSERT INTO schema_migrations (filename) VALUES ('008_profile_views.sql');

-- ===== Migration: 009_endorsements.sql =====
-- Migration 009: endorsements.
--
-- Lets any user (except the recipient) endorse another user for a
-- specific skill, with an optional comment. UNIQUE on
-- (recipient, endorser, skill) prevents the same endorser from
-- endorsing the same skill twice on the same recipient.


CREATE TABLE IF NOT EXISTS endorsements (
  id INT AUTO_INCREMENT PRIMARY KEY,
  recipient_user_id INT NOT NULL,
  endorser_user_id  INT NOT NULL,
  skill VARCHAR(100) NOT NULL,
  comment VARCHAR(500) NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uniq_endorse (recipient_user_id, endorser_user_id, skill),
  INDEX idx_endorse_recipient (recipient_user_id, created_at),
  INDEX idx_endorse_endorser (endorser_user_id),
  CONSTRAINT fk_endorse_recipient FOREIGN KEY (recipient_user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_endorse_endorser  FOREIGN KEY (endorser_user_id)  REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
INSERT INTO schema_migrations (filename) VALUES ('009_endorsements.sql');

-- ===== Migration: 010_two_factor.sql =====
-- Migration 010: two-factor authentication (TOTP).
--
-- Adds a `two_factor` table for per-user TOTP secret + backup codes.
-- Adds `users.totp_required` so we can detect the requirement at login
-- without a join. The actual TOTP verification happens via the new
-- /login_totp.php endpoint after password authentication.


-- Per-user TOTP secret + backup codes. One row per user.
CREATE TABLE IF NOT EXISTS two_factor (
  user_id INT PRIMARY KEY,
  secret VARCHAR(32) NOT NULL,
  enabled TINYINT(1) NOT NULL DEFAULT 0,
  backup_codes TEXT NULL,
  last_used_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_tf_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Master switch: is 2FA required at login for this user? Set by the
-- security panel when the user enables 2FA.
SET @sql := (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'totp_required') = 0,
  "ALTER TABLE users ADD COLUMN totp_required TINYINT(1) NOT NULL DEFAULT 0 AFTER degree_type",
  "SELECT 'users.totp_required already present' AS note"
));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
INSERT INTO schema_migrations (filename) VALUES ('010_two_factor.sql');

-- ===== Migration: 011_email_verifications.sql =====
-- Migration 011: email verification.
--
-- Stores pending email change verifications. `new_email` is the address
-- the user wants to switch to; the link in the confirmation email
-- points to /verify_email.php?token=...; only when consumed does the
-- change to `users.email` take effect. `users.email_verified` is 1
-- once verified; new registrations set it to 1 by default (we don't
-- require email verification to use the site in dev / smoke test).


CREATE TABLE IF NOT EXISTS email_verifications (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  new_email VARCHAR(150) NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_ev_token (token_hash),
  INDEX idx_ev_user (user_id),
  CONSTRAINT fk_ev_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

SET @sql := (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'email_verified') = 0,
  "ALTER TABLE users ADD COLUMN email_verified TINYINT(1) NOT NULL DEFAULT 1 AFTER email",
  "SELECT 'users.email_verified already present' AS note"
));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
INSERT INTO schema_migrations (filename) VALUES ('011_email_verifications.sql');

-- ===== Migration: 012_username_history.sql =====
-- Migration 012: username history (aliasing for old links).
--
-- On every username change, the old value is recorded here. `u/index.php`
-- does a fallback lookup against this table and 301-redirects to the
-- canonical URL. This means a user's old public link continues to work
-- forever (or until they remove it).


CREATE TABLE IF NOT EXISTS username_history (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  old_username VARCHAR(40) NOT NULL,
  changed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_uh_old (old_username),
  INDEX idx_uh_user (user_id, changed_at),
  CONSTRAINT fk_uh_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
INSERT INTO schema_migrations (filename) VALUES ('012_username_history.sql');

-- ===== Migration: 013_accounts_soft_delete.sql =====
-- Migration 013: soft delete with 30-day restore window.
--
-- Adds `deleted_at` and `scheduled_purge_at` to `users`. When the user
-- clicks "Delete account" in /settings.php?tab=danger, we set
-- deleted_at = NOW() and scheduled_purge_at = NOW() + 30 days. A daily
-- cron hard-deletes rows where scheduled_purge_at < NOW().
--
-- `require_login()` now checks `deleted_at IS NULL` and redirects to
-- /login.php?deactivated=1 with a banner explaining the situation and
-- a link to /restore_account.php?token=... (the token was emailed at
-- deactivation time and stored in `account_restorations`).


-- Soft-delete columns.
SET @sql := (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'deleted_at') = 0,
  "ALTER TABLE users ADD COLUMN deleted_at DATETIME NULL AFTER totp_required",
  "SELECT 'users.deleted_at already present' AS note"
));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET @sql := (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'scheduled_purge_at') = 0,
  "ALTER TABLE users ADD COLUMN scheduled_purge_at DATETIME NULL AFTER deleted_at",
  "SELECT 'users.scheduled_purge_at already present' AS note"
));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

-- Account restoration tokens.
CREATE TABLE IF NOT EXISTS account_restorations (
  id INT AUTO_INCREMENT PRIMARY KEY,
  user_id INT NOT NULL,
  token_hash CHAR(64) NOT NULL,
  expires_at DATETIME NOT NULL,
  used_at DATETIME NULL,
  created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_ar_token (token_hash),
  INDEX idx_ar_user (user_id),
  CONSTRAINT fk_ar_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
INSERT INTO schema_migrations (filename) VALUES ('013_accounts_soft_delete.sql');

-- ===== Migration: 014_remove_public_profile.sql =====
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
INSERT INTO schema_migrations (filename) VALUES ('014_remove_public_profile.sql');

-- ===== Migration: 015_theme_default_light.sql =====
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
INSERT INTO schema_migrations (filename) VALUES ('015_theme_default_light.sql');

-- ===== Migration: 016_drop_users_role.sql =====
-- Migration 016: drop the legacy `users.role` column.
--
-- `users.role` ENUM('user','admin') was the single-role column from before
-- migration 004. Since then every permission check has read `user_roles`
-- (admin / teacher / student, several per user); `users.role` was written
-- once at registration, never updated, and could not even hold 'teacher'.
--
-- Before dropping it, make sure nobody depends on it: any account that
-- still has no row in `user_roles` gets what migration 004 would have
-- given it (admin + student for a legacy admin, student otherwise).
-- Both steps are skipped when the column is already gone.

SET @has_role := (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
                   WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'role');

SET @sql := IF(@has_role = 1,
  "INSERT IGNORE INTO user_roles (user_id, role)
     SELECT u.id, 'admin' FROM users u
      WHERE u.role = 'admin' AND NOT EXISTS (SELECT 1 FROM user_roles r WHERE r.user_id = u.id)",
  "SELECT 'users.role already dropped' AS note");
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

INSERT IGNORE INTO user_roles (user_id, role)
  SELECT u.id, 'student' FROM users u
   WHERE NOT EXISTS (SELECT 1 FROM user_roles r WHERE r.user_id = u.id AND r.role <> 'admin');

SET @sql := IF(@has_role = 1,
  "ALTER TABLE users DROP COLUMN role",
  "SELECT 'users.role already dropped' AS note");
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
INSERT INTO schema_migrations (filename) VALUES ('016_drop_users_role.sql');

-- ===== Migration: 017_invites.sql =====
-- Migration 017: accounts are created by invitation only.
--
-- `invites` holds one row per invitation link. Only the SHA-256 of the
-- token is stored, so a database leak does not hand out working links.
--   roles       comma-separated roles the new account receives
--   email       optional: only this address may use the link
--   max_uses    how many accounts the link may create (used_count tracks it)
--   expires_at  the link stops working after this moment
--   revoked_at  set when the creator (or an admin) cancels it
--   created_by  the teacher/admin who made it; the link dies with their
--               account or their permission to invite (checked on use)
--
-- `users.invite_id` records which link an account came from.

CREATE TABLE IF NOT EXISTS invites (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    token_hash  CHAR(64) NOT NULL,
    roles       VARCHAR(40) NOT NULL,
    email       VARCHAR(150) NULL,
    note        VARCHAR(150) NULL,
    max_uses    SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    used_count  SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    expires_at  DATETIME NOT NULL,
    revoked_at  DATETIME NULL,
    created_by  INT NULL,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uq_inv_token (token_hash),
    KEY idx_inv_creator (created_by),
    CONSTRAINT fk_inv_creator FOREIGN KEY (created_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

SET @sql := (SELECT IF(
  (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'invite_id') = 0,
  "ALTER TABLE users ADD COLUMN invite_id INT NULL,
     ADD CONSTRAINT fk_users_invite FOREIGN KEY (invite_id) REFERENCES invites (id) ON DELETE SET NULL",
  "SELECT 'users.invite_id already present' AS note"
));
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
INSERT INTO schema_migrations (filename) VALUES ('017_invites.sql');

-- ===== Migration: 018_login_attempts.sql =====
-- Migration 018: rate-limit / brute-force protection.
--
-- The login, registration and forgot-password endpoints need a way to
-- count recent attempts and lock out abusive clients. A SQL table is
-- simpler and more correct than session-based counters because it
-- survives logout, works across many requests, and is shared across
-- all PHP processes.
--
-- `bucket` is the limiter key, e.g.:
--   "email:alice@example.com"  -> 5 failures in 15 minutes
--   "ip:1.2.3.4"               -> 20 failures in 10 minutes
-- `success` is 1 when the call to record_attempt() represents a
-- successful authentication (so we can clear the bucket on success).
-- Rows are retained for diagnostic purposes; a nightly cleanup can
-- delete `WHERE created_at < NOW() - INTERVAL 30 DAY` if desired.

CREATE TABLE IF NOT EXISTS login_attempts (
    id          BIGINT AUTO_INCREMENT PRIMARY KEY,
    bucket      VARCHAR(80) NOT NULL,
    user_id     INT NULL,
    username    VARCHAR(150) NULL,
    ip          VARCHAR(45) NULL,
    success     TINYINT(1) NOT NULL DEFAULT 0,
    created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_lat_bucket_time (bucket, created_at),
    INDEX idx_lat_time (created_at),
    CONSTRAINT fk_lat_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
INSERT INTO schema_migrations (filename) VALUES ('018_login_attempts.sql');

-- ===== Migration: 019_lab_only_profile.sql =====
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
    DROP COLUMN phone,
    DROP COLUMN address,
    DROP COLUMN profession,
    DROP COLUMN bio,
    DROP COLUMN photo;

ALTER TABLE users
    DROP COLUMN degree_type;

-- Activity rows about things that no longer exist, plus bookkeeping rows
-- the dashboard never showed.
DELETE FROM activity
 WHERE (kind = 'profile_update' AND (detail IS NULL OR detail NOT LIKE 'lab:%'))
    OR kind IN ('privacy_update', 'role_fallback');

-- Password-reset tokens that can no longer be used.
DELETE FROM password_resets
 WHERE used_at IS NOT NULL OR expires_at < NOW();
INSERT INTO schema_migrations (filename) VALUES ('019_lab_only_profile.sql');

-- ===== Migration: 020_single_email_no_username.sql =====
-- 020: one email per person, and no usernames.
--
--   * The workbook's "University email" column is the address the person
--     signs in with (users.email). The separate profiles.university_email
--     is dropped; see lab_field_defs() in src/includes/lab_fields.php.
--   * Usernames are gone: people are identified by name, email and
--     student ID. users.username (and its unique index) is dropped.
--
-- DESTRUCTIVE: take `make backup` first. Idempotent.

ALTER TABLE profiles
    DROP COLUMN university_email;

ALTER TABLE users
    DROP COLUMN username;
INSERT INTO schema_migrations (filename) VALUES ('020_single_email_no_username.sql');

-- ===== Migration: 021_course_enrollments.sql =====
-- 021: a person can be in several courses over time.
--
-- course_enrollments keeps one row per course a person has been in, with
-- the month they enrolled in it. The newest row is the current course;
-- profiles.course / enrolled_year / enrolled_month mirror it so the sheet
-- and its filters stay a single-table read.
--
-- The enrolment date is not typed in: it is the day the invitation link
-- was generated (first course) or the day the course was added (later
-- ones). An admin can correct it on /admin/user.php.
--
-- Additive. Idempotent.

CREATE TABLE IF NOT EXISTS course_enrollments (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    user_id        INT NOT NULL,
    course         ENUM('D1','D2','D3','M1','M2','B3','B4','R','Other') NOT NULL,
    enrolled_year  SMALLINT NULL,
    enrolled_month ENUM('January','February','March','April','May','June','July','August','September','October','November','December') NULL,
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_enrollments_user (user_id, id),
    CONSTRAINT fk_enrollment_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- People who have a course but no enrolment date yet: use the day their
-- invitation link was generated, or the day the account was created when
-- there was no invitation.
UPDATE profiles p
  JOIN users u ON u.id = p.user_id
  LEFT JOIN invites i ON i.id = u.invite_id
   SET p.enrolled_year  = YEAR(COALESCE(i.created_at, u.created_at)),
       p.enrolled_month = MONTHNAME(COALESCE(i.created_at, u.created_at))
 WHERE p.course IS NOT NULL
   AND p.enrolled_year IS NULL
   AND p.enrolled_month IS NULL;

-- Start everyone's history with the course they are in now.
INSERT INTO course_enrollments (user_id, course, enrolled_year, enrolled_month)
SELECT p.user_id, p.course, p.enrolled_year, p.enrolled_month
  FROM profiles p
 WHERE p.course IS NOT NULL
   AND NOT EXISTS (SELECT 1 FROM course_enrollments e WHERE e.user_id = p.user_id);
INSERT INTO schema_migrations (filename) VALUES ('021_course_enrollments.sql');

-- ===== Migration: 022_enrolled_month_other.sql =====
-- 022: "Other" as an enrolment month.
--
-- The lab workbook's "Enrolled Month" column offers April, October and
-- Other. The month names stay valid (the system fills in the real month
-- when someone joins or adds a course); "Other" is added to both copies
-- of the column.
--
-- Idempotent: re-running MODIFY with the same definition is a no-op.

ALTER TABLE profiles
    MODIFY enrolled_month ENUM('January','February','March','April','May','June','July','August','September','October','November','December','Other') NULL;

ALTER TABLE course_enrollments
    MODIFY enrolled_month ENUM('January','February','March','April','May','June','July','August','September','October','November','December','Other') NULL;
INSERT INTO schema_migrations (filename) VALUES ('022_enrolled_month_other.sql');

-- ===== Migration: 023_lab_entries.sql =====
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
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

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
INSERT INTO schema_migrations (filename) VALUES ('023_lab_entries.sql');

-- ===== Migration: 024_publications_awards.sql =====
-- 024: publications and awards, one row per item.
--
-- The second lab workbook has four sheets. Each sheet becomes a repeating
-- section on the student's profile and a tab on the teachers' Students
-- page (see src/includes/lab_entries.php):
--   PublishedJournals       lab_publications  kind = 'journal'
--   Conf.Proceedings        lab_publications  kind = 'proceedings'
--   Conf.PresentationOnly   lab_publications  kind = 'presentation'
--   Awards                  lab_awards
-- Columns follow the workbook's: publication month (shown "yyyy/mm"),
-- type of publication, citation, BibTeX, impact factor; and recipients,
-- award name, organization/conference, full information, date.
--
-- Additive. Idempotent.

CREATE TABLE IF NOT EXISTS lab_publications (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    user_id       INT NOT NULL,
    kind          ENUM('journal','proceedings','presentation') NOT NULL,
    pub_month     CHAR(7) NULL,
    pub_type      ENUM('Journal','Conference (published)','Conference (presentation)') NOT NULL,
    citation      TEXT NOT NULL,
    bibtex        TEXT NULL,
    impact_factor VARCHAR(10) NULL,
    sort_order    INT NOT NULL DEFAULT 0,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_lab_publications_user (user_id, kind, sort_order),
    CONSTRAINT fk_lab_publication_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS lab_awards (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    user_id      INT NOT NULL,
    recipients   VARCHAR(500) NULL,
    award_name   VARCHAR(300) NOT NULL,
    organization VARCHAR(300) NULL,
    details      TEXT NULL,
    award_date   DATE NULL,
    sort_order   INT NOT NULL DEFAULT 0,
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_lab_awards_user (user_id, sort_order),
    CONSTRAINT fk_lab_award_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
INSERT INTO schema_migrations (filename) VALUES ('024_publications_awards.sql');

-- ===== Migration: 025_lab_resources.sql =====
-- 025: lab-wide resources, kept by teachers, read by every student.
--
-- From the third lab workbook ("Information of Lab Students (2).xlsx"):
--   Templates            lab_templates   one text block per row
--   Open data and code   lab_resources   name + link
-- Unlike everything else a student sees, these are not per person: there
-- is one shared list. Teachers (and admins) add, edit and delete on
-- /resources.php; students only read it.
--
-- Additive. Idempotent.

CREATE TABLE IF NOT EXISTS lab_templates (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    body       TEXT NOT NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_by INT NULL,
    updated_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_lab_template_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_lab_template_editor  FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS lab_resources (
    id         INT AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(200) NOT NULL,
    link       VARCHAR(1000) NULL,
    sort_order INT NOT NULL DEFAULT 0,
    created_by INT NULL,
    updated_by INT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_lab_resource_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_lab_resource_editor  FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- The workbook's rows (names only; it has no links yet).
INSERT INTO lab_resources (name, sort_order)
SELECT v.name, v.n FROM (
    SELECT 'Code' AS name, 1 AS n
    UNION ALL SELECT 'Information and Simulation Video', 2
    UNION ALL SELECT '国土地理院(標高データ)', 3
) v
WHERE NOT EXISTS (SELECT 1 FROM lab_resources);
INSERT INTO schema_migrations (filename) VALUES ('025_lab_resources.sql');

-- ===== Migration: 026_course_completed.sql =====
-- 026: a course can be marked completed.
--
-- A student marks their current course completed on /profile.php (and can
-- undo it). course_enrollments.completed_at records it per course;
-- profiles.course_completed_at mirrors the CURRENT course's value, like
-- profiles.course mirrors its code, so the Students page can split
-- current and completed students with a single-table read.
-- Adding a new course makes it current and not completed.
--
-- Additive. Idempotent.

ALTER TABLE course_enrollments ADD COLUMN completed_at DATE NULL AFTER enrolled_month;
ALTER TABLE profiles ADD COLUMN course_completed_at DATE NULL AFTER enrolled_month;
INSERT INTO schema_migrations (filename) VALUES ('026_course_completed.sql');

-- ===== Migration: 027_course_completed_snapshot.sql =====
-- 027: a completed course keeps the student's details as they were.
--
-- When a student marks their current course completed, the Students
-- sheet columns (course, ID, names, email, research title, enrolment,
-- next step, previous univ, theses, papers) are copied into
-- course_enrollments.snapshot as JSON. The teachers' Completed tab shows
-- one row per completed course from these copies, so later edits (or the
-- next course) do not change what was recorded. Undo is possible only
-- while that course is still the current one; it clears the copy.
--
-- Additive. Idempotent.

ALTER TABLE course_enrollments ADD COLUMN snapshot LONGTEXT NULL AFTER completed_at;
INSERT INTO schema_migrations (filename) VALUES ('027_course_completed_snapshot.sql');

-- ===== Migration: 028_potential_journals.sql =====
-- 028: potential journals, listed by teachers, taken by students.
--
-- Teachers keep a list of journals worth submitting to on /resources.php
-- (tab "Potential journals"). Every student sees it and can take a
-- journal (say they intend to submit there) or release it. Several
-- students can take the same journal.
--   lab_journals          the list (teachers add / edit / delete)
--   lab_journal_takers    who took which journal, and when
--
-- Additive. Idempotent.

CREATE TABLE IF NOT EXISTS lab_journals (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    name          VARCHAR(300) NOT NULL,
    publisher     VARCHAR(200) NULL,
    link          VARCHAR(1000) NULL,
    impact_factor VARCHAR(10) NULL,
    notes         TEXT NULL,
    sort_order    INT NOT NULL DEFAULT 0,
    created_by    INT NULL,
    updated_by    INT NULL,
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_lab_journal_creator FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL,
    CONSTRAINT fk_lab_journal_editor  FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

CREATE TABLE IF NOT EXISTS lab_journal_takers (
    journal_id INT NOT NULL,
    user_id    INT NOT NULL,
    taken_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (journal_id, user_id),
    INDEX idx_lab_journal_takers_user (user_id),
    CONSTRAINT fk_lab_journal_taker_journal FOREIGN KEY (journal_id) REFERENCES lab_journals(id) ON DELETE CASCADE,
    CONSTRAINT fk_lab_journal_taker_user    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
INSERT INTO schema_migrations (filename) VALUES ('028_potential_journals.sql');

-- ===== Migration: 029_journal_single_text.sql =====
-- 029: a potential journal is one block of text.
--
-- Teachers write everything about a journal (name, publisher, impact
-- factor, link, scope, deadline...) in a single text field. The first line
-- is shown as its title. Any existing rows are folded into that text.
--
-- Idempotent: the fold and the drops only run while the old columns exist.

ALTER TABLE lab_journals ADD COLUMN details TEXT NULL AFTER id;

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
    DROP COLUMN name,
    DROP COLUMN publisher,
    DROP COLUMN link,
    DROP COLUMN impact_factor,
    DROP COLUMN notes;

ALTER TABLE lab_journals MODIFY details TEXT NOT NULL;
INSERT INTO schema_migrations (filename) VALUES ('029_journal_single_text.sql');

-- ===== Migration: 030_journal_fields.sql =====
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
    ADD COLUMN name          VARCHAR(300)  NULL AFTER id,
    ADD COLUMN link          VARCHAR(1000) NULL AFTER name,
    ADD COLUMN impact_factor VARCHAR(10)   NULL AFTER link,
    ADD COLUMN cite_score    VARCHAR(10)   NULL AFTER impact_factor,
    ADD COLUMN apc           VARCHAR(100)  NULL AFTER cite_score,
    ADD COLUMN suggested_by  VARCHAR(100)  NULL AFTER apc;

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

ALTER TABLE lab_journals DROP COLUMN details;
ALTER TABLE lab_journals MODIFY name VARCHAR(300) NOT NULL;

ALTER TABLE lab_journal_takers
    ADD COLUMN published TINYINT(1) NOT NULL DEFAULT 0 AFTER user_id;
INSERT INTO schema_migrations (filename) VALUES ('030_journal_fields.sql');

-- ===== Migration: 031_journal_shared_list.sql =====
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
    ADD COLUMN submitted_by VARCHAR(300) NULL AFTER suggested_by,
    ADD COLUMN published    VARCHAR(300) NULL AFTER submitted_by;

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
INSERT INTO schema_migrations (filename) VALUES ('031_journal_shared_list.sql');

-- ===== Migration: 032_session_epoch.sql =====
-- 032: sign out other sessions when it matters.
--
-- users.session_epoch is copied into the session at sign-in. Every request
-- compares the two (require_login() in src/includes/auth.php), so raising
-- the number ends every existing session of that account at once. It is
-- raised when the password is changed or reset (by the owner or an admin)
-- and when an admin resets two-factor.
--
-- Idempotent.

ALTER TABLE users
    ADD COLUMN session_epoch INT UNSIGNED NOT NULL DEFAULT 0 AFTER totp_required;
INSERT INTO schema_migrations (filename) VALUES ('032_session_epoch.sql');

-- ===== Migration: 033_email_log.sql =====
-- 033: a record of every email the app sends or tries to send.
--
-- One row per message and recipient, written by mail_send() (src/includes/
-- mailer.php). Shown to admins at /admin/emails.php.
--
-- kind       'custom' for an admin-written message, otherwise the template
--            name (invitation, password_reset, ...).
-- status     'sent' (the SMTP server accepted it), 'failed' (it refused or
--            was unreachable) or 'skipped' (email is off or APP_URL is
--            missing, so nothing was attempted).
-- body_text  The text of the message. For system emails the link in the
--            button is replaced by "[link hidden]": it is a one-time
--            password-reset / invitation / restore token and must not be
--            readable from the database or the admin pages afterwards.
-- sent_by    The admin who sent a custom email; NULL for system emails.
-- resend_of  The log row this message repeats, if it was a resend.
--
-- Idempotent.

CREATE TABLE IF NOT EXISTS email_log (
    id            BIGINT AUTO_INCREMENT PRIMARY KEY,
    kind          VARCHAR(40)  NOT NULL,
    status        ENUM('sent','failed','skipped') NOT NULL,
    to_email      VARCHAR(150) NOT NULL,
    to_name       VARCHAR(150) NULL,
    to_user_id    INT NULL,
    from_email    VARCHAR(150) NULL,
    subject       VARCHAR(255) NOT NULL,
    body_text     MEDIUMTEXT NULL,
    error         TEXT NULL,
    smtp_host     VARCHAR(150) NULL,
    duration_ms   INT UNSIGNED NULL,
    sent_by       INT NULL,
    request_ip    VARCHAR(45) NULL,
    resend_of     BIGINT NULL,
    created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_el_time (created_at),
    INDEX idx_el_status (status, created_at),
    INDEX idx_el_kind (kind, created_at),
    INDEX idx_el_to (to_email),
    CONSTRAINT fk_el_to_user FOREIGN KEY (to_user_id) REFERENCES users (id) ON DELETE SET NULL,
    CONSTRAINT fk_el_sent_by FOREIGN KEY (sent_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
INSERT INTO schema_migrations (filename) VALUES ('033_email_log.sql');

-- ===== Migration: 034_app_settings.sql =====
-- 034: settings an admin changes in the app (not in .env).
--
-- One row per setting; a missing row means the default. Used by
-- Admin › Emails › Settings (see mail_setting() in src/includes/mailer.php):
--   mail.enabled          '1' / '0'   master switch for every email
--   mail.type.<kind>      '1' / '0'   one kind of email (invitation, password_reset, ...)
--   mail.log_days         '0' = keep the email log forever, else days to keep it
--
-- Idempotent.

CREATE TABLE IF NOT EXISTS app_settings (
    name        VARCHAR(64)  NOT NULL PRIMARY KEY,
    value       VARCHAR(255) NOT NULL,
    updated_by  INT NULL,
    updated_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_settings_user FOREIGN KEY (updated_by) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
INSERT INTO schema_migrations (filename) VALUES ('034_app_settings.sql');

-- ===== Migration: 035_activity_context.sql =====
-- 035: where and as whom each activity happened, for Admin › Activity.
--
-- ip           client address (client_ip(), proxy-aware)
-- user_agent   the browser's User-Agent, cut to 255 characters
-- acting_role  the role the person was acting as (admin / teacher / student)
--
-- Filled by log_activity() from now on; older rows stay NULL. The IP and
-- browser are cleared after 90 days by housekeeping (the event is kept).
--
-- Idempotent.

ALTER TABLE activity
    ADD COLUMN ip          VARCHAR(45)  NULL AFTER detail,
    ADD COLUMN user_agent  VARCHAR(255) NULL AFTER ip,
    ADD COLUMN acting_role VARCHAR(20)  NULL AFTER user_agent;

CREATE INDEX idx_act_time ON activity (created_at);
INSERT INTO schema_migrations (filename) VALUES ('035_activity_context.sql');

-- ===== Migration: 036_timestamps_for_timezone.sql =====
-- 036: every moment in time is a TIMESTAMP, so the site timezone can change.
--
-- Admin › Site settings sets one timezone for the site; db.php applies it to
-- PHP and to each database connection (SET time_zone). TIMESTAMP columns are
-- stored in UTC and converted on the way in and out, so they follow that
-- setting. DATETIME columns are not converted: they hold a bare clock time,
-- so after a timezone change expiry checks such as `expires_at < NOW()` would
-- be off by the UTC offset. These were the DATETIME columns that hold moments
-- (DATE columns such as award dates are calendar days and stay as they are).
--
-- Until now everything ran in UTC, so the existing values are UTC: the
-- session is set to UTC first, so the conversion reads them as such and no
-- stored moment moves.
--
-- Explicit DEFAULTs stop MariaDB from giving a NOT NULL TIMESTAMP the
-- automatic "ON UPDATE CURRENT_TIMESTAMP", which would rewrite an expiry
-- whenever the row changes.
--
-- Idempotent (MODIFY to the same type is a no-op).

SET time_zone = '+00:00';

ALTER TABLE password_resets
    MODIFY expires_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    MODIFY used_at    TIMESTAMP NULL DEFAULT NULL;

ALTER TABLE email_verifications
    MODIFY expires_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    MODIFY used_at    TIMESTAMP NULL DEFAULT NULL;

ALTER TABLE invites
    MODIFY expires_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    MODIFY revoked_at TIMESTAMP NULL DEFAULT NULL;

ALTER TABLE account_restorations
    MODIFY expires_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    MODIFY used_at    TIMESTAMP NULL DEFAULT NULL;

ALTER TABLE users
    MODIFY deleted_at         TIMESTAMP NULL DEFAULT NULL,
    MODIFY scheduled_purge_at TIMESTAMP NULL DEFAULT NULL;

ALTER TABLE two_factor
    MODIFY last_used_at TIMESTAMP NULL DEFAULT NULL;
INSERT INTO schema_migrations (filename) VALUES ('036_timestamps_for_timezone.sql');

-- ===== Migration: 037_utf8mb4_japanese.sql =====
-- Japanese (and every other script, emoji included) in every text column.
--
-- Tables created before this had no character set of their own, so they
-- took the database default, which on shared hosting (InfinityFree /
-- Byet.host) is often latin1. Saving Japanese into such a column fails
-- with "Incorrect string value" and the page shows an error.
--
-- This converts the database default and every table to utf8mb4 with
-- utf8mb4_general_ci. That collation keeps Japanese apart where
-- utf8mb4_unicode_ci does not (unicode_ci counts ハ = バ = パ and は = ハ as
-- the same letter, so "ハハ" would match "パパ").
--
-- HOW TO RUN (InfinityFree): phpMyAdmin > select your database >
--   1. Export > Quick > Go   (keep this backup file!)
--   2. Import > choose this file > Go
-- Safe to run more than once; tables that do not exist are skipped. Existing text is kept: latin1 text converts
-- as is (no Japanese could have been saved into latin1 columns before).

SET NAMES utf8mb4 COLLATE utf8mb4_general_ci;
SET FOREIGN_KEY_CHECKS = 0;

-- New tables created later default to utf8mb4 too.
ALTER DATABASE CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;

SET @sql := IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'schema_migrations') > 0,
  'ALTER TABLE `schema_migrations` ROW_FORMAT=DYNAMIC, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql := IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users') > 0,
  'ALTER TABLE `users` ROW_FORMAT=DYNAMIC, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql := IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'profiles') > 0,
  'ALTER TABLE `profiles` ROW_FORMAT=DYNAMIC, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql := IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'password_resets') > 0,
  'ALTER TABLE `password_resets` ROW_FORMAT=DYNAMIC, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql := IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'activity') > 0,
  'ALTER TABLE `activity` ROW_FORMAT=DYNAMIC, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql := IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'user_roles') > 0,
  'ALTER TABLE `user_roles` ROW_FORMAT=DYNAMIC, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql := IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'two_factor') > 0,
  'ALTER TABLE `two_factor` ROW_FORMAT=DYNAMIC, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql := IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_verifications') > 0,
  'ALTER TABLE `email_verifications` ROW_FORMAT=DYNAMIC, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql := IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'account_restorations') > 0,
  'ALTER TABLE `account_restorations` ROW_FORMAT=DYNAMIC, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql := IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'invites') > 0,
  'ALTER TABLE `invites` ROW_FORMAT=DYNAMIC, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql := IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'login_attempts') > 0,
  'ALTER TABLE `login_attempts` ROW_FORMAT=DYNAMIC, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql := IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'course_enrollments') > 0,
  'ALTER TABLE `course_enrollments` ROW_FORMAT=DYNAMIC, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql := IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lab_entries') > 0,
  'ALTER TABLE `lab_entries` ROW_FORMAT=DYNAMIC, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql := IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lab_publications') > 0,
  'ALTER TABLE `lab_publications` ROW_FORMAT=DYNAMIC, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql := IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lab_awards') > 0,
  'ALTER TABLE `lab_awards` ROW_FORMAT=DYNAMIC, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql := IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lab_templates') > 0,
  'ALTER TABLE `lab_templates` ROW_FORMAT=DYNAMIC, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql := IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lab_resources') > 0,
  'ALTER TABLE `lab_resources` ROW_FORMAT=DYNAMIC, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql := IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'lab_journals') > 0,
  'ALTER TABLE `lab_journals` ROW_FORMAT=DYNAMIC, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql := IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'email_log') > 0,
  'ALTER TABLE `email_log` ROW_FORMAT=DYNAMIC, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;
SET @sql := IF((SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'app_settings') > 0,
  'ALTER TABLE `app_settings` ROW_FORMAT=DYNAMIC, CONVERT TO CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci',
  'SELECT 1');
PREPARE s FROM @sql; EXECUTE s; DEALLOCATE PREPARE s;

SET FOREIGN_KEY_CHECKS = 1;

INSERT IGNORE INTO schema_migrations (filename) VALUES ('037_utf8mb4_japanese.sql');

-- Check: every row below should say utf8mb4_general_ci.
SELECT TABLE_NAME, TABLE_COLLATION FROM information_schema.TABLES
 WHERE TABLE_SCHEMA = DATABASE() ORDER BY TABLE_NAME;
