<?php
/**
 * Teacher's student directory (read-only).
 *
 * Route:  GET /students.php               every field of every student in one wide table
 *                                         (sheet only: there is no list view here)
 *         GET /students.php?id=<id>       one student's full profile
 * Auth:   require_login(); the ACTIVE role must be teacher. Admins manage
 *         accounts on /admin/ and have no Students sheet.
 *
 * Lists every active user who holds the student role. The page itself is
 * rendered by src/includes/directory.php (shared with /admin/index.php);
 * see that file for the query parameters.
 */
declare(strict_types=1);
require_once __DIR__ . '/../src/includes/auth.php';
require_once __DIR__ . '/../src/includes/db.php';
require_once __DIR__ . '/../src/includes/url.php';
require_once __DIR__ . '/../src/includes/ui.php';
require_login();
ensure_active_role_valid();

$page_id = 'students';

if (active_user_role() !== 'teacher') {
    require_once __DIR__ . '/../src/includes/error_page.php';
    render_error_page(403, 'The Students sheet is for teachers.');
}

$DIR = [
    'scope' => 'students',
    'base'  => url_students(),
    'title' => 'Students',
    'one'   => 'student',
    'many'  => 'students',
];
require __DIR__ . '/../src/includes/directory.php';
