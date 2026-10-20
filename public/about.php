<?php
/**
 * About: what Profile System is, who uses it and how accounts are made.
 *
 * Route:  GET /about.php
 * Auth:   none (linked from the footer on every page)
 */
declare(strict_types=1);
require_once __DIR__ . '/../src/includes/auth.php';
require_once __DIR__ . '/../src/includes/url.php';
require_once __DIR__ . '/../src/includes/ui.php';

$page_title = 'About';
$page_id    = 'about';
require __DIR__ . '/../src/includes/header.php';
?>
<div class="page">
  <header class="page-header">
    <div>
      <h1 class="page-title">About Profile System</h1>
      <p class="page-desc">A private member register for the lab. Members keep their own records up to date, so nobody has to chase a spreadsheet at the start of term.</p>
    </div>
  </header>

  <div class="max-w-3xl">
    <section class="section" aria-labelledby="about-what">
      <header class="section-head"><h2 class="section-title" id="about-what">What it is</h2></header>
      <div class="doc">
        <p>Profile System replaces the lab’s <em>Information of Lab Students</em> workbooks. Each student keeps their own row: course, student ID, names, research title, next step, theses, papers, publications and awards. Teachers read everyone’s rows as one sheet, laid out like the workbook, and can download it for Excel.</p>
        <p>It is private. Nothing is public: every page except the home page, this one, Help, Privacy and the sign-in pages needs an account.</p>
      </div>
    </section>

    <section class="section" aria-labelledby="about-roles">
      <header class="section-head"><h2 class="section-title" id="about-roles">Who uses it</h2></header>
      <dl class="facts is-single is-stacked">
        <div><dt><?= ui_role('student') ?></dt><dd>Keeps their own lab details, publications and courses up to date, and reads the lab’s shared resources.</dd></div>
        <div><dt><?= ui_role('teacher') ?></dt><dd>Reads every student’s details as the Students sheet, keeps the lab’s templates and open data, and invites students.</dd></div>
        <div><dt><?= ui_role('admin') ?></dt><dd>Manages accounts: creates and edits users, assigns roles, deactivates and restores accounts, and invites people with any role.</dd></div>
      </dl>
      <p class="mt-3 text-sm text-muted-foreground-2">One person can hold several roles and switch between them from the menu at the top right.</p>
    </section>

    <section class="section" aria-labelledby="about-accounts">
      <header class="section-head"><h2 class="section-title" id="about-accounts">How accounts are made</h2></header>
      <div class="doc">
        <p>There is no open sign-up. A teacher (for students) or an admin (for any role) creates an invitation link and sends it to you. Opening it lets you register; a link can be limited to one email address, a number of uses and a number of days.</p>
        <p>Your email address is also your sign-in name, and each address can have only one account.</p>
      </div>
    </section>

    <section class="section" aria-labelledby="about-data">
      <header class="section-head"><h2 class="section-title" id="about-data">Your data</h2></header>
      <div class="doc">
        <p>The system runs on the lab’s own server. It has no adverts and no tracking, and it loads nothing from other websites: fonts and scripts are served from the same server. <a href="<?= e(url_privacy()) ?>">Privacy</a> lists exactly what is stored and who can see it.</p>
      </div>
    </section>
  </div>
</div>
<?php require __DIR__ . '/../src/includes/footer.php'; ?>
