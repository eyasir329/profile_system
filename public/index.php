<?php
/**
 * Landing page (public).
 *
 * Route:  GET /
 * Auth:   none (logged-in users are redirected to the dashboard)
 * Renders: what the system is, a sample profile built from the real
 *          components, what it does, who sees what per role, how accounts
 *          are made, and where to get help. There is no sign-up link:
 *          accounts come from invitation links.
 *
 * Public, so it shows nothing real: no member names or counts, no lab
 * name, no addresses. The sample profile is invented.
 */
declare(strict_types=1);
require_once __DIR__ . '/../src/includes/auth.php';
require_once __DIR__ . '/../src/includes/url.php';
require_once __DIR__ . '/../src/includes/ui.php';

if (is_logged_in()) {
    header('Location: ' . url_dashboard());
    exit;
}

// After deactivating (settings.php): say what happens next.
$flash = [];
if (isset($_GET['deactivated'])) {
    $flash[] = ['type' => 'success', 'title' => 'Your account is deactivated', 'duration' => 0,
        'body' => $_GET['deactivated'] === '1'
            ? 'We’ve emailed you a link to restore it within 30 days.'
            : 'To restore it within the next 30 days, ask an admin of your lab.'];
}

// What the system does: [icon, title, text].
$FEATURES = [
    ['edit', 'Fill it in where you read it', 'Every field of your profile is edited in place and saves on its own. A meter shows what is still missing.'],
    ['users', 'One sheet for teachers', 'All current students in one searchable sheet, laid out like the lab’s workbook. Filter by course, or download it for Excel.'],
    ['book', 'Papers and awards', 'Journal papers, proceedings, presentations and awards in one place. Paste a BibTeX entry and the citation is written for you.'],
    ['folder', 'Shared resources', 'Writing templates, useful data links and a shared list of journals to submit to, kept by the lab for the lab.'],
    ['cap', 'Your course history', 'Mark a course as completed when you move on: the record stays, and the next course starts fresh.'],
    ['shield', 'Private by design', 'Invitation-only accounts, optional two-factor sign-in, and no adverts, tracking or third-party scripts.'],
];

// Who sees what: [role, summary, what they can do].
$ROLES = [
    ['student', 'Keeps their own record', ['Edits their own lab details', 'Adds theses, papers and awards', 'Reads the lab’s shared resources']],
    ['teacher', 'Reads the whole lab', ['Sees every student in one sheet', 'Invites new students by link', 'Keeps the shared resources up to date']],
    ['admin', 'Looks after accounts', ['Manages accounts and roles', 'Corrects any lab detail', 'Deactivates or restores anyone']],
];

$page_title = 'Welcome';
$page_id    = 'home';
require __DIR__ . '/../src/includes/header.php';
?>
<div class="page-wide landing">

  <!-- Hero: what it is, and the one action. -->
  <section class="landing-hero" aria-labelledby="home-title">
    <div class="landing-hero-text">
      <p class="landing-eyebrow"><?= pf_icon('lock') ?> Private to the lab · by invitation</p>
      <h1 id="home-title" class="landing-title">The lab’s member records, kept up to date by the members themselves.</h1>
      <p class="landing-lead">
        Each member keeps their own profile current: course, research title, next step, theses and papers.
        Teachers read everything as one sheet, so nobody has to chase a spreadsheet at the start of term.
      </p>
      <div class="landing-actions">
        <a class="btn btn-primary btn-lg" href="<?= e(url_login()) ?>"><?= pf_icon('login') ?> Log in</a>
        <a class="btn btn-secondary btn-lg" href="#home-how">How to get an account</a>
      </div>
      <p class="landing-note">New here? Open the invitation link you were sent. <a href="<?= e(url_forgot()) ?>">Forgot your password?</a></p>
    </div>

    <!-- A sample profile, built from the same components as the real page. -->
    <figure class="landing-sample" aria-label="Sample profile">
      <div class="card">
        <div class="flex items-center gap-4">
          <?= ui_avatar(null, 'Haruka Sato', 'avatar-lg') ?>
          <div class="min-w-0">
            <p class="font-serif text-xl leading-tight font-semibold">Haruka Sato</p>
            <p class="mt-1 flex flex-wrap items-center gap-x-2.5 gap-y-1 text-sm text-muted-foreground-2">
              <span class="badge">D2</span> <span>ID 24D2007</span> <span><?= ui_role('student') ?></span>
            </p>
          </div>
        </div>
        <div class="completion mt-4 lg:w-full">
          <div class="completion-head"><span id="sample-meter">Lab details filled in</span><strong>85%</strong></div>
          <?= ui_progress(85, 'sample-meter') ?>
          <p class="completion-detail">11 of 13 done. Next: add conference papers.</p>
        </div>
        <dl class="facts is-single is-stacked mt-4">
          <div><dt>Full name (Japanese)</dt><dd>佐藤 遥</dd></div>
          <div><dt>Current research title</dt><dd>Sparse attention for low-resource speech recognition</dd></div>
          <div><dt>Enrolled</dt><dd>April 2024</dd></div>
          <div><dt>Next step (進路)</dt><dd>Postdoctoral position, from April 2027</dd></div>
        </dl>
      </div>
      <figcaption class="landing-caption">An invented example. Real profiles are visible only to signed-in teachers and admins.</figcaption>
    </figure>
  </section>

  <!-- What it does. -->
  <section class="landing-section" aria-labelledby="home-features">
    <div class="landing-section-head">
      <h2 id="home-features" class="landing-h2">What it does</h2>
      <p class="landing-sub">A records tool you open a few times a term, not another app to live in.</p>
    </div>
    <ul class="feature-grid">
      <?php foreach ($FEATURES as [$icon, $title, $text]): ?>
        <li class="feature">
          <span class="feature-icon" aria-hidden="true"><?= pf_icon($icon) ?></span>
          <h3 class="feature-title"><?= e($title) ?></h3>
          <p class="feature-text"><?= e($text) ?></p>
        </li>
      <?php endforeach; ?>
    </ul>
  </section>

  <!-- Who sees what. -->
  <section class="landing-section" aria-labelledby="home-roles">
    <div class="landing-section-head">
      <h2 id="home-roles" class="landing-h2">Who sees what</h2>
      <p class="landing-sub">Everyone signs in to the same site; your role decides what you can see and change.</p>
    </div>
    <div class="role-grid">
      <?php foreach ($ROLES as [$role, $summary, $can]): ?>
        <article class="role-card">
          <h3><?= ui_role($role) ?></h3>
          <p class="role-card-summary"><?= e($summary) ?></p>
          <ul class="role-card-list">
            <?php foreach ($can as $c): ?><li><?= pf_icon('check') ?><span><?= e($c) ?></span></li><?php endforeach; ?>
          </ul>
        </article>
      <?php endforeach; ?>
    </div>
  </section>

  <!-- How accounts work. -->
  <section class="landing-section landing-split" aria-labelledby="home-how">
    <div>
      <h2 id="home-how" class="landing-h2">How to get an account</h2>
      <p class="landing-sub">There is no open sign-up. Profiles are private to the lab, so every account starts from an invitation.</p>
    </div>
    <ol class="steps">
      <li>
        <strong>A teacher or admin sends you an invitation link</strong>
        <span>The link decides your role, can be limited to your email address, and expires after a few days.</span>
      </li>
      <li>
        <strong>You open it and choose a password</strong>
        <span>Two-factor sign-in with an authenticator app can be turned on afterwards in Settings.</span>
      </li>
      <li>
        <strong>You fill in your lab details</strong>
        <span>Students give their course and student ID when registering; the rest is filled in on the profile.</span>
      </li>
    </ol>
  </section>

  <!-- Where to go next. -->
  <section class="landing-help" aria-labelledby="home-help">
    <div class="min-w-0">
      <h2 id="home-help" class="landing-h2 text-xl">Questions first?</h2>
      <p class="landing-sub">How each page works, what is stored and who can see it.</p>
    </div>
    <div class="landing-help-links">
      <a class="btn btn-secondary" href="<?= e(url_help()) ?>"><?= pf_icon('info') ?> Help</a>
      <a class="btn btn-secondary" href="<?= e(url_privacy()) ?>"><?= pf_icon('lock') ?> Privacy</a>
      <a class="btn btn-ghost" href="<?= e(url_about()) ?>">About</a>
    </div>
  </section>

</div>

<?php require __DIR__ . '/../src/includes/footer.php'; ?>
