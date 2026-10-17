# Profile System

A private member register for a research lab. Each member keeps their
lab details, theses, papers, publications and awards up to date; teachers
read everyone's as sheets and keep the lab's shared resources; admins
manage accounts. The fields follow the lab's *Information of Lab
Students* workbooks.

Vanilla PHP 8.3 and MariaDB on the server; Tailwind CSS v4 and Alpine.js
in the browser. Runs in two Docker containers. The built CSS and JS are
committed, so running it needs no Node toolchain.

## What it does

| Role | Can |
| --- | --- |
| **Student** | Fill in and edit their own lab details in place (course, student ID, names, email, research title, enrolment year and month, next step, previous university). Add completed theses, journal and conference papers, and publications and awards as separate entries. Mark their current course completed, then add the next one (one course at a time). Read the lab's resources and take potential journals. |
| **Teacher** | Read every active student's details in one searchable sheet laid out like the workbook, with a Current students tab and a Completed tab (one fixed row per completed course). Open one student. Keep the lab's Templates, Open data and code, and Potential journals; read everyone's publications and awards. Invite new students by link. |
| **Admin** | Create, edit, deactivate, restore and delete accounts; assign roles; edit every lab field; keep the lab resources; invite people with any role. |

Each role sees only what it works with: students get their profile and
Resources, teachers get the Students sheet, Resources and invitations,
admins get Users, Resources and invitations. Lab details belong to
students; teacher and admin accounts have none. What a user may do
follows the role they are **acting as**.

**Resources** (`/resources.php`) is shared by the whole lab, one tab per
workbook sheet: Templates and Open data and code (kept by teachers,
read-only for students); Potential journals (teachers list them, students
take or release them and mark their own submission published); and PublishedJournals, Conf.Proceedings,
Conf.PresentationOnly and Awards (everyone's rows, read-only here;
students edit their own on My profile).

A user can hold several roles and switches between them from the top bar.
The full matrix is in [`docs/PERMISSIONS.md`](docs/PERMISSIONS.md).

Also included: invitation-only registration, password reset, two-factor
sign-in (TOTP) with backup codes, login rate limiting, CSRF protection,
account deactivation with a 30-day grace period, light and dark themes.

### The lab fields

Course · Student ID · Full name (Japanese) · Full name (English) ·
University email · Current research title · Enrolled year and month ·
Next step (進路) · Previous univ / level · Completed thesis titles ·
Journal papers · Conference papers

They are defined once, in `src/includes/lab_fields.php`. The university
email is the address the person signs in with: there is one email per
account, and there are no usernames. The enrolment year and month start
as the day the invitation was created (or the course was added) and the
student can change them (year 2022 to next year; month April, October or
Other).

Completed theses, journal papers and conference papers, and the four
publications and awards sheets, hold several items each. They are
**entries** defined in `src/includes/lab_entries.php`: the student adds,
edits and deletes them one by one on My profile. The three main-sheet
columns are rebuilt from the entries in the workbook's format
(`[Level: Title]`, `{published}(submitted)`) so the Students sheet reads
them. Cells with several items show a numbered list.

### Courses

A student is in one course at a time. When it is done they mark it
**completed**; only then can they add the next one. Marking it completed
saves a copy of their details as they were, and the teachers' Completed
tab shows one fixed row per completed course from those copies. Undo is
possible only while the course is still the current one.

## Quick start

Needs Docker with Compose v2, and `make`.

```bash
cp .env.example .env      # then set real passwords in .env
make up                   # build and start; applies schema + migrations
```

Open <http://localhost:8080>. While there are no users, `/register.php`
lets the first person create an account, which becomes the admin.
Everyone after that needs an invitation link from `/invites.php`.

```bash
make help                 # every target
make logs                 # tail logs
make restart              # restart web (applies new migrations)
make lint                 # php -l on every file
make backup               # database + uploads to ./backups/<timestamp>/
make restore BACKUP=./backups/<timestamp>
make assets               # rebuild CSS + JS after editing resources/
make tunnel               # share through a Cloudflare quick tunnel
```

Read [`docs/TUNNEL.md`](docs/TUNNEL.md) before exposing the app to the
internet: it covers the settings in `.env` that must change first.

## Pages

| URL | Who | What |
| --- | --- | --- |
| `/` | anyone | What the system is |
| `/login.php`, `/login_totp.php` | anyone | Sign in, role choice, 2FA code |
| `/register.php?invite=…` | invited | Create an account |
| `/forgot_password.php`, `/reset_password.php` | anyone | Password reset |
| `/dashboard.php` | signed in | One panel per role (own lab details and shortcuts / the students / account counts), account summary, recent activity |
| `/profile.php` | student | Own lab details, entries (theses, papers, publications, awards) and courses, edited in place. Others see only their name, email and roles |
| `/resources.php` | signed in | Lab resources, one tab per sheet; `?tab=` followed by `templates`, `data`, `journals`, `pub_journals`, `pub_proceedings`, `pub_presentations` or `awards`. Teachers and admins edit the first three; students take journals |
| `/settings.php` | signed in | Name, email, theme, password, 2FA, deactivate |
| `/students.php` | teacher | The Students sheet: Current students and Completed tabs (`?group=completed`), filter by course (`?course=D3`, `?course=none`), search, full screen, and **Download CSV** of what is shown (`?export=csv`, the workbook's headers, opens in Excel). Course, Student ID and both names stay frozen at the left while scrolling sideways. `?id=` for one student |
| `/admin/index.php` | admin | Users: everyone as a list, students as the sheet (`?view=sheet`), one user (`?id=`) |
| `/admin/user.php` | admin | Create or edit a user |
| `/invites.php` | teacher, admin | Create and cancel invitation links |

## Layout

```
public/              web root: one PHP file per page, built assets, uploads/.htaccess
src/includes/        shared PHP
  auth.php           session, CSRF, role checks, TOTP
  db.php             PDO connection
  lab_fields.php     the lab fields, course history, completed courses
  lab_entries.php    the repeating sections (theses, papers, publications, awards)
  directory.php      the Students / Users pages (list, sheet, one person)
  header.php         top bar          footer.php   toasts + confirm dialog
  ui.php             icons, avatars, role chips, alerts, ui_multi()
  validate.php       input validation, activity log, tokens
  invites.php        invitation rules     ratelimit.php  login throttling
resources/           CSS and JS source (Tailwind v4, Alpine.js)
database/            schema.sql + migrations/ (applied in name order on start)
docker/              container entrypoint and DB bootstrap
docs/                DESIGN, COMPONENTS, FRONTEND, PERMISSIONS, TUNNEL
```

## Database

| Table | Holds |
| --- | --- |
| `users` | Account: name, email, password hash, 2FA flag, deactivation dates |
| `user_roles` | One row per role a user holds |
| `profiles` | The lab fields (except the email, which is `users.email`) and the theme, one row per user |
| `course_enrollments` | Every course a person has been in, with its enrolment month; the newest row is the current course. A completed course also holds its completion date and a saved copy of the person's details |
| `lab_entries` | Completed theses, journal papers and conference papers, one row each |
| `lab_publications` | Published journals, conference proceedings and presentations (three `kind`s), one row each |
| `lab_awards` | Awards, one row each |
| `lab_templates`, `lab_resources` | Shared templates, and shared open data and code links (teachers edit) |
| `lab_journals`, `lab_journal_takers` | Potential journals (name, link, IF, CS, APC, suggested by) and the students who took each, with whether their submission is published |
| `invites` | Invitation links (hashed token, roles, uses, expiry) |
| `two_factor` | TOTP secret and backup codes |
| `password_resets`, `email_verifications`, `account_restorations` | One-time tokens |
| `login_attempts` | Rate limiting |
| `activity` | Per-user log shown on the dashboard |
| `schema_migrations` | Which migrations have run |

Add a migration as `database/migrations/NNN_name.sql`; it runs on the
next `make restart`. Recent ones: `019_lab_only_profile.sql` dropped the
older profile sections, contact fields, photo and profile type;
`020_single_email_no_username.sql` dropped usernames and the separate
university email; `021` added the course history; `022` added "Other" as
an enrolment month; `023` the thesis and paper entries; `024` publications
and awards; `025` templates and open data and code; `026` and `027` course
completion and its saved copy; `028`, `029` and `030` potential journals (`030` gives them the sheet's columns).

## Documentation

| Doc | Covers |
| --- | --- |
| [`docs/PERMISSIONS.md`](docs/PERMISSIONS.md) | Who can create, read, update and delete what, and where it is enforced |
| [`docs/DESIGN.md`](docs/DESIGN.md) | Stack decisions, design tokens, layout and behaviour rules |
| [`docs/COMPONENTS.md`](docs/COMPONENTS.md) | Every component class and PHP helper, with markup |
| [`docs/FRONTEND.md`](docs/FRONTEND.md) | Build commands, file layout, Alpine stores and components |
| [`docs/TUNNEL.md`](docs/TUNNEL.md) | Exposing the app through a Cloudflare tunnel safely |
| [`CHANGELOG.md`](CHANGELOG.md) | What changed |

## Security notes

- Secrets live in `.env`, which is not committed. `.env.example` holds
  placeholders only.
- Set `APP_ENV=production` before any public exposure. **The app sends no
  email.** A password-reset request stores a one-time link but, in
  production, it is not shown or sent anywhere (in development it is
  shown on screen). Until email is set up, an admin recovers an account
  by setting a new password on the user's page (Users, then Edit).
- No `Content-Security-Policy` header is sent yet.
- Nothing under `public/` should be a script that changes data without
  signing in. Keep test and seed scripts outside the web root.

## License

See [`LICENSE`](LICENSE).
