<?php
/**
 * Rate-limiting helpers backed by the `login_attempts` table.
 *
 * Three functions, all idempotent and safe to call from any endpoint:
 *
 *   - too_many_attempts($bucket, $max, $windowSeconds)
 *       Returns true if the bucket has logged >= $max attempts in the
 *       last $windowSeconds seconds.
 *
 *   - record_attempt($bucket, $userId, $ip, $username, $success)
 *       Inserts a row. Use $success = true to log a successful
 *       authentication (we never clear attempts here, callers do).
 *
 *   - clear_attempts($bucket)
 *       Deletes every row in the bucket. Call on success.
 *
 * Bucket keys are short strings, e.g. "email:foo@bar" or "ip:1.2.3.4".
 * They share the same 80-char column so keep them under that limit.
 *
 * The helpers are silent on database errors (we never want a transient
 * DB hiccup to lock everyone out). Errors are written to the error log
 * so an operator can see them.
 */
declare(strict_types=1);

require_once __DIR__ . '/db.php';

/**
 * Best-effort client IP. Behind a trusted proxy: CF-Connecting-IP, else the rightmost entry of X-Forwarded-For
 * when TRUST_PROXY=1, otherwise REMOTE_ADDR. Falls back to "0.0.0.0"
 * if neither is set (e.g. CLI).
 */
function client_ip(): string
{
    $trustProxy = getenv('TRUST_PROXY') === '1';
    if ($trustProxy) {
        // Behind Cloudflare: CF-Connecting-IP is set by Cloudflare itself.
        $cf = trim((string)($_SERVER['HTTP_CF_CONNECTING_IP'] ?? ''));
        if ($cf !== '' && filter_var($cf, FILTER_VALIDATE_IP)) {
            return $cf;
        }
        // Otherwise X-Forwarded-For. Its LEFT entries are whatever the
        // visitor sent (anyone can type a fake one, which would dodge the
        // per-IP limits); the RIGHTMOST is the address the trusted proxy
        // in front of us saw. Use that.
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $parts = explode(',', (string)$_SERVER['HTTP_X_FORWARDED_FOR']);
            $candidate = trim((string)end($parts));
            if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_IP)) {
                return $candidate;
            }
        }
    }
    $remote = $_SERVER['REMOTE_ADDR'] ?? '';
    return is_string($remote) && $remote !== '' ? $remote : '0.0.0.0';
}

/**
 * Returns true if there are >= $max entries for $bucket in the last
 * $windowSeconds seconds. Counts BOTH success and failure rows so a
 * caller can decide what to do; by convention endpoints check this
 * before they do any work.
 */
function too_many_attempts(string $bucket, int $max, int $windowSeconds): bool
{
    global $pdo;
    if (!isset($pdo) || $bucket === '' || $max <= 0 || $windowSeconds <= 0) {
        return false;
    }
    try {
        $st = $pdo->prepare(
            'SELECT COUNT(*) FROM login_attempts
              WHERE bucket = :b
                AND created_at >= (NOW() - INTERVAL :w SECOND)'
        );
        $st->bindValue('b', $bucket, PDO::PARAM_STR);
        $st->bindValue('w', $windowSeconds, PDO::PARAM_INT);
        $st->execute();
        $count = (int)$st->fetchColumn();
        return $count >= $max;
    } catch (Throwable $e) {
        error_log('ratelimit.too_many_attempts: ' . $e->getMessage());
        return false; // fail open — better to let some abuse through than to lock everyone out
    }
}

/**
 * Insert one row into login_attempts. Pass $success = true on a
 * successful authentication (so an admin can audit successful logins
 * too), and clear_attempts() afterwards to wipe the bucket.
 */
function record_attempt(
    string $bucket,
    ?int $userId,
    string $ip,
    string $username,
    bool $success
): void {
    global $pdo;
    if (!isset($pdo) || $bucket === '') {
        return;
    }
    try {
        $st = $pdo->prepare(
            'INSERT INTO login_attempts (bucket, user_id, username, ip, success)
                  VALUES (:b, :u, :n, :i, :s)'
        );
        $st->bindValue('b', substr($bucket, 0, 80), PDO::PARAM_STR);
        $st->bindValue('u', $userId, PDO::PARAM_INT);
        $st->bindValue('n', $username !== '' ? substr($username, 0, 150) : null, PDO::PARAM_STR);
        $st->bindValue('i', $ip !== '' ? substr($ip, 0, 45) : null, PDO::PARAM_STR);
        $st->bindValue('s', $success ? 1 : 0, PDO::PARAM_INT);
        $st->execute();
    } catch (Throwable $e) {
        error_log('ratelimit.record_attempt: ' . $e->getMessage());
    }
}

/**
 * Delete every row in the bucket. Call after a successful
 * authentication so a legitimate user is not punished for prior typos.
 */
function clear_attempts(string $bucket): void
{
    global $pdo;
    if (!isset($pdo) || $bucket === '') {
        return;
    }
    try {
        $st = $pdo->prepare('DELETE FROM login_attempts WHERE bucket = :b');
        $st->execute(['b' => $bucket]);
    } catch (Throwable $e) {
        error_log('ratelimit.clear_attempts: ' . $e->getMessage());
    }
}
