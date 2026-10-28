<?php
declare(strict_types=1);

/**
 * Lab entries: the parts of a student's record that hold several items.
 *
 * Five repeating sections, edited by the student on /profile.php. The
 * thesis column is read by teachers on the Students page; the four sheet
 * sections are read by everyone on /resources.php (one tab each, every
 * student's rows):
 *
 *   From "Information of Lab Students":
 *     completed_thesis    [Level: Title]                  table lab_entries
 *
 * Journal and conference papers are NOT entries any more: each is just two
 * numbers, published and submitted, stored in profiles.journal_papers /
 * profiles.conference_papers as "3(1)" (see paper_* helpers below).
 *   One workbook sheet each (the second workbook):
 *     pub_journals        PublishedJournals                table lab_publications
 *     pub_proceedings     Conf.Proceedings                 table lab_publications
 *     pub_presentations   Conf.PresentationOnly            table lab_publications
 *     awards              Awards                           table lab_awards
 *
 * Per section:
 *   table, kind    where its rows live (kind = the table's `kind` value,
 *                  null when the table has none)
 *   title, singular, desc   labels on the profile
 *   sheet          the workbook sheet name (its tab on /resources.php), or null when the
 *                  section is a column of the main sheet instead
 *   row_title, row_meta     which fields summarise a row on the profile
 *   tab            short label of a sheet section's tab on the profile
 *   form           field order in the entry form, when it differs from
 *                  the workbook's (short fields first)
 *   fields         column => spec. Field spec:
 *                    label, type (text | textarea | select | month | date
 *                    | number), required, max, options (select: value =>
 *                    label), default, placeholder; heading = the workbook
 *                    column header when it differs from the label; span =
 *                    form columns out of 3 (default: 3 for text and
 *                    textarea, 1 otherwise)
 *                  Field order is the workbook's column order.
 *
 * Every table/column name used in SQL comes from here, never from input.
 */

const LAB_PUB_TYPES = [
    'Journal'                   => 'Journal',
    'Conference (published)'    => 'Conference (published)',
    'Conference (presentation)' => 'Conference (presentation)',
];

function lab_entry_defs(): array
{
    $title  = ['label' => 'Title', 'type' => 'text', 'required' => true, 'max' => 500];
    $status = ['label' => 'Status', 'type' => 'select', 'required' => true, 'max' => 10,
               'options' => ['published' => 'Published', 'submitted' => 'Submitted']];
    // The three publication sheets share the workbook's five columns.
    $pub = fn(string $type): array => [
        'pub_month'     => ['label' => 'Publication/Presentation Year Month', 'type' => 'month', 'required' => false, 'max' => 7,
                            'heading' => "Publication/Presentation\nYear Month Date"],
        'pub_type'      => ['label' => 'Type of Publication', 'type' => 'select', 'required' => true, 'max' => 40,
                            'options' => LAB_PUB_TYPES, 'default' => $type],
        'citation'      => ['label' => 'Citation Details', 'type' => 'textarea', 'required' => true, 'max' => 2000,
                            'placeholder' => 'Authors, "Title," Journal or conference, vol., pages, year.'],
        'bibtex'        => ['label' => 'Bibtext Details', 'type' => 'textarea', 'required' => false, 'max' => 5000,
                            'placeholder' => '@ARTICLE{key, title = {...}, ...}'],
        'impact_factor' => ['label' => 'ImpactFactor', 'type' => 'number', 'required' => false, 'max' => 10,
                            'placeholder' => 'e.g. 3.3'],
    ];
    $pubMeta = ['pub_month', 'pub_type', 'impact_factor'];
    $pubForm = ['pub_month', 'pub_type', 'impact_factor', 'citation', 'bibtex'];

    return [
        'completed_thesis' => [
            'table' => 'lab_entries', 'kind' => 'thesis', 'sheet' => null,
            'title' => 'Completed theses', 'singular' => 'thesis',
            'desc' => 'Theses you have completed, one entry each. Leave empty if none.',
            'row_title' => 'title', 'row_meta' => ['level'],
            'fields' => [
                'level' => ['label' => 'Level', 'type' => 'select', 'required' => true, 'max' => 50,
                            'options' => array_combine($l = ['Associate', 'Bachelor', 'Master', 'Doctor', 'Other'], $l)],
                'title' => $title + ['placeholder' => 'Title of the thesis'],
            ],
        ],
        'pub_journals' => [
            'table' => 'lab_publications', 'kind' => 'journal', 'sheet' => 'PublishedJournals',
            'title' => 'Published journals', 'singular' => 'published journal',
            'desc' => 'Your published journal articles, with citation and BibTeX.',
            'tab' => 'Journals', 'form' => $pubForm,
            'row_title' => 'citation', 'row_meta' => $pubMeta, 'fields' => $pub('Journal'),
        ],
        'pub_proceedings' => [
            'table' => 'lab_publications', 'kind' => 'proceedings', 'sheet' => 'Conf.Proceedings',
            'title' => 'Conference proceedings', 'singular' => 'conference proceeding',
            'desc' => 'Conference papers published in proceedings.',
            'tab' => 'Proceedings', 'form' => $pubForm,
            'row_title' => 'citation', 'row_meta' => $pubMeta, 'fields' => $pub('Conference (published)'),
        ],
        'pub_presentations' => [
            'table' => 'lab_publications', 'kind' => 'presentation', 'sheet' => 'Conf.PresentationOnly',
            'title' => 'Conference presentations', 'singular' => 'conference presentation',
            'desc' => 'Conference presentations without a published paper.',
            'tab' => 'Presentations', 'form' => $pubForm,
            'row_title' => 'citation', 'row_meta' => $pubMeta, 'fields' => $pub('Conference (presentation)'),
        ],
        'awards' => [
            'table' => 'lab_awards', 'kind' => null, 'sheet' => 'Awards',
            'title' => 'Awards', 'singular' => 'award',
            'desc' => 'Awards you received, alone or with others.',
            'tab' => 'Awards', 'form' => ['award_name', 'organization', 'award_date', 'recipients', 'details'],
            'row_title' => 'award_name', 'row_meta' => ['organization', 'award_date', 'recipients'],
            'fields' => [
                'recipients'   => ['label' => 'Recipients', 'type' => 'text', 'required' => false, 'max' => 500,
                                   'placeholder' => 'Everyone who received it, e.g. Test User, Kenji Tanaka'],
                'award_name'   => ['label' => 'Award name', 'type' => 'text', 'required' => true, 'max' => 300],
                'organization' => ['label' => 'Organization/Conf.', 'type' => 'text', 'required' => false, 'max' => 300, 'span' => 2],
                'details'      => ['label' => 'Full information/Paper Title', 'type' => 'textarea', 'required' => false, 'max' => 2000],
                'award_date'   => ['label' => 'Date', 'type' => 'date', 'required' => false, 'max' => 10,
                                   'heading' => "Date\nyyyymmdd"],
            ],
        ],
    ];
}

/**
 * A stored value as the workbook writes it: months as "2024/05", award
 * dates as "20240501", the rest as stored.
 */
function lab_entry_display(array $field, ?string $value): string
{
    $value = trim((string)$value);
    if ($value === '') { return ''; }
    return match ($field['type']) {
        'month'  => str_replace('-', '/', $value),
        'date'   => str_replace('-', '', $value),
        'select' => (string)($field['options'][$value] ?? $value),
        default  => $value,
    };
}

/** WHERE clause + params selecting one section's rows. */
function lab_entry_scope(array $def): array
{
    return $def['kind'] !== null ? [' AND kind = :k', ['k' => $def['kind']]] : ['', []];
}

/** A person's entries, keyed by section, each in the person's order. */
function entries_for(PDO $pdo, int $userId): array
{
    $out = [];
    foreach (lab_entry_defs() as $key => $def) {
        [$and, $params] = lab_entry_scope($def);
        $cols = implode(', ', array_map(fn($c) => "`$c`", array_keys($def['fields'])));
        $st = $pdo->prepare("SELECT id, $cols FROM `{$def['table']}` WHERE user_id = :u$and ORDER BY sort_order, id");
        $st->execute(['u' => $userId] + $params);
        $out[$key] = $st->fetchAll();
    }
    return $out;
}

/**
 * Every active student's rows of one workbook-sheet section, for its
 * tab on /resources.php: each row has the entry fields plus user_id and the
 * student's name (name_en, else the account name). Newest date first.
 */
function entries_for_sheet(PDO $pdo, string $section): array
{
    $def = lab_entry_defs()[$section];
    [$and, $params] = lab_entry_scope($def);
    $cols  = implode(', ', array_map(fn($c) => "e.`$c`", array_keys($def['fields'])));
    $date  = isset($def['fields']['pub_month']) ? 'e.pub_month' : (isset($def['fields']['award_date']) ? 'e.award_date' : 'NULL');
    $st = $pdo->prepare(
        "SELECT e.id, e.user_id, $cols, COALESCE(NULLIF(TRIM(p.name_en), ''), u.name) AS student_name
           FROM `{$def['table']}` e
           JOIN users u ON u.id = e.user_id
           LEFT JOIN profiles p ON p.user_id = u.id
          WHERE u.deleted_at IS NULL
            AND EXISTS (SELECT 1 FROM user_roles r WHERE r.user_id = u.id AND r.role = 'student')"
            . str_replace('kind', 'e.kind', $and) . "
          ORDER BY $date IS NULL, $date DESC, student_name, e.sort_order, e.id"
    );
    $st->execute($params);
    return $st->fetchAll();
}

/**
 * Rewrite the profiles text column of a main-sheet section (the ones with
 * no `sheet`) from its entries, in the workbook's format, so the Students
 * sheet and the completeness meter read it:
 *   thesis   one "[Level: Title]" per line
 * Sections that are their own sheet have no text column; nothing to do.
 */
function entries_sync_text(PDO $pdo, int $userId, string $section): void
{
    $def = lab_entry_defs()[$section];
    if ($def['sheet'] !== null || $def['kind'] !== 'thesis') { return; }
    $rows = entries_for($pdo, $userId)[$section];
    $text = $rows
        ? implode("\n", array_map(fn($r) => '[' . ($r['level'] ?: 'Other') . ': ' . $r['title'] . ']', $rows))
        : null;
    // $section is a key of lab_entry_defs() that is also a profiles column.
    $pdo->prepare("UPDATE profiles SET `$section` = :t WHERE user_id = :u")->execute(['t' => $text, 'u' => $userId]);
}

// ============================================================================
// Paper counts: journal_papers / conference_papers
//
// Two numbers per column, {published} and (submitted), kept as the text
// "3(1)" in profiles.journal_papers / profiles.conference_papers. Teachers
// read that text as is. Older records may hold "{3}(1)" followed by one
// "Title (status)" line per paper; paper_counts() reads those too.
// ============================================================================

/** The profiles columns that hold paper counts. */
const LAB_PAPER_COLUMNS = ['journal_papers', 'conference_papers'];

/** Largest count accepted in either box. */
const LAB_PAPER_MAX = 9999;

/**
 * [published, submitted] read from a stored value ("3(1)", "{3}(1)", or an
 * older multi-line value whose first line is the count), or null when the
 * value is empty or holds no count.
 */
function paper_counts(?string $text): ?array
{
    $first = trim(explode("\n", str_replace("\r", '', (string)$text))[0]);
    if (preg_match('/^\{?\s*(\d{1,4})\s*\}?\s*\(\s*(\d{1,4})\s*\)$/', $first, $m)) {
        return [(int)$m[1], (int)$m[2]];
    }
    return null;
}

/** The stored text for two counts: "3(1)". */
function paper_format(int $published, int $submitted): string
{
    return $published . '(' . $submitted . ')';
}

/**
 * What teachers see for a stored value: "3(1)". A value with no readable
 * count (e.g. free text typed by an admin) is shown as it is.
 */
function paper_display(?string $text): string
{
    $c = paper_counts($text);
    return $c ? paper_format($c[0], $c[1]) : trim((string)$text);
}

/**
 * One count from a form value: '' counts as 0, otherwise whole digits
 * only. Returns the number, or null when the value is not acceptable.
 */
function paper_count_input(string $raw): ?int
{
    $raw = trim($raw);
    if ($raw === '') { return 0; }
    if (!preg_match('/^\d{1,4}$/', $raw)) { return null; }
    return min((int)$raw, LAB_PAPER_MAX);
}