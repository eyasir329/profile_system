<?php
declare(strict_types=1);

/**
 * Housekeeping: what Settings and Privacy promise about how long data is
 * kept.
 *
 *   - Deactivated accounts are deleted for good once their 30 days are up
 *     (users.scheduled_purge_at). Their rows in other tables go with them
 *     (foreign keys cascade); the activity log has no foreign key, so it is
 *     cleared here.
 *   - Activity log rows of accounts that no longer exist (deleted by an
 *     admin before this cleanup existed) are deleted.
 *   - Activity rows about failed sign-ins and throttled requests (they hold
 *     the typed email and IP address) older than 30 days are deleted.
 *   - Failed sign-in records (email + IP, login_attempts) older than 30
 *     days are deleted.
 *   - Used or expired one-time links (password reset, email confirmation,
 *     account restore) older than 30 days are deleted.
 *
 * Runs on its own at most every 6 hours, from an ordinary page load
 * (housekeeping_maybe_run(), called by auth.php), so no cron job is needed.
 * `make purge` runs it now: php src/cli/housekeeping.php
 */

/** @return array{purged:int, attempts:int, tokens:int} */
function housekeeping_run(PDO $pdo): array
{
    $ids = $pdo->query('SELECT id FROM users WHERE deleted_at IS NOT NULL
                          AND scheduled_purge_at IS NOT NULL AND scheduled_purge_at <= NOW()')
               ->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ids as $id) {
        $pdo->beginTransaction();
        try {
            $pdo->prepare('DELETE FROM activity WHERE user_id = :u')->execute(['u' => $id]);
            $pdo->prepare('DELETE FROM users WHERE id = :u')->execute(['u' => $id]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            error_log('[housekeeping] Could not delete user ' . $id . ': ' . $e->getMessage());
        }
    }
    if ($ids) { error_log('[housekeeping] Deleted ' . count($ids) . ' deactivated account(s) past their 30 days.'); }

    $pdo->exec('DELETE a FROM activity a LEFT JOIN users u ON u.id = a.user_id
                 WHERE a.user_id IS NOT NULL AND u.id IS NULL');
    $pdo->exec("DELETE FROM activity WHERE kind IN ('login_failed', 'forgot_password_throttled', 'register_throttled', 'password_reset_requested')
                 AND created_at < NOW() - INTERVAL 30 DAY");
    $attempts = $pdo->exec('DELETE FROM login_attempts WHERE created_at < NOW() - INTERVAL 30 DAY');
    $tokens = 0;
    foreach (['password_resets', 'email_verifications', 'account_restorations'] as $t) {
        $tokens += (int)$pdo->exec("DELETE FROM `$t` WHERE (used_at IS NOT NULL OR expires_at < NOW())
                                     AND created_at < NOW() - INTERVAL 30 DAY");
    }
    return ['purged' => count($ids), 'attempts' => (int)$attempts, 'tokens' => $tokens];
}

/**
 * Run housekeeping if the last run was more than 6 hours ago. A marker
 * file and a non-blocking lock keep it to one request at a time; if
 * anything goes wrong it is logged and the page carries on.
 */
function housekeeping_maybe_run(?PDO $pdo): void
{
    if ($pdo === null) { return; }
    $marker = sys_get_temp_dir() . '/profile_system_housekeeping';
    if (is_file($marker) && filemtime($marker) > time() - 6 * 3600) { return; }
    $fh = @fopen($marker, 'c');
    if (!$fh || !flock($fh, LOCK_EX | LOCK_NB)) { return; }
    try {
        touch($marker);
        housekeeping_run($pdo);
    } catch (Throwable $e) {
        error_log('[housekeeping] ' . $e->getMessage());
    } finally {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}
