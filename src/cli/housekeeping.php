<?php
/**
 * Run housekeeping now (see src/includes/housekeeping.php).
 *   make purge
 *   docker compose exec web php /var/www/src/cli/housekeeping.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../includes/db.php';
require __DIR__ . '/../includes/housekeeping.php';
$r = housekeeping_run($pdo);
printf("Deleted %d deactivated account(s), %d old sign-in record(s), %d old one-time link(s).\n",
    $r['purged'], $r['attempts'], $r['tokens']);
