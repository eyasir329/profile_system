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
) ENGINE=InnoDB;

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
