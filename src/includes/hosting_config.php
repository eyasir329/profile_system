<?php
declare(strict_types=1);

// Everything is UTF-8 (Japanese names, titles, emails): PHP output, the
// mbstring functions and the HTTP Content-Type all agree.
ini_set('default_charset', 'UTF-8');
if (function_exists('mb_internal_encoding')) {
    mb_internal_encoding('UTF-8');
}

/**
 * Read configuration from environment variables, $_ENV, or $_SERVER.
 * Shared hosting (InfinityFree) disables putenv(), so getenv() never sees
 * the values set in hosting_config.php: always read settings through this.
 */
if (!function_exists('app_env')) {
    function app_env(string $key, string $default = ''): string
    {
        $value = getenv($key);

        if ($value !== false && trim($value) !== '') {
            return (string) $value;
        }

        $value = $_ENV[$key] ?? $_SERVER[$key] ?? null;

        if (is_string($value) && trim($value) !== '') {
            return $value;
        }

        return $default;
    }
}

$configFile = dirname(__DIR__, 2) . '/hosting_config.php';

if (!is_file($configFile) || !is_readable($configFile)) {
    // Normal under Docker/local development: settings come from the
    // container environment (.env), not from a hosting_config.php file.
    return;
}

require_once $configFile;
