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
