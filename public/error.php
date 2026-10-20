<?php
/**
 * Error page for errors Apache produces itself (unknown URL, denied path,
 * server error). Apache's ErrorDocument points here; see
 * docker/apache/app.conf. The status comes from REDIRECT_STATUS.
 *
 * Route:  any URL that has no file  ->  404
 *         GET /error.php?code=<n>     ->  that error (for checking the page)
 * Auth:   none
 */
declare(strict_types=1);
require_once __DIR__ . '/../src/includes/error_page.php';

$code = (int)($_SERVER['REDIRECT_STATUS'] ?? 0);
if ($code < 400) { $code = (int)($_GET['code'] ?? 404); }
if (!in_array($code, [400, 401, 403, 404, 405, 410, 419, 429, 500, 503], true)) { $code = 404; }

// A 500 or 503 from Apache may mean the database or PHP is unhealthy:
// use the standalone page, which needs neither.
render_error_page($code, '', in_array($code, [500, 503], true));
