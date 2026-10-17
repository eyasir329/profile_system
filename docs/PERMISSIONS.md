# Permissions

Who can create, read, update and delete what. Every rule here is enforced
on the server; the interface only hides what you cannot do.

A profile is the lab details and nothing else, and only accounts that
hold the **student** role have one. In the table below, "Owner" rows
about lab details, entries and courses apply to students; `/profile.php`
refuses every save unless the account is **acting as a student**. A user
can hold several roles. What they may do at a given moment follows the
role they are **acting as** (the role switcher in the top bar).

## Summary

| Data | Owner (any role) | Teacher | Admin |
| --- | --- | --- | --- |
| Own name, password, theme, 2FA | Read, update | – | – |
| Another user's name, password | – | Read name (students only) | Create, read, update |
| Roles | Read own | – | Read, update (cannot remove own admin role or the last admin's) |
| Lab details: course, student ID, names (EN/JP), **university email**, research title, next step, previous university | Read, update (own). Course, student ID and email cannot be left empty; the rest can be cleared | Read (students only) | Read, update |
| Lab details: **completed theses, journal papers, conference papers** (entries: level + title, or title + published/submitted) | Add, edit, delete (own), up to 50 per section | Read (students only), as the workbook text: `[Level: Title]` lines, or `{published}(submitted)` plus the titles | Read (generated text, read-only on `/admin/user.php`) |
| **Publications and awards** (the second workbook's sheets: PublishedJournals, Conf.Proceedings, Conf.PresentationOnly, Awards) | Add, edit, delete (own) on My profile, up to 50 per sheet. Read everyone's on `/resources.php` (one tab per sheet) | Read everyone's on `/resources.php`, and on each student's page | Read |
| **Lab resources** (`/resources.php`: Templates, Open data and code), shared by the whole lab | Read only (copy templates, open links) | Read, add, edit, delete | Read, add, edit, delete |
| **Potential journals** (`/resources.php`, tab Potential journals) | Read; take a journal or release it (own, while acting as a student). Sees who took each journal | Read (with who took each), add, edit, delete | Read, add, edit, delete |
| Lab details: **enrolled year and month** (of the current course) | Read, update (own), from the workbook's choices: year 2022 to next year; month April, October or Other. Starts as the day the invitation link was generated, or the day a course was added | Read (students only) | Read, update, clear |
| **Course completed** (the current course) | Mark completed, or undo while it is still the current course (own), in Courses on My profile. Once the next course is added, the completed one is fixed: no undo, no removal | Read: Current students tab, and a Completed tab with one row per completed course, showing the details frozen when it was completed | Read |
| Course history | Add a new course (becomes current), correct the current course, remove a past course (own) | Read (students only) | Read; corrects the current course from the user form |
| Account status (active / deactivated) | Deactivate own | – | Deactivate, restore, delete permanently (not self, not the last admin) |
| Invitations | – | Create and cancel own; students only | Create and cancel any; any role |
| Activity log | Read own recent activity | – | – |

The university email is the address the person signs in with. There is
one email per account, so changing it on the profile or in Settings
changes the sign-in address; it must be unique. There are no usernames.
A student ID must also be unique across accounts.

"Students only" means active accounts that hold the student role.
Deactivated accounts are never shown to teachers.

## Where each rule lives

| Rule | Enforced in |
| --- | --- |
| Lab details exist for students only | `active_user_role() === 'student'` in `public/profile.php` (edited only while acting as a student); `$holdsStudent` in `src/includes/directory.php`; the navigation in `src/includes/header.php` |
| Signed in, account not deactivated | `require_login()` in `src/includes/auth.php` |
| Acting as admin | `require_admin()`; used by `public/admin/*.php` |
| Acting as teacher or admin for the Students directory | `public/students.php` |
| Teachers see active students only | `$scopeWhere` in `src/includes/directory.php` |
| Profile edits touch the signed-in user's row only | every query in `public/profile.php` is bound to `user_id = <session user>`; the id never comes from the request |
| Owner may write only some lab fields | `lab_field_defs()` (`owner_edit`) in `src/includes/lab_fields.php`, checked by `op_update_lab()` in `public/profile.php`. A refused attempt returns 403 and is logged as `lab_update_denied` |
| Entries (theses, papers, publications, awards) | `lab_entry_defs()` and `entries_for()` in `src/includes/lab_entries.php`; ops `add_entry` / `update_entry` / `delete_entry` in `public/profile.php`, bound to the session user. The table and column names come from the definitions, never from the request |
| One course at a time; completing a course | `can_add_course()` and `op_add_course()` / `op_complete_course()` in `public/profile.php`; `enrollment_set_completed()`, `completed_courses_for()` and `enrollment_remove()` in `src/includes/lab_fields.php` (a completed course cannot be removed) |
| Current / Completed tabs of the Students sheet | `$group` in `src/includes/directory.php` |
| Lab resources: templates, open data and code, potential journals | `public/resources.php`: every write needs the acting role to be teacher or admin (403 otherwise); taking and releasing a journal needs the acting role to be student |
| Publications and awards on Resources | `entries_for_sheet()` in `src/includes/lab_entries.php`; read-only on the page |
| Course and student ID at registration | `public/register.php`, only when the invitation grants the student role |
| Course history and enrolment dates | `enrollment_add()`, `enrollment_sync_current()`, `enrollment_remove()` in `src/includes/lab_fields.php`; ops `add_course` / `remove_course` in `public/profile.php` |
| Student ID format and uniqueness | `student_id_problem()` in `src/includes/lab_fields.php`, used by registration, the profile and the admin form |
| Email format and uniqueness | `validate_email()` plus a uniqueness query in each of `register.php`, `settings.php`, `profile.php` (`op_update_lab`) and `admin/user.php` |
| Admin safety rules (self, last admin) | `public/admin/user.php` |
| Who may invite which roles | `invite_grantable_roles()` in `src/includes/invites.php`, re-checked when the link is used |
| All state-changing requests | `verify_csrf()` |

## Changing who may edit a lab field

Flip `owner_edit` for that field in `lab_field_defs()`
(`src/includes/lab_fields.php`). The profile page, the editor and the server check
all read from that one list. A field with `owner_edit => false` is shown
to its owner with a "Set by admin" note and no Edit button. Today every
lab field is owner-editable; the flag is there for any field that should
become admin-only.
