<?php
/**
 * Admin: every user, with create / edit / deactivate / delete.
 *
 * Route:  GET /admin/  or  /admin/index.php    every account: roles, status, joined
 *         GET /admin/index.php?id=<id>          one user's account and lab details
 * Auth:   require_admin() (the ACTIVE role must be admin)
 *
 * Account management only: role and status filters and the admin
 * actions. The Students sheet (teachers' work) is not part of it. It is rendered by src/includes/directory.php; see that file for
 * the query parameters. Creating, editing and deleting happen on
 * /admin/user.php.
 */
declare(strict_types=1);
require_once __DIR__ . '/../../src/includes/auth.php';
require_once __DIR__ . '/../../src/includes/db.php';
require_once __DIR__ . '/../../src/includes/url.php';
require_once __DIR__ . '/../../src/includes/ui.php';
require_admin();

$page_id = 'admin';
$DIR = [
    'scope' => 'users',
    'base'  => url_admin(),
    'title' => 'Users',
    'one'   => 'user',
    'many'  => 'users',
];
require __DIR__ . '/../../src/includes/directory.php';
