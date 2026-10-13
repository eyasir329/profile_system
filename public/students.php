<?php
/**
 * Teacher's student directory (read-only).
 *
 * Route:  GET /students.php               compact list
 *         GET /students.php?view=sheet    every field in one wide table
 *         GET /students.php?id=<id>       one student's full profile
 * Auth:   require_login(); the ACTIVE role must be teacher (or admin).
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

if (!in_array(active_user_role(), ['teacher', 'admin'], true)) {
    http_response_code(403);
    $page_title = 'Students';
    require __DIR__ . '/../src/includes/header.php';
    ?>
    <div class="page-narrow">
      <section class="card">
        <h1 class="page-title text-2xl">Teachers only</h1>
        <p class="page-desc">The student directory is available while you are acting as a teacher.
          <?= has_role('teacher') ? 'Use the role switcher in the top bar to switch to Teacher.' : 'Your account does not have the teacher role.' ?></p>
        <a class="btn btn-primary mt-6" href="<?= e(url_dashboard()) ?>">Back to dashboard</a>
      </section>
    </div>
    <?php
    require __DIR__ . '/../src/includes/footer.php';
    exit;
}

$DIR = [
    'scope' => 'students',
    'base'  => url_students(),
    'title' => 'Students',
    'one'   => 'student',
    'many'  => 'students',
];
require __DIR__ . '/../src/includes/directory.php';
