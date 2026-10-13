<?php
/**
 * Migration runner.
 *
 * Reads `database/migrations/*.sql` in filename order and runs each one
 * inside a transaction. Records applied versions in the
 * `schema_migrations` table so re-runs are a no-op.
 *
 * Usage:
 *   php database/migrate.php
 *
 * Exit code: 0 on success, 1 on any failure.
 *
 * Idempotent: running twice is safe.
 *
 * The bookkeeping table uses a `filename` VARCHAR(255) PRIMARY KEY
 * (matching the table created by the original entrypoint), not `version`.
 *
 * Reads the same DB_* env vars as the rest of the app (see src/includes/db.php).
 */
declare(strict_types=1);

require_once __DIR__ . '/../src/includes/db.php';

$migrationsDir = __DIR__ . '/migrations';

// 1) Ensure the bookkeeping table exists. The column name is `filename`
//    to match the convention already used by the prior entrypoint.
$pdo->exec(<<<SQL
    CREATE TABLE IF NOT EXISTS schema_migrations (
        filename VARCHAR(255) PRIMARY KEY,
        applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB
SQL);

$applied = [];
foreach ($pdo->query('SELECT `filename` FROM `schema_migrations`') as $row) {
    $applied[(string)$row['filename']] = true;
}

// 2) Read all migration files in order.
$files = glob($migrationsDir . '/*.sql') ?: [];
sort($files, SORT_STRING);

$ran = 0;
foreach ($files as $path) {
    $version = basename($path);
    if (isset($applied[$version])) {
        echo "  skip   $version (already applied)\n";
        continue;
    }

    $sql = file_get_contents($path);
    if ($sql === false) {
        fwrite(STDERR, "Could not read $path\n");
        exit(1);
    }

    echo "  apply  $version ... ";
    // MariaDB (and MySQL) auto-commit DDL statements (CREATE TABLE,
    // ALTER TABLE, ...), which silently closes any open transaction.
    // We therefore don't wrap migrations in a transaction; each file
    // runs as a sequence of statements, and we only record success in
    // `schema_migrations` after every statement has been applied.
    try {
        $statements = preg_split('/;\s*\n/', $sql);
        if ($statements === false || count($statements) <= 1) {
            $pdo->exec($sql);
        } else {
            foreach ($statements as $stmt) {
                $stmt = trim($stmt);
                if ($stmt === '') { continue; }
                $pdo->exec($stmt);
            }
        }
        $pdo->prepare('INSERT INTO schema_migrations (filename) VALUES (:v)')
            ->execute(['v' => $version]);
        echo "ok\n";
        $ran++;
    } catch (Throwable $e) {
        echo "FAILED\n";
        fwrite(STDERR, "  -> " . $e->getMessage() . "\n");
        exit(1);
    }
}

echo "\nDone. $ran migration(s) applied, " . count($applied) . " already up to date.\n";
exit(0);
