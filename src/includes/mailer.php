<?php
declare(strict_types=1);

// Load hosting-panel settings when running on shared hosting.
require_once __DIR__ . '/hosting_config.php';

/**
 * Sending email over SMTP (PHPMailer), and the one layout every message
 * uses. The messages themselves are in mail_templates.php.
 *
 * Configuration can come from environment variables, $_ENV, or $_SERVER.
 * This supports Docker, local development, and shared hosting.
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/ratelimit.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;

/**
 * Read configuration from getenv(), $_ENV, or $_SERVER.
 *
 * Environment variables take precedence, followed by $_ENV and $_SERVER.
 */
if (!function_exists('mail_config_value')) {
    function mail_config_value(
        string $key,
        string $default = ''
    ): string {
        $value = getenv($key);

        if ($value !== false && trim((string) $value) !== '') {
            return (string) $value;
        }

        $value = $_ENV[$key] ?? $_SERVER[$key] ?? null;

        if (is_string($value) && trim($value) !== '') {
            return $value;
        }

        return $default;
    }
}

/** Is sending configured? */
function mail_enabled(): bool
{
    return mail_config_value('MAIL_HOST') !== ''
        && mail_config_value('MAIL_FROM_ADDRESS') !== '';
}

/**
 * Every kind of email the app sends, for Admin › Emails › Settings and the
 * log. Each can be switched off there; "off" says what happens instead.
 */
function mail_types(): array
{
    return [
        'invitation' => [
            'group' => 'Accounts',
            'label' => 'Invitation',
            'to' => 'The invited address',
            'when' => 'A teacher or admin creates an invitation for one email address.',
            'off' => 'The link is still shown on the Invitations page to copy and send yourself.',
        ],
        'password_reset' => [
            'group' => 'Accounts',
            'label' => 'Password reset link',
            'to' => 'The account holder',
            'when' => 'Someone uses “Forgot password”.',
            'off' => 'Nobody can reset a forgotten password themselves; an admin has to set a new one under Users.',
        ],
        'verify_new_email' => [
            'group' => 'Accounts',
            'label' => 'Confirm new email',
            'to' => 'The new address',
            'when' => 'A member changes their sign-in email.',
            'off' => 'A new address is used straight away, without proving the member owns it.',
        ],
        'account_deactivated' => [
            'group' => 'Accounts',
            'label' => 'Account deactivated (restore link)',
            'to' => 'The account holder',
            'when' => 'A member deactivates their own account.',
            'off' => 'No restore link is sent; to come back within 30 days they must ask an admin.',
        ],
        'password_changed' => [
            'group' => 'Security notices',
            'label' => 'Password changed',
            'to' => 'The account holder',
            'when' => 'A password is changed or reset, by the member or an admin.',
            'off' => 'Members are not told when their password changes, so a stolen account may go unnoticed.',
        ],
        'email_changed' => [
            'group' => 'Security notices',
            'label' => 'Sign-in email changed',
            'to' => 'The old address',
            'when' => 'The sign-in email of an account changes.',
            'off' => 'The previous address is not told that the account moved to a new one.',
        ],
        'two_factor_off' => [
            'group' => 'Security notices',
            'label' => 'Two-factor turned off',
            'to' => 'The account holder',
            'when' => 'Two-factor sign-in is turned off or reset by an admin.',
            'off' => 'Members are not told when their two-factor protection is removed.',
        ],
        'backup_code_used' => [
            'group' => 'Security notices',
            'label' => 'Backup code used',
            'to' => 'The account holder',
            'when' => 'Someone signs in with a two-factor backup code.',
            'off' => 'Members are not told when one of their backup codes is used.',
        ],
        'custom' => [
            'group' => 'Messages',
            'label' => 'Custom emails from admins',
            'to' => 'Whoever the admin picks',
            'when' => 'An admin sends a message from Emails › Write an email.',
            'off' => 'The Write an email form is closed.',
        ],
    ];
}

/**
 * A setting from app_settings, or $default when it was never saved.
 * Read once per request.
 */
function mail_setting(string $name, string $default = ''): string
{
    static $cache = null;
    global $pdo;

    if ($name === '') {
        $cache = null;
        return '';
    }

    if ($cache === null) {
        $cache = [];

        try {
            if ($pdo instanceof PDO) {
                $cache = $pdo->query(
                    "SELECT name, value FROM app_settings WHERE name LIKE 'mail.%'"
                )->fetchAll(PDO::FETCH_KEY_PAIR);
            }
        } catch (Throwable $e) {
            // app_settings may not have been migrated yet.
        }
    }

    return array_key_exists($name, $cache)
        ? (string) $cache[$name]
        : $default;
}

/** Save settings (name => value) as $by. */
function mail_settings_save(PDO $pdo, array $values, int $by): void
{
    $st = $pdo->prepare(
        'INSERT INTO app_settings (name, value, updated_by)
         VALUES (:n, :v, :b)
         ON DUPLICATE KEY UPDATE
         value = VALUES(value),
         updated_by = VALUES(updated_by)'
    );

    foreach ($values as $n => $v) {
        $st->execute([
            'n' => $n,
            'v' => (string) $v,
            'b' => $by,
        ]);
    }

    mail_setting('');
}

/**
 * Will an email of this kind be sent?
 */
function mail_type_enabled(string $kind): bool
{
    return mail_enabled()
        && mail_setting('mail.enabled', '1') === '1'
        && mail_setting('mail.type.' . $kind, '1') === '1';
}

/** Why an email of this kind would not be sent. */
function mail_off_reason(string $kind): string
{
    if (!mail_enabled()) {
        return 'Email is off: MAIL_HOST or MAIL_FROM_ADDRESS is not set.';
    }

    if (mail_setting('mail.enabled', '1') !== '1') {
        return 'All email is turned off in Emails › Settings.';
    }

    if (mail_setting('mail.type.' . $kind, '1') !== '1') {
        return 'This kind of email is turned off in Emails › Settings.';
    }

    return '';
}

/**
 * The site's base URL for links in emails.
 */
function mail_app_url(): string
{
    $url = rtrim(trim(mail_config_value('APP_URL')), '/');

    if ($url !== '') {
        return $url;
    }

    if (
        mail_config_value('APP_ENV') === 'production'
        || empty($_SERVER['HTTP_HOST'])
    ) {
        return '';
    }

    $https = (
        !empty($_SERVER['HTTPS'])
        && $_SERVER['HTTPS'] !== 'off'
    ) || (
        mail_config_value('TRUST_PROXY') === '1'
        && ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https'
    );

    return ($https ? 'https' : 'http')
        . '://'
        . $_SERVER['HTTP_HOST'];
}

/** An absolute link for an email, from a site path. */
function mail_link(string $path): string
{
    $base = mail_app_url();

    return $base === ''
        ? ''
        : $base . '/' . ltrim($path, '/');
}

/**
 * Send one message.
 *
 * Returns true when the SMTP server accepts it.
 */
function mail_send(
    string $toEmail,
    string $toName,
    array $msg,
    array $meta = []
): bool {
    $started = microtime(true);

    if (($meta['kind'] ?? '') === '') {
        $meta['kind'] = 'other';

        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 4) as $f) {
            if (
                str_starts_with($f['function'], 'mail_')
                && $f['function'] !== 'mail_send'
            ) {
                $meta['kind'] = substr($f['function'], 5);
                break;
            }
        }
    }

    $log = fn(string $status, string $error = '') =>
        mail_log(
            $toEmail,
            $toName,
            $msg,
            $meta,
            $status,
            $error,
            (int) round((microtime(true) - $started) * 1000)
        );

    if (($off = mail_off_reason((string) $meta['kind'])) !== '') {
        error_log(
            '[mail] Not sent (' . $off . '): "'
            . $msg['subject'] . '" to ' . $toEmail
        );

        $log('skipped', $off);
        return false;
    }

    if (
        !empty($msg['button'])
        && ($msg['button']['url'] ?? '') === ''
    ) {
        error_log(
            '[mail] Not sent: APP_URL is not set, so the link in "'
            . $msg['subject'] . '" cannot be built.'
        );

        $log(
            'skipped',
            'APP_URL is not set, so the link in this email cannot be built.'
        );

        return false;
    }

    $m = new PHPMailer(true);

    try {
        $m->isSMTP();

        // Read SMTP configuration from the hosting environment.
        $m->Host = mail_config_value('MAIL_HOST');

        $m->Port = (int) mail_config_value('MAIL_PORT', '587');

        $enc = strtolower(
            mail_config_value('MAIL_ENCRYPTION', 'tls')
        );

        $m->SMTPSecure = $enc === 'ssl'
            ? PHPMailer::ENCRYPTION_SMTPS
            : ($enc === 'tls'
                ? PHPMailer::ENCRYPTION_STARTTLS
                : '');

        $m->SMTPAutoTLS = $enc !== 'none';

        $user = mail_config_value('MAIL_USERNAME');

        $m->SMTPAuth = $user !== '';
        $m->Username = $user;
        $m->Password = mail_config_value('MAIL_PASSWORD');

        $m->Timeout = 10;
        $m->CharSet = PHPMailer::CHARSET_UTF8;
        $m->Encoding = PHPMailer::ENCODING_QUOTED_PRINTABLE;

        $m->setFrom(
            mail_config_value('MAIL_FROM_ADDRESS'),
            mail_config_value('MAIL_FROM_NAME', 'Profile System')
        );

        $m->addAddress($toEmail, $toName);

        $m->Subject = $msg['subject'];
        $m->isHTML(true);
        $m->Body = mail_render_html($msg);
        $m->AltBody = mail_render_text($msg);

        $m->send();

        $log('sent');
        return true;
    } catch (MailException $e) {
        error_log(
            '[mail] Failed: "' . $msg['subject']
            . '" to ' . $toEmail . ': ' . $m->ErrorInfo
        );

        $log(
            'failed',
            $m->ErrorInfo !== '' ? $m->ErrorInfo : $e->getMessage()
        );

        return false;
    }
}

/**
 * Record one send in email_log.
 * Never throws: a logging problem must not stop the email or break the page.
 */
function mail_log(
    string $toEmail,
    string $toName,
    array $msg,
    array $meta,
    string $status,
    string $error,
    int $ms
): void {
    global $pdo;

    if (!($pdo instanceof PDO)) {
        return;
    }

    try {
        $kind = (string) ($meta['kind'] ?? 'other');

        // Keep one-time links out of the email log.
        $stored = $kind === 'custom'
            ? $msg
            : array_merge($msg, [
                'button' => !empty($msg['button'])
                    ? [
                        'label' => $msg['button']['label'],
                        'url' => '[link hidden]',
                    ]
                    : null,
            ]);

        $user = $pdo->prepare(
            'SELECT id FROM users WHERE email = :e LIMIT 1'
        );

        $user->execute(['e' => $toEmail]);
        $uid = $user->fetchColumn();

        $pdo->prepare(
            'INSERT INTO email_log (
                kind, status, to_email, to_name, to_user_id,
                from_email, subject, body_text, error,
                smtp_host, duration_ms, sent_by, request_ip, resend_of
            ) VALUES (
                :k, :s, :to, :tn, :tu, :f, :sub, :b, :err,
                :h, :ms, :by, :ip, :re
            )'
        )->execute([
            'k' => mb_substr($kind, 0, 40),
            's' => $status,
            'to' => mb_substr($toEmail, 0, 150),
            'tn' => $toName !== ''
                ? mb_substr($toName, 0, 150)
                : null,
            'tu' => $uid !== false ? (int) $uid : null,
            'f' => mail_config_value('MAIL_FROM_ADDRESS') ?: null,
            'sub' => mb_substr((string) $msg['subject'], 0, 255),
            'b' => mail_render_text($stored),
            'err' => $error !== '' ? $error : null,
            'h' => mail_config_value('MAIL_HOST') ?: null,
            'ms' => $ms,
            'by' => $meta['sent_by'] ?? null,
            'ip' => PHP_SAPI === 'cli' ? null : client_ip(),
            're' => $meta['resend_of'] ?? null,
        ]);
    } catch (Throwable $e) {
        error_log(
            '[mail] Could not write the email log: '
            . $e->getMessage()
        );
    }
}

/** Plain-text version of an email. */
function mail_render_text(array $msg): string
{
    $out = [$msg['heading'], ''];

    foreach ($msg['paras'] ?? [] as $p) {
        $out[] = $p;
        $out[] = '';
    }

    if (!empty($msg['button'])) {
        $out[] = $msg['button']['label'] . ':';
        $out[] = $msg['button']['url'];
        $out[] = '';
    }

    foreach ($msg['after'] ?? [] as $p) {
        $out[] = $p;
        $out[] = '';
    }

    $out[] = '--';
    $out[] = 'Profile System'
        . (mail_app_url() !== '' ? ' · ' . mail_app_url() : '');

    if (!empty($msg['reason'])) {
        $out[] = $msg['reason'];
    }

    return implode("\n", $out);
}

/**
 * HTML version of an email.
 */
function mail_render_html(array $msg): string
{
    $h = fn(string $s): string =>
        htmlspecialchars(
            $s,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );

    $p = fn(string $s, string $color = '#3b3632'): string =>
        '<p style="margin:0 0 16px;font-size:15px;line-height:1.6;color:'
        . $color . ';">' . nl2br($h($s)) . '</p>';

    $font = "font-family:'IBM Plex Sans',-apple-system,'Segoe UI',"
        . "'Hiragino Sans','Noto Sans JP',sans-serif;";

    $body = '<h1 style="margin:0 0 16px;font-family:Georgia,'
        . '\'Times New Roman\',serif;font-size:24px;line-height:1.25;'
        . 'color:#1f1b18;">'
        . $h($msg['heading'])
        . '</h1>';

    foreach ($msg['paras'] ?? [] as $s) {
        $body .= $p($s);
    }

    if (!empty($msg['button'])) {
        $url = $h($msg['button']['url']);

        $body .= '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:8px 0 24px;"><tr>'
            . '<td style="border-radius:6px;background:#233a6b;">'
            . '<a href="' . $url . '" style="display:inline-block;padding:12px 22px;font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;border-radius:6px;">'
            . $h($msg['button']['label'])
            . '</a></td></tr></table>'
            . $p(
                'If the button does not work, copy this address into your browser:',
                '#6b645d'
            )
            . '<p style="margin:-8px 0 20px;font-size:13px;line-height:1.5;word-break:break-all;">'
            . '<a href="' . $url . '" style="color:#233a6b;">'
            . $url
            . '</a></p>';
    }

    foreach ($msg['after'] ?? [] as $s) {
        $body .= $p($s, '#6b645d');
    }

    $site = mail_app_url();

    return '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width">'
        . '<title>' . $h($msg['subject']) . '</title></head>'
        . '<body style="margin:0;padding:0;background:#f1efeb;' . $font . '">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f1efeb;"><tr><td align="center" style="padding:24px 12px;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;">'
        . '<tr><td style="background:#1b2a4f;border-radius:8px 8px 0 0;padding:14px 24px;">'
        . '<span style="display:inline-block;width:26px;height:26px;line-height:26px;text-align:center;border-radius:5px;background:#ffffff;color:#1b2a4f;font-family:Georgia,serif;font-weight:700;font-size:14px;vertical-align:middle;">P</span>'
        . '<span style="margin-left:10px;color:#ffffff;font-weight:700;font-size:15px;vertical-align:middle;">Profile System</span>'
        . '</td></tr>'
        . '<tr><td style="background:#ffffff;border:1px solid #dcd8d2;border-top:0;border-radius:0 0 8px 8px;padding:28px 24px 12px;">'
        . $body
        . '</td></tr>'
        . '<tr><td style="padding:16px 8px;font-size:12px;line-height:1.5;color:#6b645d;text-align:center;">'
        . (!empty($msg['reason']) ? $h($msg['reason']) . '<br>' : '')
        . 'Profile System'
        . ($site !== ''
            ? ' · <a href="' . $h($site)
                . '" style="color:#6b645d;">'
                . $h(preg_replace('#^https?://#', '', $site))
                . '</a>'
            : '')
        . '</td></tr></table></td></tr></table></body></html>';
}