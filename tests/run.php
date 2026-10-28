<?php
declare(strict_types=1);

/**
 * Dependency-free unit tests for the pure helpers (no database, no server).
 *
 *   make test        # or: php tests/run.php
 *
 * Exits 1 on the first failing run so CI can gate on it. Add a case by
 * calling check() with the expected and actual values.
 */

require_once __DIR__ . '/../src/includes/validate.php';
require_once __DIR__ . '/../src/includes/lab_entries.php';

$passed = 0;
$failed = [];

function check(string $name, mixed $expected, mixed $actual): void
{
    global $passed, $failed;
    if ($expected === $actual) {
        $passed++;
        return;
    }
    $failed[] = sprintf("%s\n    expected %s\n    actual   %s", $name, var_export($expected, true), var_export($actual, true));
}

// ---- passwords: length is counted in characters, not bytes ----
check('password: empty is rejected', true, isset(validate_password('')['errors']['password']));
check('password: 7 characters rejected', true, isset(validate_password('abcdefg')['errors']['password']));
check('password: 8 characters accepted', [], validate_password('abcdefgh')['errors']);
check('password: 3 Japanese characters (9 bytes) rejected', true, isset(validate_password('あいう')['errors']['password']));
check('password: 8 Japanese characters accepted', [], validate_password('あいうえおかきく')['errors']);
check('password: login does not enforce the minimum', [], validate_password('abc', false)['errors']);
check('password: over 4096 bytes rejected', true, isset(validate_password(str_repeat('a', 4097))['errors']['password']));

// ---- first_name(): any whitespace, including the full-width space ----
check('first_name: western', 'Haruka', first_name('Haruka Sato'));
check('first_name: full-width space', '佐藤', first_name('佐藤　遥'));
check('first_name: surrounding spaces', 'Ana', first_name('  Ana  Maria '));
check('first_name: empty', '', first_name('   '));

// ---- paper counts: "published(submitted)" ----
check('paper_counts: plain', [3, 1], paper_counts('3(1)'));
check('paper_counts: braces (older format)', [3, 1], paper_counts('{3}(1)'));
check('paper_counts: first line of an older multi-line value', [2, 0], paper_counts("2(0)\nA paper (published)"));
check('paper_counts: free text', null, paper_counts('many'));
check('paper_counts: null', null, paper_counts(null));
check('paper_format', '4(2)', paper_format(4, 2));
check('paper_display: normalises', '3(1)', paper_display('{3}(1)'));
check('paper_display: keeps free text', 'many', paper_display(' many '));
check('paper_count_input: empty is 0', 0, paper_count_input(''));
check('paper_count_input: digits', 12, paper_count_input(' 12 '));
check('paper_count_input: letters rejected', null, paper_count_input('1a'));
check('paper_count_input: negative rejected', null, paper_count_input('-1'));
check('paper_count_input: five digits rejected', null, paper_count_input('12345'));

// ---- TOTP secrets ----
check('totp: generated secret is standard', true, totp_secret_is_standard(generate_totp_secret()));
check('totp: garbage is not', false, totp_secret_is_standard('not base32!'));

echo "$passed passed, " . count($failed) . " failed\n";
foreach ($failed as $f) { echo "FAIL $f\n"; }
exit($failed ? 1 : 0);
