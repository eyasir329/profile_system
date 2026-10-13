<?php
declare(strict_types=1);

/**
 * Profile section registry.
 *
 * Each profile (Bachelor / M.Sc. / PhD) shows a different set of sections.
 * This file is the single source of truth for:
 *   - which sections exist
 *   - which fields each section has (for the form editor)
 *   - which sections are visible per degree_type
 *   - how to render each section on the public profile
 *
 * Adding a new section = add one entry to $SECTIONS below.
 *   - key        = table suffix (e.g. 'journal_articles' -> SQL table journal_articles)
 *   - title      = human label
 *   - singular   = human label for a single row
 *   - accent     = design token (CSS variable name) for the section accent color
 *   - description= short blurb shown above the section in the editor
 *   - fields[]   = the editable fields for this section
 *   - render()   = closure to render one row in the public profile
 *   - visible_for() = closure: degree_type => bool
 *
 * Field tuple (extended):
 *   [name, type, label, required, max, options?, help?, placeholder?, input_attrs?, rows?]
 *   - name        = column name
 *   - type        = 'text' | 'textarea' | 'year' | 'date' | 'select' | 'number' | 'check' | 'url' | 'email' | 'tel'
 *   - label       = visible field label
 *   - required    = bool — render aria-required and (visually) an asterisk
 *   - max         = int max length (0 = unlimited); for 'year' = the year window
 *   - options     = for 'select' only, [value => label]
 *   - help        = small text rendered below the input
 *   - placeholder = the input's placeholder attribute
 *   - input_attrs = associative array of extra HTML attributes (autocomplete,
 *                   inputmode, enterkeyhint, pattern, …) merged onto the input
 *   - rows        = for 'textarea' only, the visible row count (default 4)
 */

const DEGREE_TYPES = [
    'bachelor' => 'Bachelor',
    'msc'      => 'M.Sc.',
    'phd'      => 'PhD',
];

function normalize_degree_type(?string $raw): string
{
    $raw = strtolower(trim((string)$raw));
    return array_key_exists($raw, DEGREE_TYPES) ? $raw : 'bachelor';
}

function degree_label(?string $type): string
{
    $type = normalize_degree_type($type);
    return DEGREE_TYPES[$type];
}

/**
 * Section accent tokens. Each section picks a CSS custom property name
 * (without the leading `--`) declared in `assets/css/app.css`. The single
 * source of truth for the color values lives in the stylesheet.
 */
function section_accent(string $key): string
{
    $map = [
        'educations'         => 'accent-edu',
        'work_experiences'   => 'accent-work',
        'journal_articles'   => 'accent-journal',
        'conference_papers'  => 'accent-conference',
        'awards'             => 'accent-award',
        'skills'             => 'accent-skill',
        'certifications'     => 'accent-cert',
        'research_interests' => 'accent-research',
        'languages'          => 'accent-language',
    ];
    return $map[$key] ?? 'accent-default';
}

$SECTIONS = [
    'educations' => [
        'title'       => 'Education',
        'singular'    => 'Education entry',
        'description' => 'Add your schools, degrees, and years attended. Most recent first.',
        'fields'   => [
            ['institution',    'text',     'Institution',     true,  200, null,
                'The school, university, or bootcamp you attended.',
                'e.g. Massachusetts Institute of Technology',
                ['autocomplete' => 'organization', 'enterkeyhint' => 'Next']],
            ['degree',         'text',     'Degree',          true,  150, null,
                'The credential earned (B.Sc., M.A., PhD, …).',
                'e.g. B.Sc. in Computer Science',
                ['autocomplete' => 'off', 'enterkeyhint' => 'Next']],
            ['field_of_study', 'text',     'Field of study',  false, 150, null,
                'Your major, concentration, or specialization.',
                'e.g. Machine Learning',
                ['autocomplete' => 'off', 'enterkeyhint' => 'Next']],
            ['start_year',     'year',     'Start year',      false, 0, null,
                'Four-digit year you started. Leave blank if unknown.'],
            ['end_year',       'year',     'End year',        false, 0, null,
                'Four-digit year you graduated (or expect to).'],
            ['description',    'textarea', 'Description',     false, 2000, null,
                'Notable coursework, thesis, GPA, honors — anything that helps a reader skim.',
                'Activities, societies, thesis topic…',
                [], 5],
        ],
        'visible_for' => fn(string $t) => in_array($t, ['bachelor','msc','phd'], true),
    ],

    'work_experiences' => [
        'title'       => 'Work experience',
        'singular'    => 'Work experience entry',
        'description' => 'Jobs, internships, and research positions — most recent first.',
        'fields'   => [
            ['organization', 'text',     'Organization',     true,  200, null,
                'Company, lab, or organization.',
                'e.g. Anthropic',
                ['autocomplete' => 'organization', 'enterkeyhint' => 'Next']],
            ['role_title',   'text',     'Role / title',     true,  150, null,
                'Your job title, internship role, or position.',
                'e.g. Research Engineer Intern',
                ['autocomplete' => 'organization-title', 'enterkeyhint' => 'Next']],
            ['location',     'text',     'Location',         false, 150, null,
                'City, region, or "Remote".',
                'e.g. San Francisco, CA · Remote',
                ['autocomplete' => 'address-level2', 'enterkeyhint' => 'Next']],
            ['start_date',   'date',     'Start date',       false, 0, null,
                'When you started. Leave blank if unknown.'],
            ['end_date',     'date',     'End date',         false, 0, null,
                'When you finished. Leave blank if you still work here.'],
            ['is_current',   'check',    'I currently work here', false, 0, null,
                'Tick to suppress the end date and mark this as your current role.'],
            ['description',  'textarea', 'Description',      false, 2000, null,
                'A few bullets on what you did and the impact. Plain text, no formatting.',
                '• Led a team of 3 to …',
                [], 5],
        ],
        'visible_for' => fn(string $t) => in_array($t, ['bachelor','msc','phd'], true),
    ],

    'journal_articles' => [
        'title'       => 'Journal articles',
        'singular'    => 'Journal article',
        'description' => 'Peer-reviewed publications. List authors in publication order.',
        'fields'   => [
            ['title',   'text',     'Title',     true,  300, null,
                'Full article title as it appeared in print.',
                'e.g. Attention is all you need',
                ['autocomplete' => 'off', 'enterkeyhint' => 'Next']],
            ['authors', 'text',     'Authors',   false, 500, null,
                'Comma-separated list, e.g. "A. Vaswani, N. Shazeer, …".',
                'A. Vaswani, N. Shazeer, N. Parmar, …',
                ['autocomplete' => 'off', 'enterkeyhint' => 'Next']],
            ['journal', 'text',     'Journal',   false, 200, null,
                'Journal or publication name.',
                'e.g. Advances in Neural Information Processing Systems',
                ['autocomplete' => 'off', 'enterkeyhint' => 'Next']],
            ['year',    'year',     'Year',      false, 0, null,
                'Four-digit publication year.'],
            ['volume',  'text',     'Volume',    false, 40, null,
                'Volume number, if applicable.', 'e.g. 30'],
            ['issue',   'text',     'Issue',     false, 40, null,
                'Issue number, if applicable.', 'e.g. 1'],
            ['pages',   'text',     'Pages',     false, 40, null,
                'Page range, e.g. "5998–6008".', 'e.g. 5998–6008'],
            ['doi',     'text',     'DOI',       false, 150, null,
                'Digital Object Identifier, e.g. 10.1162/neco.2009.10-08-881.',
                '10.xxxx/yyyyy'],
            ['url',     'url',      'URL',       false, 500, null,
                'Public link to the paper or preprint.',
                'https://arxiv.org/abs/…',
                ['autocomplete' => 'url', 'inputmode' => 'url', 'enterkeyhint' => 'Done']],
        ],
        'visible_for' => fn(string $t) => in_array($t, ['msc','phd'], true),
    ],

    'conference_papers' => [
        'title'       => 'Conference papers',
        'singular'    => 'Conference paper',
        'description' => 'Presentations, posters, and proceedings.',
        'fields'   => [
            ['title',      'text',     'Title',      true,  300, null,
                'Title of the talk or paper.',
                'e.g. Sparse mixture-of-experts at scale',
                ['autocomplete' => 'off', 'enterkeyhint' => 'Next']],
            ['authors',    'text',     'Authors',    false, 500, null,
                'Comma-separated list of co-authors.'],
            ['conference', 'text',     'Conference', false, 200, null,
                'Conference or workshop name.',
                'e.g. NeurIPS 2024'],
            ['year',       'year',     'Year',       false, 0, null,
                'Year of presentation.'],
            ['location',   'text',     'Location',   false, 150, null,
                'City or "Virtual".', 'e.g. Vancouver, Canada'],
            ['doi',        'text',     'DOI',        false, 150, null,
                'DOI, if assigned.', '10.xxxx/yyyyy'],
            ['url',        'url',      'URL',        false, 500, null,
                'Public link to the slides, paper, or recording.',
                'https://…'],
        ],
        'visible_for' => fn(string $t) => in_array($t, ['msc','phd'], true),
    ],

    'awards' => [
        'title'       => 'Awards',
        'singular'    => 'Award',
        'description' => 'Honors, scholarships, and recognitions.',
        'fields'   => [
            ['title',        'text',     'Title',        true,  200, null,
                'Name of the award, fellowship, or scholarship.',
                'e.g. NSF Graduate Research Fellowship'],
            ['organization', 'text',     'Organization', false, 200, null,
                'Awarding body or sponsor.'],
            ['year',         'year',     'Year',         false, 0, null,
                'Year you received it.'],
            ['description',  'textarea', 'Description',  false, 2000, null,
                'Optional context: selection criteria, amount, scope…',
                'Selected from 12,000 applicants nationwide…', [], 4],
        ],
        'visible_for' => fn(string $t) => in_array($t, ['bachelor','msc','phd'], true),
    ],

    'skills' => [
        'title'       => 'Skills',
        'singular'    => 'Skill',
        'description' => 'Languages, frameworks, tools, and how confident you are with each.',
        'fields'   => [
            ['name',  'text',   'Skill',  true, 100, null,
                'A single skill, e.g. "Python" or "Figma".',
                'e.g. Python'],
            ['level', 'select', 'Level',  false, 0, [
                ''            => '— select a level —',
                'beginner'    => 'Beginner',
                'intermediate'=> 'Intermediate',
                'advanced'    => 'Advanced',
                'expert'      => 'Expert',
            ], 'Your honest self-assessment.'],
        ],
        'visible_for' => fn(string $t) => in_array($t, ['bachelor','msc','phd'], true),
    ],

    'certifications' => [
        'title'       => 'Certifications',
        'singular'    => 'Certification',
        'description' => 'Professional certificates and licenses.',
        'fields'   => [
            ['name',        'text',     'Name',         true,  200, null,
                'Full name of the certification.',
                'e.g. AWS Certified Solutions Architect – Associate'],
            ['issuing_org', 'text',     'Issued by',    false, 200, null,
                'The organization that issued it.',
                'e.g. Amazon Web Services'],
            ['issue_date',  'date',     'Issue date',   false, 0, null,
                'When you earned it.'],
            ['expiry_date', 'date',     'Expiry date',  false, 0, null,
                'When it expires, if applicable. Leave blank if it does not expire.'],
            ['credential_id','text',    'Credential ID',false, 100, null,
                'The verification code or credential ID.'],
            ['url',         'url',      'URL',          false, 500, null,
                'Public verification link.',
                'https://…'],
        ],
        'visible_for' => fn(string $t) => in_array($t, ['bachelor','msc','phd'], true),
    ],

    'research_interests' => [
        'title'       => 'Research interests',
        'singular'    => 'Research interest',
        'description' => 'Topics you want to explore or specialize in. One per row.',
        'fields'   => [
            ['name', 'text', 'Interest', true, 150, null,
                'A research topic or area.',
                'e.g. Reinforcement learning from human feedback'],
        ],
        'visible_for' => fn(string $t) => in_array($t, ['msc','phd'], true),
    ],

    'languages' => [
        'title'       => 'Languages',
        'singular'    => 'Language',
        'description' => 'Spoken languages and your proficiency in each.',
        'fields'   => [
            ['name',       'text',   'Language', true, 80, null,
                'A language you speak.',
                'e.g. French'],
            ['proficiency','select', 'Proficiency', false, 0, [
                ''                     => '— select proficiency —',
                'elementary'           => 'Elementary',
                'limited_working'      => 'Limited working',
                'professional_working' => 'Professional working',
                'full_professional'    => 'Full professional',
                'native'               => 'Native / bilingual',
            ], 'Closest match to your day-to-day ability.'],
        ],
        'visible_for' => fn(string $t) => in_array($t, ['bachelor','msc','phd'], true),
    ],
];

/** Return the list of section keys visible for a given degree_type. */
function visible_sections(string $degreeType): array
{
    global $SECTIONS;
    $out = [];
    foreach ($SECTIONS as $key => $def) {
        if (($def['visible_for'])($degreeType)) {
            $out[] = $key;
        }
    }
    return $out;
}

/** Load all visible sections for a user as [sectionKey => array of rows]. */
function load_sections_for_user(PDO $pdo, int $userId, string $degreeType, ?int $viewerId = null): array
{
    $out = [];
    foreach (visible_sections($degreeType) as $key) {
        $st = $pdo->prepare("SELECT * FROM `$key` WHERE user_id = :u ORDER BY sort_order, id");
        $st->execute(['u' => $userId]);
        $out[$key] = $st->fetchAll();
    }
    return $out;
}

/**
 * The profile completion checklist: a photo, the four "About" fields, and
 * at least one entry in every section visible for the degree type.
 * Shared by the dashboard and the profile page so both show the same number.
 *
 * @param array $profile       Row from `profiles` (photo, profession, bio, phone, address).
 * @param array $sectionCounts sectionKey => number of entries.
 * @return array<int, array{key: string, label: string, done: bool}>
 */
function profile_completion_steps(array $profile, array $sectionCounts, string $degreeType): array
{
    global $SECTIONS;
    $filled = fn(string $k): bool => trim((string)($profile[$k] ?? '')) !== '';
    $steps = [
        ['key' => 'photo',             'label' => 'Add a photo',         'done' => $filled('photo')],
        ['key' => 'basics:profession', 'label' => 'Add your profession', 'done' => $filled('profession')],
        ['key' => 'basics:bio',        'label' => 'Write a short bio',   'done' => $filled('bio')],
        ['key' => 'basics:phone',      'label' => 'Add a phone number',  'done' => $filled('phone')],
        ['key' => 'basics:address',    'label' => 'Add your address',    'done' => $filled('address')],
    ];
    foreach (visible_sections($degreeType) as $key) {
        $steps[] = [
            'key'   => 'section:' . $key,
            'label' => 'Add ' . lcfirst($SECTIONS[$key]['title']),
            'done'  => (int)($sectionCounts[$key] ?? 0) > 0,
        ];
    }
    return $steps;
}

/** Whitelist of editable column names per section, derived from $SECTIONS. */
function section_columns(string $sectionKey): array
{
    global $SECTIONS;
    if (!isset($SECTIONS[$sectionKey])) { return []; }
    $cols = ['sort_order'];
    foreach ($SECTIONS[$sectionKey]['fields'] as $f) {
        $cols[] = $f[0];
    }
    return array_values(array_unique($cols));
}

/**
 * Render an HTML input element for a single section field.
 * Used by both the editor and any code that needs to render a section
 * field outside of a full form.
 */
function render_section_input(string $sectionKey, int $rowIndex, string $name, array $field, ?string $value, array $errors = []): string
{
    // Field tuple: [name, type, label, required, max, options?, help?, placeholder?, input_attrs?, rows?]
    [$fname, $type, $label, $required, $max, $options, $help, $placeholder, $inputAttrs, $rows] = array_pad($field, 10, null);
    $inputId = "s_{$sectionKey}_{$rowIndex}_{$name}";
    $hasError = !empty($errors[$name]);
    $attrs = $inputAttrs ?: [];
    $aria = '';
    if ($required) { $aria .= ' aria-required="true"'; }
    if ($hasError)  { $aria .= ' aria-invalid="true"'; }
    if ($help)      { $aria .= ' aria-describedby="' . $inputId . '-help"'; }

    $common = ' class="field-input" id="' . $inputId . '" name="' . $name . '"' . $aria;
    if ($max > 0) { $common .= ' maxlength="' . (int)$max . '"'; }
    if ($placeholder) { $common .= ' placeholder="' . htmlspecialchars((string)$placeholder, ENT_QUOTES) . '"'; }
    foreach ($attrs as $k => $v) {
        $common .= ' ' . htmlspecialchars((string)$k, ENT_QUOTES) . '="' . htmlspecialchars((string)$v, ENT_QUOTES) . '"';
    }
    $valAttr = ' value="' . htmlspecialchars((string)$value, ENT_QUOTES) . '"';

    $out = '';
    switch ($type) {
        case 'textarea':
            $r = (int)($rows ?? 4);
            $out .= '<textarea' . $common . ' rows="' . $r . '">' . htmlspecialchars((string)$value, ENT_QUOTES) . '</textarea>';
            break;
        case 'select':
            $out .= '<select' . $common . '>';
            foreach (($options ?: []) as $optVal => $optLabel) {
                $sel = ((string)$optVal === (string)$value) ? ' selected' : '';
                $out .= '<option value="' . htmlspecialchars((string)$optVal, ENT_QUOTES) . '"' . $sel . '>' . htmlspecialchars((string)$optLabel, ENT_QUOTES) . '</option>';
            }
            $out .= '</select>';
            break;
        case 'check':
            $checked = !empty($value) ? ' checked' : '';
            $out .= '<label class="field-check"><input type="checkbox" id="' . $inputId . '" name="' . $name . '" value="1"' . $checked . $aria . '> <span>' . htmlspecialchars((string)$label, ENT_QUOTES) . '</span></label>';
            break;
        case 'year':
            $out .= '<input type="number" min="1900" max="2100" step="1" inputmode="numeric"' . $common . $valAttr . '>';
            break;
        case 'date':
            $out .= '<input type="date"' . $common . $valAttr . '>';
            break;
        case 'number':
            $out .= '<input type="number" step="any" inputmode="decimal"' . $common . $valAttr . '>';
            break;
        case 'url':
            $out .= '<input type="url"' . $common . $valAttr . '>';
            break;
        case 'email':
            $out .= '<input type="email"' . $common . $valAttr . '>';
            break;
        case 'tel':
            $out .= '<input type="tel"' . $common . $valAttr . '>';
            break;
        case 'text':
        default:
            $out .= '<input type="text"' . $common . $valAttr . '>';
            break;
    }
    if ($help) {
        $out .= '<p class="field-help" id="' . $inputId . '-help">' . htmlspecialchars((string)$help, ENT_QUOTES) . '</p>';
    }
    if ($hasError) {
        $out .= '<p class="field-error" id="' . $inputId . '-err" role="alert">' . htmlspecialchars((string)$errors[$name], ENT_QUOTES) . '</p>';
    }
    return $out;
}
