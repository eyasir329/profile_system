<?php
/**
 * Landing page (public).
 *
 * Route:  GET /
 * Auth:   none (logged-in users are redirected to the dashboard)
 * Renders: what the system is, a sample profile built from the real entry
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
      <h1 id="home-title" class="font-serif text-[2.5rem] leading-[1.08] font-semibold tracking-[-0.02em] sm:text-[3.25rem]">
        The lab’s member records, kept by the members themselves.
      </h1>
      <p class="mt-6 text-lg leading-relaxed text-muted-foreground-2">
        Everyone keeps one profile up to date: education, publications, skills and the lab details.
        Teachers read it as a directory, so nobody has to chase a spreadsheet at the start of term.
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
            <span>@haruka</span> <span class="badge">PhD</span> <span class="badge">D2</span>
          </p>
        </div>
      </div>
      <div class="mt-5">
        <p class="border-b border-layer-line pb-2 text-sm font-semibold">Education</p>
        <div class="entry">
          <div class="entry-body">
            <p class="entry-title">Kyoto Institute of Technology</p>
            <p class="entry-sub">M.Eng., Information Science</p>
            <p class="entry-meta">2021 – 2023</p>
          </div>
        </div>
        <p class="mt-4 border-b border-layer-line pb-2 text-sm font-semibold">Journal articles</p>
        <div class="entry">
          <div class="entry-body">
            <p class="entry-title">Sparse attention for low-resource speech recognition</p>
            <p class="entry-sub">H. Sato, K. Mori, T. Ito</p>
            <p class="entry-meta">Speech Communication · 2025 · Vol. 158</p>
          </div>
        </div>
        <p class="mt-4 border-b border-layer-line pb-2 text-sm font-semibold">Skills</p>
        <div class="entry-list" data-layout="tags">
          <div class="entry"><div class="entry-body"><span class="tag-name">Python</span><span class="tag-level">Advanced</span></div></div>
          <div class="entry"><div class="entry-body"><span class="tag-name">PyTorch</span><span class="tag-level">Advanced</span></div></div>
          <div class="entry"><div class="entry-body"><span class="tag-name">LaTeX</span></div></div>
        </div>
      </div>
      <figcaption class="mt-1 text-[0.8125rem] text-muted-foreground-1">A sample profile. Yours is visible only to signed-in teachers and admins.</figcaption>
    </figure>
  </section>

  <section class="border-t border-layer-line py-12" aria-labelledby="home-roles">
    <h2 id="home-roles" class="font-serif text-2xl font-semibold">Who sees what</h2>
    <div class="mt-8 grid gap-x-12 gap-y-10 md:grid-cols-3">
      <div>
        <h3><?= ui_role('student') ?></h3>
        <p class="mt-2 text-muted-foreground-2">Edits their own profile in place. Each field saves on its own, and a checklist shows what is still missing.</p>
      </div>
      <div>
        <h3><?= ui_role('teacher') ?></h3>
        <p class="mt-2 text-muted-foreground-2">Reads every student’s profile in a searchable directory, or as one wide sheet, and invites new students by link.</p>
      </div>
      <div>
        <h3><?= ui_role('admin') ?></h3>
        <p class="mt-2 text-muted-foreground-2">Manages accounts and roles, maintains the lab details, and can deactivate or restore anyone.</p>
      </div>
    </div>
  </section>

  <section class="grid gap-x-16 gap-y-8 border-t border-layer-line py-12 lg:grid-cols-[minmax(0,20rem)_minmax(0,1fr)]" aria-labelledby="home-accounts">
    <div>
      <h2 id="home-accounts" class="font-serif text-2xl font-semibold">How accounts work</h2>
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
        <strong>You fill in your profile</strong>
        <span>Which sections you get follows your profile type: Bachelor, M.Sc. or PhD.</span>
      </li>
    </ol>
  </section>

</div>

<?php require __DIR__ . '/../src/includes/footer.php'; ?>
