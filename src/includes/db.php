<?php
declare(strict_types=1);

/**
 * Database connection.
 *
 * All values are read from environment variables so the same code works
 * identically on:
 *   - Docker (env vars injected from docker-compose / .env)
 *   - Local dev (set in your shell or php-fpm pool)
 *   - CI / production (set in your platform's secret store)
 *
 * Override anything by setting the env var before PHP starts, e.g. in
 * docker-compose.yml under `environment:` or in a project-level .env file.
 *
 * Required:
 *   DB_HOST     (default: profile_db) - Docker service name, or localhost for native
 *   DB_NAME     (default: profile_system)
 *   DB_USER     (default: root)
 *   DB_PASSWORD (default: rootpass)   - override this in production
 *   DB_PORT     (default: 3306)
 *
 * The app connects as APP_DB_USER (falls back to DB_USER). In
 * production, set APP_DB_USER + DB_APP_PASSWORD to a dedicated,
 * non-root account created by the web entrypoint.
 */

$host    = getenv('DB_HOST')     ?: 'profile_db';
$port    = getenv('DB_PORT')     ?: '3306';
$dbname  = getenv('DB_NAME')     ?: 'profile_system';
$user    = getenv('APP_DB_USER') ?: (getenv('DB_USER') ?: 'root');
$pass    = getenv('DB_APP_PASSWORD') ?: (getenv('DB_PASSWORD') ?: 'rootpass');
$charset = 'utf8mb4';

// DSN with port. mariadb / mysql client libs accept the unix socket fallback
// when host is empty, but we always pass a TCP host:port for portability.
$dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=%s', $host, $port, $dbname, $charset);

$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

// Retry briefly while the DB container boots. This handles the
// "DB not ready yet on first start" race without failing the request.
$attempts = 0;
$maxAttempts = 10;
$lastError = null;
$pdo = null;

while ($attempts < $maxAttempts) {
    try {
        $pdo = new PDO($dsn, $user, $pass, $options);
        break;
    } catch (PDOException $e) {
        $lastError = $e;
        $attempts++;
        if ($attempts >= $maxAttempts) { break; }
        usleep(500_000); // 0.5s
    }
}

if (!$pdo) {
    error_log('Database connection error after ' . $maxAttempts . ' attempts: ' . ($lastError ? $lastError->getMessage() : 'unknown'));
    // The cause goes to the error log above; visitors get the standalone
    // "temporarily unavailable" page (it needs no session or database).
    require_once __DIR__ . '/error_page.php';
    render_error_page(503, '', minimal: true);
}

// ---------------------------------------------------------------------------
// Site timezone (Admin › Site settings, app_settings 'app.timezone'; else the
// APP_TIMEZONE env var; else UTC). PHP and this connection both use it, so
// every time shown, every "today" and every chart follows it, and PHP and SQL
// always agree on what a time string means. Moments are stored as TIMESTAMP
// (UTC inside, migration 036), so changing the zone never moves one.
// ---------------------------------------------------------------------------
$appTimezone = 'UTC';
$tzEnv = trim((string)getenv('APP_TIMEZONE'));
if ($tzEnv !== '' && in_array($tzEnv, timezone_identifiers_list(), true)) { $appTimezone = $tzEnv; }
try {
    $tzSaved = $pdo->query("SELECT value FROM app_settings WHERE name = 'app.timezone'")->fetchColumn();
    if (is_string($tzSaved) && in_array($tzSaved, timezone_identifiers_list(), true)) { $appTimezone = $tzSaved; }
} catch (Throwable $e) { /* app_settings not migrated yet: keep the default */ }
date_default_timezone_set($appTimezone);
try {
    // A named zone follows daylight saving; it needs MariaDB's timezone tables.
    $pdo->exec('SET time_zone = ' . $pdo->quote($appTimezone));
} catch (Throwable $e) {
    // No timezone tables: today's offset (right except across a DST change).
    $pdo->exec("SET time_zone = '" . (new DateTimeImmutable('now'))->format('P') . "'");
}
unset($tzEnv, $tzSaved);

/** The site timezone, e.g. "Asia/Dhaka". */
function app_timezone(): string
{
    return date_default_timezone_get();
}

/** "Asia/Dhaka (UTC+06:00)", for notes such as "times are in …". */
function app_timezone_label(?string $tz = null): string
{
    $zone = new DateTimeZone($tz ?? app_timezone());
    $offset = (new DateTimeImmutable('now', $zone))->format('P');
    if ($zone->getName() === 'UTC') { return 'UTC'; }
    return str_replace('_', ' ', $zone->getName()) . ' (UTC' . $offset . ')';
}
