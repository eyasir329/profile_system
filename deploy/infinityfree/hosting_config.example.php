<?php
declare(strict_types=1);

/**
 * Byet.host deployment settings.
 *
 * This file is intentionally outside htdocs. Edit the values below in
 * Byet.host File Manager or FTP after uploading. Never commit real credentials.
 */
$profileSystemHostingConfig = [
    // Copy these values from your Byet.host control panel > MySQL Databases.
    'DB_HOST' => 'CHANGE_ME',
    'DB_PORT' => 'CHANGE_ME',
    'DB_NAME' => 'CHANGE_ME',
    'DB_USER' => 'CHANGE_ME',
    'DB_PASSWORD' => 'CHANGE_ME',

    // Public HTTPS address, with no trailing slash.
    'APP_URL' => 'https://example.com',
    'APP_ENV' => 'production',
    'APP_TIMEZONE' => 'Asia/Dhaka',

    // This package expects HTTPS. Set to 0 only temporarily if you do not yet have SSL.
    'COOKIE_SECURE' => '1',
    'TRUST_PROXY' => '0',

    // Gmail SMTP defaults. Email stays disabled until username, app password, and from address are set.
    // Some free hosts restrict outbound SMTP; test with your provider.
    'MAIL_HOST' => 'smtp.gmail.com',
    'MAIL_PORT' => '587',
    'MAIL_ENCRYPTION' => 'tls',
    'MAIL_USERNAME' => 'CHANGE_ME',
    'MAIL_PASSWORD' => 'CHANGE_ME',
    'MAIL_FROM_ADDRESS' => 'CHANGE_ME',
    'MAIL_FROM_NAME' => 'Lab Profile System',
];

foreach ($profileSystemHostingConfig as $key => $value) {
    $value = (string) $value;

    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;

    if (function_exists('putenv')) {
        @putenv($key . '=' . $value);
    }
}

unset($profileSystemHostingConfig);
