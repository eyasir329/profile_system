<?php
declare(strict_types=1);

/**
 * Presentation helpers shared by the templates: icons, avatars, role
 * chips, alerts and progress bars.
 * Every helper returns an HTML string and escapes what it is given.
 * The class names they emit are documented in docs/COMPONENTS.md.
 */

require_once __DIR__ . '/url.php';
require_once __DIR__ . '/lab_fields.php';

/**
 * Inline 16px stroke icon (24px grid, 2px stroke, round joins).
 * Decorative by default: always pair it with visible text, or put an
 * aria-label on the button that contains it.
 */
function pf_icon(string $name, string $class = ''): string
{
    static $paths = [
        'edit'     => '<path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/>',
        'download' => '<path d="M12 4v11"/><path d="M7 10l5 5 5-5"/><path d="M5 20h14"/>',
        'cap'      => '<path d="M2 9l10-5 10 5-10 5z"/><path d="M6 11v5c0 1.5 3 3 6 3s6-1.5 6-3v-5"/>',
        'book'     => '<path d="M4 5a2 2 0 0 1 2-2h13v16H6a2 2 0 0 0-2 2z"/><path d="M4 21V5"/><path d="M9 8h6"/>',
        'folder'   => '<path d="M3 7a2 2 0 0 1 2-2h4l2 2h8a2 2 0 0 1 2 2v8a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>',
        'copy'     => '<rect x="9" y="9" width="11" height="11" rx="2"/><path d="M5 15V6a2 2 0 0 1 2-2h9"/>',
        'trash'    => '<path d="M4 7h16"/><path d="M10 11v6M14 11v6"/><path d="M6 7l1 12a2 2 0 0 0 2 2h6a2 2 0 0 0 2-2l1-12"/><path d="M9 7V4h6v3"/>',
        'plus'     => '<path d="M12 5v14M5 12h14"/>',
        'camera'   => '<path d="M4 8h3l2-3h6l2 3h3a1 1 0 0 1 1 1v9a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V9a1 1 0 0 1 1-1Z"/><circle cx="12" cy="13" r="3.5"/>',
        'link'     => '<path d="M14 5h5v5"/><path d="M19 5l-8 8"/><path d="M19 14v4a1 1 0 0 1-1 1H6a1 1 0 0 1-1-1V6a1 1 0 0 1 1-1h4"/>',
        'chevron'  => '<path d="M9 6l6 6-6 6"/>',
        'caret'    => '<path d="M6 9l6 6 6-6"/>',
        'back'     => '<path d="M19 12H5"/><path d="M11 6l-6 6 6 6"/>',
        'check'    => '<path d="M5 12.5l4.5 4.5L19 7.5"/>',
        'close'    => '<path d="M6 6l12 12M18 6L6 18"/>',
        'menu'     => '<path d="M4 7h16M4 12h16M4 17h16"/>',
        'search'   => '<circle cx="11" cy="11" r="6.5"/><path d="M20 20l-4-4"/>',
        'user'     => '<circle cx="12" cy="8" r="4"/><path d="M4 21a8 8 0 0 1 16 0"/>',
        'users'    => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20a6.5 6.5 0 0 1 13 0"/><path d="M16 4.6a3.5 3.5 0 0 1 0 6.8"/><path d="M18 14.3a6.5 6.5 0 0 1 3.5 5.7"/>',
        'settings' => '<path d="M4 7h10M18 7h2M4 17h2M10 17h10"/><circle cx="16" cy="7" r="2"/><circle cx="8" cy="17" r="2"/>',
        'lock'     => '<rect x="5" y="11" width="14" height="10" rx="2"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/>',
        'shield'   => '<path d="M12 3l8 3v6c0 4.5-3.2 8-8 9-4.8-1-8-4.5-8-9V6Z"/>',
        'login'    => '<path d="M14 4h4a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2h-4"/><path d="M4 12h11"/><path d="M11 8l4 4-4 4"/>',
        'logout'   => '<path d="M10 4H6a2 2 0 0 0-2 2v12a2 2 0 0 0 2 2h4"/><path d="M20 12H9"/><path d="M16 8l4 4-4 4"/>',
        'swap'     => '<path d="M4 8h14"/><path d="M14 4l4 4-4 4"/><path d="M20 16H6"/><path d="M10 12l-4 4 4 4"/>',
        'palette'  => '<circle cx="12" cy="12" r="9"/><path d="M12 3v18"/><path d="M12 7a5 5 0 0 1 0 10"/>',
        'alert'    => '<path d="M12 4l9 16H3Z"/><path d="M12 10v4"/><path d="M12 17.5v.01"/>',
        'info'     => '<circle cx="12" cy="12" r="9"/><path d="M12 11v5"/><path d="M12 7.5v.01"/>',
        'mail'     => '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="M3.5 7l8.5 6 8.5-6"/>',
        'dot'      => '<circle cx="12" cy="12" r="2"/>',
        'pulse'    => '<path d="M3 12h4l3-7 4 14 3-7h4"/>',
        'expand'   => '<path d="M9 4H4v5"/><path d="M15 4h5v5"/><path d="M9 20H4v-5"/><path d="M15 20h5v-5"/>',
        'collapse' => '<path d="M4 9h5V4"/><path d="M20 9h-5V4"/><path d="M4 15h5v5"/><path d="M20 15h-5v5"/>',
    ];
    return '<svg class="icon' . ($class !== '' ? ' ' . $class : '') . '" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor"'
        . ' stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'
        . ($paths[$name] ?? '') . '</svg>';
}

/**
 * Avatar: the photo if there is one, otherwise the first letter of the name.
 * $size is '' (36px) or one of avatar-sm | avatar-lg | avatar-xl.
 * Decorative: the name is always printed next to it.
 */
function ui_avatar(?string $photo, string $name, string $size = '', string $attrs = ''): string
{
    $class = 'avatar' . ($size !== '' ? ' ' . $size : '');
    if ($photo !== null && $photo !== '') {
        return '<img class="' . $class . '" src="' . e(url_for($photo)) . '" alt="" loading="lazy"' . $attrs . '>';
    }
    return '<span class="' . $class . '" aria-hidden="true"' . $attrs . '>'
        . e(mb_strtoupper(mb_substr(trim($name), 0, 1, 'UTF-8'), 'UTF-8')) . '</span>';
}

/** One role: a coloured dot and the role name. */
function ui_role(string $role): string
{
    return '<span class="role role-' . e($role) . '">' . e(ucfirst($role)) . '</span>';
}

/** A set of roles, or a "No role" badge when the set is empty. */
function ui_roles(array $roles): string
{
    $roles = array_values(array_filter($roles, fn($r) => $r !== ''));
    if (!$roles) { return '<span class="badge badge-danger">No role</span>'; }
    return '<span class="role-list">' . implode('', array_map('ui_role', $roles)) . '</span>';
}

/** Inline alert. $type: error | success | warning | info. $html is trusted markup. */
function ui_alert(string $type, string $html): string
{
    $icon = ['error' => 'alert', 'warning' => 'alert', 'success' => 'check', 'info' => 'info'][$type] ?? 'info';
    $role = $type === 'error' ? 'alert' : 'status';
    return '<div class="alert alert-' . e($type) . '" role="' . $role . '">' . pf_icon($icon) . '<div>' . $html . '</div></div>';
}

/**
 * Show / hide button for a password input. Use inside
 *   <div class="password-field" x-data="{ show: false }">
 *     <input class="input" type="password" :type="show ? 'text' : 'password'" ...>
 *     <?= ui_pw_toggle() ?>
 *   </div>
 */
function ui_pw_toggle(): string
{
    return '<button type="button" class="password-toggle" @click="show = !show" :aria-pressed="show.toString()"'
        . ' aria-label="Show password" x-text="show ? \'Hide\' : \'Show\'">Show</button>';
}

/** Thin progress bar. $labelledBy is the id of the element that names it. */
/**
 * A cell or field that can hold several items (theses, papers, award
 * recipients). One item is plain text; several are a numbered list with a
 * divider between them (class .multi), so where one ends and the next
 * begins is clear. $items are plain strings; empty ones are dropped.
 */
function ui_multi(array $items): string
{
    $items = array_values(array_filter(array_map('trim', $items), fn($v) => $v !== ''));
    if (count($items) < 2) { return e($items[0] ?? ''); }
    return '<ol class="multi">' . implode('', array_map(fn($v) => '<li>' . e($v) . '</li>', $items)) . '</ol>';
}

function ui_progress(int $percent, string $labelledBy = '', string $attrs = '', string $barAttrs = ''): string
{
    $percent = max(0, min(100, $percent));
    return '<div class="progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="' . $percent . '"'
        . ($labelledBy !== '' ? ' aria-labelledby="' . e($labelledBy) . '"' : '') . $attrs . '>'
        . '<span class="progress-bar" style="width: ' . $percent . '%"' . $barAttrs . '></span></div>';
}

/** "just now", "5 minutes ago", "3 days ago", or a date once it is old. */
function ui_ago(int $seconds, string $absolute): string
{
    $unit = function (int $n, string $word): string {
        return $n . ' ' . $word . ($n === 1 ? '' : 's') . ' ago';
    };
    if ($seconds < 60)      { return 'just now'; }
    if ($seconds < 3600)    { return $unit(intdiv($seconds, 60), 'minute'); }
    if ($seconds < 86400)   { return $unit(intdiv($seconds, 3600), 'hour'); }
    if ($seconds < 172800)  { return 'yesterday'; }
    if ($seconds < 2592000) { return $unit(intdiv($seconds, 86400), 'day'); }
    $ts = strtotime($absolute);
    return $ts ? date('M j, Y', $ts) : $absolute;
}

/**
 * Turn one `activity` row into plain language.
 * Returns [icon name, sentence]. `$count` > 1 means that many consecutive
 * rows of the same kind were folded into this one.
 */
function ui_activity(string $kind, ?string $detail, int $count = 1): array
{
    $detail = trim((string)$detail);

    switch ($kind) {
        case 'login':
            if ($count > 1) { return ['login', 'Signed in ' . $count . ' times']; }
            return ['login', 'Signed in' . ($detail !== '' ? ' as ' . $detail : '')];
        case 'register':
            return ['user', 'Created your account'];
        case 'profile_update':
            if ($count > 1) { return ['edit', 'Made ' . $count . ' changes to your lab details']; }
            if (str_starts_with($detail, 'lab:')) { return ['edit', 'Updated your lab details']; }
            return ['edit', 'Updated your profile'];
        case 'account_update':
            return ['user', 'Updated your account details'];
        case 'theme_update':
            return ['palette', 'Changed the theme' . (str_starts_with($detail, 'theme=') ? ' to ' . substr($detail, 6) : '')];
        case 'password_change':
            return ['lock', 'Changed your password'];
        case 'password_reset':
            return ['lock', 'Reset your password'];
        case 'role_switch':
            if ($count > 1) { return ['swap', 'Switched role ' . $count . ' times']; }
            return ['swap', 'Switched role' . ($detail !== '' ? ' (' . str_replace('->', ' → ', $detail) . ')' : '')];
        case 'invite_create':
            return ['link', 'Created an invitation link'];
        case 'invite_revoke':
            return ['link', 'Cancelled an invitation'];
        case 'admin_user_create':
            return ['user', 'Created a user' . ($detail !== '' ? ' (' . explode(';', $detail)[0] . ')' : '')];
        case 'admin_user_update':
            return ['user', 'Edited a user' . ($detail !== '' ? ' (' . $detail . ')' : '')];
        case 'admin_user_deactivate':
            return ['user', 'Deactivated a user' . ($detail !== '' ? ' (' . $detail . ')' : '')];
        case 'admin_user_restore':
            return ['user', 'Restored a user' . ($detail !== '' ? ' (' . str_replace(';bulk', ', with others', $detail) . ')' : '')];
        case 'admin_user_delete':
            return ['trash', 'Deleted a user' . ($detail !== '' ? ' (' . $detail . ')' : '')];
        case 'resources_update':
            if ($count > 1) { return ['edit', 'Made ' . $count . ' changes to lab resources']; }
            // Taking journals was removed (migration 031); older log rows still use these.
            if ($detail === 'take_journal')    { return ['check', 'Took a potential journal']; }
            if ($detail === 'release_journal') { return ['edit', 'Released a potential journal']; }
            if ($detail === 'publish_journal')   { return ['check', 'Marked a journal submission as published']; }
            if ($detail === 'unpublish_journal') { return ['edit', 'Unmarked a published journal']; }
            $what = str_ends_with($detail, '_template') ? 'a template' : (str_ends_with($detail, '_journal') ? 'a potential journal' : 'a data/code link');
            $verb = ['add' => 'Added', 'edit' => 'Edited', 'delete' => 'Deleted'][explode('_', $detail)[0]] ?? 'Changed';
            return ['edit', $verb . ' ' . $what . ' in lab resources'];
        case 'role_change':
            return ['shield', 'Your role was changed' . ($detail !== '' ? ' (' . $detail . ')' : '')];
        case 'backup_code_used':
            return ['shield', 'Signed in with a backup code'];
        case 'backup_codes_regenerated':
            return ['shield', 'Made new backup codes'];
        case 'two_factor_reset':
            return ['shield', 'Two-factor sign-in was reset by an admin'];
        case 'admin_2fa_reset':
            return ['shield', 'Reset two-factor for a user' . ($detail !== '' ? ' (' . $detail . ')' : '')];
        case 'email_change_requested':
            return ['mail', 'Asked to change your sign-in email'];
        case 'login_failed':
            return ['lock', $count > 1 ? $count . ' failed sign-in attempts' : 'Failed sign-in attempt'];
        case 'logout':
            return ['logout', 'Signed out'];
        case 'two_factor_on':
            return ['shield', 'Turned on two-factor sign-in'];
        case 'two_factor_off':
            return ['shield', 'Turned off two-factor sign-in'];
        case 'login_2fa_failed':
            return ['lock', $count > 1 ? $count . ' wrong two-factor codes' : 'Wrong two-factor code'];
        case 'login_2fa_locked':
            return ['lock', 'Two-factor sign-in locked after too many wrong codes'];
        case 'account_restore':
            return ['user', 'Restored your account'];
        case 'students_export':
            return ['download', 'Downloaded the Students sheet'];
    }
    return ['dot', ucfirst(str_replace('_', ' ', $kind))];
}

/** Activity kinds by area, for the filters on Admin › Activity. */
const ACTIVITY_GROUPS = [
    'signin'    => ['Sign-ins', ['login', 'login_failed', 'login_password_ok', 'login_2fa_failed', 'login_2fa_locked', 'logout',
                                 'role_switch', 'role_fallback', 'backup_code_used']],
    'security'  => ['Security', ['password_change', 'password_reset', 'password_reset_requested', 'backup_codes_regenerated',
                                 'two_factor_on', 'two_factor_off',
                                 'two_factor_reset', 'forgot_password_throttled', 'register_throttled']],
    'account'   => ['Account', ['register', 'account_update', 'theme_update', 'email_change_requested', 'account_deactivate', 'account_restore']],
    'profile'   => ['Profile & lab', ['profile_update', 'lab_update_denied', 'lab_fields_update', 'lab_xlsx_upload']],
    'resources' => ['Resources', ['resources_update']],
    'invites'   => ['Invitations', ['invite_create', 'invite_revoke']],
    'admin'     => ['Admin actions', ['admin_user_create', 'admin_user_update', 'admin_user_deactivate', 'admin_user_restore',
                                      'admin_user_delete', 'admin_2fa_reset', 'role_change', 'admin_email_send',
                                      'admin_email_resend', 'admin_email_settings', 'admin_site_settings',
                                      'admin_password_link', 'admin_signout_all']],
    'exports'   => ['Downloads', ['students_export', 'admin_activity_export', 'admin_users_export']],
];

/** The ACTIVITY_GROUPS key a kind belongs to ('other' if none). */
function activity_group_of(string $kind): string
{
    foreach (ACTIVITY_GROUPS as $g => [, $kinds]) {
        if (in_array($kind, $kinds, true)) { return $g; }
    }
    return 'other';
}

/**
 * One activity row as an admin reads it: [icon, sentence, tone], in the third
 * person ("Updated their lab details"). tone is '' or 'danger' / 'warning'
 * for events worth a second look. Falls back to ui_activity().
 */
function ui_activity_admin(string $kind, ?string $detail): array
{
    $detail = trim((string)$detail);
    $kv = [];
    foreach (explode(';', $detail) as $part) {
        if (str_contains($part, '=')) { [$k, $v] = explode('=', $part, 2); $kv[$k] = $v; }
    }
    switch ($kind) {
        case 'login_failed':
            return ['lock', 'Failed sign-in' . (!empty($kv['email']) ? ' as ' . $kv['email'] : ''), 'danger'];
        case 'login':
            [$role, $how] = array_pad(explode(';', $detail, 2), 2, '');
            return ['login', 'Signed in' . ($role !== '' ? ' as ' . $role : '') . ($how === '2fa' ? ' (with two-factor)' : ''), ''];
        case 'role_fallback':
            return ['swap', 'Was moved to another role (' . str_replace('->', ' → ', $detail) . ') because a role was removed', ''];
        case 'password_reset_requested':
            return ['lock', $detail === 'no_match' ? 'Someone asked to reset the password of an address with no account' : 'Asked for a password reset link', $detail === 'no_match' ? 'warning' : ''];
        case 'forgot_password_throttled':
            return ['alert', 'Too many password reset requests; blocked for a while', 'warning'];
        case 'register_throttled':
            return ['alert', 'Too many sign-up attempts; blocked for a while', 'warning'];
        case 'lab_update_denied':
            return ['alert', 'Tried to change a lab field only admins may set' . ($detail !== '' ? ' (' . $detail . ')' : ''), 'warning'];
        case 'account_deactivate':
            return ['user', 'Deactivated their own account', 'warning'];
        case 'account_update':
            return ['user', 'Updated their account details' . ($detail !== '' ? ' (' . $detail . ')' : ''), ''];
        case 'profile_update':
            return ['edit', str_starts_with($detail, 'lab:') ? 'Updated their lab details (' . substr($detail, 4) . ')' : 'Updated their profile', ''];
        case 'password_reset':
            return ['lock', 'Reset their password with an emailed link', ''];
        case 'admin_user_delete':
            return ['trash', 'Deleted a user' . ($detail !== '' ? ' (' . $detail . ')' : ''), 'danger'];
        case 'admin_user_deactivate':
            return ['user', 'Deactivated a user' . ($detail !== '' ? ' (' . str_replace(';bulk', ', with others', $detail) . ')' : ''), 'warning'];
        case 'admin_2fa_reset':
            return ['shield', 'Reset two-factor for a user' . ($detail !== '' ? ' (' . $detail . ')' : ''), 'warning'];
        case 'admin_email_send':
            return ['mail', 'Sent a custom email to ' . ($kv['recipients'] ?? '?') . ' ' . (($kv['recipients'] ?? '') === '1' ? 'person' : 'people')
                . (!empty($kv['failed']) ? ' (' . $kv['failed'] . ' failed)' : ''), ''];
        // Older kinds, from before the lab sheet moved into the profile.
        case 'lab_fields_update':
            return ['edit', 'Updated lab details' . ($detail !== '' ? ' of ' . $detail : ''), ''];
        case 'lab_xlsx_upload':
            return ['download', 'Uploaded a lab spreadsheet', ''];
        case 'admin_password_link':
            return ['mail', 'Emailed a password reset link' . ($kv === [] && $detail !== '' ? ' to ' . explode(';', $detail)[0] : '') . (str_contains($detail, ';failed') ? ' (not sent)' : ''), str_contains($detail, ';failed') ? 'warning' : ''];
        case 'admin_signout_all':
            return ['logout', 'Signed a user out everywhere' . ($detail !== '' ? ' (' . $detail . ')' : ''), 'warning'];
        case 'admin_users_export':
            return ['download', 'Downloaded the user list (CSV' . (str_contains($detail, 'filtered') ? ', filtered' : '') . ')', ''];
        case 'admin_site_settings':
            if (str_starts_with($detail, 'activity_days=')) {
                $keepD = (int)($kv['activity_days'] ?? 0);
                return ['settings', 'Set activity to be kept ' . ($keepD ? 'for ' . $keepD . ' days' : 'forever') . ' (IP and browser ' . (int)($kv['ip_days'] ?? 90) . ' days)'
                    . (!empty($kv['deleted']) ? '; ' . $kv['deleted'] . ' old events deleted' : ''), !empty($kv['deleted']) ? 'warning' : ''];
            }
            return ['settings', str_starts_with($detail, 'timezone=') ? 'Changed the site time zone (' . str_replace('->', ' → ', substr($detail, 9)) . ')' : 'Changed site settings', 'warning'];
        case 'login_password_ok':
            return ['login', 'Entered the right password; asked for the two-factor code', ''];
        case 'login_2fa_failed':
            return ['lock', 'Entered a wrong two-factor code', 'danger'];
        case 'login_2fa_locked':
            return ['lock', 'Locked out after too many wrong two-factor codes', 'danger'];
        case 'two_factor_off':
            return ['shield', 'Turned off two-factor sign-in', 'warning'];
        case 'students_export':
            return ['download', 'Downloaded the Students sheet (' . (($kv['group'] ?? '') === 'completed' ? 'completed' : 'current') . (!empty($kv['course']) ? ', ' . $kv['course'] : '') . (str_contains($detail, 'search') ? ', filtered' : '') . ')', ''];
        case 'admin_activity_export':
            return ['download', 'Downloaded the activity log (CSV)', ''];
        case 'admin_email_resend':
            return ['mail', 'Sent a custom email again', ''];
        case 'admin_email_settings':
            $offs = array_keys(array_filter($kv, fn($v) => $v === '0'));
            return ['settings', 'Changed email settings' . ($offs ? ' (turned off: ' . implode(', ', array_map(fn($n) => str_replace(['mail.type.', 'mail.'], '', $n), $offs)) . ')' : ''), $offs ? 'warning' : ''];
    }
    [$icon, $text] = ui_activity($kind, $detail);
    $text = str_replace([' your ', 'Your '], [' their ', 'Their '], $text . ' ');
    return [$icon, rtrim($text), ''];
}

/** "Chrome on Windows" from a User-Agent string, or '' when unknown. */
function ui_device(?string $ua): string
{
    $ua = (string)$ua;
    if ($ua === '') { return ''; }
    $browser = match (true) {
        str_contains($ua, 'Edg/')                                => 'Edge',
        str_contains($ua, 'OPR/') || str_contains($ua, 'Opera')  => 'Opera',
        str_contains($ua, 'Firefox/')                            => 'Firefox',
        str_contains($ua, 'Chrome/') || str_contains($ua, 'CriOS') => 'Chrome',
        str_contains($ua, 'Safari/')                             => 'Safari',
        str_contains($ua, 'curl') || str_contains($ua, 'python') || str_contains($ua, 'bot') => 'Script or bot',
        default                                                  => 'Browser',
    };
    $os = match (true) {
        str_contains($ua, 'iPhone') || str_contains($ua, 'iPad') => 'iOS',
        str_contains($ua, 'Android')                             => 'Android',
        str_contains($ua, 'Windows')                             => 'Windows',
        str_contains($ua, 'Mac OS X') || str_contains($ua, 'Macintosh') => 'macOS',
        str_contains($ua, 'CrOS')                                => 'ChromeOS',
        str_contains($ua, 'Linux')                               => 'Linux',
        default                                                  => '',
    };
    return $browser . ($os !== '' ? ' on ' . $os : '');
}
