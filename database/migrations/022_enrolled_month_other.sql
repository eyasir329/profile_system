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
