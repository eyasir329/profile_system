<?php
/**
 * Privacy: what the system stores, who can see it, cookies and how long
 * data is kept. Plain description of what the code does; keep it in step
 * with docs/PERMISSIONS.md when access rules change.
 *
 * Route:  GET /privacy.php
 * Auth:   none (linked from the footer and from About)
 */
declare(strict_types=1);
require_once __DIR__ . '/../src/includes/auth.php';
require_once __DIR__ . '/../src/includes/url.php';
require_once __DIR__ . '/../src/includes/ui.php';

$page_title = 'Privacy';
$page_id    = 'privacy';
require __DIR__ . '/../src/includes/header.php';
?>
<div class="page">
  <header class="page-header">
    <div>
      <h1 class="page-title">Privacy</h1>
      <p class="page-desc">What Profile System keeps about you, who can see it, and how long it is kept. It is run by the lab for the lab’s own records and is not shared with anyone outside it.</p>
    </div>
  </header>

  <div class="max-w-3xl">
    <section class="section" aria-labelledby="pv-what">
      <header class="section-head"><h2 class="section-title" id="pv-what">What is stored</h2></header>
      <dl class="facts is-single is-stacked">
        <div><dt>Your account</dt><dd>Name, email address (also your sign-in name), your roles, and your password, stored only as a one-way hash that cannot be turned back into the password. If you turn on two-factor sign-in, its secret key.</dd></div>
        <div><dt>Lab details (students)</dt><dd>Course and course history, student ID, names in English and Japanese, university email, research title, enrolment year and month, previous university, next step, theses, papers, publications and awards.</dd></div>
        <div><dt>Activity</dt><dd>A short log of what happened on your account, such as signing in, switching role and changing your profile, with the time. It is shown to you on your dashboard.</dd></div>
        <div><dt>Sign-in protection</dt><dd>Failed sign-in attempts are recorded with the email address that was typed and the network (IP) address, so repeated password guessing can be blocked. These records are deleted after 30 days.</dd></div>
        <div><dt>Emails</dt><dd>Emails go to your account’s address only when something needs you: an invitation, a password-reset link, a restore link after deactivating, and security notices (password or sign-in email changed, two-factor turned off). They pass through the mail service the lab has set up. There are no newsletters.</dd></div>
        <div><dt>Server logs</dt><dd>Like any web server, it records each request’s address, time and IP address, for troubleshooting.</dd></div>
      </dl>
    </section>

    <section class="section" aria-labelledby="pv-who">
      <header class="section-head"><h2 class="section-title" id="pv-who">Who can see it</h2></header>
      <dl class="facts is-single is-stacked">
        <div><dt>You</dt><dd>Everything about your own account and profile.</dd></div>
        <div><dt><?= ui_role('teacher') ?></dt><dd>Students’ names, email addresses and lab details, on the Students sheet and each student’s page. Teachers do not see deactivated accounts.</dd></div>
        <div><dt><?= ui_role('admin') ?></dt><dd>Every account: name, email, roles, status and lab details, so they can correct records and manage access.</dd></div>
        <div><dt>Everyone in the lab</dt><dd>Publications and awards, on the Resources page, and the shared lists there (templates, open data, potential journals), including the name of whoever added or last changed an item.</dd></div>
        <div><dt>Nobody else</dt><dd>There are no public pages, adverts or tracking, and nothing is loaded from other websites.</dd></div>
      </dl>
    </section>

    <section class="section" aria-labelledby="pv-cookies">
      <header class="section-head"><h2 class="section-title" id="pv-cookies">Cookies</h2></header>
      <dl class="facts is-single is-stacked">
        <div><dt>Session</dt><dd>Keeps you signed in. It is deleted when you log out or close the browser, and cannot be read by scripts.</dd></div>
        <div><dt>Theme</dt><dd>Remembers light, dark or “match my system” from Settings → Appearance.</dd></div>
      </dl>
      <p class="mt-3 text-sm text-muted-foreground-2">No other cookies are set, and none come from other websites.</p>
    </section>

    <section class="section" aria-labelledby="pv-keep">
      <header class="section-head"><h2 class="section-title" id="pv-keep">How long it is kept, and your choices</h2></header>
      <div class="doc">
        <ul>
          <li>Your data is kept while your account is active. You can change your profile and account details yourself at any time.</li>
          <li>You can deactivate your account in Settings → Danger zone. It stops working straight away and is kept for 30 days so it can be restored (we email you a link); after that it is deleted permanently, with its profile and activity log. An admin can also delete an account permanently at any time.</li>
          <li>Course details frozen when you mark a course completed are kept as the lab’s record of that course.</li>
          <li>To see, correct or remove something you cannot change yourself, ask an admin of your lab.</li>
        </ul>
      </div>
    </section>
  </div>
</div>
<?php require __DIR__ . '/../src/includes/footer.php'; ?>
