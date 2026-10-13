<?php
declare(strict_types=1);

/**
 * Render the visible profile sections for a given user.
 * Used by any caller that needs to render a user's own profile data
 * (e.g. the editor's preview / future print views).
 *
 * Each section <section> element gets `data-section-accent` so the
 * stylesheet can color the left border without hard-coding hex values
 * per section.
 *
 * Profiles are private: there is no per-row `visible_to_public` filter
 * here — the owner sees every row they entered.
 */

require_once __DIR__ . '/profile_sections.php';

/**
 * @param string $degreeType One of: 'bachelor' | 'msc' | 'phd'.
 * @param array  $sections   Output of load_sections_for_user().
 * @param bool   $viewerIsOwner Whether the viewer is the profile owner.
 * @param bool   $viewerIsLoggedIn Whether anyone is signed in.
 */
function render_sections(string $degreeType, array $sections, bool $viewerIsOwner = false, bool $viewerIsLoggedIn = false): void
{
    $tagSections = ['skills', 'research_interests', 'languages'];

    foreach (visible_sections($degreeType) as $key) {
        if (in_array($key, $tagSections, true)) { continue; }
        $rows = $sections[$key] ?? [];
        if (!$rows) { continue; }
        $def = $GLOBALS['SECTIONS'][$key];
        $accent = section_accent($key);
        echo '<section class="profile-section public-section" id="sec-' . e($key) . '" data-section="' . e($key) . '" data-section-accent="' . e($accent) . '">';
        echo '  <header class="public-section-head">';
        echo '    <h2>' . e($def['title']) . '</h2>';
        echo '    <span class="public-section-count" aria-label="' . (int)count($rows) . ' entries">' . count($rows) . '</span>';
        echo '  </header>';
        echo '  <div class="public-section-body">';
        echo '    <ul class="list">';
        foreach ($rows as $row) {
            echo '<li class="item public-section-item">';
            render_section_row($key, $row);
            echo '</li>';
        }
        echo '    </ul>';
        echo '  </div>';
        echo '</section>';
    }
}

function render_section_row(string $sectionKey, array $row): void
{
    switch ($sectionKey) {
        case 'educations':
            echo '<div class="item-title">' . e($row['degree']) . ($row['field_of_study'] ? ', ' . e($row['field_of_study']) : '') . '</div>';
            echo '<div class="item-meta">' . e($row['institution']);
            $yrs = trim(($row['start_year'] ?? '') . (isset($row['end_year']) && $row['end_year'] ? ' – ' . $row['end_year'] : ''));
            if ($yrs !== '' && $yrs !== ' – ') { echo ' · ' . e($yrs); }
            echo '</div>';
            if (!empty($row['description'])) { echo '<div class="item-body">' . nl2br(e($row['description'])) . '</div>'; }
            break;

        case 'work_experiences':
            echo '<div class="item-title">' . e($row['role_title']) . ' · ' . e($row['organization']) . '</div>';
            $dates = '';
            if (!empty($row['start_date'])) { $dates .= date('M Y', strtotime($row['start_date'])); }
            if (!empty($row['end_date']))   { $dates .= ' – ' . date('M Y', strtotime($row['end_date'])); }
            elseif (!empty($row['is_current'])) { $dates .= ' – Present'; }
            if ($dates !== '') { echo '<div class="item-meta">' . e($dates) . (!empty($row['location']) ? ' · ' . e($row['location']) : '') . '</div>'; }
            elseif (!empty($row['location'])) { echo '<div class="item-meta">' . e($row['location']) . '</div>'; }
            if (!empty($row['description'])) { echo '<div class="item-body">' . nl2br(e($row['description'])) . '</div>'; }
            break;

        case 'journal_articles':
            echo '<div class="item-title">';
            if (!empty($row['url'])) {
                echo '<a class="item-title-link" href="' . e($row['url']) . '" target="_blank" rel="noopener">' . e($row['title']) . '</a>';
            } else {
                echo e($row['title']);
            }
            echo '</div>';
            $meta = array_filter([$row['authors'] ?? '', $row['journal'] ?? '', $row['year'] ?? '']);
            if ($meta) { echo '<div class="item-meta">' . e(implode(' · ', $meta)) . '</div>'; }
            $links = [];
            if (!empty($row['doi'])) { $links[] = '<a class="item-link" href="https://doi.org/' . e($row['doi']) . '" target="_blank" rel="noopener">doi:' . e($row['doi']) . '</a>'; }
            if (!empty($row['url']) && empty($row['title'])) { $links[] = '<a class="item-link" href="' . e($row['url']) . '" target="_blank" rel="noopener">link</a>'; }
            if ($links) { echo '<div class="item-body">' . implode(' &nbsp; ', $links) . '</div>'; }
            break;

        case 'conference_papers':
            echo '<div class="item-title">';
            if (!empty($row['url'])) {
                echo '<a class="item-title-link" href="' . e($row['url']) . '" target="_blank" rel="noopener">' . e($row['title']) . '</a>';
            } else {
                echo e($row['title']);
            }
            echo '</div>';
            $meta = array_filter([$row['authors'] ?? '', $row['conference'] ?? '', $row['year'] ?? '', $row['location'] ?? '']);
            if ($meta) { echo '<div class="item-meta">' . e(implode(' · ', $meta)) . '</div>'; }
            $links = [];
            if (!empty($row['doi'])) { $links[] = '<a class="item-link" href="https://doi.org/' . e($row['doi']) . '" target="_blank" rel="noopener">doi:' . e($row['doi']) . '</a>'; }
            if ($links) { echo '<div class="item-body">' . implode(' &nbsp; ', $links) . '</div>'; }
            break;

        case 'awards':
            echo '<div class="item-title">' . e($row['title']) . '</div>';
            $meta = array_filter([$row['organization'] ?? '', $row['year'] ?? '']);
            if ($meta) { echo '<div class="item-meta">' . e(implode(' · ', $meta)) . '</div>'; }
            if (!empty($row['description'])) { echo '<div class="item-body">' . nl2br(e($row['description'])) . '</div>'; }
            break;

        case 'skills':
            echo '<div class="item-title">' . e($row['name']);
            if (!empty($row['level'])) { echo ' <span class="muted">· ' . e(ucfirst($row['level'])) . '</span>'; }
            echo '</div>';
            break;

        case 'certifications':
            echo '<div class="item-title">' . e($row['name']) . '</div>';
            $meta = array_filter([
                $row['issuing_org'] ?? '',
                !empty($row['issue_date']) ? 'Issued ' . date('M Y', strtotime($row['issue_date'])) : '',
                !empty($row['expiry_date']) ? 'Expires ' . date('M Y', strtotime($row['expiry_date'])) : '',
            ]);
            if ($meta) { echo '<div class="item-meta">' . e(implode(' · ', $meta)) . '</div>'; }
            if (!empty($row['url'])) { echo '<div class="item-body"><a class="item-link" href="' . e($row['url']) . '" target="_blank" rel="noopener">Verify</a></div>'; }
            break;

        case 'research_interests':
            echo '<div class="item-title">' . e($row['name']) . '</div>';
            break;

        case 'languages':
            echo '<div class="item-title">' . e($row['name']);
            if (!empty($row['proficiency'])) { echo ' <span class="muted">· ' . e(ucwords(str_replace('_', ' ', $row['proficiency']))) . '</span>'; }
            echo '</div>';
            break;
    }
}

/** Compact tag-style renderer (e.g. skills, languages, research_interests). */
function render_section_tags(string $sectionKey, array $rows, bool $viewerIsOwner = false): void
{
    if (!$rows) { return; }
    $def = $GLOBALS['SECTIONS'][$sectionKey];
    $accent = section_accent($sectionKey);
    echo '<section class="profile-section public-section" id="sec-' . e($sectionKey) . '" data-section="' . e($sectionKey) . '" data-section-accent="' . e($accent) . '">';
    echo '  <header class="public-section-head">';
    echo '    <h2>' . e($def['title']) . '</h2>';
    echo '    <span class="public-section-count" aria-label="' . (int)count($rows) . ' entries">' . count($rows) . '</span>';
    echo '  </header>';
    echo '  <div class="public-section-body">';
    echo '    <ul class="tag-list">';
    foreach ($rows as $row) {
        $level = '';
        if (!empty($row['level']))      { $level = ucfirst($row['level']); }
        elseif (!empty($row['proficiency'])) { $level = ucwords(str_replace('_', ' ', $row['proficiency'])); }
        $aria = $level !== '' ? ' aria-label="' . e($row['name']) . ' (' . e($level) . ')"' : '';
        echo '<li class="tag tag-chip" data-level="' . e(strtolower($level)) . '"' . $aria . '><span class="tag-name">' . e($row['name']) . '</span>';
        if ($level !== '') { echo ' <span class="tag-level">' . e($level) . '</span>'; }
        echo '</li>';
    }
    echo '    </ul>';
    echo '  </div>';
    echo '</section>';
}

/**
 * Format a Unix timestamp as a short relative time string
 * (e.g. "5 minutes ago", "yesterday", "3 days ago").
 * Used as a fallback when Intl.RelativeTimeFormat is unavailable in PHP.
 */
function format_relative_time(int $ts): string
{
    $diff = time() - $ts;
    if ($diff < 0)     { $diff = -$diff; $suffix = 'from now'; $prefix = 'in ';}
    else               { $suffix = 'ago'; $prefix = ''; }
    if ($diff < 60)    { return $diff < 5 ? 'just now' : ($prefix . $diff . ' seconds ' . $suffix); }
    if ($diff < 3600)  { $n = (int)floor($diff / 60);   return $prefix . $n . ' minute' . ($n === 1 ? '' : 's') . ' ' . $suffix; }
    if ($diff < 86400) { $n = (int)floor($diff / 3600); return $prefix . $n . ' hour' . ($n === 1 ? '' : 's') . ' ' . $suffix; }
    if ($diff < 604800){ $n = (int)floor($diff / 86400);return $prefix . $n . ' day' . ($n === 1 ? '' : 's') . ' ' . $suffix; }
    if ($diff < 2592000){ $n = (int)floor($diff / 604800); return $prefix . $n . ' week' . ($n === 1 ? '' : 's') . ' ' . $suffix; }
    return date('M j, Y', $ts);
}
