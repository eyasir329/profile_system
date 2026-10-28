# Changelog

All notable changes to this project are documented here. The format is loosely
based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the
project does **not** currently follow [Semantic Versioning](https://semver.org/).

The DB schema is the source of truth for compatibility. New migrations appear
in `migrations/`, applied by the web container's entrypoint in name order.

---

## [Unreleased]

### Production release: synced with the live site (profile-system.kesug.com)

The project now matches the code running in production on InfinityFree.

- **Deployment**: `docs/DEPLOYMENT.md` and `deploy/infinityfree/` (Apache
  rules, `install.sql`, `hosting_config.example.php`, and `build.sh`, which
  assembles the flat `htdocs/` layout from `public/` + `src/`; its output
  was checked identical to the production tree apart from the secrets
  file). `hosting_config.php` (credentials) is git-ignored.
- **Configuration**: settings are read through `app_env()` (environment,
  `$_ENV`, `$_SERVER`), because shared hosts disable `putenv()`. No change
  under Docker. `src/includes/hosting_config.php` loads the optional file.
- **Japanese text**: migration `037_utf8mb4_japanese.sql` and
  `SET NAMES utf8mb4 COLLATE utf8mb4_general_ci` on every connection;
  password length counts characters; `first_name()` splits on the
  full-width space.
- **Students sheet** is now also visible (read-only) to students.
  A "Lab Equipment Access" link opens in a new tab from the top bar.
- **Journal and conference papers** are two numbers (published, submitted)
  stored as `3(1)`, no longer lists of entries. Older records still display
  (`paper_display()`).
- **Security**: sign-in fails closed when the two-factor status cannot be
  read; a password-reset link is claimed atomically, so two simultaneous
  requests cannot both use it; a reset link is shown on screen only when
  `APP_ENV` is explicitly `development` or `local`; an unauthenticated
  fetch gets a redirect instead of a 401.
- **Reliability**: the database connection times out after 3 s and does
  not retry; the housekeeping marker is per install.
- **Docs**: README, INSTALL, PERMISSIONS and ARCHITECTURE updated; new
  `docs/README.md` index and root `SECURITY.md` (reporting policy).

### Repository structure

- **CI** (`.github/workflows/ci.yml`): `composer validate`, PHP lint, unit
  tests, front-end build must equal the committed assets, shell syntax and
  a dry run of the shared-hosting build, and a guard against committed
  secrets. Dependabot (npm, Composer, Docker, Actions), `CODEOWNERS`, PR
  and issue templates.
- **Tests**: `tests/run.php` and `make test` (26 checks: password length in
  characters, `first_name()`, paper counts, TOTP secrets).
- `.editorconfig`; removed the redundant `README.txt`.

### Documentation: install from nothing, architecture, decisions

- **`docs/INSTALL.md`**: running the app on a computer with nothing
  installed, for Windows (WSL 2 + Docker Desktop), macOS and Linux:
  requirements, installing Docker/Git/make, `.env`, first start and first
  admin, everyday commands, the plain `docker compose` equivalents of
  every `make` target, moving data to another computer, going live, and
  a troubleshooting table.
- **`docs/ARCHITECTURE.md`**: a tour from the repository root (every
  folder and file), the request lifecycle, the data model, the
  containers, and where to make common changes.
- **`docs/DECISIONS.md`**: the reasons behind the stack, infrastructure,
  data model, security, every feature and the interface, with the options
  considered and the costs, plus the rules that guided them and a
  timeline. Reasons not written down at the time are marked *inferred*.
- **`.gitattributes`** keeps shell scripts, SQL and config in Linux line
  endings, so a clone on Windows (`core.autocrlf=true`) no longer breaks
  the web container's entrypoint.
- Out-of-date docs fixed: CONTRIBUTING.md (no more `docs/DIRECTORY.md`,
  "no Composer", single hand-written CSS/JS file, public profiles),
  DESIGN.md (the CSP *is* sent; what a profile holds), the Makefile's
  note that Git Bash bundles `make` (it doesn't), and `make up` no longer
  prints an Adminer address for a service it didn't start.

### Responsive on every device, and a new home page

Every page was opened for each role at nine screen sizes (320 to 1920 px,
a phone in landscape, phones as touch devices) and checked for sideways
scrolling, cut-off content and small tap targets. Found and fixed:

- **Students sheet and every Resources table scrolled the whole page
  sideways on phones** (by up to 2,500 px). The "Not set" screen-reader
  labels in empty cells are absolutely positioned and escaped their
  scroll frame. Every scroller is now `position: relative`, and `.sr-only`
  gets `left: 0` as a safety net.
- **The admin top bar did not fit a 768 px tablet** (60 px off screen on
  every page). The full bar now starts at 1024 px for every role; phones
  and tablets use the drawer, which scrolls when it is taller than the
  screen.
- **My profile was 427 px wide on a 320 px phone**: section and
  publication tabs (same label bug) and the course row ("Mark as
  completed" now wraps).
- Teacher dashboard cards, Resources template buttons and the "Use my
  computer's zone" button in Site settings overflowed on small phones.
  Grid items now shrink (`min-width: 0`), button rows wrap, and long
  unbroken words wrap everywhere.
- Users, Emails and Activity tabs (`.view-tabs`) were cut off on phones;
  they now wrap onto a second row.
- Tap targets of at least 24 px: footer links, "Show as table", sortable
  column headers, small links; 20 px checkboxes on touch screens.
- **New home page**: a fluid headline that fits small phones, the sample
  profile with its completion meter, what the system does, a card per
  role, how to get an account, and links to Help and Privacy. Invented
  sample data only: no member, count, lab name or address.
- `tools/responsive-audit.js` repeats the check (accounts from the
  environment); the rules are in `docs/FRONTEND.md` ("Responsive rules").

### Security: second audit (black-box and white-box)

Full write-up in [`docs/SECURITY.md`](docs/SECURITY.md) §1.

- **Rate limits held under parallel requests.** Sign-in, two-factor and
  password-reset checks run one at a time per email / account / IP
  (`rate_limit_lock()`, a MariaDB `GET_LOCK`). Before, many requests sent
  at once all passed the "5 tries" check before any failure was recorded.
- **Signing in no longer resets the per-IP counter**, so an account holder
  can't spray other accounts from one address by logging into their own in
  between. Registration no longer clears it either.
- **Two-factor codes and backup codes are strictly single use**, also when
  two requests arrive together (atomic claim of the code's time step).
- **An email change ends open password-reset links** (they went to the old
  address), and a change that applies without confirmation now tells the
  old address.
- **Sessions end** after 2 hours without activity and 7 days after
  sign-in. The cookie is now `__Host-ps_session` over HTTPS, so another
  site under the same parent domain can't plant or overwrite it.
  **Everyone is signed out once** by this change.
- **The sign-in check fails closed**: if the database can't confirm the
  account, the request gets the 503 page instead of carrying on.
- **Containers**: the app's database user can only read and write rows
  (no `DROP`/`ALTER`); the root DB password is removed from Apache's
  environment; the code is read-only to the web server (read-only mounts,
  root-owned files in the image); `.dockerignore` keeps `backups/`, test
  scripts with credentials and spreadsheets out of the image.
- Removed the three student spreadsheets (`Information of Lab Students*.xlsx`)
  from the repository. They remain in git history: see SECURITY.md §4.

### Fixed: admin search on touch screens (iPad)

- Dragging in the search panel scrolled the page behind instead of the
  results. While the panel is open the page is now pinned (the iPad-proof
  way: position:fixed, scroll position restored on close), the result list
  keeps its scroll to itself (overscroll-behavior), and drags outside it
  do nothing.
- The panel fits the visible screen, also with the on-screen keyboard up.
  On touch screens the keyboard no longer pops up by itself; a short drag
  through the results no longer counts as a tap.

### Fixed: Google Authenticator said the setup key was invalid

- Two-factor setup keys were 20 characters (one letter per random byte, so
  100 bits: not whole bytes). Google Authenticator rejects such keys as
  "Key value is invalid". Keys are now 20 random bytes properly
  base32-encoded: the standard 32 characters. Checked against the RFC 6238
  test vectors and an independent implementation.
- A setup still waiting for its first code gets a new, standard key when
  the page is opened. Two-factor that is already on keeps working.

### Site settings: activity log clean-up (admin)

- **Admin › Site settings › Activity log**: keep activity forever (the
  default), or 30 days to 2 years; keep the IP address and browser 7 days
  to 1 year (default 90, never longer than the event). Shows how many
  events are stored and, before saving, how many the choice would delete.
- Applied at once on save (after a confirmation) and then automatically by
  housekeeping every few hours (`activity_purge()` in housekeeping.php).
  The change is logged after the clean-up, so it is never deleted by it.
- The Privacy page states the configured periods instead of a fixed
  "90 days".

### Admin side, reworked

- **One page per user** (`/admin/user.php?id=`), laid out like My profile:
  identity panel with a summary (last active, last sign-in, sign-ins and
  failures in 30 days, two-factor, joined), an "On this page" menu, and
  sections for Account, Roles, Lab details (same labels as the profile),
  Sign-in & security, Recent activity, Emails and Remove access. A save bar
  stays in view while editing. The old read-only view (`/admin/?id=`)
  redirects here.
- New on that page: **email a password reset link** (the admin never sees
  a password) and **sign out everywhere**. Both are logged.
- **Users list** is its own page: Active / Deactivated tabs and role chips
  with counts, two-factor filter, sort by name / newest / recently active /
  longest inactive, last-active and two-factor columns, **bulk email,
  deactivate and restore** (same safety rules as one at a time), CSV
  download.
- **Dashboard (admin)**: health numbers, **Needs attention** (failed
  sign-ins and emails, admins without two-factor, email off, accounts about
  to be deleted, invitations about to expire, never signed in, time zone not
  chosen), a 14-day activity chart, quick actions and everyone's latest
  activity.
- **Command palette**: Ctrl/⌘ + K (or Search in the top bar) jumps to any
  admin page or person.
- Invitations: state chips (Active / Used / Expired / Cancelled) for admins.
- Navigation order: Dashboard · Users · Invitations · Emails · Activity ·
  Site settings.

### Site settings: time zone (admin)

- **Admin › Site settings** chooses the time zone every time in the app is
  shown in (activity, emails, invitations, profiles, charts, the times in
  security emails), for everyone. A live preview, "use my computer's
  zone", and a footer note "Times in …" for signed-in users. `APP_TIMEZONE`
  in `.env` sets the default.
- db.php applies it to PHP and to each database connection, so "today",
  the day/hour charts and expiry checks all agree.
- Migration `036`: the eleven `DATETIME` columns that hold moments
  (expiries, `used_at`, `deleted_at`, …) are now `TIMESTAMP`, so changing
  the zone never shifts an expiry. Existing values are converted as UTC;
  checked unchanged.

### Activity (admin)

A new **Activity** tab for admins (`/admin/activity.php`):

- **Timeline** of everything people do (sign-ins, failed sign-ins, profile,
  account, resources, invitations, security and admin actions), grouped by
  day, with repeats folded ("Switched role 4 times"). Filter by person,
  area, period or text (name, email, IP, detail); download as CSV
  (spreadsheet formulas neutralised; each download is itself logged).
- **People**: everyone with last active, last sign-in, sign-ins and failed
  sign-ins in 30 days, and where they were last seen from; sortable.
- **One person**: summary numbers, the addresses and devices they used,
  and their own timeline; linked from Admin › Users › Edit.
- **Charts** (also on the Emails log): activity per day (per hour for the
  last 24 hours), a weekday × hour heatmap, most active people / events by
  area; each with a tooltip and a table view. Colours checked for
  colour-blind safety in light and dark.
- **Now also tracked:** signing out; wrong two-factor codes and the
  lock-out; turning two-factor on or off; restoring an account from the
  emailed link; downloading the Students sheet.
- **Fixed:** with two-factor on, "Signed in" was logged as soon as the
  password was right, even if the code never was. It is now logged after
  the code ("Signed in … (with two-factor)"); the password step is its own
  event.
- Activity now records the **IP address, browser and acting role**
  (migration `035`); housekeeping clears IP and browser after 90 days. The
  Privacy page says so.

### Emails (admin)

A new **Emails** tab for admins (`/admin/emails.php`):

- **Email log** (migration `033`): every email the app sends or tries to
  send, with the result, the mail server's answer in plain words, timing,
  who triggered it, and the text. Links in system emails (reset,
  invitation, restore tokens) are stored as `[link hidden]`.
- **Write an email**: to picked people, everyone, or a role, plus typed
  addresses. One copy per person, `{name}` placeholder, live preview, a
  test copy to yourself, and a confirm step. At most 100 recipients per
  send and 200 emails per admin per hour.
- **Settings** (migration `034`, table `app_settings`): a master switch
  for all email, one switch per kind of email (each says what happens
  while it is off), how long to keep the log, the mail server settings
  read from `.env`, and a "send me a test email" check. A switched-off
  email is logged as "Not sent". With "Confirm new email" off, an email
  change applies at once, as when no mail server is set up.

### Security audit (October 2026)

Full report: `docs/SECURITY.md`. Fixed:

- **Per-IP limits could be dodged** behind the tunnel: the client address
  came from the visitor-written end of `X-Forwarded-For`. Now
  `CF-Connecting-IP`, else the rightmost entry; and `WEB_BIND=127.0.0.1`
  (new, in `compose.yml` / `.env.example`) stops anyone on the network
  from skipping the tunnel.
- **Other sessions now end** when the password is changed or reset or an
  admin resets two-factor (`users.session_epoch`, migration `032`), and a
  deleted account's session ends.
- **A revoked role stops working at once**: roles are re-read from the
  database on every request.
- **Adminer and Mailpit** listen on 127.0.0.1 only.
- **At most 3 password-reset emails per address per hour.**
- **Sign-in takes the same time for unknown emails** (no account
  enumeration by timing).
- **An authenticator code works once**, and the session id is renewed
  after two-factor.
- **HSTS** on HTTPS responses.
- **Failed-sign-in log rows** are deleted after 30 days, as `/privacy.php`
  says.

### Closing the gaps: backup codes, admin 2FA reset, email confirmation, deletion after 30 days, security headers

- **Backup codes work.** The two-factor step accepts a backup code
  (single use; the owner is emailed how many are left). Codes are now
  stored hashed and shown once, when made; Settings → Security shows how
  many are left and can **make new backup codes**. Older plain-text lists
  keep working until replaced.
- **The two-factor step is rate-limited:** 5 wrong codes per 10 minutes
  per account (it had no limit, so codes could be guessed).
- **Admins can reset two-factor** (Users → Edit → Sign-in) for someone
  who lost their phone and backup codes; the person is emailed.
- **Changing your own sign-in email is confirmed**: with email set up,
  the change waits for a link sent to the new address (24 hours), then
  the old address is told (`src/includes/email_change.php`,
  `verify_email.php`, which was unused before). Admin changes still apply
  directly.
- **Deactivated accounts are deleted after 30 days**, as Settings
  promised (nothing did it before), together with their activity log.
  Failed sign-in records and used one-time links are deleted after 30
  days. Runs on its own every 6 hours; `make purge` runs it now. Admin
  "Delete user" now also removes the activity log, and the first run
  removed 395 log rows left behind by 145 earlier deletions.
- **Security headers** in `docker/apache/app.conf`: Content-Security-
  Policy, Referrer-Policy (protects tokens in reset and invitation
  links), X-Content-Type-Options, X-Frame-Options, Permissions-Policy.
- **The seed script moved** from `public/docker/` to `docker/`, out of
  the web root.

### Email

- **Sending over SMTP** with PHPMailer 6.10.0 (`composer.json`, installed
  into `src/vendor` and committed, like the built assets). Settings in
  `.env` (`MAIL_*`, `APP_URL`); empty `MAIL_HOST` = email off.
  `src/includes/mailer.php` sends and renders one HTML + plain-text
  layout in the site's colours; `src/includes/mail_templates.php` holds
  the messages. A failed send is logged and never breaks the page.
- **Emails:** invitation (when the link is for one address; the page
  says "Invitation emailed to …" and still shows the link), password
  reset, and security notices after a password change (own, by reset or
  by an admin), a sign-in email change (sent to the old address), and
  two-factor being turned off.
- **Restoring a deactivated account now works:** the restore link was
  created and thrown away; it is now emailed, and the home page says so.
- **Links in emails** use `APP_URL` only in production, so a forged Host
  header cannot point a reset link at another site.
- **Local testing:** `make mail` starts Mailpit (inbox at
  http://localhost:8025); `.env.example` shows the four settings.

### About, Help, Privacy and error pages

- **New pages**, linked from the footer on every page: **About** (what
  the system is, roles, how accounts are made), **Help** (questions that
  open to show the answer, grouped by task) and **Privacy** (what is
  stored, who can see it, cookies, how long it is kept). The old footer
  "About" link pointed at `README.txt`, which is not served (it was a 404).
- **Error pages** for 400, 401, 403, 404, 405, 410, 419, 429, 500 and 503,
  in the app's own style, with the right next step (dashboard or home,
  go back, log in, try again) and a link to Help
  (`src/includes/error_page.php`). Used for unknown URLs (via
  `public/error.php`), expired forms, "no access" (replacing three
  hand-made boxes and two plain-text messages), crashes and a database
  that is down (a standalone page that needs neither).
- **An expired form now returns 403**, not 500: Apache turned the old 419
  into a 500.
- **Server settings** (`docker/apache/app.conf`, `docker/php/app.ini`,
  added to the image): `.htaccess` files were never read, so their rules
  now live in Apache's config: no directory listings, no scripts run from
  `uploads/`, and `public/docker/` (a seed script) is denied. Apache and
  PHP versions are no longer advertised. PHP errors go to the log, not
  into the page (they used to be shown to visitors and not logged).
- `robots.txt` asks search engines not to index the site.

### Admin is account management only

- **Top bar for admins:** Dashboard, Users, Invitations. Resources is no
  longer offered; `/resources.php` and `/students.php` answer 403 while
  acting as admin (an admin who also teaches switches to Teacher).
- **Users** is the account list only: roles, a new **Status** column,
  joined date, Edit. Removed for admins: the "Students sheet" view
  (`?view=sheet`, Current / Completed tabs, course chips, CSV download,
  Full screen), the course filter and the lab-details progress column.
- **One user's page** for admins keeps Account, Lab details (which admins
  can correct) and Courses; the publications and awards sections are
  teachers' only.
- **Admin dashboard:** removed the "Students sheet" row.

### Clearer separation and no duplicate actions

- **Top bar** is now a solid ink-blue band with bold labels; the current
  page is a lighter tab with an amber rule. The mobile drawer uses the
  same colours.
- **Panels stand apart from the page**: a light grey page, gray-300
  borders and a soft shadow on cards, tables and tiles; dashboard cards
  get a ruled header strip.
- **Tables** have a blue-tinted header row with a heavier rule.
- **Tools** (search, filters, Download CSV, Full screen) sit in their own
  blue-tinted strip, and are real buttons instead of text-like links.
- **Buttons and inputs** have a 3:1 border (WCAG 1.4.11) and semibold
  labels.
- **Students: Current / Completed** are now a segmented view switch
  (`.view-tabs`) above the search bar, with icons and count badges; the
  selected group is a raised white tab with a blue count. On phones the
  two tabs share the width equally.
- **Resources: the two tab groups** (Shared resources, Lab publications)
  sit in one compact panel, each label on the same line as its tabs,
  instead of two stacked blocks with a large gap between them.
- **Profile pages** (My profile and a student's page under Students):
  the header, the "On this page" list and every section (Personal,
  Study, Research, theses, papers, publications, courses) are now
  separate white panels. Each section has a grey header strip with a
  blue marker; the current section in the list is highlighted, and the
  "Lab details filled in" box takes the blue tool tint.
- **Every switch now shows its selected state the same way** (blue text,
  a blue marker, a raised or tinted background): the Publications &
  awards tabs on My profile, the Settings section list (a panel; Danger
  zone selected in red), "All users / Students sheet" (selected half
  solid blue), the option cards (theme, invite role, user roles, "act
  as"), and the tabs in the full-screen sheet bar. All invitations is a
  panel like the other sections.
- **One Add per empty list:** while a list of theses, papers or
  publications is empty, only "Add your first ..." is shown; the
  header's "Add" returns once there is an entry.
- **One entry point per destination.** Removed buttons that repeated a
  top-bar tab on the same page: "Open the Students sheet" / "Open the
  sheet" / the "Invite students" card (teacher dashboard), "Open my
  profile" and the "Lab resources" tile (student dashboard), "Manage
  users" / "All users" / "Invite by link" (admin dashboard and Users),
  "Invite students" (Students), the "Settings" card link (dashboard),
  "Account settings" (My profile), the back link on Invitations, and "My
  profile" in the account menu while acting as a student. See
  `docs/DESIGN.md`, "One entry point per destination".

### Resources, entries, completed courses and a larger type scale

- **Profile split into sections** with an "On this page" side nav:
  Personal, Study, Research, Completed theses, Journal papers, Conference
  papers, Publications & awards, Courses.
- **Entries.** Completed theses, journal papers and conference papers are
  now lists the student adds to, edits and deletes one by one (migration
  `023`). The Students sheet still shows the workbook's text, rebuilt from
  the entries (`[Level: Title]`, `{published}(submitted)`).
- **Publications and awards** from the second workbook: one tabbed
  section (Journals, Proceedings, Presentations, Awards) with cards, BibTeX
  copy, and a "Fill citation and month from BibTeX" helper (migration
  `024`).
- **Enrolled year and month are editable** by the student, from the
  workbook's choices (year 2022 to next year; month April, October,
  Other; migration `022`).
- **Resources page** (`/resources.php`), linked for every role: Templates
  and Open data and code (teachers edit, students read; migration `025`),
  Potential journals (a shared sheet: Journal name, Link, IF, CS, APC,
  Suggested by, Submitted by, Published, which students and teachers alike
  add, edit and delete; migrations `028` to `031`), and read-only tabs of
  everyone's PublishedJournals, Conf.Proceedings, Conf.PresentationOnly
  and Awards, with full screen.
- **Completed courses.** A student marks the current course completed
  (undo while it is current) and can add the next one only afterwards, one
  course at a time. The Students sheet has a Current students tab and a
  Completed tab with one fixed row per completed course, from a copy of
  the student's details saved at completion (migrations `026`, `027`).
- **Students sheet** follows the workbook: its exact header text and
  column order, rows grouped by course (D3 to R), empty cells blank, paper
  columns listing each paper, alternating row colours, and a numbered list
  with dividers wherever a cell holds several items.
- **Students sheet UX:** frozen panes (Course, Student ID and both names
  stay at the left while scrolling sideways), course filter chips with
  counts on both tabs, a line between course groups, and **Download CSV**
  of the current view with the workbook's headers (UTF-8 for Excel;
  formula-like cells are made text).
- **Resources UX:** tabs in two labelled groups ("Shared by teachers",
  "Lab publications"); every sheet has an instant filter, "Only mine" for
  students, a row count and sortable columns (journals by IF or CS);
  templates use their first line as the title; one page width for every
  tab so nothing jumps when switching.
- **Teachers and admins** get a plain "My account" page: lab details show
  only while acting as a student.
- **UI:** base text 16 px (was 15), small text 14 px (was 13), headings
  bold; Resources tabs wrap as pills so none is hidden; student dashboard
  has "Your lab" shortcut tiles with live counts; smaller phone header on
  the profile.
- **Docs:** `docs/PERMISSIONS.md` added; README, DESIGN, COMPONENTS and
  FRONTEND updated.
- **Known gap:** the app sends no email. In production a password-reset
  request is stored but never delivered; an admin sets a new password on
  the user's page instead.

### Each role sees only what it needs

Lab details are a student's profile. Teachers and admins have none, so
the app no longer shows them any.

| | Student | Teacher | Admin |
| --- | --- | --- | --- |
| Top bar | Dashboard, My profile | Dashboard, Students, Invitations | Dashboard, Users, Invitations |
| Dashboard | Lab details progress and next fields | Students: how many, who still has details to fill in | Account counts by role, deactivated, open invitations |
| Profile page | Lab details and Courses | Name, email, roles and a link to Settings | Same as teacher |

- "My profile" is in the account menu for anyone who holds the student
  role, so a teacher who is also a student reaches it without switching.
- `/profile.php` refuses every save from an account without the student
  role (403).
- **Users (admin):** the list marks non-students instead of showing an
  empty course and 0%. The sheet is the students' workbook, so it lists
  students only; the list view still shows everyone. A person's page
  shows lab details and courses only for students.
- **Admin user form:** the Lab details card appears only while the
  Student role is ticked.
- The activity list words invitation and admin actions in plain language.

### Course history and automatic enrolment dates

- **A person can be in several courses over time.** The profile has a
  *Courses* list: "Add course" starts a new one (it becomes the current
  course), earlier ones stay, and a past one can be removed. Editing the
  *Course* field corrects the current course without adding a row.
- **The enrolment date is filled in automatically**: the day the
  invitation link was generated for the first course, the day the course
  was added for later ones. Users cannot type it; an admin can correct
  the current one on the user form.
- The sheet's Course / Enrolled columns show the current course.
  Teachers and admins see the full history on a person's page once
  there is more than one course.
- **Database** (migration `021_course_enrollments.sql`, additive): new
  `course_enrollments` table; `profiles.course` and the enrolled columns
  mirror its newest row. Missing enrolment dates were backfilled from
  the invitation date (or the account's creation date).

### One email, no usernames, more fields the user can edit

- **The sign-up email is the sheet's University email.** There is one
  email per account; the separate `profiles.university_email` is gone.
  It can be changed on the profile or in Settings, must be unique, and
  changing it changes the sign-in address.
- **Course and student ID are editable by their owner** on the profile
  (course from a list; student ID checked for format and uniqueness).
  Neither can be left empty. Only the enrolment date stays admin-only.
- **Usernames are removed** from registration, Settings, the admin form,
  the directory, search and the top bar. People are identified by name,
  email and student ID.
- **Database** (migration `020_single_email_no_username.sql`,
  destructive): drops `users.username` and `profiles.university_email`.
- Fixed: saving Settings → Account failed after the profile type was
  removed (a leftover reference); caught before release.

### A profile is the lab details only

The profile now holds exactly the columns of the lab's *Information of
Lab Students* workbook. Everything else was removed from the app and
the database.

#### Removed
- The nine profile sections (education, work experience, journal
  articles, conference papers, awards, skills, certifications, research
  interests, languages), and phone, address, profession, bio and photo.
- The Bachelor / M.Sc. / PhD profile type (it only decided which
  sections were shown): gone from registration, Settings, the admin
  form and the directory filter.
- **Database** (migration `019_lab_only_profile.sql`, destructive): the
  nine section tables, `profiles.phone/address/profession/bio/photo`,
  `users.degree_type`, activity rows about the removed things, and
  spent password-reset tokens. Uploaded photo files were deleted.
- Code: `src/includes/profile_sections.php` and `render_sections.php`
  (replaced by `lab_fields.php`), the entry renderers in `ui.php`, eight
  unused validators, most of the profile editor script, and the CSS for
  entries, tags and the photo control.
- `docs/DIRECTORY.md` and the long, outdated README (rewritten).

#### Changed
- `/profile.php` is one list of lab fields with a "filled in" meter.
- Completeness everywhere (dashboard, Users list) is the share of lab
  fields filled in.
- One person's page in the directory shows the lab details (admins also
  see the account facts).
- The admin Users sheet shows the account columns followed by the
  workbook's columns.

### UI redesign: Tailwind CSS v4 + Alpine.js + Preline tokens

Every page was rebuilt on a new design system. No routes, database tables
or server-side behaviour changed.

#### Added
- **Build pipeline** in `resources/` (`package.json`, Tailwind v4 CLI,
  esbuild). `make assets` / `make assets-watch` run it in a Node
  container. Built files in `public/assets/` are committed.
- **Design tokens** (`resources/css/theme.css`) on top of Preline UI's
  `theme.css`: warm neutral scale, ink-blue accent, status and role
  colours, light and dark values.
- **Component classes** (`resources/css/components/`) and **PHP helpers**
  (`ui_avatar`, `ui_role`, `ui_roles`, `ui_alert`, `ui_pw_toggle`,
  `ui_progress`, more icons in `pf_icon`).
- **Alpine.js** stores, directives and components (`resources/js/ui.js`).
- **Self-hosted fonts**: Source Serif 4 and IBM Plex Sans.
- **Docs**: `docs/DESIGN.md`, `docs/COMPONENTS.md`, `docs/FRONTEND.md`.
- Dashboard shortcuts for teachers (Students) and admins (Invitations).

#### Changed
- **Top bar**: Settings and Log out moved into an account menu; the role
  switcher is a keyboard-operable menu; one drawer on small screens.
- **Landing page** rewritten around what the system is, with a sample
  profile built from the real components.
- **Profile**: one section list (sticky column on wide screens, scrolling
  strip on small ones) instead of two.
- **Directory**: the person's name is the link to their profile (the
  separate "View" button is gone); view switch and Full screen sit in
  the filter row.
- **Toasts** moved to the bottom centre and pause while hovered.
- **Confirm dialog** opens with focus on Cancel for destructive actions.
- The old `public/assets/css/app.css` and `js/app.js` are now build
  output. The hand-written originals are in `backups/pre-tailwind/`.

- **Registering through a student invitation now requires Course and
  Student ID.** Both are saved to the new profile; a student ID already
  used by another account is rejected.
- **Your own profile now shows everything staff can see about you**: a
  read-only *Lab details* section (the 13 lab fields admins maintain) and
  a read-only *Account* section (email and verification, roles, profile
  type, status, two-factor, joined date).
- **Lab details are editable by their owner, with server-enforced
  permissions.** Names, university email, research title, next step,
  previous university, completed thesis and paper counts save in place on
  the profile (`op=update_lab`). Course, student ID and enrolment stay
  admin-only and are refused with a 403 if posted. See
  [`docs/PERMISSIONS.md`](docs/PERMISSIONS.md).
- **Students directory (teachers) is sheet-only**: the list view and the
  List / Full sheet switch are removed there. Admins keep both on Users.
- **The teacher's sheet shows only the columns of the lab workbook**
  (*Information of Lab Students.xlsx*), in the workbook's order: Course,
  Student ID, Full name (Japanese), Full name (English), University email,
  Current research title, Enrolled year, Enrolled month, Next step (進路),
  Previous univ / level, Completed thesis titles, Journal papers,
  Conference papers. The English name links to the student's profile.

#### Fixed
- Log in: a "role no longer available" error from the role picker was
  never shown; it now appears above the form.
- Forgot password: the "reset link is on its way" notice no longer shows
  alongside a validation error.
- Profile photos on `/dashboard.php` now use a root-relative URL.

### Hardening pass + Cloudflare tunnel workflow

The app is now safe to expose to the public internet, with a one-line
`make tunnel` workflow for sharing it. See [`docs/TUNNEL.md`](docs/TUNNEL.md)
for the full guide.

#### Security
- **Strong, unique `DB_PASSWORD`** in `.env` (replaces the `rootpass`
  default that previously shipped in `.env.example`).
- **Dedicated `profile_app` MariaDB user** with least privilege. The web
  service connects as `profile_app`; only the first-boot entrypoint
  uses `root`. The app user is created on container start (see
  `docker/web-entrypoint.sh`) and is idempotent.
- **`APP_DB_USER` / `DB_APP_PASSWORD` env vars** are read by
  `includes/db.php`, with fallbacks to `DB_USER` / `DB_PASSWORD` for
  backwards compatibility.
- **Rate limiting** on `login.php` (5 fails / 15 min per email + 20
  fails / 10 min per IP), `register.php` (5 / hour per IP), and
  `forgot_password.php` (5 / hour per IP). Backed by the new
  `login_attempts` table (migration 018) and `includes/ratelimit.php`.
  `login_attempts` uses a composite index on `(bucket, created_at)` so
  the window check is a single index lookup.
- **`Secure` session cookie behind a reverse proxy.** `auth.php` now
  honors `TRUST_PROXY=1` + `X-Forwarded-Proto: https`, so cookies
  still get the `Secure` flag when traffic is terminated by
  `cloudflared` instead of going directly over HTTPS.
- **`APP_ENV=production` hides the dev reset-link banner** on
  `forgot_password.php`. The on-screen link is the single biggest
  pre-publication risk and is now opt-in.
- **Pinned base images** in `compose.yml` (`mariadb:11.4`,
  `adminer:4.8.1`) instead of floating `:latest` tags.
- **Adminer is OFF by default.** It lives on the `tools` Compose
  profile and is started with `make tools`; `make tools-down` stops
  just that service without affecting the rest of the stack.

#### Ops / developer experience
- **`make tunnel`** — start a Cloudflare quick tunnel to the local
  web app. Detects the assigned `https://*.trycloudflare.com` URL
  from the log and prints it.
- **`make tunnel-url`** — re-print the URL of the currently-running
  tunnel; refuses to print a stale URL.
- **`make tunnel-stop`** — kill the tunnel.
- **`make tunnel-restart`** — stop + start (the URL WILL change).
- **`make tools`** / **`make tools-down`** — bring Adminer up and down
  on the `tools` profile without restarting the main stack.
- **`.env.example`** documents every new env var
  (`COOKIE_SECURE`, `TRUST_PROXY`, `APP_ENV`, `APP_DB_USER`,
  `DB_APP_PASSWORD`, `ENABLE_ADMINER`, `WEB_PORT`, `ADMINER_PORT`).
- **`.gitignore`** ignores `cloudflared.pid`.

#### Documentation
- `docs/TUNNEL.md` — full public-exposure guide: prerequisites,
  step-by-step, troubleshooting, "things still on you" checklist,
  DB-password rotation runbook, how to re-enable Adminer locally.
- `README.md` — new "Public exposure" pointer, new "Hardening" section,
  Security notes updated to mention rate limiting + the `Secure`
  cookie fix, troubleshooting section extended, useful one-liners
  block now includes a `login_attempts` aggregator.
- `docs/DIRECTORY.md` — adds the `login_attempts` migration,
  `includes/ratelimit.php`, `docs/TUNNEL.md`, and the
  `cloudflared.log` / `cloudflared.pid` scratch files.
- `CONTRIBUTING.md` — "what 'done' means" now requires exercising
  the rate limiter for auth-touching PRs; new "public-exposure
  development" note on `TRUST_PROXY` and `X-Forwarded-Proto`.

### Planned
- Global activity feed for admins
- Public-profile privacy controls (per-section `is_public` flag)
- Email transport for the password-reset flow (PHPMailer + SMTP)
- Image cropping in the photo uploader
- i18n (English only today)

### Done (moved from Planned)
- **Rate limiting on login / register / reset endpoints** — see the
  [Hardening pass](#hardening-pass--cloudflare-tunnel-workflow) entry
  above. `includes/ratelimit.php` + `login_attempts` table.

---

## 2025-10-11 — Academic profile sections + full Docker rework

### Added
- **Docker Compose stack** with two services (`db` = MariaDB 11, `web` = custom
  PHP 8.3 + Apache image) and an optional `adminer` service for browsing the
  database in a browser. Healthchecks, named volumes, idempotent entrypoint,
  env-driven config. See `compose.yml`, `Dockerfile`, `docker/web-entrypoint.sh`.
- **Three profile types** — `bachelor`, `msc`, `phd` — stored in the new
  `users.degree_type` column. Selected at registration; changeable in Settings.
- **Nine academic profile sections**, each backed by its own table:
  - Education (`educations`)
  - Work experience (`work_experiences`)
  - Journal articles (`journal_articles`) — M.Sc. & PhD only
  - Conference papers (`conference_papers`) — M.Sc. & PhD only
  - Research interests (`research_interests`) — M.Sc. & PhD only
  - Awards (`awards`)
  - Skills (`skills`)
  - Certifications (`certifications`)
  - Languages (`languages`)
- **Section editor** on `edit_profile.php` with add / remove / inline-validate
  per row, driven by the `includes/profile_sections.php` registry.
- **Public profile** at `/u/<username>` (Apache rewrite in `.htaccess`).
- **Settings** page with three tabs: Account, Security, Danger zone (delete
  account).
- **Forgot / reset password** flow using a tokenized `password_resets` table.
- **Admin panel** at `/admin/index.php` with search (name / email / username),
  filter by role and degree type, sort by any column, mobile card view, and a
  per-user detail page with role / degree / delete actions.
- **Activity log** (`activity` table) used by the dashboard's recent-activity
  widget and the admin audit trail.
- **Design system** in `assets/css/app.css` (~700 lines, fully commented
  sections).
- **Interactive layer** in `assets/js/app.js`: toasts, `<dialog>`-based confirm
  modal, photo uploader with drag/drop, password show/hide, copy-to-clipboard
  buttons, mobile hamburger, character counters, dirty-form guard,
  debounced search, focus-first-error.
- **Username** column on `users` (with backfill) so each user gets a stable,
  shareable public URL slug.
- Migrations: `migrations/001_redesign.sql` (username + password_resets +
  activity), `migrations/002_academic_profile.sql` (degree_type + 9 tables).
- Docker ignore files, env example, full README rewrite, troubleshooting
  section, security notes, backup / restore runbook.

### Changed
- `edit_profile.php` is now a full editor: photo upload with live preview,
  drag/drop, type/size check, remove-photo button, character counters on text
  fields, inline errors per field, deletes the old photo file on replace.
- `profile.php` (private) is a read-only summary with a "Copy link" button.
- `dashboard.php` is a proper home: welcome card, profile-completion progress
  bar (0-100%), recent-activity feed, role/username/joined stats, admin
  shortcut for admins.
- `register.php` collects username and degree_type, validates them server-side.
- `includes/header.php` is now a shared partial — every page gets the same
  topbar, skip-link, and toast container. The "logout is in different places"
  inconsistency is gone.
- All output goes through `e()`; all SQL is prepared statements with
  `EMULATE_PREPARES = false`.

### Security
- `password_hash()` with `PASSWORD_DEFAULT`.
- `session_regenerate_id(true)` on successful login.
- `httponly` + `samesite=Lax` session cookies.
- CSRF tokens on every state-changing form.
- Photo uploads: real MIME check via `finfo`, 2 MB cap, randomized filenames,
  `is_uploaded_file()` guard, `.htaccess` denies PHP execution in `uploads/`.
- Self-demote / self-delete guards on admin actions.
- Numeric URL params (`?id=`) validated with `ctype_digit`.

### Known limitations (carried forward)
- **No SMTP** — the password-reset link is shown on screen in dev. The page
  comment makes this clear; an email transport is a follow-up. (In
  `APP_ENV=production` the link is hidden entirely; recovery is then
  manual via the DB until SMTP is wired up.)
- **No 2FA enforcement** — the schema has `users.totp_required` and the
  TOTP migration is in place, but opting in is per-user, not required.
- **All profile fields are public by default** — no per-section privacy toggle.
  (Per-section `is_visible` was added in migration 005, but the editor
  doesn't expose it yet.)

---

## Earlier history (pre-2025-10)

The project started as a XAMPP-style starter (`README.txt` has the original
quick-start). It shipped with `index.php`, `login.php`, `register.php`,
`logout.php`, `dashboard.php`, `profile.php`, `edit_profile.php`, a basic
`style.css`, an `includes/db.php` with hardcoded credentials, and a
`database.sql` with two tables (`users`, `profiles`).

The 2025-10-11 redesign replaced almost everything: the design system, the
shared layout, the editor, the admin, the public profile, the settings, the
password reset, the activity log, and the academic sections. The original
`database.sql` and `logout.php` are kept; the old `style.css` was deleted.
