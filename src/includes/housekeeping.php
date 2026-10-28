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
 *   - Activity older than the period chosen in Admin › Site settings is
 *     deleted (kept forever by default), and the IP address and browser of
 *     rows older than their own period are cleared (90 days by default).
 *     See activity_retention() and activity_purge().
 *   - Email log rows older than the period chosen in Admin › Emails ›
 *     Settings (kept forever unless one is chosen).
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
    // The activity log, as long as Admin › Site settings says.
    activity_purge($pdo);
    // The email log is kept as long as Admin › Emails › Settings says (0 = forever).
    try {
        $days = (int)$pdo->query("SELECT value FROM app_settings WHERE name = 'mail.log_days'")->fetchColumn();
        if ($days > 0) {
            $pdo->prepare('DELETE FROM email_log WHERE created_at < NOW() - INTERVAL :d DAY')->execute(['d' => $days]);
        }
    } catch (Throwable $e) { /* tables not migrated yet */ }
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
    // Per-install name: on shared hosting the temp directory can be shared
    // with other sites, which must not be able to skip or block our run.
    $marker = sys_get_temp_dir() . '/profile_system_housekeeping_' . substr(sha1(__DIR__), 0, 12);
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

/** Choices for Admin › Site settings › Activity log (days => label; 0 = forever). */
const ACTIVITY_KEEP_DAYS = [0 => 'Forever', 30 => '30 days', 90 => '90 days', 180 => '6 months', 365 => '1 year', 730 => '2 years'];
const ACTIVITY_IP_DAYS   = [7 => '7 days', 30 => '30 days', 90 => '90 days', 180 => '6 months', 365 => '1 year'];

/**
 * How long activity is kept: ['keep' => days (0 = forever), 'ip' => days].
 * From app_settings 'activity.keep_days' / 'activity.ip_days'; the IP and
 * browser are never kept longer than the event itself.
 */
function activity_retention(PDO $pdo): array
{
    $keep = 0; $ip = 90;
    try {
        $rows = $pdo->query("SELECT name, value FROM app_settings WHERE name IN ('activity.keep_days', 'activity.ip_days')")->fetchAll(PDO::FETCH_KEY_PAIR);
        if (isset($rows['activity.keep_days'], ACTIVITY_KEEP_DAYS[(int)$rows['activity.keep_days']])) { $keep = (int)$rows['activity.keep_days']; }
        if (isset($rows['activity.ip_days'], ACTIVITY_IP_DAYS[(int)$rows['activity.ip_days']])) { $ip = (int)$rows['activity.ip_days']; }
    } catch (Throwable $e) { /* app_settings not migrated yet: defaults */ }
    if ($keep > 0 && $ip > $keep) { $ip = $keep; }
    return ['keep' => $keep, 'ip' => $ip];
}

/**
 * Apply the activity retention now. Returns ['deleted' => rows removed,
 * 'cleared' => rows whose IP and browser were removed].
 */
function activity_purge(PDO $pdo): array
{
    $r = activity_retention($pdo);
    $deleted = $cleared = 0;
    try {
        if ($r['keep'] > 0) {
            $st = $pdo->prepare('DELETE FROM activity WHERE created_at < NOW() - INTERVAL :d DAY');
            $st->execute(['d' => $r['keep']]);
            $deleted = $st->rowCount();
        }
        $st = $pdo->prepare('UPDATE activity SET ip = NULL, user_agent = NULL
                              WHERE created_at < NOW() - INTERVAL :d DAY AND (ip IS NOT NULL OR user_agent IS NOT NULL)');
        $st->execute(['d' => $r['ip']]);
        $cleared = $st->rowCount();
    } catch (Throwable $e) {
        error_log('[housekeeping] activity: ' . $e->getMessage());
    }
    return ['deleted' => $deleted, 'cleared' => $cleared];
}
