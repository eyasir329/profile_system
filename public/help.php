<?php
/**
 * Help: answers to the questions people actually get stuck on, grouped by
 * task. Each answer is a <details> so the page scans as a list of
 * questions.
 *
 * Route:  GET /help.php
 * Auth:   none (linked from the footer and from every error page)
 *
 * Keep the answers true to the code (src/includes/auth.php for backup
 * codes, email_change.php for confirming a new address).
 */
declare(strict_types=1);
require_once __DIR__ . '/../src/includes/auth.php';
require_once __DIR__ . '/../src/includes/url.php';
require_once __DIR__ . '/../src/includes/ui.php';

// question => answer (HTML; built here so links use the url helpers)
$GROUPS = [
    'Getting started' => [
        'How do I get an account?' =>
            '<p>Accounts are by invitation only. Ask a teacher (if you are a student) or an admin of your lab for an invitation link, open it, and register. The link may only work for one email address and expires after a few days, so use it soon.</p>',
        'My invitation link does not work' =>
            '<p>It has probably expired, been used up or been cancelled. Ask whoever sent it for a new one.</p>',
        'I have several roles. How do I switch?' =>
            '<p>Open the role menu at the top right (it shows the role you are acting as, for example <strong>Teacher</strong>) and pick another. What you see and can change follows the role you are acting as.</p>',
    ],
    'Signing in' => [
        'I forgot my password' =>
            '<p>On the <a href="' . e(url_login()) . '">Log in</a> page, choose <strong>Forgot password?</strong>. If no reset link reaches you, ask an admin: they can set a new password for you from <strong>Users → Edit</strong>.</p>',
        'I lost the phone with my authenticator app' =>
            '<p>On the code screen, enter one of the <strong>backup codes</strong> you saved when you turned on two-factor (each works once). Then make a new set in <strong>Settings → Security</strong>. No codes left? Ask an admin: they can reset two-factor for you from <strong>Users → Edit</strong>, after checking it is really you.</p>',
        'It says “Too many failed attempts” or “Too many wrong codes”' =>
            '<p>After several wrong passwords, or five wrong two-factor codes, sign-in is paused for a few minutes (ten for codes) to stop guessing. Wait, then try again carefully, or reset your password.</p>',
        'I was signed out with “Signed out”' =>
            '<p>Your password was changed or reset, or an admin reset your two-factor sign-in, so every other session of your account was ended. Log in again. If you didn’t change anything, reset your password and tell an admin.</p>',
        'How do I change my sign-in email?' =>
            '<p>In <strong>Settings → Account</strong> (or University email on My profile), enter the new address and save. We email a link to the new address; your email changes only when you open it, within 24 hours. Your old address gets a notice when it is done.</p>',
    ],
    'Your profile (students)' => [
        'How do I fill in my lab details?' =>
            '<p>Open <strong>My profile</strong>. Each field has an <strong>Add</strong> or <strong>Edit</strong> button; change it and save. The list on the left jumps to each section and shows how much of it is filled in.</p>',
        'I finished my course. What now?' =>
            '<p>In <strong>My profile → Courses</strong>, choose <strong>Mark as completed</strong>. Then add your next course (for example M1 after B4); it becomes your current course. Teachers see the completed one under <strong>Completed</strong>, with your details as they were when you finished.</p>',
        'Who can see my publications and awards?' =>
            '<p>Everyone in the lab, on the <strong>Resources</strong> page. Your other lab details are seen by teachers and admins only. See <a href="' . e(url_privacy()) . '">Privacy</a> for the full list.</p>',
    ],
    'Teachers' => [
        'How do I get the sheet into Excel?' =>
            '<p>On <strong>Students</strong>, choose <strong>Download CSV</strong>. It downloads what is on screen (the tab, course and search you picked) with the workbook’s column headings, and opens in Excel with Japanese names intact.</p>',
        'How do I invite students?' =>
            '<p>Open <strong>Invitations</strong>, set who the link is for and how long it works, choose <strong>Create link</strong>, and send the link. The day you create it becomes the student’s enrolment date.</p>',
    ],
    'Admins' => [
        'Where are the Students sheet and Resources?' =>
            '<p>They are teachers’ work, so they are not shown while you act as admin. If you also hold the teacher role, switch to it from the role menu.</p>',
        'How do I change someone’s roles or deactivate them?' =>
            '<p>Open <strong>Users</strong>, find the person and choose <strong>Edit</strong>. Roles, password, lab details and the account status are all there. A deactivated account can be restored for 30 days.</p>',
    ],
    'Something is not working' => [
        'It says “Page expired”' =>
            '<p>The form was open for a long time, or you signed in somewhere else meanwhile. Go back, reload the page and enter your change again. Nothing was saved.</p>',
        'I want to delete my account' =>
            '<p>Open <strong>Settings → Danger zone</strong>. Your account is deactivated straight away and can be restored by an admin for 30 days.</p>',
        'My question is not here' =>
            '<p>Ask a teacher or an admin of your lab. They can see your account and fix most things directly.</p>',
    ],
];

$page_title = 'Help';
$page_id    = 'help';
require __DIR__ . '/../src/includes/header.php';
?>
<div class="page">
  <header class="page-header">
    <div>
      <h1 class="page-title">Help</h1>
      <p class="page-desc">Answers to the questions people get stuck on. Open a question to read the answer.</p>
    </div>
  </header>

  <div class="max-w-3xl">
    <?php $i = 0; foreach ($GROUPS as $group => $items): $i++; ?>
      <section class="section" aria-labelledby="help-<?= $i ?>">
        <header class="section-head"><h2 class="section-title" id="help-<?= $i ?>"><?= e($group) ?></h2></header>
        <div class="faq">
          <?php foreach ($items as $q => $a): ?>
            <details>
              <summary><?= e($q) ?><?= pf_icon('caret', 'faq-caret') ?></summary>
              <div class="doc"><?= $a ?></div>
            </details>
          <?php endforeach; ?>
        </div>
      </section>
    <?php endforeach; ?>
  </div>
</div>
<?php require __DIR__ . '/../src/includes/footer.php'; ?>
