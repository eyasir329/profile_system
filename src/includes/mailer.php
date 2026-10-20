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

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;

/** Is sending configured? */
function mail_enabled(): bool
{
    return trim((string)getenv('MAIL_HOST')) !== '' && trim((string)getenv('MAIL_FROM_ADDRESS')) !== '';
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
 * Returns true when the server accepted it.
 */
function mail_send(string $toEmail, string $toName, array $msg): bool
{
    if (!mail_enabled()) {
        error_log('[mail] Not sent (MAIL_HOST is not set): "' . $msg['subject'] . '" to ' . $toEmail);
        return false;
    }
    if (!empty($msg['button']) && ($msg['button']['url'] ?? '') === '') {
        error_log('[mail] Not sent: APP_URL is not set, so the link in "' . $msg['subject'] . '" cannot be built.');
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
        return true;
    } catch (MailException $e) {
        error_log('[mail] Failed: "' . $msg['subject'] . '" to ' . $toEmail . ': ' . $m->ErrorInfo);
        return false;
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
