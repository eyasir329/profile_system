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
  ADD COLUMN IF NOT EXISTS degree_type ENUM('bachelor','msc','phd') NULL AFTER role;

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
) ENGINE=InnoDB;

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
) ENGINE=InnoDB;

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
) ENGINE=InnoDB;

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
) ENGINE=InnoDB;

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
) ENGINE=InnoDB;

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
) ENGINE=InnoDB;

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
) ENGINE=InnoDB;

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
) ENGINE=InnoDB;

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
) ENGINE=InnoDB;

COMMIT;
