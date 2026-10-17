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
    DROP COLUMN IF EXISTS university_email;

ALTER TABLE users
    DROP COLUMN IF EXISTS username;
