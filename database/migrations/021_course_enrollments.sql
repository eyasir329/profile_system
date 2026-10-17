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
) ENGINE=InnoDB;

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
