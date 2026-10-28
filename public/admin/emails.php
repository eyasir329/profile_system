<?php
/**
 * Admin: the email log, one email in full, and a form to write a custom email.
 *
 * Route:  GET  /admin/emails.php                  the log: totals, filters, emails grouped by day
 *         GET  /admin/emails.php?id=<id>          one email: result, what the server said, the text
 *         GET  /admin/emails.php?view=settings    switch all email, or each kind, on or off
 *         GET  /admin/emails.php?view=compose     write a custom email
 *                 &user=<id>                      ... with this account already picked
 *                 &users=<id>,<id>,…              ... with several picked (Users › bulk Email)
 *                 &from=<log id>                  ... starting from an earlier custom email
 *         POST /admin/emails.php                  action = send | test | resend | settings | server_test
 * Auth:   require_admin()
 * CSRF:   verify_csrf() on POST
 *
 * The log is email_log, written by mail_send() for every email the app
 * sends (invitations, password resets, security notices and the custom
 * emails sent from here), including ones that failed or were skipped
 * because email is off. Links inside system emails are not stored.
 *
 * Settings (app_settings, see mail_types() in mailer.php): a master switch,
 * one switch per kind of email, and how long to keep this log. A switched-off
 * email is not sent but is still logged as "Not sent", with the reason.
 *
 * Custom emails go out one message per person, so nobody sees the other
 * recipients. "{name}" in the text becomes the person's name. "Send me a
 * test" sends one copy to the admin's own address first. At most
 * MAX_RECIPIENTS people per send and MAX_PER_HOUR custom emails per admin
 * per hour, so a slip cannot turn into a flood.
 */
declare(strict_types=1);
require_once __DIR__ . '/../../src/includes/auth.php';
require_once __DIR__ . '/../../src/includes/db.php';
require_once __DIR__ . '/../../src/includes/url.php';
require_once __DIR__ . '/../../src/includes/validate.php';
require_once __DIR__ . '/../../src/includes/ui.php';
require_once __DIR__ . '/../../src/includes/mail_templates.php';
require_once __DIR__ . '/../../src/includes/charts.php';
require_admin();

const MAX_RECIPIENTS = 100;
const MAX_PER_HOUR   = 200;
const PER_PAGE       = 30;

$meId    = (int)current_user_id();
$self    = url_admin_emails();
$page_id = 'admin-emails';
$flash   = [];

$TYPES = mail_types();
$KINDS = array_map(fn($t) => $t['label'], $TYPES) + ['test' => 'Server test'];
$LOG_DAYS = ['0' => 'Forever', '30' => '30 days', '90' => '90 days', '180' => '6 months', '365' => '1 year'];
$STATUS = [
    'sent'    => ['Sent', 'badge-success'],
    'failed'  => ['Failed', 'badge-danger'],
    'skipped' => ['Not sent', 'badge-warning'],
];
$kindLabel = fn(string $k): string => $KINDS[$k] ?? ucfirst(str_replace('_', ' ', $k));
$badge = function (string $s) use ($STATUS): string {
    [$l, $c] = $STATUS[$s] ?? [$s, ''];
    return '<span class="badge ' . $c . '">' . e($l) . '</span>';
};
$stamp = fn(?string $t): string => $t ? date('M j, Y · H:i', strtotime($t)) : '';
$seconds = fn(?int $ms): string => $ms === null ? '–' : ($ms < 1000 ? $ms . ' ms' : number_format($ms / 1000, 1) . ' s');

/** What a mail-server error means, in plain words, or '' if it is not a known one. */
$explain = function (string $error): string {
    $e = strtolower($error);
    return match (true) {
        str_contains($e, 'all email is turned off')
            => 'All email was switched off in Emails › Settings when this was attempted.',
        str_contains($e, 'turned off in emails')
            => 'This kind of email was switched off in Emails › Settings when this was attempted.',
        str_contains($e, 'mail_host') || str_contains($e, 'email is off')
            => 'Email is not set up on this server. Set MAIL_HOST and MAIL_FROM_ADDRESS in .env and restart.',
        str_contains($e, 'app_url')
            => 'The site address (APP_URL) is not set, so the link in this email could not be built. Set it in .env.',
        str_contains($e, 'authenticate') || str_contains($e, '535') || str_contains($e, 'username and password')
            => 'The mail server refused the sign-in. Check MAIL_USERNAME and MAIL_PASSWORD (for Gmail, an app password).',
        str_contains($e, 'connect') || str_contains($e, 'timed out') || str_contains($e, 'timeout')
            => 'The mail server could not be reached. Check MAIL_HOST, MAIL_PORT and MAIL_ENCRYPTION, and that the server can reach the internet.',
        str_contains($e, '550') || str_contains($e, 'does not exist') || str_contains($e, 'recipients failed') || str_contains($e, 'user unknown')
            => 'The address was rejected: it probably has a typo or no longer exists. Check it with the person.',
        str_contains($e, '552') || str_contains($e, 'quota') || str_contains($e, 'mailbox full')
            => 'The recipient’s mailbox is full. Try again later.',
        str_contains($e, '421') || str_contains($e, '450') || str_contains($e, '451') || str_contains($e, 'rate') || str_contains($e, 'limit')
            => 'The mail server asked to slow down or try later. Sending again in a while usually works.',
        default => '',
    };
};

/** The admin's own words from a logged custom email (heading and footer removed). */
$customText = function (array $row): string {
    $t = (string)$row['body_text'];
    $t = preg_replace('/\A.*?\R\R/s', '', $t, 1);          // heading (= subject)
    $t = preg_replace('/\R\R?--\R.*\z/s', '', (string)$t);  // signature
    return trim((string)$t);
};

/** Send one custom message to each [email, name]; returns [sent, failed]. */
$deliver = function (array $to, string $subject, string $message, ?int $resendOf = null) use ($meId): array {
    $paras = array_values(array_filter(array_map('trim', preg_split('/\n{2,}/', $message)), 'strlen'));
    $ok = $bad = 0;
    foreach ($to as [$email, $name]) {
        $mine = array_map(fn($p) => str_replace('{name}', $name !== '' ? $name : 'there', $p), $paras);
        mail_custom($email, $name, $subject, $mine, $meId, $resendOf) ? $ok++ : $bad++;
        record_attempt('mailsend:' . $meId, $meId, client_ip(), '', false);
    }
    return [$ok, $bad];
};
$overLimit = fn(int $n): bool => too_many_attempts('mailsend:' . $meId, MAX_PER_HOUR - $n + 1, 3600);

// ---------- Actions ----------
$errors = [];
$form   = ['audience' => 'selected', 'users' => [], 'extra' => '', 'subject' => '', 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'settings') {
        $new = ['mail.enabled' => isset($_POST['mail_enabled']) ? '1' : '0'];
        foreach (array_keys($TYPES) as $k) { $new['mail.type.' . $k] = isset($_POST['type'][$k]) ? '1' : '0'; }
        $days = (string)($_POST['log_days'] ?? '0');
        $new['mail.log_days'] = isset($LOG_DAYS[$days]) ? $days : '0';
        $changed = [];
        foreach ($new as $n => $v) {
            if (mail_setting($n, $n === 'mail.log_days' ? '0' : '1') !== $v) { $changed[] = $n . '=' . $v; }
        }
        if ($changed) {
            mail_settings_save($pdo, $new, $meId);
            log_activity($meId, 'admin_email_settings', mb_substr(implode(';', $changed), 0, 250));
        }
        header('Location: ' . $self . '?view=settings&done=' . ($changed ? 'saved' : 'unchanged'));
        exit;
    }

    if ($action === 'server_test') {
        $me = $pdo->prepare('SELECT name, email FROM users WHERE id = :id');
        $me->execute(['id' => $meId]);
        $me = $me->fetch();
        $state = 'limit';
        if (!$overLimit(1)) {
            $okTest = mail_send((string)$me['email'], (string)$me['name'], [
                'subject' => 'Test email from Profile System',
                'heading' => 'Email works',
                'paras'   => ['This is a test sent from Emails › Settings. If you can read it, the mail server settings are right.'],
                'button'  => null, 'after' => [],
                'reason'  => 'You got this email because you asked for a test in Emails › Settings.',
            ], ['kind' => 'test', 'sent_by' => $meId]);
            record_attempt('mailsend:' . $meId, $meId, client_ip(), '', false);
            $state = $okTest ? 'test_ok' : 'test_failed';
        }
        header('Location: ' . $self . '?view=settings&done=' . $state);
        exit;
    }

    if ($action === 'resend') {
        $id = (int)($_POST['id'] ?? 0);
        $st = $pdo->prepare("SELECT * FROM email_log WHERE id = :id AND kind = 'custom'");
        $st->execute(['id' => $id]);
        $old = $st->fetch();
        $state = 'none';
        if ($old && !mail_type_enabled('custom')) {
            $state = 'off';
        } elseif ($old && $overLimit(1)) {
            $state = 'limit';
        } elseif ($old) {
            // The stored text already has the name filled in, so send it as is.
            [$ok] = $deliver([[(string)$old['to_email'], (string)$old['to_name']]], (string)$old['subject'], $customText($old), (int)$old['id']);
            log_activity($meId, 'admin_email_resend', 'log=' . $id . ';ok=' . $ok);
            $state = $ok ? 'resent' : 'resend_failed';
        }
        header('Location: ' . $self . '?id=' . $id . '&done=' . $state);
        exit;
    }

    if ($action === 'send' || $action === 'test') {
        $audience = (string)($_POST['audience'] ?? 'selected');
        $subject  = trim((string)($_POST['subject'] ?? ''));
        $message  = trim(str_replace("\r\n", "\n", (string)($_POST['message'] ?? '')));
        $extra    = trim((string)($_POST['extra'] ?? ''));
        $picked   = array_values(array_unique(array_filter(array_map('intval', (array)($_POST['users'] ?? [])))));
        $form     = ['audience' => $audience, 'users' => $picked, 'extra' => $extra, 'subject' => $subject, 'message' => $message];

        if ($subject === '') { $errors['subject'] = 'Write a subject.'; }
        elseif (mb_strlen($subject) > 200) { $errors['subject'] = 'Keep the subject to 200 characters or fewer.'; }
        if ($message === '') { $errors['message'] = 'Write the message.'; }
        elseif (mb_strlen($message) > 10000) { $errors['message'] = 'Keep the message to 10,000 characters or fewer.'; }

        // Recipients: active accounts only, de-duplicated by address.
        $to  = [];
        $add = function (string $email, string $name) use (&$to): void { $to[strtolower($email)] ??= [$email, $name]; };
        if ($audience === 'selected') {
            if ($picked) {
                $in = implode(',', array_fill(0, count($picked), '?'));
                $q = $pdo->prepare("SELECT name, email FROM users WHERE deleted_at IS NULL AND id IN ($in)");
                $q->execute($picked);
                foreach ($q as $u) { $add((string)$u['email'], (string)$u['name']); }
            }
        } elseif (in_array($audience, ['all', 'student', 'teacher', 'admin'], true)) {
            $q = $pdo->prepare('SELECT DISTINCT u.name, u.email FROM users u'
                . ($audience === 'all' ? '' : ' JOIN user_roles r ON r.user_id = u.id AND r.role = :r')
                . ' WHERE u.deleted_at IS NULL');
            $q->execute($audience === 'all' ? [] : ['r' => $audience]);
            foreach ($q as $u) { $add((string)$u['email'], (string)$u['name']); }
        } else {
            $errors['audience'] = 'Choose who should get the email.';
        }
        $bad = [];
        foreach (preg_split('/[\s,;]+/', $extra, -1, PREG_SPLIT_NO_EMPTY) as $addr) {
            filter_var($addr, FILTER_VALIDATE_EMAIL) && strlen($addr) <= 150 ? $add($addr, '') : $bad[] = $addr;
        }
        if ($bad) {
            $errors['extra'] = 'Not a valid email address: ' . implode(', ', array_slice($bad, 0, 3)) . (count($bad) > 3 ? ' and ' . (count($bad) - 3) . ' more' : '') . '.';
        }

        if (($off = mail_off_reason('custom')) !== '') {
            $errors['form'] = 'Nothing can be sent. ' . $off;
        } elseif ($action === 'test') {
            // A test only needs the text; recipients are checked on the real send.
            unset($errors['audience'], $errors['extra']);
            if (!$errors && $overLimit(1)) { $errors['form'] = 'You reached the limit of ' . MAX_PER_HOUR . ' emails an hour. Try again later.'; }
            if (!$errors) {
                $me = $pdo->prepare('SELECT name, email FROM users WHERE id = :id');
                $me->execute(['id' => $meId]);
                $me = $me->fetch();
                [$ok] = $deliver([[(string)$me['email'], (string)$me['name']]], '[Test] ' . $subject, $message);
                $flash[] = $ok
                    ? ['type' => 'success', 'title' => 'Test sent', 'body' => 'Check ' . $me['email'] . '. When it looks right, send it for real.']
                    : ['type' => 'error', 'title' => 'The test could not be sent', 'body' => 'See the newest entry in the email log for the reason.'];
            }
        } else {
            if (!$to && !isset($errors['audience']) && !isset($errors['extra'])) {
                $errors['audience'] = $audience === 'selected' ? 'Pick at least one person, or type an address below.' : 'Nobody has that role yet.';
            }
            if (count($to) > MAX_RECIPIENTS) {
                $errors['audience'] = 'That is ' . count($to) . ' people; one send can reach at most ' . MAX_RECIPIENTS . '. Split it into smaller groups.';
            }
            if (!$errors && $overLimit(count($to))) {
                $errors['form'] = 'Sending this would pass the limit of ' . MAX_PER_HOUR . ' emails an hour. Try again later or send to fewer people.';
            }
            if (!$errors) {
                [$ok, $fail] = $deliver(array_values($to), $subject, $message);
                log_activity($meId, 'admin_email_send', 'recipients=' . count($to) . ';sent=' . $ok . ';failed=' . $fail);
                header('Location: ' . $self . '?done=sent&ok=' . $ok . '&fail=' . $fail);
                exit;
            }
        }
        $_GET = ['view' => 'compose'];
    }
}

// ---------- Which view ----------
$id   = (int)($_GET['id'] ?? 0);
$view = $id > 0 ? 'detail' : (in_array($_GET['view'] ?? '', ['compose', 'settings'], true) ? (string)$_GET['view'] : 'list');

$done = (string)($_GET['done'] ?? '');
$ok   = (int)($_GET['ok'] ?? 0);
$fail = (int)($_GET['fail'] ?? 0);
$flash = array_merge($flash, match ($done) {
    'sent' => [$fail === 0
        ? ['type' => 'success', 'title' => 'Email sent', 'body' => $ok . ' ' . ($ok === 1 ? 'person' : 'people') . ' will get it.']
        : ['type' => 'error', 'title' => $fail . ' of ' . ($ok + $fail) . ' could not be sent', 'body' => 'Filter the log by “Failed” to see why.', 'duration' => 0]],
    'resent'        => [['type' => 'success', 'body' => 'Sent again.']],
    'resend_failed' => [['type' => 'error', 'body' => 'Sending again failed. The new attempt is listed under Details.']],
    'off'           => [['type' => 'error', 'body' => 'Custom emails are off (on the server or in Settings), so nothing was sent.']],
    'saved'         => [['type' => 'success', 'body' => 'Email settings saved.']],
    'unchanged'     => [['type' => 'info', 'body' => 'Nothing was changed.']],
    'test_ok'       => [['type' => 'success', 'title' => 'Test email sent', 'body' => 'The mail server accepted it. Check your inbox (and spam folder).']],
    'test_failed'   => [['type' => 'error', 'title' => 'The test email was not sent', 'body' => 'Open the newest entry in the email log to see why.', 'duration' => 0]],
    'limit'         => [['type' => 'error', 'body' => 'You reached the limit of ' . MAX_PER_HOUR . ' emails an hour. Try again later.']],
    default         => [],
});

$logTotal = (int)$pdo->query('SELECT COUNT(*) FROM email_log')->fetchColumn();
$tabs = function (string $current) use ($self, $logTotal): string {
    $html = '<nav class="view-tabs" aria-label="Emails">';
    // Short labels on phones, where three tabs share one row.
    foreach (['list' => ['folder', 'Email log', 'Log', $logTotal], 'compose' => ['mail', 'Write an email', 'Write', null], 'settings' => ['settings', 'Settings', 'Settings', null]] as $v => [$icon, $label, $short, $n]) {
        $html .= '<a href="' . e($v === 'list' ? $self : $self . '?view=' . $v) . '"' . ($current === $v ? ' aria-current="page"' : '') . '>'
            . pf_icon($icon) . '<span class="max-sm:hidden">' . e($label) . '</span><span class="sm:hidden">' . e($short) . '</span>'
            . ($n !== null ? ' <span class="view-tabs-count">' . number_format($n) . '</span>' : '') . '</a>';
    }
    return $html . '</nav>';
};

// ---------- One email ----------
$d = null;
if ($view === 'detail') {
    $st = $pdo->prepare(
        'SELECT l.*, TIMESTAMPDIFF(SECOND, l.created_at, NOW()) AS ago,
                s.name AS sender_name, u.name AS user_name, u.deleted_at AS user_deleted
           FROM email_log l
           LEFT JOIN users s ON s.id = l.sent_by
           LEFT JOIN users u ON u.id = l.to_user_id
          WHERE l.id = :id'
    );
    $st->execute(['id' => $id]);
    $d = $st->fetch();
    if (!$d) {
        require_once __DIR__ . '/../../src/includes/error_page.php';
        render_error_page(404, 'That email is not in the log.');
    }
    $resends = $pdo->prepare('SELECT id, status, created_at FROM email_log WHERE resend_of = :id ORDER BY id DESC');
    $resends->execute(['id' => $id]);
    $resends = $resends->fetchAll();
    $hist = $pdo->prepare('SELECT id, kind, status, subject, created_at FROM email_log WHERE to_email = :e AND id <> :id ORDER BY created_at DESC, id DESC LIMIT 8');
    $hist->execute(['e' => $d['to_email'], 'id' => $id]);
    $others = $hist->fetchAll();
    $histCount = $pdo->prepare('SELECT COUNT(*) FROM email_log WHERE to_email = :e');
    $histCount->execute(['e' => $d['to_email']]);
    $histCount = (int)$histCount->fetchColumn();

    // Rebuild the email's parts from the stored text for the preview.
    $lines  = preg_split('/\R/', (string)$d['body_text']);
    $sig    = array_search('--', $lines, true);
    $main   = $sig === false ? $lines : array_slice($lines, 0, $sig);
    $foot   = $sig === false ? [] : array_slice($lines, $sig + 1);
    $blocks = array_values(array_filter(array_map('trim', preg_split('/\n{2,}/', implode("\n", $main))), 'strlen'));
    $heading = array_shift($blocks) ?? (string)$d['subject'];
}

// ---------- The log ----------
if ($view === 'list') {
    $f = [
        'status' => isset($STATUS[$_GET['status'] ?? '']) ? (string)$_GET['status'] : '',
        'kind'   => preg_match('/^[a-z_]{1,40}$/', (string)($_GET['kind'] ?? '')) ? (string)$_GET['kind'] : '',
        'q'      => mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100),
        'period' => in_array($_GET['period'] ?? '', ['1', '7', '30'], true) ? (string)$_GET['period'] : '',
    ];
    $where = []; $args = [];
    if ($f['status'] !== '') { $where[] = 'l.status = :status'; $args['status'] = $f['status']; }
    if ($f['kind'] !== '')   { $where[] = 'l.kind = :kind'; $args['kind'] = $f['kind']; }
    if ($f['period'] !== '') { $where[] = 'l.created_at >= NOW() - INTERVAL :days DAY'; $args['days'] = (int)$f['period']; }
    if ($f['q'] !== '') {
        $like = '%' . addcslashes($f['q'], '%_\\') . '%';
        $where[] = '(l.to_email LIKE :q1 OR l.to_name LIKE :q2 OR l.subject LIKE :q3)';
        $args += ['q1' => $like, 'q2' => $like, 'q3' => $like];
    }
    $w = $where ? ' WHERE ' . implode(' AND ', $where) : '';
    $filtered = (bool)array_filter($f);

    $c = $pdo->prepare('SELECT COUNT(*) FROM email_log l' . $w);
    $c->execute($args);
    $total  = (int)$c->fetchColumn();
    $pages  = max(1, (int)ceil($total / PER_PAGE));
    $pageNo = min($pages, max(1, (int)($_GET['page'] ?? 1)));

    $st = $pdo->prepare(
        'SELECT l.id, l.kind, l.status, l.to_email, l.to_name, l.subject, l.created_at, l.error, l.to_user_id,
                DATE(l.created_at) AS day, TIMESTAMPDIFF(SECOND, l.created_at, NOW()) AS ago, s.name AS sender_name
           FROM email_log l LEFT JOIN users s ON s.id = l.sent_by' . $w
        . ' ORDER BY l.created_at DESC, l.id DESC LIMIT ' . PER_PAGE . ' OFFSET ' . (($pageNo - 1) * PER_PAGE)
    );
    $st->execute($args);
    $rows = $st->fetchAll();

    $stats = $pdo->query(
        "SELECT COALESCE(SUM(status = 'sent'), 0) AS sent, COALESCE(SUM(status = 'failed'), 0) AS failed,
                COALESCE(SUM(status = 'skipped'), 0) AS skipped,
                COALESCE(SUM(created_at >= NOW() - INTERVAL 1 DAY), 0) AS day,
                COALESCE(SUM(status = 'failed' AND created_at >= NOW() - INTERVAL 7 DAY), 0) AS failed_week,
                AVG(CASE WHEN status = 'sent' THEN duration_ms END) AS avg_ms,
                CURDATE() AS today
           FROM email_log"
    )->fetch();
    $attempted  = (int)$stats['sent'] + (int)$stats['failed'];

    // Emails per day (per hour for "last 24 hours"), same filters as the list.
    // Emphasis form: Sent in the series blue, Failed in the alert red (what
    // the reader looks for), Not sent in grey. Red/green would fail the
    // colour-blind check, so "sent" is deliberately not green.
    $rangeDays = (int)($f['period'] ?: 30);
    $hourly    = $rangeDays === 1;
    $bucketSql = $hourly ? "DATE_FORMAT(l.created_at, '%Y-%m-%d %H:00')" : 'DATE(l.created_at)';
    $cst = $pdo->prepare("SELECT $bucketSql AS b, l.status, COUNT(*) AS n FROM email_log l"
        . ($w ? $w . ' AND ' : ' WHERE ') . 'l.created_at >= NOW() - INTERVAL ' . $rangeDays . ' DAY GROUP BY b, l.status');
    $cst->execute($args);
    $byBucket = [];
    foreach ($cst as $r) { $byBucket[(string)$r['b']][(string)$r['status']] = (int)$r['n']; }
    $now = new DateTimeImmutable((string)$pdo->query('SELECT NOW()')->fetchColumn());
    $points = [];
    for ($i = ($hourly ? 23 : $rangeDays - 1); $i >= 0; $i--) {
        $t = $now->modify('-' . $i . ($hourly ? ' hours' : ' days'));
        $points[] = $hourly
            ? ['label' => $t->format('M j, H:00'), 'tick' => (int)$t->format('G') % 6 === 0 ? $t->format('H:00') : '', 'values' => $byBucket[$t->format('Y-m-d H:00')] ?? []]
            : ['label' => $t->format('D, M j'), 'tick' => $i % ($rangeDays <= 7 ? 1 : ($rangeDays <= 30 ? 7 : 14)) === 0 ? $t->format('M j') : '', 'values' => $byBucket[$t->format('Y-m-d')] ?? []];
    }
    $kindCounts = $pdo->query('SELECT kind, COUNT(*) FROM email_log GROUP BY kind')->fetchAll(PDO::FETCH_KEY_PAIR);

    $link = function (array $over = []) use ($self, $f): string {
        $qs = http_build_query(array_filter(array_merge($f, $over), fn($v) => $v !== '' && $v !== null));
        return $self . ($qs !== '' ? '?' . $qs : '');
    };
    $dayLabel = function (string $day) use ($stats): string {
        $diff = (int)round((strtotime((string)$stats['today']) - strtotime($day)) / 86400);
        return match (true) {
            $diff === 0 => 'Today',
            $diff === 1 => 'Yesterday',
            $diff < 7   => date('l, M j', strtotime($day)),
            default     => date('M j, Y', strtotime($day)),
        };
    };
}

// ---------- Settings ----------
if ($view === 'settings') {
    $typeStats = [];
    foreach ($pdo->query(
        "SELECT kind,
                SUM(status = 'sent' AND created_at >= NOW() - INTERVAL 30 DAY) AS sent30,
                SUM(status = 'skipped' AND created_at >= NOW() - INTERVAL 30 DAY) AS held30,
                MAX(CASE WHEN status = 'sent' THEN created_at END) AS last_sent
           FROM email_log GROUP BY kind"
    ) as $r) { $typeStats[(string)$r['kind']] = $r; }
    $groups = [];
    foreach ($TYPES as $k => $t) { $groups[$t['group']][$k] = $t; }
    $lastChange = $pdo->query("SELECT s.updated_at, u.name FROM app_settings s LEFT JOIN users u ON u.id = s.updated_by
                                WHERE s.name LIKE 'mail.%' ORDER BY s.updated_at DESC LIMIT 1")->fetch();
    $offCount = count(array_filter(array_keys($TYPES), fn($k) => mail_setting('mail.type.' . $k, '1') !== '1'));
}

// ---------- Write an email ----------
if ($view === 'compose') {
    $people = $pdo->query('SELECT id, name, email FROM users WHERE deleted_at IS NULL ORDER BY name')->fetchAll();
    $people = array_map(fn($p) => ['id' => (int)$p['id'], 'name' => (string)$p['name'], 'email' => (string)$p['email']], $people);
    $groupCounts = ['all' => count($people)];
    foreach ($pdo->query('SELECT r.role, COUNT(DISTINCT u.id) FROM user_roles r JOIN users u ON u.id = r.user_id AND u.deleted_at IS NULL GROUP BY r.role')->fetchAll(PDO::FETCH_KEY_PAIR) as $r => $n) {
        $groupCounts[$r] = (int)$n;
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        if (isset($_GET['user'])) { $form['users'] = [(int)$_GET['user']]; }
        // Several people at once, from the bulk bar on Users (?users=1,2,3).
        if (isset($_GET['users'])) { $form['users'] = array_slice(array_values(array_filter(array_map('intval', explode(',', (string)$_GET['users'])))), 0, MAX_RECIPIENTS); }
        if (isset($_GET['from'])) {
            $st = $pdo->prepare("SELECT * FROM email_log WHERE id = :id AND kind = 'custom'");
            $st->execute(['id' => (int)$_GET['from']]);
            if ($src = $st->fetch()) {
                $text = $customText($src);
                // Put the placeholder back where the recipient's name was filled in.
                if ((string)$src['to_name'] !== '') { $text = str_replace((string)$src['to_name'], '{name}', $text); }
                $form['subject'] = (string)$src['subject'];
                $form['message'] = $text;
            }
        }
    }
    $AUDIENCES = [
        'selected' => ['People I pick', 'Choose accounts below, or type addresses.'],
        'all'      => ['Everyone', 'Every active account.'],
        'student'  => ['Students', 'Everyone with the student role.'],
        'teacher'  => ['Teachers', 'Everyone with the teacher role.'],
        'admin'    => ['Admins', 'Everyone with the admin role.'],
    ];
}

$invalid   = fn(string $k): string => isset($errors[$k]) ? ' aria-invalid="true" aria-describedby="' . $k . '-err"' : '';
$errorLine = fn(string $k): string => isset($errors[$k]) ? '<p class="field-error" id="' . $k . '-err" role="alert">' . e($errors[$k]) . '</p>' : '';

$page_title = match ($view) { 'detail' => $d['subject'] . ' · Emails', 'compose' => 'Write an email', 'settings' => 'Email settings', default => 'Emails' };
require __DIR__ . '/../../src/includes/header.php';
?>
<div class="page">
<?php if ($view === 'detail'): ?>
  <a class="back-link" href="<?= e($self) ?>"><?= pf_icon('back') ?> Email log</a>
  <header class="page-header">
    <div class="min-w-0">
      <h1 class="page-title wrap-break-word"><?= e($d['subject']) ?></h1>
      <p class="page-desc">
        <?= e($kindLabel((string)$d['kind'])) ?> email to <?= e($d['to_name'] ?: $d['to_email']) ?>
        · <span title="<?= e($stamp($d['created_at'])) ?>"><?= e(ui_ago((int)$d['ago'], (string)$d['created_at'])) ?></span>
      </p>
    </div>
    <div class="flex flex-wrap gap-2">
      <?php if ($d['kind'] === 'custom'): ?>
        <a class="btn btn-secondary" href="<?= e($self . '?view=compose&from=' . (int)$d['id'] . ($d['to_user_id'] ? '&user=' . (int)$d['to_user_id'] : '')) ?>"><?= pf_icon('edit') ?> Edit and send</a>
        <form method="post" action="<?= e($self) ?>" x-confirm
              data-confirm-title="Send this email again?"
              data-confirm-body="<?= e('The same message goes to ' . $d['to_email'] . ' once more.') ?>"
              data-confirm-ok="Send again" data-confirm-tone="primary">
          <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
          <input type="hidden" name="action" value="resend">
          <input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
          <button class="btn btn-primary" type="submit"<?= mail_type_enabled('custom') ? '' : ' disabled title="Custom emails are switched off"' ?>><?= pf_icon('swap') ?> Send again</button>
        </form>
      <?php elseif ($d['to_user_id'] && empty($d['user_deleted'])): ?>
        <a class="btn btn-secondary" href="<?= e($self . '?view=compose&user=' . (int)$d['to_user_id']) ?>"><?= pf_icon('mail') ?> Write to <?= e(first_name((string)$d['user_name'])) ?></a>
      <?php endif; ?>
    </div>
  </header>

  <?php
    $hint = $d['error'] ? $explain((string)$d['error']) : '';
    if ($d['status'] === 'sent') {
        echo ui_alert('success', '<strong>Accepted by the mail server</strong> (' . e((string)$d['smtp_host']) . ') in '
            . e($seconds($d['duration_ms'] !== null ? (int)$d['duration_ms'] : null)) . '. Whether it was opened is not tracked.');
    } elseif ($d['status'] === 'failed') {
        echo ui_alert('error', '<strong>The mail server refused this email.</strong>' . ($hint !== '' ? ' ' . e($hint) : '')
            . '<span class="mt-2 block font-mono text-xs wrap-break-word opacity-80">' . e((string)$d['error']) . '</span>');
    } else {
        echo ui_alert('warning', '<strong>Not sent.</strong> ' . e($hint !== '' ? $hint : (string)$d['error']));
    }
  ?>

  <div class="mt-6 grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_20rem]">
    <section aria-label="The email">
      <div class="mail-preview">
        <dl class="mail-head">
          <div><dt>From</dt><dd><?= e((string)($d['from_email'] ?? '–')) ?></dd></div>
          <div><dt>To</dt><dd><?= e($d['to_name'] ? $d['to_name'] . ' <' . $d['to_email'] . '>' : $d['to_email']) ?></dd></div>
          <div><dt>Subject</dt><dd class="font-semibold"><?= e($d['subject']) ?></dd></div>
          <div><dt>Date</dt><dd><?= e($stamp($d['created_at'])) ?></dd></div>
        </dl>
        <div class="mail-canvas">
          <div class="mail-brand"><span class="mail-logo">P</span> Profile System</div>
          <div class="mail-body">
            <h3><?= e($heading) ?></h3>
            <?php foreach ($blocks as $b): ?>
              <?php if (str_ends_with($b, '[link hidden]')): ?>
                <p><span class="mail-button"><?= e(rtrim(trim(str_replace('[link hidden]', '', $b)), ':') ?: 'Open link') ?></span>
                   <span class="block text-xs text-muted-foreground-1">One-time link, not kept in the log.</span></p>
              <?php else: ?>
                <p><?= e($b) ?></p>
              <?php endif; ?>
            <?php endforeach; ?>
          </div>
          <?php if ($foot): ?><p class="mail-foot"><?= e(implode(' · ', array_filter(array_map('trim', $foot)))) ?></p><?php endif; ?>
        </div>
      </div>
    </section>

    <aside class="section mt-0" aria-labelledby="em-facts-h">
      <header class="section-head"><h2 class="section-title" id="em-facts-h">Details</h2></header>
      <dl class="facts is-single">
        <div><dt>Result</dt><dd><?= $badge((string)$d['status']) ?></dd></div>
        <div><dt>Type</dt><dd><?= e($kindLabel((string)$d['kind'])) ?></dd></div>
        <div><dt>Sent by</dt><dd><?= $d['sender_name'] ? e($d['sender_name']) : ($d['sent_by'] ? 'A deleted admin' : 'The system') ?></dd></div>
        <div><dt>Account</dt><dd>
          <?php if ($d['to_user_id']): ?><a href="<?= e(url_admin_user() . '?id=' . (int)$d['to_user_id']) ?>"><?= e($d['user_name']) ?></a>
          <?php else: ?><span class="faint">No account</span><?php endif; ?></dd></div>
        <div><dt>Attempted</dt><dd><?= e($stamp($d['created_at'])) ?></dd></div>
        <div><dt>Took</dt><dd><?= e($seconds($d['duration_ms'] !== null ? (int)$d['duration_ms'] : null)) ?></dd></div>
        <div><dt>Mail server</dt><dd class="break-all"><?= e((string)($d['smtp_host'] ?? '–')) ?></dd></div>
        <div><dt>Requested from</dt><dd class="mono"><?= e((string)($d['request_ip'] ?? 'background task')) ?></dd></div>
        <div><dt>Log number</dt><dd class="mono">#<?= (int)$d['id'] ?></dd></div>
        <?php if ($d['resend_of']): ?>
          <div><dt>Resend of</dt><dd><a href="<?= e($self . '?id=' . (int)$d['resend_of']) ?>">#<?= (int)$d['resend_of'] ?></a></dd></div>
        <?php endif; ?>
        <?php foreach ($resends as $r): ?>
          <div><dt>Sent again</dt><dd><?= $badge((string)$r['status']) ?> <a href="<?= e($self . '?id=' . (int)$r['id']) ?>"><?= e(date('M j, H:i', strtotime((string)$r['created_at']))) ?></a></dd></div>
        <?php endforeach; ?>
      </dl>
    </aside>
  </div>

  <?php if ($others): ?>
    <section class="section" aria-labelledby="em-more-h">
      <header class="section-head">
        <h2 class="section-title" id="em-more-h">Other emails to <?= e($d['to_email']) ?> <span class="section-count"><?= $histCount - 1 ?></span></h2>
        <?php if ($histCount - 1 > count($others)): ?><a class="text-sm" href="<?= e($self . '?q=' . urlencode((string)$d['to_email'])) ?>">See all</a><?php endif; ?>
      </header>
      <ul>
        <?php foreach ($others as $o): ?>
          <li class="flex flex-wrap items-center gap-x-3 gap-y-1 border-b border-layer-line py-3">
            <?= $badge((string)$o['status']) ?>
            <a class="min-w-0 font-semibold" href="<?= e($self . '?id=' . (int)$o['id']) ?>"><?= e($o['subject']) ?></a>
            <span class="ml-auto text-sm text-muted-foreground-1"><?= e($kindLabel((string)$o['kind'])) ?> · <?= e($stamp($o['created_at'])) ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
    </section>
  <?php endif; ?>

<?php else: ?>
  <header class="page-header">
    <div>
      <h1 class="page-title">Emails</h1>
      <p class="page-desc">Every email the system sends and whether the mail server accepted it. You can also write to members from here.</p>
    </div>
  </header>

  <?php if (mail_enabled() && mail_setting('mail.enabled', '1') !== '1'): ?>
    <div class="mb-6"><?= ui_alert('warning', '<strong>All email is switched off.</strong> Nothing is sent, not even password resets. '
        . ($view === 'settings' ? 'Turn “Send email” back on below.' : '<a href="' . e($self . '?view=settings') . '">Open Settings</a> to turn it back on.')) ?></div>
  <?php endif; ?>
  <?php if (!mail_enabled()): ?>
    <div class="mb-6"><?= ui_alert('warning', '<strong>Email is off on this server.</strong> Nothing is sent until <code>MAIL_HOST</code> and <code>MAIL_FROM_ADDRESS</code> are set in <code>.env</code>; attempts are logged as “Not sent”.') ?></div>
  <?php endif; ?>

  <?= $tabs($view) ?>

  <?php if ($view === 'list'): ?>
    <?php if ($logTotal > 0): ?>
      <dl class="stat-strip">
        <div><dt><?= pf_icon('check') ?> Sent</dt>
          <dd><a href="<?= e($self . '?status=sent') ?>"><?= number_format((int)$stats['sent']) ?></a></dd>
          <p class="stat-note"><?= $attempted ? round(100 * $stats['sent'] / $attempted) . '% of attempts' : 'No attempts yet' ?></p></div>
        <div<?= (int)$stats['failed'] ? ' class="is-danger"' : '' ?>><dt><?= pf_icon('alert') ?> Failed</dt>
          <dd><a href="<?= e($self . '?status=failed') ?>"><?= number_format((int)$stats['failed']) ?></a></dd>
          <p class="stat-note"><?= (int)$stats['failed_week'] ?> in the last 7 days</p></div>
        <div><dt><?= pf_icon('info') ?> Not sent</dt>
          <dd><a href="<?= e($self . '?status=skipped') ?>"><?= number_format((int)$stats['skipped']) ?></a></dd>
          <p class="stat-note">Email was off</p></div>
        <div><dt><?= pf_icon('mail') ?> Last 24 hours</dt>
          <dd><a href="<?= e($self . '?period=1') ?>"><?= number_format((int)$stats['day']) ?></a></dd>
          <p class="stat-note"><?= $stats['avg_ms'] !== null ? 'Avg. ' . e($seconds((int)$stats['avg_ms'])) . ' to send' : '&nbsp;' ?></p></div>
      </dl>

      <form method="get" action="<?= e($self) ?>" class="toolbar" role="search" x-autosubmit>
        <div class="grow search-field">
          <label class="sr-only" for="f-q">Search emails</label>
          <?= pf_icon('search') ?>
          <input class="input" id="f-q" name="q" type="search" value="<?= e($f['q']) ?>" placeholder="Search address, name or subject">
        </div>
        <div>
          <label class="sr-only" for="f-kind">Type</label>
          <select class="select" id="f-kind" name="kind">
            <option value="">All types</option>
            <?php foreach ($KINDS as $k => $l): ?>
              <option value="<?= $k ?>"<?= $f['kind'] === $k ? ' selected' : '' ?>><?= e($l) ?><?= isset($kindCounts[$k]) ? ' (' . (int)$kindCounts[$k] . ')' : '' ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label class="sr-only" for="f-period">When</label>
          <select class="select" id="f-period" name="period">
            <option value="">Any time</option>
            <option value="1"<?= $f['period'] === '1' ? ' selected' : '' ?>>Last 24 hours</option>
            <option value="7"<?= $f['period'] === '7' ? ' selected' : '' ?>>Last 7 days</option>
            <option value="30"<?= $f['period'] === '30' ? ' selected' : '' ?>>Last 30 days</option>
          </select>
        </div>
        <?php if ($f['status'] !== ''): ?><input type="hidden" name="status" value="<?= e($f['status']) ?>"><?php endif; ?>
        <button class="btn btn-secondary" type="submit">Search</button>
        <?php if ($filtered): ?><a class="btn btn-ghost" href="<?= e($self) ?>">Clear</a><?php endif; ?>
      </form>

      <nav class="filter-chips" aria-label="Filter by result">
        <span class="filter-chips-label">Result</span>
        <?php foreach (['' => 'All'] + array_map(fn($s) => $s[0], $STATUS) as $k => $l): ?>
          <a href="<?= e($link(['status' => $k])) ?>"<?= $f['status'] === $k ? ' aria-current="page"' : '' ?>><?= e($l) ?></a>
        <?php endforeach; ?>
      </nav>
    <?php endif; ?>

    <?php if ($logTotal > 0): ?>
      <div class="mb-6">
        <?= chart_columns([
            'sent'    => ['Sent', '--series-1'],
            'failed'  => ['Failed', '--series-alert'],
            'skipped' => ['Not sent', '--series-muted'],
        ], $points, [
            'id' => 'em-cols', 'unit' => 'emails', 'xLabel' => $hourly ? 'Hour' : 'Day',
            'title' => 'Emails ' . ($hourly ? 'per hour' : 'per day'),
            'desc' => ($hourly ? 'Last 24 hours' : 'Last ' . $rangeDays . ' days') . ($filtered ? ', matching the filters above' : '') . '. Failed is in red; “Not sent” means email was switched off.',
        ]) ?>
      </div>
    <?php endif; ?>

    <?php if (!$rows): ?>
      <div class="empty mt-2">
        <?php if ($filtered): ?>
          <h2 class="empty-title">No emails match</h2>
          <p>Try another search, or clear the filters.</p>
          <a class="btn btn-secondary" href="<?= e($self) ?>">Clear filters</a>
        <?php else: ?>
          <h2 class="empty-title">No emails yet</h2>
          <p>Invitations, password resets and security notices appear here as they are sent.</p>
          <a class="btn btn-secondary" href="<?= e($self . '?view=compose') ?>"><?= pf_icon('mail') ?> Write an email</a>
        <?php endif; ?>
      </div>
    <?php else: ?>
      <div class="table-wrap">
        <div class="table-scroll max-md:hidden">
          <table class="table">
            <thead>
              <tr>
                <th scope="col">Email</th>
                <th scope="col">To</th>
                <th scope="col">Result</th>
                <th scope="col" class="num">Time</th>
              </tr>
            </thead>
            <tbody>
              <?php $lastDay = null; foreach ($rows as $r): ?>
                <?php if ($r['day'] !== $lastDay): $lastDay = $r['day']; ?>
                  <tr class="table-group"><th colspan="4" scope="colgroup"><?= e($dayLabel((string)$r['day'])) ?></th></tr>
                <?php endif; ?>
                <tr class="has-row-link">
                  <td class="min-w-64">
                    <a class="row-link" href="<?= e($self . '?id=' . (int)$r['id']) ?>"><?= e($r['subject']) ?></a>
                    <span class="cell-sub"><?= e($kindLabel((string)$r['kind'])) ?><?= $r['sender_name'] ? ' · by ' . e($r['sender_name']) : '' ?></span>
                  </td>
                  <td>
                    <span class="block font-medium"><?= e($r['to_name'] ?: $r['to_email']) ?></span>
                    <?php if ($r['to_name']): ?><span class="cell-sub"><?= e($r['to_email']) ?></span><?php endif; ?>
                  </td>
                  <td>
                    <?= $badge((string)$r['status']) ?>
                    <?php if ($r['status'] === 'failed' && $r['error']): ?><span class="cell-error" title="<?= e($r['error']) ?>"><?= e($explain((string)$r['error']) ?: (string)$r['error']) ?></span><?php endif; ?>
                  </td>
                  <td class="num nowrap" title="<?= e($stamp($r['created_at'])) ?>">
                    <?= e(date('H:i', strtotime((string)$r['created_at']))) ?>
                    <?php if ((int)$r['ago'] < 86400): ?><span class="cell-sub"><?= e(ui_ago((int)$r['ago'], (string)$r['created_at'])) ?></span><?php endif; ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <!-- Small screens: one block per email -->
        <ul class="card-list md:hidden">
          <?php $lastDay = null; foreach ($rows as $r): ?>
            <?php if ($r['day'] !== $lastDay): $lastDay = $r['day']; ?>
              <li class="card-list-day"><?= e($dayLabel((string)$r['day'])) ?></li>
            <?php endif; ?>
            <li>
              <a class="card-list-item gap-1.5 text-foreground no-underline hover:bg-layer-hover" href="<?= e($self . '?id=' . (int)$r['id']) ?>">
                <span class="flex items-start justify-between gap-3">
                  <span class="min-w-0 font-semibold wrap-break-word"><?= e($r['subject']) ?></span>
                  <?= $badge((string)$r['status']) ?>
                </span>
                <span class="text-sm wrap-break-word text-muted-foreground-2">To <?= e($r['to_name'] ?: $r['to_email']) ?></span>
                <span class="text-xs text-muted-foreground-1"><?= e($kindLabel((string)$r['kind'])) ?> · <?= e(date('H:i', strtotime((string)$r['created_at']))) ?></span>
                <?php if ($r['status'] === 'failed' && $r['error']): ?><span class="text-xs text-danger"><?= e($explain((string)$r['error']) ?: mb_strimwidth((string)$r['error'], 0, 90, '…')) ?></span><?php endif; ?>
              </a>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>

      <nav class="mt-4 flex flex-wrap items-center justify-between gap-3" aria-label="Pages">
        <p class="text-sm text-muted-foreground-1">
          <?= number_format(($pageNo - 1) * PER_PAGE + 1) ?>–<?= number_format(min($total, $pageNo * PER_PAGE)) ?> of <?= number_format($total) ?><?= $filtered ? ' matching' : '' ?>
        </p>
        <?php if ($pages > 1): ?>
          <div class="flex gap-2">
            <?php if ($pageNo > 1): ?><a class="btn btn-secondary btn-sm" href="<?= e($link(['page' => $pageNo - 1 > 1 ? $pageNo - 1 : null])) ?>">← Newer</a><?php endif; ?>
            <?php if ($pageNo < $pages): ?><a class="btn btn-secondary btn-sm" href="<?= e($link(['page' => $pageNo + 1])) ?>">Older →</a><?php endif; ?>
          </div>
        <?php endif; ?>
      </nav>
    <?php endif; ?>

  <?php elseif ($view === 'settings'): ?>
    <form method="post" action="<?= e($self) ?>" x-data="dirtyForm" class="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_20rem]">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="settings">

      <div class="min-w-0" x-data="{ all: <?= mail_setting('mail.enabled', '1') === '1' ? 'true' : 'false' ?> }">
        <section class="card" aria-labelledby="set-all-h">
          <div class="setting-row py-0">
            <div class="setting-body">
              <h2 class="card-title" id="set-all-h">Send email</h2>
              <p class="card-desc">The master switch. Off stops every email below at once, including password resets. Each attempt is still logged as “Not sent”.</p>
              <p class="setting-stat">
                <?php if ($lastChange): ?>Last changed <?= e(date('M j, Y · H:i', strtotime((string)$lastChange['updated_at']))) ?><?= $lastChange['name'] ? ' by ' . e($lastChange['name']) : '' ?>.<?php else: ?>Everything is on (the default).<?php endif; ?>
                <?= $offCount ? $offCount . ' kind' . ($offCount === 1 ? ' is' : 's are') . ' switched off below.' : '' ?>
              </p>
              <p class="setting-off" x-show="!all" x-cloak><?= pf_icon('alert') ?> Everything below is held back while this is off.</p>
            </div>
            <label class="switch">
              <input type="checkbox" role="switch" name="mail_enabled" value="1" x-model="all" aria-labelledby="set-all-h">
              <span class="switch-track"></span>
            </label>
          </div>
        </section>

        <?php foreach ($groups as $group => $items): ?>
          <section class="section" aria-labelledby="set-<?= e(md5($group)) ?>">
            <header class="section-head">
              <h2 class="section-title" id="set-<?= e(md5($group)) ?>"><?= e($group) ?></h2>
            </header>
            <?php foreach ($items as $k => $t):
                $on = mail_setting('mail.type.' . $k, '1') === '1';
                $st = $typeStats[$k] ?? null;
            ?>
              <div class="setting-row" x-data="{ on: <?= $on ? 'true' : 'false' ?> }" :class="(!all || !on) && 'is-muted'">
                <div class="setting-body">
                  <p class="setting-title" id="set-<?= e($k) ?>">
                    <?= e($t['label']) ?>
                    <span class="badge badge-warning" x-show="!on" x-cloak>Off</span>
                  </p>
                  <dl class="setting-meta">
                    <dt>Sent to</dt><dd><?= e($t['to']) ?></dd>
                    <dt>When</dt><dd><?= e($t['when']) ?></dd>
                  </dl>
                  <p class="setting-stat">
                    <?php if ($st && (int)$st['sent30'] + (int)$st['held30'] > 0): ?>
                      <a href="<?= e($self . '?kind=' . $k . '&period=30') ?>"><?= (int)$st['sent30'] ?> sent in the last 30 days</a><?= (int)$st['held30'] ? ' · ' . (int)$st['held30'] . ' not sent' : '' ?>
                    <?php else: ?>None in the last 30 days<?php endif; ?>
                    <?php if ($st && $st['last_sent']): ?> · last sent <?= e(date('M j, Y', strtotime((string)$st['last_sent']))) ?><?php endif; ?>
                  </p>
                  <p class="setting-off" x-show="!on" x-cloak><?= pf_icon('alert') ?> <span><strong>While off:</strong> <?= e($t['off']) ?></span></p>
                </div>
                <label class="switch">
                  <input type="checkbox" role="switch" name="type[<?= e($k) ?>]" value="1" x-model="on" aria-labelledby="set-<?= e($k) ?>">
                  <span class="switch-track"></span>
                </label>
              </div>
            <?php endforeach; ?>
          </section>
        <?php endforeach; ?>

        <section class="card mt-6" aria-labelledby="set-log-h">
          <h2 class="card-title" id="set-log-h">Email log</h2>
          <p class="card-desc mb-4">How long to keep the list of sent emails. Older entries are deleted automatically (checked every few hours).</p>
          <div class="field mb-0 max-w-xs">
            <label class="label" for="set-days">Keep entries for</label>
            <select class="select" id="set-days" name="log_days">
              <?php foreach ($LOG_DAYS as $v => $l): ?>
                <option value="<?= $v ?>"<?= mail_setting('mail.log_days', '0') === (string)$v ? ' selected' : '' ?>><?= e($l) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </section>

        <div class="form-actions sticky bottom-0 z-10 -mx-1 mt-6 rounded-lg bg-[var(--background)]/90 px-1 py-3 backdrop-blur">
          <p class="form-note" x-show="dirty" x-cloak>You have unsaved changes.</p>
          <button class="btn btn-primary" type="submit">Save settings</button>
        </div>
      </div>

      <aside class="space-y-6 lg:sticky lg:top-24">
        <section class="section mt-0" aria-labelledby="set-srv-h">
          <header class="section-head"><h2 class="section-title" id="set-srv-h">Mail server</h2></header>
          <div class="pt-4">
            <p class="mb-3 flex items-center gap-2 text-sm">
              <?= mail_enabled() ? '<span class="badge badge-success">Set up</span>' : '<span class="badge badge-danger">Not set up</span>' ?>
              <span class="text-muted-foreground-2">Read from <code>.env</code></span>
            </p>
            <dl class="server-facts">
              <dt>Server</dt><dd><?= e((string)mail_config_value('MAIL_HOST') ?: '–') ?><?= mail_config_value('MAIL_HOST') ? ':' . e((string)(mail_config_value('MAIL_PORT') ?: '587')) : '' ?></dd>
              <dt>Security</dt><dd><?= e(strtoupper((string)(mail_config_value('MAIL_ENCRYPTION') ?: 'tls'))) ?></dd>
              <dt>From</dt><dd><?= e((string)mail_config_value('MAIL_FROM_ADDRESS') ?: '–') ?></dd>
              <dt>Sender name</dt><dd><?= e((string)(mail_config_value('MAIL_FROM_NAME') ?: 'Profile System')) ?></dd>
              <dt>Links use</dt><dd><?= e(mail_app_url() ?: 'APP_URL not set') ?></dd>
            </dl>
            <p class="hint mt-3">To change these, edit <code>.env</code> and restart the app. The password is never shown.</p>
          </div>
        </section>
        <section class="card" aria-labelledby="set-test-h">
          <h2 class="card-title" id="set-test-h">Check that email works</h2>
          <p class="card-desc mb-4">Sends a short test to your own address and logs the result.</p>
          <button class="btn btn-secondary btn-block" type="submit" form="srv-test"<?= mail_type_enabled('test') ? '' : ' disabled' ?>><?= pf_icon('mail') ?> Send me a test email</button>
        </section>
      </aside>
    </form>
    <!-- The test button sits in the aside above but submits this form (forms cannot nest). -->
    <form method="post" action="<?= e($self) ?>" id="srv-test" hidden>
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="action" value="server_test">
    </form>

  <?php else: /* compose */ ?>
    <form method="post" action="<?= e($self) ?>" x-data="emailComposer" @submit="submit" novalidate
          data-people="<?= e(json_encode($people)) ?>"
          data-picked="<?= e(json_encode(array_values($form['users']))) ?>"
          data-groups="<?= e(json_encode($groupCounts)) ?>"
          class="grid items-start gap-6 lg:grid-cols-[minmax(0,1fr)_24rem]">
      <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">

      <div class="min-w-0">
        <?php if (mail_enabled() && mail_setting('mail.enabled', '1') === '1' && !mail_type_enabled('custom')): ?>
          <div class="mb-5"><?= ui_alert('info', '<strong>Custom emails are switched off.</strong> You can write a draft, but it cannot be sent until they are turned on in <a href="' . e($self . '?view=settings') . '">Settings</a>.') ?></div>
        <?php endif; ?>
        <?php if ($errors): ?>
          <div class="mb-5"><?= ui_alert('error', e($errors['form'] ?? 'Nothing was sent. Please fix the highlighted fields.')) ?></div>
        <?php endif; ?>

        <section class="card" aria-labelledby="em-to-h">
          <h2 class="card-title" id="em-to-h">1. Who gets it</h2>
          <p class="card-desc mb-4">Everyone gets their own copy and cannot see the other recipients.</p>

          <fieldset class="field">
            <legend class="sr-only">Send to</legend>
            <div class="choice-list">
              <?php foreach ($AUDIENCES as $k => [$title, $desc]): ?>
                <label class="choice">
                  <input type="radio" name="audience" value="<?= $k ?>" x-model="audience"<?= $form['audience'] === $k ? ' checked' : '' ?>>
                  <span class="flex-1">
                    <span class="choice-title"><?= e($title) ?><?php if ($k !== 'selected'): ?> <span class="badge ml-1"><?= (int)($groupCounts[$k] ?? 0) ?></span><?php endif; ?></span>
                    <span class="choice-desc"><?= e($desc) ?></span>
                  </span>
                </label>
              <?php endforeach; ?>
            </div>
            <?= $errorLine('audience') ?>
          </fieldset>

          <div class="field" x-show="audience === 'selected'">
            <span class="label" id="em-pick-l">Accounts</span>
            <div class="pick" role="group" aria-labelledby="em-pick-l">
              <div class="pick-head">
                <div class="search-field">
                  <?= pf_icon('search') ?>
                  <input class="input" type="search" x-model="query" placeholder="Search by name or email" aria-label="Search accounts" @keydown.enter.prevent>
                </div>
                <button type="button" class="btn btn-secondary btn-sm" @click="pickShown()" :disabled="!shown.length"
                        x-text="query ? 'Select ' + shown.length + ' shown' : 'Select all'">Select all</button>
                <button type="button" class="btn btn-ghost btn-sm" @click="clearPicked()" x-show="picked.length">Clear</button>
              </div>
              <ul class="pick-list">
                <template x-for="p in shown" :key="p.id">
                  <li>
                    <label class="pick-row">
                      <input type="checkbox" name="users[]" :value="p.id" :checked="isPicked(p.id)" @change="toggle(p.id)">
                      <span class="avatar avatar-sm" aria-hidden="true" x-text="p.name.charAt(0).toUpperCase()"></span>
                      <span class="min-w-0">
                        <span class="person-name block truncate" x-text="p.name"></span>
                        <span class="person-sub block truncate" x-text="p.email"></span>
                      </span>
                    </label>
                  </li>
                </template>
                <li class="pick-empty" x-show="!shown.length">Nobody matches “<span x-text="query"></span>”.</li>
              </ul>
              <div class="pick-foot">
                <span><strong x-text="picked.length">0</strong> of <?= count($people) ?> selected</span>
              </div>
            </div>
            <!-- Picked people that the search is hiding still need to be posted. -->
            <template x-for="id in picked.filter(i => !shown.some(p => p.id === i))" :key="'h' + id">
              <input type="hidden" name="users[]" :value="id">
            </template>
            <div class="chip-list" x-show="picked.length" aria-label="Selected">
              <template x-for="p in pickedPeople.slice(0, 12)" :key="'c' + p.id">
                <span class="chip"><span x-text="p.name"></span>
                  <button type="button" @click="toggle(p.id)" :aria-label="'Remove ' + p.name"><?= pf_icon('close') ?></button></span>
              </template>
              <span class="chip pr-2.5 text-muted-foreground-2" x-show="picked.length > 12" x-text="'+ ' + (picked.length - 12) + ' more'"></span>
            </div>
          </div>

          <div class="field mb-0">
            <label class="label" for="em-extra">Other addresses <span class="optional">(optional)</span></label>
            <textarea class="textarea" id="em-extra" name="extra" rows="2" x-model="extra"
                      placeholder="one@example.com, two@example.com"<?= $invalid('extra') ?>><?= e($form['extra']) ?></textarea>
            <p class="hint" :class="extraList.invalid.length && 'is-bad'"
               x-text="extraList.invalid.length ? 'Not an address: ' + extraList.invalid.slice(0, 3).join(', ') : 'For people without an account. Separate with commas or new lines.'">
              For people without an account. Separate with commas or new lines.</p>
            <?= $errorLine('extra') ?>
          </div>
        </section>

        <section class="card" aria-labelledby="em-msg-h">
          <h2 class="card-title mb-4" id="em-msg-h">2. The message</h2>
          <div class="field">
            <label class="label" for="em-sub">Subject</label>
            <input class="input" id="em-sub" name="subject" maxlength="200" x-model="subject" value="<?= e($form['subject']) ?>"
                   placeholder="e.g. Lab meeting moved to Thursday"<?= $invalid('subject') ?>>
            <div class="field-under"><span></span><span class="counter" :class="subject.length > 180 && 'is-warning'" x-text="subject.length + ' / 200'"></span></div>
            <?= $errorLine('subject') ?>
          </div>
          <div class="field mb-0">
            <div class="mb-1.5 flex items-end justify-between gap-2">
              <label class="label mb-0" for="em-msg">Message</label>
              <button type="button" class="btn btn-ghost btn-sm" @click="insertName()" title="Each person sees their own name here">+ Insert {name}</button>
            </div>
            <textarea class="textarea" id="em-msg" name="message" rows="12" maxlength="10000" x-ref="message" x-model="message"
                      placeholder="Hi {name},&#10;&#10;Write your message here. Leave a blank line between paragraphs."<?= $invalid('message') ?>><?= e($form['message']) ?></textarea>
            <div class="field-under">
              <p class="hint">Plain text. A blank line starts a new paragraph.</p>
              <span class="counter" :class="message.length > 9000 && 'is-warning'" x-text="message.length.toLocaleString() + ' / 10,000'"></span>
            </div>
            <?= $errorLine('message') ?>
          </div>

          <div class="send-bar">
            <p class="send-bar-note" aria-live="polite">
              <span x-show="recipientCount > 0">Goes to <strong x-text="recipientCount"></strong> <span x-text="recipientCount === 1 ? 'person' : 'people'"></span></span>
              <span x-show="recipientCount === 0">No recipients yet</span>
            </p>
            <button class="btn btn-secondary" type="submit" name="action" value="test"<?= mail_type_enabled('custom') ? '' : ' disabled' ?>
                    title="Sends one copy to your own address">Send me a test</button>
            <button class="btn btn-primary" type="submit" name="action" value="send"<?= mail_type_enabled('custom') ? '' : ' disabled' ?>
                    <?php if (mail_type_enabled('custom')): ?>:disabled="recipientCount === 0 || recipientCount > <?= MAX_RECIPIENTS ?> || !subject.trim() || !message.trim()"<?php endif; ?>>
              <?= pf_icon('mail') ?> Send email</button>
          </div>
          <p class="hint text-right" x-show="recipientCount > <?= MAX_RECIPIENTS ?>">At most <?= MAX_RECIPIENTS ?> people per send.</p>
        </section>
      </div>

      <aside class="lg:sticky lg:top-24" aria-labelledby="em-prev-h">
        <h2 class="mb-2 text-sm font-semibold text-muted-foreground-2" id="em-prev-h">Preview</h2>
        <div class="mail-preview">
          <dl class="mail-head">
            <div><dt>To</dt><dd x-text="previewTo || '—'">—</dd></div>
            <div><dt>Subject</dt><dd class="font-semibold" x-text="subject || '—'"><?= e($form['subject'] ?: '—') ?></dd></div>
          </dl>
          <div class="mail-canvas">
            <div class="mail-brand"><span class="mail-logo">P</span> Profile System</div>
            <div class="mail-body">
              <h3 :class="!subject && 'mail-placeholder'" x-text="subject || 'Your subject'">Your subject</h3>
              <template x-for="(p, i) in previewParas" :key="i"><p x-text="p"></p></template>
              <p class="mail-placeholder" x-show="!previewParas.length">Your message appears here as you type.</p>
            </div>
            <p class="mail-foot">This message was sent to you by an admin of the lab’s Profile System.</p>
          </div>
        </div>
        <p class="hint mt-2" x-show="message.includes('{name}')">Showing {name} as “<span x-text="previewName"></span>”.</p>
      </aside>
    </form>
  <?php endif; ?>
<?php endif; ?>
</div>
<?php require __DIR__ . '/../../src/includes/footer.php'; ?>
