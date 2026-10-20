<?php
/**
 * Smoke-test helper: seed a complete dummy profile for the `viewtest`
 * user. Creates a fresh user + profile row + section rows for every
 * section visible to a bachelor student. The smoke test runs this and
 * then asserts that all the data appears on /u/viewtest.
 *
 * Usage (from smoke.sh):
 *   php docker/seed_dummy_profile.php
 *
 * Env (matches smoke.sh conventions):
 *   DB_HOST=db DB_PORT=3306 DB_NAME=profile_system DB_PASSWORD=rootpass
 */
declare(strict_types=1);

$host   = getenv('DB_HOST')     ?: 'db';
$port   = getenv('DB_PORT')     ?: '3306';
$db     = getenv('DB_NAME')     ?: 'profile_system';
$pass   = getenv('DB_PASSWORD') ?: 'rootpass';
$user   = 'root';

$dsn = "mysql:host=$host;port=$port;dbname=$db;charset=utf8mb4";
$pdo = new PDO($dsn, $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);

// Clean slate: remove any prior viewtest user (cascades to profiles + sections
// if FK is set; otherwise we delete children explicitly).
$pdo->exec("DELETE FROM users WHERE username='viewtest'");

// User row. The student role is added to user_roles further down.
// degree_type=bachelor means the
// edit form will only show education/work/skills/awards/certs/languages
// (not journal_articles / conference_papers / research_interests).
$hash = password_hash('testpass', PASSWORD_DEFAULT);
$pdo->prepare(
    "INSERT INTO users (name, username, email, password_hash, degree_type)
     VALUES (?, ?, ?, ?, 'bachelor')"
)->execute([
    'View Tester',
    'viewtest',
    'viewtest@example.com',
    $hash,
]);
$userId = (int)$pdo->lastInsertId();

// Profile row. Phone/address are formatted to exercise the tel: and
// openstreetmap link builders in the public profile template.
$pdo->prepare(
    "INSERT INTO profiles
        (user_id, phone, address, profession, bio)
     VALUES (?, ?, ?, ?, ?)"
)->execute([
    $userId,
    '+1 555 123 4567',
    "123 Test Lane\nApt 4B\nTestville, CA 90210",
    'Software Engineer in Test',
    "Hi, I'm View Tester — a smoke-test fixture used by docker/smoke.sh.\n\n"
    . "I have one entry in every section visible to a bachelor student so "
    . "the public-profile page can be verified end-to-end without leaking "
    . "real user data.",
]);

// Grant the student role so login.php finalizes the session without a
// role-picker detour.
$pdo->prepare("INSERT IGNORE INTO user_roles (user_id, role) VALUES (?, 'student')")
    ->execute([$userId]);

// ----- Section rows: 1+ entry in every visible section -----
// Each table is a list of associative arrays keyed by column name. The
// seed only inserts columns present in the row; any columns added by
// later migrations (e.g. visible_to_public) keep their table DEFAULT.
// Column names are looked up from information_schema so we don't break
// when migrations add new columns.
$sections = [
    'educations' => [
        ['sort_order' => 1, 'institution' => 'Test University',         'degree' => 'B.Sc. Computer Science', 'field_of_study' => 'Software Engineering', 'start_year' => 2018, 'end_year' => 2022, 'description' => 'Studied testing, compilers, and a little ML.'],
        ['sort_order' => 2, 'institution' => 'Smoke Community College', 'degree' => 'A.A. General Studies',   'field_of_study' => 'Mathematics',          'start_year' => 2016, 'end_year' => 2018, 'description' => 'Transferred after two years.'],
    ],
    'work_experiences' => [
        ['sort_order' => 1, 'organization' => 'Smoke Co.', 'role_title' => 'Senior QA Engineer', 'location' => 'Remote', 'start_date' => '2022-07-01', 'end_date' => null, 'is_current' => 1, 'description' => 'Runs the smoke-test fleet and writes fixtures.'],
    ],
    'awards' => [
        ['sort_order' => 1, 'title' => 'Best Bug Hunter', 'organization' => 'Smoke Co.', 'year' => 2024, 'description' => 'Awarded for finding the most flaky test of the year.'],
    ],
    'skills' => [
        ['sort_order' => 1, 'name' => 'Python',     'level' => 'expert'],
        ['sort_order' => 2, 'name' => 'JavaScript', 'level' => 'advanced'],
        ['sort_order' => 3, 'name' => 'Bash',       'level' => 'intermediate'],
        ['sort_order' => 4, 'name' => 'PHP',        'level' => 'advanced'],
    ],
    'certifications' => [
        ['sort_order' => 1, 'name' => 'ISTQB Foundation Level', 'issuing_org' => 'ISTQB', 'issue_date' => '2023-04-15', 'expiry_date' => null, 'credential_id' => 'CTFL-2023-9999', 'url' => 'https://www.istqb.org/certifications'],
    ],
    'languages' => [
        ['sort_order' => 1, 'name' => 'English',  'proficiency' => 'native'],
        ['sort_order' => 2, 'name' => 'Japanese', 'proficiency' => 'limited_working'],
    ],
];

foreach ($sections as $table => $rows) {
    $colInfo = $pdo->query("SHOW COLUMNS FROM `$table`")->fetchAll(PDO::FETCH_COLUMN);
    $autoCols = ['id', 'created_at', 'updated_at'];
    $userCols = array_values(array_filter($colInfo, fn($c) => !in_array($c, $autoCols, true)));

    foreach ($rows as $row) {
        $sortOrder = (int)($row['sort_order'] ?? 0);
        $explicit = array_diff(array_keys($row), ['sort_order']);
        // Insert only columns the seed explicitly named; everything else
        // (user_id + any new columns) uses the table DEFAULT.
        $insertCols = ['user_id', 'sort_order'];
        $params     = [$userId, $sortOrder];
        foreach ($explicit as $col) {
            if (!in_array($col, $colInfo, true)) {
                throw new RuntimeException("$table has no column $col");
            }
            $insertCols[] = $col;
            $params[]     = $row[$col];
        }
        $placeholders = implode(',', array_fill(0, count($insertCols), '?'));
        $sql = "INSERT INTO `$table` (" . implode(',', $insertCols) . ") VALUES ($placeholders)";
        $pdo->prepare($sql)->execute($params);
    }
}

echo "viewtest_id=$userId" . PHP_EOL;
echo "viewtest sections seeded: " . implode(', ', array_keys($sections)) . PHP_EOL;
