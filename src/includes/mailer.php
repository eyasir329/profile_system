<?php
declare(strict_types=1);

/**
 * Sending email over SMTP (PHPMailer), and the one layout every message
 * uses. The messages themselves are in mail_templates.php.
 *
 * Configuration comes from the environment (.env, passed in by compose.yml):
 *   MAIL_HOST          SMTP server. Empty = email is off (nothing is sent;
 *                      in development the pages show the links instead).
 *   MAIL_PORT          587 (STARTTLS, the usual), 465 (SSL) or 1025 (Mailpit)
 *   MAIL_ENCRYPTION    tls | ssl | none
 *   MAIL_USERNAME      SMTP login, if the server needs one
 *   MAIL_PASSWORD
 *   MAIL_FROM_ADDRESS  the sender address; must be allowed by the server
 *   MAIL_FROM_NAME     defaults to "Profile System"
 *   APP_URL            the site's public address, e.g. https://lab.example.ac.jp
 *                      Links in emails are built from it. Required in
 *                      production; see mail_app_url().
 *
 * Sending is synchronous and never throws: a failure is written to the
 * error log (`make logs`) and the caller gets false, so it can tell the
 * person to use another way (for example, copy the invitation link).
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/ratelimit.php';

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;

/** Is sending configured? */
function mail_enabled(): bool
{
    return trim((string)getenv('MAIL_HOST')) !== '' && trim((string)getenv('MAIL_FROM_ADDRESS')) !== '';
}

/**
 * Every kind of email the app sends, for Admin › Emails › Settings and the
 * log. Each can be switched off there; "off" says what happens instead, so
 * the admin knows what they are giving up.
 *   group  heading it is listed under
 *   to     who receives it
 *   when   what triggers it
 *   off    what happens while it is switched off
 */
function mail_types(): array
{
    return [
        'invitation' => ['group' => 'Accounts', 'label' => 'Invitation',
            'to' => 'The invited address', 'when' => 'A teacher or admin creates an invitation for one email address.',
            'off' => 'The link is still shown on the Invitations page to copy and send yourself.'],
        'password_reset' => ['group' => 'Accounts', 'label' => 'Password reset link',
            'to' => 'The account holder', 'when' => 'Someone uses “Forgot password”.',
            'off' => 'Nobody can reset a forgotten password themselves; an admin has to set a new one under Users.'],
        'verify_new_email' => ['group' => 'Accounts', 'label' => 'Confirm new email',
            'to' => 'The new address', 'when' => 'A member changes their sign-in email.',
            'off' => 'A new address is used straight away, without proving the member owns it.'],
        'account_deactivated' => ['group' => 'Accounts', 'label' => 'Account deactivated (restore link)',
            'to' => 'The account holder', 'when' => 'A member deactivates their own account.',
            'off' => 'No restore link is sent; to come back within 30 days they must ask an admin.'],
        'password_changed' => ['group' => 'Security notices', 'label' => 'Password changed',
            'to' => 'The account holder', 'when' => 'A password is changed or reset, by the member or an admin.',
            'off' => 'Members are not told when their password changes, so a stolen account may go unnoticed.'],
        'email_changed' => ['group' => 'Security notices', 'label' => 'Sign-in email changed',
            'to' => 'The old address', 'when' => 'The sign-in email of an account changes.',
            'off' => 'The previous address is not told that the account moved to a new one.'],
        'two_factor_off' => ['group' => 'Security notices', 'label' => 'Two-factor turned off',
            'to' => 'The account holder', 'when' => 'Two-factor sign-in is turned off or reset by an admin.',
            'off' => 'Members are not told when their two-factor protection is removed.'],
        'backup_code_used' => ['group' => 'Security notices', 'label' => 'Backup code used',
            'to' => 'The account holder', 'when' => 'Someone signs in with a two-factor backup code.',
            'off' => 'Members are not told when one of their backup codes is used.'],
        'custom' => ['group' => 'Messages', 'label' => 'Custom emails from admins',
            'to' => 'Whoever the admin picks', 'when' => 'An admin sends a message from Emails › Write an email.',
            'off' => 'The Write an email form is closed.'],
    ];
}

/**
 * A setting from app_settings, or $default when it was never saved (or the
 * table is missing). Read once per request.
 */
function mail_setting(string $name, string $default = ''): string
{
    static $cache = null;
    global $pdo;
    if ($name === '') { $cache = null; return ''; } // reset after a save
    if ($cache === null) {
        $cache = [];
        try {
            if ($pdo instanceof PDO) {
                $cache = $pdo->query("SELECT name, value FROM app_settings WHERE name LIKE 'mail.%'")->fetchAll(PDO::FETCH_KEY_PAIR);
            }
        } catch (Throwable $e) { /* not migrated yet: defaults */ }
    }
    return array_key_exists($name, $cache) ? (string)$cache[$name] : $default;
}

/** Save settings (name => value) as $by. */
function mail_settings_save(PDO $pdo, array $values, int $by): void
{
    $st = $pdo->prepare('INSERT INTO app_settings (name, value, updated_by) VALUES (:n, :v, :b)
                         ON DUPLICATE KEY UPDATE value = VALUES(value), updated_by = VALUES(updated_by)');
    foreach ($values as $n => $v) { $st->execute(['n' => $n, 'v' => (string)$v, 'b' => $by]); }
    mail_setting(''); // drop the cache
}

/**
 * Will an email of this kind be sent? False when the server has no email
 * set up, an admin turned all email off, or turned off this kind.
 */
function mail_type_enabled(string $kind): bool
{
    return mail_enabled() && mail_setting('mail.enabled', '1') === '1' && mail_setting('mail.type.' . $kind, '1') === '1';
}

/** Why an email of this kind would not be sent, or '' when it would. */
function mail_off_reason(string $kind): string
{
    if (!mail_enabled()) { return 'Email is off: MAIL_HOST or MAIL_FROM_ADDRESS is not set.'; }
    if (mail_setting('mail.enabled', '1') !== '1') { return 'All email is turned off in Emails › Settings.'; }
    if (mail_setting('mail.type.' . $kind, '1') !== '1') { return 'This kind of email is turned off in Emails › Settings.'; }
    return '';
}

/**
 * The site's base URL for links in emails, without a trailing slash, or ''
 * if it cannot be known safely.
 *
 * APP_URL wins. Without it, development falls back to the address of the
 * current request. Production does not: the Host header comes from the
 * visitor, so an attacker could make a password-reset email point at
 * their own site. Set APP_URL in production.
 */
function mail_app_url(): string
{
    $url = rtrim(trim((string)getenv('APP_URL')), '/');
    if ($url !== '') { return $url; }
    if (getenv('APP_ENV') === 'production' || empty($_SERVER['HTTP_HOST'])) { return ''; }
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (getenv('TRUST_PROXY') === '1' && ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
    return ($https ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'];
}

/** An absolute link for an email, from a site path such as url_login(). */
function mail_link(string $path): string
{
    $base = mail_app_url();
    return $base === '' ? '' : $base . '/' . ltrim($path, '/');
}

/**
 * Send one message. $msg is what mail_templates.php builds:
 *   subject    string
 *   heading    string
 *   paras      list of plain-text paragraphs
 *   button     ['label' => ..., 'url' => ...] or null
 *   after      list of plain-text paragraphs under the button
 *   reason     one line in the footer: why this person got the email
 * $meta is optional bookkeeping for the email log (see mail_log()).
 * Every attempt is logged, including ones skipped because email is off on
 * the server or switched off in Admin › Emails › Settings (mail_off_reason()).
 * Returns true when the server accepted it.
 */
function mail_send(string $toEmail, string $toName, array $msg, array $meta = []): bool
{
    $started = microtime(true);
    // The kind defaults to the template function that called us, so
    // mail_password_reset() is logged and switched as "password_reset".
    if (($meta['kind'] ?? '') === '') {
        $meta['kind'] = 'other';
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 4) as $f) {
            if (str_starts_with($f['function'], 'mail_') && $f['function'] !== 'mail_send') {
                $meta['kind'] = substr($f['function'], 5);
                break;
            }
        }
    }
    $log = fn(string $status, string $error = '') => mail_log($toEmail, $toName, $msg, $meta, $status, $error, (int)round((microtime(true) - $started) * 1000));

    if (($off = mail_off_reason((string)$meta['kind'])) !== '') {
        error_log('[mail] Not sent (' . $off . '): "' . $msg['subject'] . '" to ' . $toEmail);
        $log('skipped', $off);
        return false;
    }
    if (!empty($msg['button']) && ($msg['button']['url'] ?? '') === '') {
        error_log('[mail] Not sent: APP_URL is not set, so the link in "' . $msg['subject'] . '" cannot be built.');
        $log('skipped', 'APP_URL is not set, so the link in this email cannot be built.');
        return false;
    }

    $m = new PHPMailer(true);
    try {
        $m->isSMTP();
        $m->Host       = (string)getenv('MAIL_HOST');
        $m->Port       = (int)(getenv('MAIL_PORT') ?: 587);
        $enc           = strtolower((string)(getenv('MAIL_ENCRYPTION') ?: 'tls'));
        $m->SMTPSecure = $enc === 'ssl' ? PHPMailer::ENCRYPTION_SMTPS : ($enc === 'tls' ? PHPMailer::ENCRYPTION_STARTTLS : '');
        $m->SMTPAutoTLS = $enc !== 'none';
        $user          = (string)getenv('MAIL_USERNAME');
        $m->SMTPAuth   = $user !== '';
        $m->Username   = $user;
        $m->Password   = (string)getenv('MAIL_PASSWORD');
        $m->Timeout    = 10;
        $m->CharSet    = PHPMailer::CHARSET_UTF8;
        $m->Encoding   = PHPMailer::ENCODING_QUOTED_PRINTABLE;

        $m->setFrom((string)getenv('MAIL_FROM_ADDRESS'), (string)(getenv('MAIL_FROM_NAME') ?: 'Profile System'));
        $m->addAddress($toEmail, $toName);
        $m->Subject = $msg['subject'];
        $m->isHTML(true);
        $m->Body    = mail_render_html($msg);
        $m->AltBody = mail_render_text($msg);
        $m->send();
        $log('sent');
        return true;
    } catch (MailException $e) {
        error_log('[mail] Failed: "' . $msg['subject'] . '" to ' . $toEmail . ': ' . $m->ErrorInfo);
        $log('failed', $m->ErrorInfo !== '' ? $m->ErrorInfo : $e->getMessage());
        return false;
    }
}

/**
 * Record one send in email_log. Never throws: a logging problem must not
 * stop the email or break the page.
 *
 * $meta: kind (set by mail_send()), sent_by (admin id), resend_of (log id).
 */
function mail_log(string $toEmail, string $toName, array $msg, array $meta, string $status, string $error, int $ms): void
{
    global $pdo;
    if (!($pdo instanceof PDO)) { return; }
    try {
        $kind = (string)($meta['kind'] ?? 'other');
        // Links in system emails are one-time tokens: keep them out of the log.
        $stored = $kind === 'custom' ? $msg : array_merge($msg, ['button' => !empty($msg['button']) ? ['label' => $msg['button']['label'], 'url' => '[link hidden]'] : null]);
        $user = $pdo->prepare('SELECT id FROM users WHERE email = :e LIMIT 1');
        $user->execute(['e' => $toEmail]);
        $uid = $user->fetchColumn();
        $pdo->prepare(
            'INSERT INTO email_log (kind, status, to_email, to_name, to_user_id, from_email, subject, body_text, error,
                                    smtp_host, duration_ms, sent_by, request_ip, resend_of)
             VALUES (:k, :s, :to, :tn, :tu, :f, :sub, :b, :err, :h, :ms, :by, :ip, :re)'
        )->execute([
            'k' => mb_substr($kind, 0, 40), 's' => $status, 'to' => mb_substr($toEmail, 0, 150),
            'tn' => $toName !== '' ? mb_substr($toName, 0, 150) : null, 'tu' => $uid !== false ? (int)$uid : null,
            'f' => (string)getenv('MAIL_FROM_ADDRESS') ?: null, 'sub' => mb_substr((string)$msg['subject'], 0, 255),
            'b' => mail_render_text($stored), 'err' => $error !== '' ? $error : null,
            'h' => (string)getenv('MAIL_HOST') ?: null, 'ms' => $ms,
            'by' => $meta['sent_by'] ?? null, 'ip' => PHP_SAPI === 'cli' ? null : client_ip(),
            're' => $meta['resend_of'] ?? null,
        ]);
    } catch (Throwable $e) {
        error_log('[mail] Could not write the email log: ' . $e->getMessage());
    }
}

/** Plain-text version: every client can show it, and spam filters expect one. */
function mail_render_text(array $msg): string
{
    $out = [$msg['heading'], ''];
    foreach ($msg['paras'] ?? [] as $p) { $out[] = $p; $out[] = ''; }
    if (!empty($msg['button'])) { $out[] = $msg['button']['label'] . ':'; $out[] = $msg['button']['url']; $out[] = ''; }
    foreach ($msg['after'] ?? [] as $p) { $out[] = $p; $out[] = ''; }
    $out[] = '--';
    $out[] = 'Profile System' . (mail_app_url() !== '' ? ' · ' . mail_app_url() : '');
    if (!empty($msg['reason'])) { $out[] = $msg['reason']; }
    return implode("\n", $out);
}

/**
 * HTML version. Table layout and inline styles, because email clients
 * ignore stylesheets. Same ink blue and warm greys as the site.
 */
function mail_render_html(array $msg): string
{
    $h = fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $p = fn(string $s, string $color = '#3b3632'): string =>
        '<p style="margin:0 0 16px;font-size:15px;line-height:1.6;color:' . $color . ';">' . nl2br($h($s)) . '</p>';
    $font = "font-family:'IBM Plex Sans',-apple-system,'Segoe UI','Hiragino Sans','Noto Sans JP',sans-serif;";

    $body = '<h1 style="margin:0 0 16px;font-family:Georgia,\'Times New Roman\',serif;font-size:24px;line-height:1.25;color:#1f1b18;">'
        . $h($msg['heading']) . '</h1>';
    foreach ($msg['paras'] ?? [] as $s) { $body .= $p($s); }
    if (!empty($msg['button'])) {
        $url = $h($msg['button']['url']);
        $body .= '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:8px 0 24px;"><tr>'
            . '<td style="border-radius:6px;background:#233a6b;">'
            . '<a href="' . $url . '" style="display:inline-block;padding:12px 22px;font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;border-radius:6px;">'
            . $h($msg['button']['label']) . '</a></td></tr></table>'
            . $p('If the button does not work, copy this address into your browser:', '#6b645d')
            . '<p style="margin:-8px 0 20px;font-size:13px;line-height:1.5;word-break:break-all;"><a href="' . $url . '" style="color:#233a6b;">' . $url . '</a></p>';
    }
    foreach ($msg['after'] ?? [] as $s) { $body .= $p($s, '#6b645d'); }

    $site = mail_app_url();
    return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width">'
        . '<title>' . $h($msg['subject']) . '</title></head>'
        . '<body style="margin:0;padding:0;background:#f1efeb;' . $font . '">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f1efeb;"><tr><td align="center" style="padding:24px 12px;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;">'
        // Brand bar: the same ink-blue band as the site's top bar.
        . '<tr><td style="background:#1b2a4f;border-radius:8px 8px 0 0;padding:14px 24px;">'
        . '<span style="display:inline-block;width:26px;height:26px;line-height:26px;text-align:center;border-radius:5px;background:#ffffff;color:#1b2a4f;font-family:Georgia,serif;font-weight:700;font-size:14px;vertical-align:middle;">P</span>'
        . '<span style="margin-left:10px;color:#ffffff;font-weight:700;font-size:15px;vertical-align:middle;">Profile System</span></td></tr>'
        . '<tr><td style="background:#ffffff;border:1px solid #dcd8d2;border-top:0;border-radius:0 0 8px 8px;padding:28px 24px 12px;">' . $body . '</td></tr>'
        . '<tr><td style="padding:16px 8px;font-size:12px;line-height:1.5;color:#6b645d;text-align:center;">'
        . (!empty($msg['reason']) ? $h($msg['reason']) . '<br>' : '')
        . 'Profile System' . ($site !== '' ? ' · <a href="' . $h($site) . '" style="color:#6b645d;">' . $h(preg_replace('#^https?://#', '', $site)) . '</a>' : '')
        . '</td></tr></table></td></tr></table></body></html>';
}
