<?php
declare(strict_types=1);

/**
 * Small charts rendered as HTML/CSS (no canvas, no chart library), so they
 * are responsive, theme with the site, and work without JavaScript. The
 * x-chart directive (resources/js/ui.js) adds the hover / focus tooltip.
 * Styles: resources/css/components/chart.css. Every chart has a
 * "Show as table" twin, so no value is only reachable by hovering.
 *
 *   chart_columns()  stacked columns over time (one column per day or hour)
 *   chart_heatmap()  weekday × hour grid in one blue ramp
 *   chart_bars()     horizontal bars, one hue, value at the tip
 *
 * Colours are roles, not hex: series take a CSS variable name
 * (--series-1 … --series-3, --series-alert, --series-muted) defined per
 * theme in chart.css. The palette was checked with the data-viz validator
 * against the site's card surfaces (#ffffff light, #1b1816 dark).
 */

require_once __DIR__ . '/auth.php'; // e()

/** A round axis maximum at or above $max: 1, 2, 5, 10, 20, 50 … */
function chart_nice_max(int $max): int
{
    if ($max <= 4) { return 4; }
    $pow = 10 ** (int)floor(log10($max));
    foreach ([1, 2, 2.5, 5, 10] as $m) {
        if ($m * $pow >= $max) { return (int)ceil($m * $pow); }
    }
    return 10 * $pow;
}

/** 1,284 -> "1.3K" from 1,000 up, for axis ticks. */
function chart_compact(int $n): string
{
    return $n >= 1000 ? rtrim(rtrim(number_format($n / 1000, 1), '0'), '.') . 'K' : (string)$n;
}

/**
 * Stacked columns.
 *   $series  [key => [label, css var]] in stack order, bottom first
 *   $points  list of ['label' => 'Oct 12', 'tick' => 'Oct 12' or '', 'values' => [key => n]]
 *   $opts    title, desc, unit (e.g. 'events'), id
 */
function chart_columns(array $series, array $points, array $opts): string
{
    $id = (string)($opts['id'] ?? 'chart' . bin2hex(random_bytes(3)));
    $unit = (string)($opts['unit'] ?? '');
    $max = 0; $total = 0;
    foreach ($points as $p) { $t = array_sum($p['values']); $max = max($max, $t); $total += $t; }
    $top = chart_nice_max($max);

    $h = '<figure class="chart" x-data x-chart aria-labelledby="' . e($id) . '-t">';
    $h .= '<figcaption class="chart-head"><span class="chart-title" id="' . e($id) . '-t">' . e((string)$opts['title']) . '</span>'
        . (!empty($opts['desc']) ? '<span class="chart-desc">' . e((string)$opts['desc']) . '</span>' : '') . '</figcaption>';
    if (count($series) > 1) {
        $h .= '<ul class="chart-legend">';
        foreach ($series as [$label, $var]) {
            $h .= '<li><span class="chart-key" style="background: var(' . e($var) . ')"></span>' . e($label) . '</li>';
        }
        $h .= '</ul>';
    }
    $h .= '<div class="chart-plot" role="img" aria-label="' . e((string)$opts['title'] . ': ' . number_format($total) . ' ' . $unit . ' in total; peak ' . $max . '. Use “Show as table” for every value.') . '">';
    $h .= '<div class="chart-grid" aria-hidden="true">';
    foreach ([$top, intdiv($top, 2), 0] as $tick) { $h .= '<span><b>' . chart_compact($tick) . '</b></span>'; }
    $h .= '</div><div class="chart-cols">';
    foreach ($points as $p) {
        $sum = array_sum($p['values']);
        $rows = [];
        foreach (array_reverse($series, true) as $k => [$label, $var]) {
            $rows[] = [$label, (int)($p['values'][$k] ?? 0), $var];
        }
        $tip = json_encode(['title' => $p['label'], 'total' => $sum . ($unit !== '' ? ' ' . $unit : ''), 'rows' => count($series) > 1 ? $rows : []]);
        $h .= '<div class="chart-col" tabindex="0" data-tip="' . e((string)$tip) . '" aria-label="' . e($p['label'] . ': ' . $sum . ' ' . $unit) . '">'
            . '<div class="chart-stack" style="height:' . ($top ? round(100 * $sum / $top, 2) : 0) . '%">';
        foreach (array_reverse($series, true) as $k => [, $var]) {
            $v = (int)($p['values'][$k] ?? 0);
            if ($v > 0) { $h .= '<span style="flex-grow:' . $v . ';background:var(' . e($var) . ')"></span>'; }
        }
        $h .= '</div></div>';
    }
    $h .= '</div></div><div class="chart-x' . (count($points) > 14 ? ' is-dense' : '') . '" aria-hidden="true">';
    foreach ($points as $p) { $h .= '<span>' . e((string)($p['tick'] ?? '')) . '</span>'; }
    $h .= '</div>';

    // The table twin: every value, readable without hovering.
    $h .= '<details class="chart-table"><summary>Show as table</summary><div class="table-scroll"><table class="table"><thead><tr><th scope="col">' . e((string)($opts['xLabel'] ?? 'Day')) . '</th>';
    foreach ($series as [$label]) { $h .= '<th scope="col" class="num">' . e($label) . '</th>'; }
    if (count($series) > 1) { $h .= '<th scope="col" class="num">Total</th>'; }
    $h .= '</tr></thead><tbody>';
    foreach (array_reverse($points) as $p) {
        $h .= '<tr><th scope="row">' . e($p['label']) . '</th>';
        foreach ($series as $k => $s) { $h .= '<td class="num">' . (int)($p['values'][$k] ?? 0) . '</td>'; }
        if (count($series) > 1) { $h .= '<td class="num font-semibold">' . array_sum($p['values']) . '</td>'; }
        $h .= '</tr>';
    }
    return $h . '</tbody></table></div></details></figure>';
}

/**
 * Weekday × hour heatmap. $grid[0..6][0..23] = count, 0 = Monday.
 * Seven steps of one blue ramp; an empty hour is the bare track.
 */
function chart_heatmap(array $grid, array $opts): string
{
    $id = (string)($opts['id'] ?? 'heat' . bin2hex(random_bytes(3)));
    $unit = (string)($opts['unit'] ?? '');
    $days = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];
    $long = ['Mondays', 'Tuesdays', 'Wednesdays', 'Thursdays', 'Fridays', 'Saturdays', 'Sundays'];
    $max = 0;
    foreach ($grid as $row) { $max = max($max, ...array_values($row)); }
    $step = fn(int $n): int => $n <= 0 || $max === 0 ? 0 : max(1, (int)ceil(7 * $n / $max));

    $h = '<figure class="chart" x-data x-chart aria-labelledby="' . e($id) . '-t">';
    $h .= '<figcaption class="chart-head"><span class="chart-title" id="' . e($id) . '-t">' . e((string)$opts['title']) . '</span>'
        . (!empty($opts['desc']) ? '<span class="chart-desc">' . e((string)$opts['desc']) . '</span>' : '') . '</figcaption>';
    $h .= '<div class="heat" role="img" aria-label="' . e((string)$opts['title'] . '. Use “Show as table” for every value.') . '">';
    $h .= '<span></span>';
    for ($hr = 0; $hr < 24; $hr++) { $h .= '<span class="heat-hour" aria-hidden="true">' . ($hr % 6 === 0 ? sprintf('%02d', $hr) : '') . '</span>'; }
    foreach ($days as $d => $label) {
        $h .= '<span class="heat-day" aria-hidden="true">' . $label . '</span>';
        for ($hr = 0; $hr < 24; $hr++) {
            $n = (int)($grid[$d][$hr] ?? 0);
            $tip = json_encode(['title' => $long[$d] . ', ' . sprintf('%02d:00–%02d:59', $hr, $hr), 'total' => $n . ($unit !== '' ? ' ' . $unit : ''), 'rows' => []]);
            $h .= '<span class="heat-cell" data-step="' . $step($n) . '" tabindex="-1" data-tip="' . e((string)$tip) . '"></span>';
        }
    }
    $h .= '</div><div class="heat-scale" aria-hidden="true"><span>Less</span>';
    for ($s = 0; $s <= 7; $s++) { $h .= '<span class="heat-cell" data-step="' . $s . '"></span>'; }
    $h .= '<span>More</span><span class="ml-auto">Busiest hour: ' . $max . ($unit !== '' ? ' ' . $unit : '') . '</span></div>';

    $h .= '<details class="chart-table"><summary>Show as table</summary><div class="table-scroll"><table class="table"><thead><tr><th scope="col">Hour</th>';
    foreach ($days as $label) { $h .= '<th scope="col" class="num">' . $label . '</th>'; }
    $h .= '</tr></thead><tbody>';
    for ($hr = 0; $hr < 24; $hr++) {
        $h .= '<tr><th scope="row">' . sprintf('%02d:00', $hr) . '</th>';
        foreach ($days as $d => $label) { $h .= '<td class="num">' . (int)($grid[$d][$hr] ?? 0) . '</td>'; }
        $h .= '</tr>';
    }
    return $h . '</tbody></table></div></details></figure>';
}

/**
 * Horizontal bars, largest first. $rows: list of ['label', 'value', 'href' (optional), 'sub' (optional)].
 * One series, so no legend: the title says what is measured.
 */
function chart_bars(array $rows, array $opts): string
{
    $id = (string)($opts['id'] ?? 'bars' . bin2hex(random_bytes(3)));
    $unit = (string)($opts['unit'] ?? '');
    $max = max(1, ...array_map(fn($r) => (int)$r['value'], $rows ?: [['value' => 1]]));
    $h = '<figure class="chart" aria-labelledby="' . e($id) . '-t">';
    $h .= '<figcaption class="chart-head"><span class="chart-title" id="' . e($id) . '-t">' . e((string)$opts['title']) . '</span>'
        . (!empty($opts['desc']) ? '<span class="chart-desc">' . e((string)$opts['desc']) . '</span>' : '') . '</figcaption>';
    if (!$rows) { return $h . '<p class="chart-empty">Nothing in this period.</p></figure>'; }
    $h .= '<ol class="bars">';
    foreach ($rows as $r) {
        $label = e((string)$r['label']);
        $name = !empty($r['href']) ? '<a href="' . e((string)$r['href']) . '">' . $label . '</a>' : $label;
        $h .= '<li><span class="bars-label" title="' . e((string)($r['sub'] ?? $r['label'])) . '">' . $name . '</span>'
            . '<span class="bars-track"><span class="bars-fill" style="width:' . round(100 * (int)$r['value'] / $max, 2) . '%"></span>'
            . '<span class="bars-value">' . number_format((int)$r['value']) . '</span></span></li>';
    }
    // The bars carry their numbers as text, so the list is its own table view.
    return $h . '</ol>' . (!empty($opts['note']) ? '<p class="chart-note">' . e((string)$opts['note']) . '</p>' : '') . '</figure>';
}
