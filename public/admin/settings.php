<?php
/**
 * Admin: settings for the whole site.
 *
 * Route:  GET  /admin/settings.php
 *         POST /admin/settings.php   action = save (time zone) | save_activity
 * Auth:   require_admin()
 * CSRF:   verify_csrf() on POST
 *
 * Time zone (app_settings 'app.timezone'): every time in the app is shown in
 * it, "today" starts at its midnight, and the charts count days and hours in
 * it. db.php applies it to PHP and to the database connection on every
 * request. Stored times are TIMESTAMP (UTC inside, migration 036), so a
 * change moves nothing that is stored; it only changes how times read.
 *
 * Activity log (app_settings 'activity.keep_days', 'activity.ip_days'):
 * how long events are kept (forever by default) and how long their IP
 * address and browser are kept (90 days by default, never longer than the
 * event). Housekeeping applies it every few hours (activity_purge() in
 * housekeeping.php); saving applies it at once. The Privacy page reads the
 * same settings.
 *
 * Email switches have their own page (Emails › Settings).
 */
declare(strict_types=1);
require_once __DIR__ . '/../../src/includes/auth.php';
require_once __DIR__ . '/../../src/includes/db.php';
require_once __DIR__ . '/../../src/includes/url.php';
require_once __DIR__ . '/../../src/includes/validate.php';
require_once __DIR__ . '/../../src/includes/ui.php';
require_admin();

$self    = url_admin_settings();
$page_id = 'admin-settings';
$meId    = (int)current_user_id();
$ZONES   = timezone_identifiers_list();
$errors  = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_activity') {
    verify_csrf();
    $keep = (int)($_POST['keep_days'] ?? -1);
    $ip   = (int)($_POST['ip_days'] ?? -1);
    if (!isset(ACTIVITY_KEEP_DAYS[$keep]) || !isset(ACTIVITY_IP_DAYS[$ip])) {
        header('Location: ' . $self . '?done=activity_invalid#activity'); exit;
    }
    $old = activity_retention($pdo);
    $save = $pdo->prepare('INSERT INTO app_settings (name, value, updated_by) VALUES (:n, :v, :b)
                           ON DUPLICATE KEY UPDATE value = VALUES(value), updated_by = VALUES(updated_by)');
    $save->execute(['n' => 'activity.keep_days', 'v' => (string)$keep, 'b' => $meId]);
    $save->execute(['n' => 'activity.ip_days', 'v' => (string)$ip, 'b' => $meId]);
    $new = activity_retention($pdo);
    $res = activity_purge($pdo);
    // Logged after the clean-up, so the record of it is never what it removes.
    if ($old !== $new || $res['deleted'] || $res['cleared']) {
        log_activity($meId, 'admin_site_settings', 'activity_days=' . $new['keep'] . ';ip_days=' . $new['ip'] . ';deleted=' . $res['deleted'] . ';cleared=' . $res['cleared']);
    }
    header('Location: ' . $self . '?' . http_build_query(['done' => 'activity', 'deleted' => $res['deleted'], 'cleared' => $res['cleared']]) . '#activity');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verify_csrf();
    $tz = (string)($_POST['timezone'] ?? '');
    if (!in_array($tz, $ZONES, true)) {
        $errors['timezone'] = 'Choose a time zone from the list.';
    } else {
        $old = app_timezone();
        if ($tz !== $old) {
            $pdo->prepare('INSERT INTO app_settings (name, value, updated_by) VALUES (\'app.timezone\', :v, :b)
                           ON DUPLICATE KEY UPDATE value = VALUES(value), updated_by = VALUES(updated_by)')
                ->execute(['v' => $tz, 'b' => $meId]);
            // Log it in the new zone, like everything after it.
            date_default_timezone_set($tz);
            $pdo->exec('SET time_zone = ' . $pdo->quote($tz));
            log_activity($meId, 'admin_site_settings', 'timezone=' . $old . '->' . $tz);
        }
        header('Location: ' . $self . '?done=' . ($tz !== $old ? 'saved' : 'unchanged'));
        exit;
    }
}

$flash = match ($_GET['done'] ?? '') {
    'saved'     => [['type' => 'success', 'title' => 'Time zone saved', 'body' => 'Every time in the app is now shown in ' . app_timezone_label() . '.']],
    'unchanged' => [['type' => 'info', 'body' => 'Nothing was changed.']],
    'activity'  => [['type' => 'success', 'title' => 'Activity log settings saved',
                     'body' => ((int)($_GET['deleted'] ?? 0) ? number_format((int)$_GET['deleted']) . ' old events deleted. ' : 'No events were old enough to delete. ')
                             . ((int)($_GET['cleared'] ?? 0) ? 'IP address and browser removed from ' . number_format((int)$_GET['cleared']) . ' more.' : '')]],
    'activity_invalid' => [['type' => 'error', 'body' => 'Choose how long to keep activity from the lists.']],
    default     => [],
};

// The list, grouped by region, each with its offset right now.
$groups = [];
$now = new DateTimeImmutable('now');
foreach ($ZONES as $z) {
    $region = str_contains($z, '/') ? strstr($z, '/', true) : 'Other';
    $off = $now->setTimezone(new DateTimeZone($z))->format('P');
    $groups[$region][$z] = str_replace(['_', '/'], [' ', ' / '], substr($z, strlen($region) + 1) ?: $z) . ' — UTC' . $off;
}
ksort($groups);
$current = app_timezone();

// Activity log: what is stored, and how much each choice would remove now.
$ret = activity_retention($pdo);
$actStats = $pdo->query('SELECT COUNT(*) AS n, MIN(created_at) AS oldest, COALESCE(SUM(ip IS NOT NULL OR user_agent IS NOT NULL), 0) AS with_ip FROM activity')->fetch();
$olderThan = [];
foreach (array_unique([...array_keys(ACTIVITY_KEEP_DAYS), ...array_keys(ACTIVITY_IP_DAYS)]) as $d) {
    if ($d === 0) { continue; }
    $st = $pdo->prepare('SELECT COUNT(*) AS n, COALESCE(SUM(ip IS NOT NULL OR user_agent IS NOT NULL), 0) AS ip FROM activity WHERE created_at < NOW() - INTERVAL :d DAY');
    $st->execute(['d' => $d]);
    $olderThan[$d] = array_map('intval', $st->fetch());
}
$lastAct = $pdo->query("SELECT s.updated_at, u.name FROM app_settings s LEFT JOIN users u ON u.id = s.updated_by WHERE s.name = 'activity.keep_days'")->fetch();
$last = $pdo->query("SELECT s.updated_at, u.name FROM app_settings s LEFT JOIN users u ON u.id = s.updated_by WHERE s.name = 'app.timezone'")->fetch();

$page_title = 'Site settings';
require __DIR__ . '/../../src/includes/header.php';
?>
<div class="page">
  <header class="page-header">
    <div>
      <h1 class="page-title">Site settings</h1>
      <p class="page-desc">Settings for everyone who uses the site. Email switches are under <a href="<?= e(url_admin_emails() . '?view=settings') ?>">Emails › Settings</a>.</p>
    </div>
  </header>

  <form method="post" action="<?= e($self) ?>" x-data="tzPicker" data-current="<?= e($current) ?>" class="max-w-3xl">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="action" value="save">

    <section class="card" aria-labelledby="tz-h">
      <h2 class="card-title" id="tz-h">Time zone</h2>
      <p class="card-desc mb-5">All times in the app are shown in this zone, for everyone: activity, emails, invitations, profiles and the charts. “Today” starts at its midnight.</p>

      <div class="panel-tint mb-5 flex flex-wrap items-baseline justify-between gap-2">
        <span><span class="text-sm text-muted-foreground-2">Now using</span> <strong><?= e(app_timezone_label()) ?></strong></span>
        <span class="text-sm text-muted-foreground-2">It is <?= e(date('D, M j, Y · H:i')) ?> there</span>
      </div>

      <div class="field">
        <label class="label" for="tz">Show times in</label>
        <select class="select" id="tz" name="timezone" x-model="tz"<?= isset($errors['timezone']) ? ' aria-invalid="true" aria-describedby="tz-err"' : '' ?>>
          <?php foreach ($groups as $region => $zones): ?>
            <optgroup label="<?= e($region) ?>">
              <?php foreach ($zones as $z => $label): ?>
                <option value="<?= e($z) ?>"<?= $z === $current ? ' selected' : '' ?>><?= e($label) ?></option>
              <?php endforeach; ?>
            </optgroup>
          <?php endforeach; ?>
        </select>
        <?php if (isset($errors['timezone'])): ?><p class="field-error" id="tz-err" role="alert"><?= e($errors['timezone']) ?></p><?php endif; ?>
        <div class="mt-2 flex flex-wrap items-center gap-2">
          <button type="button" class="btn btn-secondary btn-sm" @click="useBrowser()" x-show="browser" x-cloak>
            <?= pf_icon('swap') ?> <span x-text="'Use my computer’s zone (' + browser + ')'"></span></button>
          <?php foreach (['UTC', 'Asia/Tokyo', 'Asia/Dhaka'] as $quick): ?>
            <button type="button" class="btn btn-ghost btn-sm" @click="tz = '<?= e($quick) ?>'"><?= e($quick) ?></button>
          <?php endforeach; ?>
        </div>
      </div>

      <div class="rounded-lg border border-layer-line p-4" aria-live="polite">
        <p class="text-sm text-muted-foreground-2">With <strong class="text-foreground" x-text="tz"></strong>, times will read like this:</p>
        <dl class="facts is-single mt-2">
          <div><dt>Right now</dt><dd x-text="format(new Date())"></dd></div>
          <div><dt>Difference from UTC</dt><dd x-text="offset()"></dd></div>
          <div><dt>Your computer’s time</dt><dd x-text="format(new Date(), browser)"></dd></div>
        </dl>
        <p class="hint mt-3" x-show="tz !== current" x-cloak>Not saved yet. Stored times do not change: only how they are shown, and which day an event counts toward in the charts.</p>
      </div>

      <div class="form-actions">
        <p class="form-note" x-show="tz !== current" x-cloak>You have unsaved changes.</p>
        <button class="btn btn-primary" type="submit" :disabled="tz === current">Save time zone</button>
      </div>
      <?php if ($last): ?>
        <p class="mt-2 text-right text-xs text-muted-foreground-1">Last changed <?= e(date('M j, Y · H:i', strtotime((string)$last['updated_at']))) ?><?= $last['name'] ? ' by ' . e($last['name']) : '' ?></p>
      <?php endif; ?>
    </section>
  </form>

  <form method="post" action="<?= e($self) ?>" id="activity" class="mt-8 max-w-3xl scroll-mt-24"
        x-data="{ keep: '<?= $ret['keep'] ?>', ip: '<?= $ret['ip'] ?>', older: <?= e(json_encode($olderThan)) ?>,
                  get gone() { return this.keep === '0' ? 0 : (this.older[this.keep]?.n || 0) },
                  get changed() { return this.keep !== '<?= $ret['keep'] ?>' || this.ip !== '<?= $ret['ip'] ?>' } }"
        x-confirm data-confirm-title="Save and clean up now?" :data-confirm-body="gone ? gone + ' events older than the period are deleted now. This cannot be undone.' : 'Nothing is old enough to delete yet; older events will be deleted automatically from now on.'"
        data-confirm-ok="Save">
    <input type="hidden" name="csrf_token" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="action" value="save_activity">

    <section class="card" aria-labelledby="act-h">
      <h2 class="card-title" id="act-h">Activity log</h2>
      <p class="card-desc mb-5">Old activity is deleted automatically, checked every few hours. Keep it short to store less personal data; keep it long for a longer audit trail.</p>

      <div class="panel-tint mb-5 flex flex-wrap items-baseline justify-between gap-2 text-sm">
        <span><strong><?= number_format((int)$actStats['n']) ?></strong> events stored<?= $actStats['oldest'] ? ', the oldest from ' . e(date('M j, Y', strtotime((string)$actStats['oldest']))) : '' ?></span>
        <span class="text-muted-foreground-2"><?= number_format((int)$actStats['with_ip']) ?> still have an IP address and browser</span>
      </div>

      <div class="form-grid">
        <div class="field">
          <label class="label" for="keep-days">Keep activity for</label>
          <select class="select" id="keep-days" name="keep_days" x-model="keep">
            <?php foreach (ACTIVITY_KEEP_DAYS as $d => $label): ?>
              <option value="<?= $d ?>"<?= $ret['keep'] === $d ? ' selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
          <p class="hint">Older events are deleted. Deleting an account removes all of its activity anyway.</p>
        </div>
        <div class="field">
          <label class="label" for="ip-days">Keep IP address and browser for</label>
          <select class="select" id="ip-days" name="ip_days" x-model="ip">
            <?php foreach (ACTIVITY_IP_DAYS as $d => $label): ?>
              <option value="<?= $d ?>"<?= $ret['ip'] === $d ? ' selected' : '' ?> :disabled="keep !== '0' && <?= $d ?> > +keep"><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
          <p class="hint">After this, the event stays but where it came from is removed. Never longer than the activity itself.</p>
        </div>
      </div>

      <div aria-live="polite">
        <p class="setting-off" x-show="gone > 0" x-cloak><?= pf_icon('alert') ?> <span>Saving deletes <strong x-text="gone.toLocaleString()"></strong> events that are older than this, straight away.</span></p>
        <p class="hint" x-show="gone === 0 && keep !== '0'" x-cloak>Nothing is that old yet: events start being deleted once they pass this age.</p>
      </div>

      <div class="form-actions">
        <p class="form-note" x-show="changed" x-cloak>You have unsaved changes.</p>
        <button class="btn btn-primary" type="submit" :disabled="!changed">Save activity settings</button>
      </div>
      <?php if ($lastAct): ?>
        <p class="mt-2 text-right text-xs text-muted-foreground-1">Last changed <?= e(date('M j, Y · H:i', strtotime((string)$lastAct['updated_at']))) ?><?= $lastAct['name'] ? ' by ' . e($lastAct['name']) : '' ?></p>
      <?php endif; ?>
    </section>
  </form>
</div>
<?php require __DIR__ . '/../../src/includes/footer.php'; ?>
