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

ALTER TABLE course_enrollments ADD COLUMN IF NOT EXISTS snapshot LONGTEXT NULL AFTER completed_at;
