<?php
/**
 * Landing page (public).
 *
 * Route:  GET /
 * Auth:   none (logged-in users are redirected to the dashboard)
 * Renders: what the system is, a sample profile built from the real
 *          components, who sees what per role, and how accounts are made.
 *          There is no sign-up link: accounts come from invitation links.
 */
declare(strict_types=1);
require_once __DIR__ . '/../src/includes/auth.php';
require_once __DIR__ . '/../src/includes/url.php';
require_once __DIR__ . '/../src/includes/ui.php';

if (is_logged_in()) {
    header('Location: ' . url_dashboard());
    exit;
}

$page_title = 'Welcome';
$page_id    = 'home';
require __DIR__ . '/../src/includes/header.php';
?>
<div class="page-wide">

  <section class="grid items-start gap-x-16 gap-y-12 py-6 lg:grid-cols-[minmax(0,1fr)_26rem] lg:py-14" aria-labelledby="home-title">
    <div class="max-w-xl">
      <h1 id="home-title" class="font-serif text-[2.5rem] leading-[1.08] font-bold tracking-[-0.02em] sm:text-[3.25rem]">
        The lab’s member records, kept by the members themselves.
      </h1>
      <p class="mt-6 text-lg leading-relaxed text-muted-foreground-2">
        Every member keeps their own row up to date: course, research title, next step, theses and papers.
        Teachers read all of it as one sheet, so nobody has to chase a spreadsheet at the start of term.
      </p>
      <div class="mt-8 flex flex-wrap items-center gap-x-5 gap-y-3">
        <a class="btn btn-primary btn-lg" href="<?= e(url_login()) ?>">Log in</a>
        <p class="text-sm text-muted-foreground-2">New here? Open the invitation link you were sent.</p>
      </div>
    </div>

    <!-- A sample profile, built from the same components as the real page. -->
    <figure class="card" aria-label="Sample profile">
      <div class="flex items-center gap-4">
        <?= ui_avatar(null, 'Haruka Sato', 'avatar-lg') ?>
        <div class="min-w-0">
          <p class="font-serif text-xl leading-tight font-semibold">Haruka Sato</p>
          <p class="mt-1 flex flex-wrap items-center gap-x-2.5 gap-y-1 text-sm text-muted-foreground-2">
            <span>haruka@univ.example</span> <span class="badge">D2</span> <span>ID 24D2007</span>
          </p>
        </div>
      </div>
      <dl class="facts is-single is-stacked mt-4">
        <div><dt>Full name (Japanese)</dt><dd>佐藤 遥</dd></div>
        <div><dt>Current research title</dt><dd>Sparse attention for low-resource speech recognition</dd></div>
        <div><dt>Enrolled</dt><dd>April 2024</dd></div>
        <div><dt>Next step (進路)</dt><dd>Postdoctoral position, from April 2027</dd></div>
        <div><dt>Journal papers</dt><dd>{2}(1)</dd></div>
      </dl>
      <figcaption class="mt-4 text-sm text-muted-foreground-1">A sample profile. Yours is visible only to signed-in teachers and admins.</figcaption>
    </figure>
  </section>

  <section class="border-t border-layer-line py-12" aria-labelledby="home-roles">
    <h2 id="home-roles" class="font-serif text-2xl font-bold">Who sees what</h2>
    <div class="mt-8 grid gap-x-12 gap-y-10 md:grid-cols-3">
      <div>
        <h3><?= ui_role('student') ?></h3>
        <p class="mt-2 text-muted-foreground-2">Fills in their own lab details in place. Each field saves on its own, and a meter shows what is still missing.</p>
      </div>
      <div>
        <h3><?= ui_role('teacher') ?></h3>
        <p class="mt-2 text-muted-foreground-2">Reads every student’s details in one searchable sheet, laid out like the lab’s workbook, and invites new students by link.</p>
      </div>
      <div>
        <h3><?= ui_role('admin') ?></h3>
        <p class="mt-2 text-muted-foreground-2">Manages accounts and roles, corrects any lab detail, and can deactivate or restore anyone.</p>
      </div>
    </div>
  </section>

  <section class="grid gap-x-16 gap-y-8 border-t border-layer-line py-12 lg:grid-cols-[minmax(0,20rem)_minmax(0,1fr)]" aria-labelledby="home-accounts">
    <div>
      <h2 id="home-accounts" class="font-serif text-2xl font-bold">How accounts work</h2>
      <p class="mt-3 text-muted-foreground-2">There is no open sign-up. Profiles are private to the lab, so every account starts from an invitation.</p>
    </div>
    <ol class="steps max-w-xl">
      <li>
        <strong>A teacher or admin creates an invitation link</strong>
        <span>The link decides the role, can be limited to one email address, and expires after a set number of days.</span>
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

</div>

<?php require __DIR__ . '/../src/includes/footer.php'; ?>
