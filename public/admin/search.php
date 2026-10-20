<?php
/**
 * Admin: people for the command palette (Ctrl/⌘ + K in the top bar).
 *
 * Route:  GET /admin/search.php?q=<text>   JSON: {"users": [{id, name, email, roles, active, url}]}
 * Auth:   require_admin()
 *
 * At most 8 people whose name, email or student ID contains the text. The
 * pages the palette offers are listed in header.php, not here.
 */
declare(strict_types=1);
require_once __DIR__ . '/../../src/includes/auth.php';
require_once __DIR__ . '/../../src/includes/db.php';
require_once __DIR__ . '/../../src/includes/url.php';
require_admin();

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
$q = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100);
if ($q === '') { echo '{"users":[]}'; exit; }
$like = '%' . addcslashes($q, '%_\\') . '%';
$st = $pdo->prepare(
    "SELECT u.id, u.name, u.email, u.deleted_at,
            (SELECT GROUP_CONCAT(r.role ORDER BY FIELD(r.role, 'admin', 'teacher', 'student')) FROM user_roles r WHERE r.user_id = u.id) AS roles
       FROM users u LEFT JOIN profiles p ON p.user_id = u.id
      WHERE u.name LIKE :a OR u.email LIKE :b OR p.student_id LIKE :c
      ORDER BY u.deleted_at IS NOT NULL, u.name LIMIT 8"
);
$st->execute(['a' => $like, 'b' => $like, 'c' => $like]);
$out = [];
foreach ($st as $u) {
    $out[] = ['id' => (int)$u['id'], 'name' => (string)$u['name'], 'email' => (string)$u['email'],
              'roles' => (string)$u['roles'], 'active' => $u['deleted_at'] === null, 'url' => url_admin_user() . '?id=' . (int)$u['id']];
}
echo json_encode(['users' => $out], JSON_UNESCAPED_UNICODE);
