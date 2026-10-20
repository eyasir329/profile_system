<?php
declare(strict_types=1);

/**
 * Every email the app sends, one function each. Each returns true when the
 * mail server accepted it (see mail_send() in mailer.php).
 *
 *   mail_invitation()          a new invitation link, when it has an email
 *   mail_password_reset()      "Forgot password" link (30 minutes)
 *   mail_password_changed()    notice after any password change
 *   mail_email_changed()       notice to the OLD address after a change
 *   mail_two_factor_off()      notice after two-factor sign-in is turned off
 *   mail_backup_code_used()    notice after signing in with a backup code
 *   mail_verify_new_email()    confirm a new sign-in address (link, 24 hours)
 *   mail_account_deactivated() restore link (30 days) after deactivating
 *   mail_custom()              a message an admin writes (/admin/emails.php)
 *
 * Wording: say what happened, what to do next, and what to do if it was
 * not you. Plain text only here; mailer.php does the HTML.
 */

require_once __DIR__ . '/mailer.php';
require_once __DIR__ . '/url.php';

/** "you@example.com" -> "y**@example.com", for notices about a changed address. */
function mail_mask(string $email): string
{
    [$local, $domain] = array_pad(explode('@', $email, 2), 2, '');
    return mb_substr($local, 0, 1) . str_repeat('*', max(2, min(6, mb_strlen($local) - 1))) . '@' . $domain;
}

/** The "if this wasn't you" line for security notices. */
function mail_not_you(): string
{
    return 'If this was not you, reset your password straight away and tell an admin of your lab.';
}

function mail_invitation(string $to, string $token, array $roles, string $inviterName, string $expiresAt): bool
{
    $roleText = implode(' and ', array_map('ucfirst', $roles));
    return mail_send($to, '', [
        'subject' => $inviterName . ' invited you to Profile System',
        'heading' => 'You’re invited to Profile System',
        'paras'   => [
            $inviterName . ' has invited you to join the lab’s member register as ' . (preg_match('/^[AEIOU]/', $roleText) ? 'an ' : 'a ') . $roleText . '.',
            'Open the link to create your account. This email address will be your sign-in name.',
        ],
        'button'  => ['label' => 'Create my account', 'url' => mail_link(url_register() . '?invite=' . $token)],
        'after'   => [
            'The link works until ' . date('F j, Y', strtotime($expiresAt)) . ' and only for this email address.',
            'If you weren’t expecting this, you can ignore this email.',
        ],
        'reason'  => 'You got this email because someone in the lab entered your address for an invitation.',
    ]);
}

function mail_password_reset(string $to, string $name, string $token): bool
{
    return mail_send($to, $name, [
        'subject' => 'Reset your Profile System password',
        'heading' => 'Choose a new password',
        'paras'   => ['Hi ' . $name . ', someone asked to reset the password for your account. If it was you, choose a new one:'],
        'button'  => ['label' => 'Choose a new password', 'url' => mail_link(url_reset() . '?token=' . $token)],
        'after'   => [
            'The link works once, for 30 minutes.',
            'If you didn’t ask for this, ignore this email: your password has not changed.',
        ],
        'reason'  => 'You got this email because a password reset was requested for your account.',
    ]);
}

function mail_password_changed(string $to, string $name, bool $byAdmin = false): bool
{
    return mail_send($to, $name, [
        'subject' => 'Your Profile System password was changed',
        'heading' => 'Your password was changed',
        'paras'   => [
            'Hi ' . $name . ', the password for your account was changed'
                . ($byAdmin ? ' by an admin' : '') . ' on ' . date('F j, Y \a\t H:i') . ' (' . app_timezone_label() . ').',
            $byAdmin ? 'Use the new password the admin gave you to sign in.' : 'If it was you, there is nothing else to do.',
        ],
        'button'  => ['label' => 'Reset my password', 'url' => mail_link(url_forgot())],
        'after'   => [mail_not_you()],
        'reason'  => 'Security notice: we email you whenever your password changes.',
    ]);
}

function mail_email_changed(string $oldEmail, string $name, string $newEmail): bool
{
    return mail_send($oldEmail, $name, [
        'subject' => 'Your Profile System sign-in email was changed',
        'heading' => 'Your sign-in email was changed',
        'paras'   => [
            'Hi ' . $name . ', the email address for your account was changed to ' . mail_mask($newEmail) . ' on ' . date('F j, Y \a\t H:i') . ' (' . app_timezone_label() . ').',
            'From now on, sign in with the new address. This is the last email sent to this one.',
        ],
        'button'  => null,
        'after'   => ['If this was not you, tell an admin of your lab straight away: they can change it back.'],
        'reason'  => 'Security notice: we email the old address whenever the sign-in email changes.',
    ]);
}

function mail_two_factor_off(string $to, string $name, bool $byAdmin = false): bool
{
    return mail_send($to, $name, [
        'subject' => 'Two-factor sign-in was turned off',
        'heading' => 'Two-factor sign-in is off',
        'paras'   => [
            'Hi ' . $name . ', two-factor sign-in was ' . ($byAdmin ? 'reset by an admin' : 'turned off') . ' for your account on ' . date('F j, Y \a\t H:i') . ' (' . app_timezone_label() . '). Signing in now needs only your password.',
            'You can turn it back on in Settings → Security.',
        ],
        'button'  => ['label' => 'Open security settings', 'url' => mail_link(url_settings() . '?tab=security')],
        'after'   => [mail_not_you()],
        'reason'  => 'Security notice: we email you when two-factor sign-in is turned off.',
    ]);
}

function mail_account_deactivated(string $to, string $name, string $token): bool
{
    return mail_send($to, $name, [
        'subject' => 'Your Profile System account was deactivated',
        'heading' => 'Your account is deactivated',
        'paras'   => [
            'Hi ' . $name . ', your account was deactivated and you can no longer sign in.',
            'Changed your mind? You can restore it, with everything on it, for the next 30 days:',
        ],
        'button'  => ['label' => 'Restore my account', 'url' => mail_link(url_for('restore_account.php') . '?token=' . $token)],
        'after'   => ['The link works once, until ' . date('F j, Y', strtotime('+30 days')) . '. If you meant to leave, ignore this email.'],
        'reason'  => 'You got this email because your account was deactivated.',
    ]);
}

function mail_backup_code_used(string $to, string $name, int $left): bool
{
    return mail_send($to, $name, [
        'subject' => 'A backup code was used to sign in',
        'heading' => 'You signed in with a backup code',
        'paras'   => [
            'Hi ' . $name . ', a backup code was used instead of your authenticator app to sign in on ' . date('F j, Y \a\t H:i') . ' (' . app_timezone_label() . '). That code no longer works.',
            $left === 0
                ? 'You have no backup codes left. Make a new set in Settings → Security, or you could be locked out.'
                : 'You have ' . $left . ' backup ' . ($left === 1 ? 'code' : 'codes') . ' left. If you are running low, make a new set in Settings → Security.',
        ],
        'button'  => ['label' => 'Open security settings', 'url' => mail_link(url_settings() . '?tab=security')],
        'after'   => [mail_not_you()],
        'reason'  => 'Security notice: we email you whenever a backup code is used.',
    ]);
}

function mail_verify_new_email(string $newEmail, string $name, string $token): bool
{
    return mail_send($newEmail, $name, [
        'subject' => 'Confirm your new Profile System email',
        'heading' => 'Confirm your new email address',
        'paras'   => [
            'Hi ' . $name . ', you asked to sign in to Profile System with this address from now on. Confirm it is yours:',
        ],
        'button'  => ['label' => 'Confirm this address', 'url' => mail_link(url_for('verify_email.php') . '?token=' . $token)],
        'after'   => [
            'The link works once, for 24 hours. Until you open it, your old address stays in use.',
            'If you didn’t ask for this, ignore this email: nothing changes.',
        ],
        'reason'  => 'You got this email because someone asked to use this address for a Profile System account.',
    ]);
}

/**
 * An email an admin wrote at /admin/emails.php. The text is the admin's own,
 * so it is logged in full (kind "custom").
 *
 * @param list<string> $paras  paragraphs, already split
 */
function mail_custom(string $to, string $name, string $subject, array $paras, int $sentBy, ?int $resendOf = null): bool
{
    return mail_send($to, $name, [
        'subject' => $subject,
        'heading' => $subject,
        'paras'   => $paras,
        'button'  => null,
        'after'   => [],
        'reason'  => 'This message was sent to you by an admin of the lab’s Profile System.',
    ], ['kind' => 'custom', 'sent_by' => $sentBy, 'resend_of' => $resendOf]);
}
