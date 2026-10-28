<?php
declare(strict_types=1);

/**
 * The lab fields: the single source of truth for what a profile contains.
 *
 * A profile is exactly the columns of the lab's "Information of Lab
 * Students" workbook. This file defines them once; the profile page, the
 * directory, the completeness meter and the permission check all read
 * from here. To add or relabel a field, or change who may edit it, edit
 * lab_field_defs() (and add the column to `profiles` in a migration).
 */

/** Values the enrolled_month columns accept (admins pick from all of them). */
const LAB_MONTHS = ['January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December', 'Other'];

/** The workbook's "Enrolled Month" choices: what the owner picks from. */
const LAB_ENROL_MONTHS = ['April', 'October', 'Other'];

/** The workbook's "Enrolled Year" choices: 2022 up to next year. */
function lab_enrol_years(): array
{
    return array_map('strval', range(2022, (int)date('Y') + 1));
}

/** Values the `profiles.course` column accepts. */
const LAB_COURSES = ['D1', 'D2', 'D3', 'M1', 'M2', 'B3', 'B4', 'R', 'Other'];

/**
 * The lab fields: the columns of the lab's "Information of Lab Students"
 * workbook, in the workbook's order. They are the whole profile.
 *
 * Per field:
 *   owner_edit  who may WRITE it:
 *               true   the account owner (/profile.php) and admins
 *               false  admins only (/admin/user.php); the owner sees it
 *                      read-only
 *   required    cannot be cleared once set (course, student ID, email)
 *   type        text | textarea | email | select (with `options`)
 *               A select also accepts the value already stored, so a
 *               date the system set (e.g. "July") is not rejected.
 * Teachers can read all of them in the Students sheet and write none.
 *
 * One key is not a plain `profiles` column:
 *   email     the account's sign-in address (users.email). The workbook's
 *             "University email" column IS this address; there is no
 *             second one.
 *
 * enrolled_year / enrolled_month are the enrolment date of the CURRENT
 * course. The system fills them in (see enrollment_add()); the owner can
 * change them, and the change is copied to the course history.
 */
function lab_field_defs(): array
{
    $f = fn(string $label, bool $own, string $type = 'text', int $max = 255, int $rows = 0, string $placeholder = '', string $hint = '', bool $required = false, array $options = []): array => [
        'label' => $label, 'owner_edit' => $own, 'type' => $type, 'max' => $max, 'rows' => $rows,
        'placeholder' => $placeholder, 'hint' => $hint, 'empty' => 'Not set yet.',
        'required' => $required, 'options' => $options,
    ];
    return [
        'course'              => $f('Course', true, 'select', 10, 0, '', 'D, M, B = year of study. R = research student.', true, LAB_COURSES),
        'student_id'          => $f('Student ID', true, 'text', 64, 0, 'e.g. 24M1042', 'As printed on your student card.', true),
        'name_jp'             => $f('Full name (Japanese)', true, 'text', 255, 0, '例：佐藤 遥', 'If you have one.'),
        'name_en'             => $f('Full name (English)', true, 'text', 255, 0, 'e.g. Haruka Sato', 'As on university records.'),
        'email'               => $f('University email', true, 'email', 150, 0, 'name@university.ac.jp', 'This is also the address you sign in with.', true),
        'research_title'      => $f('Current research title', true, 'textarea', 2000, 2, 'The working title of your current research.'),
        'enrolled_year'       => $f('Enrolled year', true, 'select', 4, 0, '', 'The year you started your current course.', false, lab_enrol_years()),
        'enrolled_month'      => $f('Enrolled month', true, 'select', 10, 0, '', 'The month you started your current course.', false, LAB_ENROL_MONTHS),
        'next_step'           => $f('Next step (進路)', true, 'text', 255, 0, 'e.g. Master course, job hunting, undecided', 'What you plan to do after this course.'),
        'previous_univ_level' => $f('Previous univ / level', true, 'text', 255, 0, 'e.g. Example University / Bachelor'),
        // Generated from the person's thesis entries (lab_entries, edited on
        // /profile.php): "[Level: Title]" per thesis.
        'completed_thesis'    => $f('Completed thesis titles', true, 'textarea', 5000),
        // Two numbers each, edited on /profile.php as "published" and
        // "submitted" boxes and stored as "3(1)" (see paper_format()).
        'journal_papers'      => $f('Journal papers', true, 'text', 20, 0, '', 'Published(submitted), e.g. 3(1).'),
        'conference_papers'   => $f('Conference papers', true, 'text', 20, 0, '', 'Published(submitted), e.g. 2(0).'),
    ];
}

/**
 * Student ID rules, shared by registration, the profile and the admin
 * form. Returns an error message, or '' when $id is acceptable.
 * $exceptUserId is the account being edited (its own ID is not a clash).
 */
function student_id_problem(PDO $pdo, string $id, ?int $exceptUserId = null): string
{
    if (!preg_match('/^[A-Za-z0-9][A-Za-z0-9._\/-]{0,63}$/', $id)) {
        return 'Use letters, numbers, hyphens, dots or slashes only (up to 64 characters).';
    }
    $st = $pdo->prepare('SELECT 1 FROM profiles WHERE student_id = :s AND user_id <> :u');
    $st->execute(['s' => $id, 'u' => (int)$exceptUserId]);
    return $st->fetch() ? 'Another account already uses that student ID.' : '';
}

/**
 * The lab values of one person, keyed like lab_field_defs().
 * $profile is a `profiles` row joined with `users.email`.
 */
function lab_field_values(array $profile): array
{
    $out = [];
    foreach (lab_field_defs() as $key => $_) {
        $out[$key] = trim((string)($profile[$key] ?? ''));
    }
    return $out;
}

/**
 * Profile completeness: one step per lab field. $profile is a `profiles`
 * row joined with `users.email`. Each step: key ('lab:<field>'), label, done, owner_edit (false =
 * only an admin can complete it).
 */
function profile_completion_steps(array $profile): array
{
    $values = lab_field_values($profile);
    $steps = [];
    foreach (lab_field_defs() as $key => $def) {
        $steps[] = [
            'key'        => 'lab:' . $key,
            'label'      => 'Add ' . lcfirst($def['label']),
            'field'      => $def['label'],
            'done'       => $values[$key] !== '',
            'owner_edit' => $def['owner_edit'],
        ];
    }
    return $steps;
}

// ============================================================================
// Course history
//
// A person can be in several courses over time (B4, then M1, ...). Each one
// is a row in `course_enrollments`; the newest row is the current course.
// profiles.course / enrolled_year / enrolled_month mirror that newest row,
// so the sheet reads one table.
//
// Enrolment dates start as:
//   first course   the day the invitation link was generated
//   later courses  the day the course was added
// The owner (/profile.php) or an admin (/admin/user.php) can change the
// current one.
// ============================================================================

/**
 * Put a person in a new course and make it their current one.
 * $when is a date string (the invitation's created_at); null means now.
 */
function enrollment_add(PDO $pdo, int $userId, string $course, ?string $when = null): void
{
    $ts    = $when !== null ? (strtotime($when) ?: time()) : time();
    $year  = (int)date('Y', $ts);
    $month = date('F', $ts);
    $pdo->prepare('INSERT INTO course_enrollments (user_id, course, enrolled_year, enrolled_month) VALUES (:u, :c, :y, :m)')
        ->execute(['u' => $userId, 'c' => $course, 'y' => $year, 'm' => $month]);
    $pdo->prepare('UPDATE profiles SET course = :c, enrolled_year = :y, enrolled_month = :m, course_completed_at = NULL WHERE user_id = :u')
        ->execute(['u' => $userId, 'c' => $course, 'y' => $year, 'm' => $month]);
}

/**
 * Mark the CURRENT course completed (today) or not completed ($done
 * false). Completing it also freezes a copy of the person's lab details
 * (every lab_field_defs() value, plus the account name) in
 * course_enrollments.snapshot: the teachers' Completed tab shows that
 * copy, so it keeps what was true at completion. Undo clears it.
 * Returns false when the person has no course yet.
 */
function enrollment_set_completed(PDO $pdo, int $userId, bool $done): bool
{
    $last = $pdo->prepare('SELECT id FROM course_enrollments WHERE user_id = :u ORDER BY id DESC LIMIT 1');
    $last->execute(['u' => $userId]);
    $id = $last->fetchColumn();
    if (!$id) { return false; }
    $date = $done ? date('Y-m-d') : null;
    $snapshot = null;
    if ($done) {
        $st = $pdo->prepare('SELECT u.name, u.email, p.* FROM users u LEFT JOIN profiles p ON p.user_id = u.id WHERE u.id = :u');
        $st->execute(['u' => $userId]);
        $row = $st->fetch() ?: [];
        $snapshot = json_encode(lab_field_values($row) + ['name' => (string)($row['name'] ?? '')], JSON_UNESCAPED_UNICODE);
    }
    $pdo->prepare('UPDATE course_enrollments SET completed_at = :d, snapshot = :s WHERE id = :id')
        ->execute(['d' => $date, 's' => $snapshot, 'id' => (int)$id]);
    $pdo->prepare('UPDATE profiles SET course_completed_at = :d WHERE user_id = :u')->execute(['d' => $date, 'u' => $userId]);
    return true;
}

/**
 * Every completed course of the given people, newest completion first:
 * one row per course, the frozen snapshot merged with enrollment_id,
 * user_id, completed_at. $userIds empty = nobody.
 */
function completed_courses_for(PDO $pdo, array $userIds): array
{
    if (!$userIds) { return []; }
    $in = implode(',', array_map('intval', $userIds));
    $rows = $pdo->query(
        "SELECT id, user_id, course, enrolled_year, enrolled_month, completed_at, snapshot
           FROM course_enrollments
          WHERE completed_at IS NOT NULL AND user_id IN ($in)
          ORDER BY completed_at DESC, id DESC"
    )->fetchAll();
    $out = [];
    foreach ($rows as $r) {
        $snap = json_decode((string)$r['snapshot'], true) ?: [];
        // The course and its dates come from the enrolment itself.
        $out[] = array_merge($snap, [
            'enrollment_id'  => (int)$r['id'],
            'user_id'        => (int)$r['user_id'],
            'course'         => (string)$r['course'],
            'enrolled_year'  => (string)$r['enrolled_year'],
            'enrolled_month' => (string)$r['enrolled_month'],
            'completed_at'   => (string)$r['completed_at'],
        ]);
    }
    return $out;
}

/**
 * After profiles.course or the enrolment date was edited directly (a
 * correction by the owner or an admin), bring the newest history row in
 * line with it. Creates the first row if the person had none.
 */
function enrollment_sync_current(PDO $pdo, int $userId): void
{
    $st = $pdo->prepare('SELECT course, enrolled_year, enrolled_month FROM profiles WHERE user_id = :u');
    $st->execute(['u' => $userId]);
    $p = $st->fetch();
    if (!$p || empty($p['course'])) { return; }
    $last = $pdo->prepare('SELECT id FROM course_enrollments WHERE user_id = :u ORDER BY id DESC LIMIT 1');
    $last->execute(['u' => $userId]);
    $id = $last->fetchColumn();
    $params = ['c' => $p['course'], 'y' => $p['enrolled_year'], 'm' => $p['enrolled_month']];
    if ($id) {
        $pdo->prepare('UPDATE course_enrollments SET course = :c, enrolled_year = :y, enrolled_month = :m WHERE id = :id')
            ->execute($params + ['id' => (int)$id]);
    } else {
        $pdo->prepare('INSERT INTO course_enrollments (user_id, course, enrolled_year, enrolled_month) VALUES (:u, :c, :y, :m)')
            ->execute($params + ['u' => $userId]);
    }
}

/**
 * A person's courses, newest first. Each: id, course, enrolled ("April
 * 2022" or ''), current (bool: the newest row), completed ("YYYY-MM-DD"
 * or '').
 */
function enrollments_for(PDO $pdo, int $userId): array
{
    $st = $pdo->prepare('SELECT id, course, enrolled_year, enrolled_month, completed_at FROM course_enrollments WHERE user_id = :u ORDER BY id DESC');
    $st->execute(['u' => $userId]);
    $out = [];
    foreach ($st->fetchAll() as $i => $r) {
        $out[] = [
            'id'       => (int)$r['id'],
            'course'   => (string)$r['course'],
            'enrolled' => trim(($r['enrolled_month'] ?? '') . ' ' . ($r['enrolled_year'] ?? '')),
            'current'  => $i === 0,
            'completed' => (string)($r['completed_at'] ?? ''),
        ];
    }
    return $out;
}

/** Remove a PAST, not completed course from a person's history. The current one stays. */
function enrollment_remove(PDO $pdo, int $userId, int $id): bool
{
    $rows = enrollments_for($pdo, $userId);
    foreach ($rows as $r) {
        // A completed course is part of the record (teachers' Completed tab): it stays.
        if ($r['id'] === $id && !$r['current'] && $r['completed'] === '') {
            $pdo->prepare('DELETE FROM course_enrollments WHERE id = :id AND user_id = :u')->execute(['id' => $id, 'u' => $userId]);
            return true;
        }
    }
    return false;
}
