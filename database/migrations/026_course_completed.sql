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

ALTER TABLE course_enrollments ADD COLUMN IF NOT EXISTS completed_at DATE NULL AFTER enrolled_month;
ALTER TABLE profiles ADD COLUMN IF NOT EXISTS course_completed_at DATE NULL AFTER enrolled_month;
