<?php
declare(strict_types=1);

/**
 * Changing your own sign-in email (Settings, or University email on My
 * profile).
 *
 * With email set up, the change waits for a confirmation link sent to the
 * NEW address (email_verifications, 24 hours): a typo, or someone at an
 * unlocked screen, cannot move the account to an address its owner does
 * not control. verify_email.php applies it and tells the OLD address.
 * Without email (no MAIL_HOST) a confirmation cannot be sent, so the
 * change applies at once, as before.
 *
 * Admins changing someone's email on /admin/user.php skip this: they act
 * for the lab, and the old address is still told.
 */

require_once __DIR__ . '/mail_templates.php';
require_once __DIR__ . '/validate.php';

/**
 * Returns 'sent' (confirmation emailed; nothing changed yet), 'changed'
 * (applied now) or 'failed' (the email could not be sent; nothing changed).
 * The caller has already validated $newEmail and checked it is free.
 */
function request_email_change(PDO $pdo, int $userId, string $name, string $newEmail): string
{
    if (!mail_enabled()) {
        $pdo->prepare('UPDATE users SET email = :e WHERE id = :u')->execute(['e' => $newEmail, 'u' => $userId]);
        log_activity($userId, 'account_update', 'email');
        return 'changed';
    }
    // Only the latest request counts.
    $pdo->prepare('UPDATE email_verifications SET used_at = NOW() WHERE user_id = :u AND used_at IS NULL')
        ->execute(['u' => $userId]);
    $pair = generate_token_pair();
    $pdo->prepare('INSERT INTO email_verifications (user_id, new_email, token_hash, expires_at)
                   VALUES (:u, :e, :h, DATE_ADD(NOW(), INTERVAL 24 HOUR))')
        ->execute(['u' => $userId, 'e' => $newEmail, 'h' => $pair['hash']]);
    $id = (int)$pdo->lastInsertId();
    if (!mail_verify_new_email($newEmail, $name, $pair['token'])) {
        $pdo->prepare('DELETE FROM email_verifications WHERE id = :id')->execute(['id' => $id]);
        return 'failed';
    }
    log_activity($userId, 'email_change_requested', null);
    return 'sent';
}

/** The address waiting for confirmation, or ''. */
function pending_email_change(PDO $pdo, int $userId): string
{
    $st = $pdo->prepare('SELECT new_email FROM email_verifications
                          WHERE user_id = :u AND used_at IS NULL AND expires_at > NOW()
                          ORDER BY id DESC LIMIT 1');
    $st->execute(['u' => $userId]);
    return (string)($st->fetchColumn() ?: '');
}
