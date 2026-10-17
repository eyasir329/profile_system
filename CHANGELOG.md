# Changelog

All notable changes to this project are documented here. The format is loosely
based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the
project does **not** currently follow [Semantic Versioning](https://semver.org/).

The DB schema is the source of truth for compatibility. New migrations appear
in `migrations/`, applied by the web container's entrypoint in name order.

---

## [Unreleased]

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
  Potential journals (one text block each; students take or release them
  and see who took what; migration `028`, `029`), and read-only tabs of
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
