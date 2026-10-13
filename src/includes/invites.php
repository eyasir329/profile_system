<?php
declare(strict_types=1);

/**
 * Invitation links: the only way an account gets created.
 *
 * A teacher may invite students; an admin may invite any role. The
 * link carries a random token; only its SHA-256 is stored (see
 * migration 017). Used by /invites.php (create / cancel) and
 * /register.php (redeem).
 */

const INVITE_ROLES    = ['student', 'teacher', 'admin'];
const INVITE_MAX_USES = 100;
const INVITE_DAYS     = [1 => '1 day', 3 => '3 days', 7 => '7 days', 14 => '14 days', 30 => '30 days'];

/** Roles the given set of inviter roles is allowed to hand out. */
function invite_grantable_roles(array $inviterRoles): array
{
    if (in_array('admin', $inviterRoles, true))   { return INVITE_ROLES; }
    if (in_array('teacher', $inviterRoles, true)) { return ['student']; }
    return [];
}

/** 'active' | 'used' | 'expired' | 'cancelled' for a row from `invites`. */
function invite_state(array $inv): string
{
    if (!empty($inv['revoked_at']))                              { return 'cancelled'; }
    if ((int)$inv['used_count'] >= (int)$inv['max_uses'])        { return 'used'; }
    if (strtotime((string)$inv['expires_at']) <= invite_now())   { return 'expired'; }
    return 'active';
}

/** "Now" on the database clock, so expiry does not depend on PHP's timezone. */
function invite_now(): int
{
    static $now = null;
    if ($now === null) {
        global $pdo;
        $now = strtotime((string)$pdo->query('SELECT NOW()')->fetchColumn()) ?: time();
    }
    return $now;
}

/**
 * Find the invitation for a token and say whether it can be used.
 * Returns ['invite' => row|null, 'problem' => '' | reason for the visitor].
 * With $lock the row is locked (call inside a transaction) so two people
 * cannot both take the last use.
 */
function invite_check(PDO $pdo, string $token, bool $lock = false): array
{
    $invalid = ['invite' => null, 'problem' => 'This invitation link is not valid. Check that you copied the whole link.'];
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) { return $invalid; }

    $st = $pdo->prepare('SELECT * FROM invites WHERE token_hash = :h' . ($lock ? ' FOR UPDATE' : ''));
    $st->execute(['h' => hash('sha256', $token)]);
    $inv = $st->fetch();
    if (!$inv) { return $invalid; }

    $problem = match (invite_state($inv)) {
        'cancelled' => 'This invitation was cancelled.',
        'used'      => 'This invitation has already been used.',
        'expired'   => 'This invitation has expired.',
        default     => '',
    };

    // The link is only as good as its creator's current permissions.
    if ($problem === '') {
        $ok = false;
        if (!empty($inv['created_by'])) {
            $c = $pdo->prepare(
                'SELECT r.role FROM users u JOIN user_roles r ON r.user_id = u.id
                  WHERE u.id = :id AND u.deleted_at IS NULL'
            );
            $c->execute(['id' => (int)$inv['created_by']]);
            $grantable = invite_grantable_roles($c->fetchAll(PDO::FETCH_COLUMN));
            $ok = !array_diff(explode(',', (string)$inv['roles']), $grantable);
        }
        if (!$ok) { $problem = 'This invitation is no longer valid because the person who sent it can no longer invite people.'; }
    }
    return ['invite' => $inv, 'problem' => $problem === '' ? '' : $problem . ' Ask for a new one.'];
}

/** "Student", "Student and Teacher", "Student, Teacher and Admin". */
function invite_roles_label(string $rolesCsv): string
{
    $roles = array_map('ucfirst', array_filter(explode(',', $rolesCsv)));
    $last  = array_pop($roles);
    return $roles ? implode(', ', $roles) . ' and ' . $last : (string)$last;
}
