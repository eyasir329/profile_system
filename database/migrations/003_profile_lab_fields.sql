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
