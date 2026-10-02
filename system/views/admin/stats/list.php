<?php
/**
 * Statistics and leads (Core\Report, 2.3).
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\Stats $module
 * @var array<string, mixed> $report
 * @var bool $isEnabled
 */
$days = (int) $report['period_days'];
$chart = $report['days'];
$totals = $report['totals'];
$max = max(1, ...array_column($chart, 'views'));
$leads = fn (array $r): string => format_count((int) $r['enquiries']) . ' / ' . format_count((int) $r['signups']);
$table = function (string $title, array $rows, array $columns, string $empty = 'No data yet.', string $class = ''): void {
    echo '<div' . ($class !== '' ? ' class="' . $class . '"' : '') . '><h2>' . e(t($title)) . '</h2>';
    if ($rows === []) {
        echo '<p>' . e(t($empty)) . '</p></div>';

        return;
    }
    echo '<div class="tab-obal"><table class="vypis"><thead><tr>';
    foreach ($columns as $label => $_) {
        echo '<th scope="col"' . ($label === array_key_first($columns) ? '' : ' class="cislo"') . '>' . e(t($label)) . '</th>';
    }
    echo '</tr></thead><tbody>';
    foreach ($rows as $row) {
        echo '<tr>';
        foreach ($columns as $label => $cell) {
            echo '<td' . ($label === array_key_first($columns) ? '' : ' class="cislo"') . '>' . $cell($row) . '</td>';
        }
        echo '</tr>';
    }
    echo '</tbody></table></div></div>';
};
$percent = fn (?float $p): string => $p === null ? '–' : e(format_count($p, 1)) . ' %';
// real-user speed (Core\WebVitals): the p75 value with Google's rating as a badge
$ratingBadge = ['good' => ['stitek-vydano', 'good'], 'needs_improvement' => ['stitek-koncept', 'needs improvement'], 'poor' => ['stitek-chyba', 'poor']];
$vital = function (array $r, string $metric, callable $format) use ($ratingBadge): string {
    if ($r[$metric . '_p75'] === null) {
        return '–';
    }
    [$class, $label] = $ratingBadge[$r[$metric . '_rating']] ?? ['', $r[$metric . '_rating']];

    return e($format((float) $r[$metric . '_p75'])) . ' <span class="stitek ' . $class . '">' . e(t($label)) . '</span>';
};
?>
<?php if (!$isEnabled): ?>
<p class="hlaska hlaska-chyba"><?= e(t('Analytics is turned off. Turn it on in Settings → Analytics.')) ?></p>
<?php endif ?>
<nav class="zalozky" aria-label="<?= e(t('Period')) ?>">
<?php foreach (Kaleta\Core\Report::PERIODS as $d): ?>
	<a href="<?= e($module->url('', ['dni' => $d])) ?>"<?= $days === $d ? ' class="aktivni" aria-current="page"' : '' ?>><?= e(t('%s days', $d)) ?></a>
<?php endforeach ?>
</nav>
<div class="dlazdice">
	<div class="dlazdice-polozka"><strong><?= format_count((int) $totals['visits']) ?></strong><span><?= e(t('Visits')) ?></span></div>
	<div class="dlazdice-polozka"><strong><?= format_count((int) $totals['views']) ?></strong><span><?= e(t('Page views')) ?></span></div>
	<div class="dlazdice-polozka"><strong><?= format_count((int) $totals['enquiries'] + (int) $totals['signups']) ?></strong><span><?= e(t('Leads (enquiries and sign-ups)')) ?></span></div>
	<div class="dlazdice-polozka"><strong><?= $percent($totals['conversion']) ?></strong><span><?= e(t('Visits that became a lead')) ?></span></div>
</div>
<h2><?= e(t('Page views and visits by day')) ?></h2>
<div class="graf" role="img" aria-label="<?= e(t('Bar chart of page views by day')) ?>">
<?php foreach ($chart as $h): ?>
<?php $dayLabel = t('%s: %s page views, %s visits', format_date($h['day']), $h['views'], $h['visits']) . ($h['enquiries'] + $h['signups'] > 0 ? ', ' . t('%s leads', $h['enquiries'] + $h['signups']) : ''); ?>
	<div class="graf-sloupec" data-tip="<?= e($dayLabel) ?>" aria-label="<?= e($dayLabel) ?>" tabindex="0"><i data-tip-kotva style="height:<?= round($h['views'] / $max * 100, 1) ?>%"><b style="height:<?= $h['views'] > 0 ? round($h['visits'] / $h['views'] * 100, 1) : 0 ?>%"></b></i></div>
<?php endforeach ?>
</div>
<p class="smltxt"><?= e(format_date($chart[0]['day'])) ?> – <?= e(format_date($chart[count($chart) - 1]['day'])) ?> · <?= e(t('the light part of each bar is page views, the dark part visits. Measurement uses no cookies and stores no IP addresses; bots are not counted.')) ?></p>

<div class="stat-tabulky">
<?php
$table('Pages that bring leads', $report['pages'], [
    'Page' => fn (array $r): string => '<a href="' . e($r['path']) . '" target="_blank" rel="noopener">' . e($r['path']) . '</a>',
    'Views' => fn (array $r): string => format_count((int) $r['views']),
    'Enquiries / sign-ups' => $leads,
    'Conversion' => fn (array $r): string => $percent($r['conversion']),
]);
$table('Real-user speed (Core Web Vitals)', $report['web_vitals'], [
    'Page' => fn (array $r): string => '<a href="' . e($r['path']) . '" target="_blank" rel="noopener">' . e($r['path']) . '</a>',
    'Measurements' => fn (array $r): string => format_count((int) $r['samples']),
    'LCP' => fn (array $r): string => $vital($r, 'lcp', fn (float $v): string => format_count($v / 1000, 1) . ' s'),
    'CLS' => fn (array $r): string => $vital($r, 'cls', fn (float $v): string => rtrim(rtrim(format_count($v, 3), '0'), ',.')),
    'INP' => fn (array $r): string => $vital($r, 'inp', fn (float $v): string => format_count($v) . ' ms'),
], 'No measurements yet – they arrive from visitors’ browsers while the statistics are on.', 'stat-siroka');
$table('Campaigns', $report['campaigns'], [
    'Campaign (source / medium / name)' => fn (array $r): string => e($r['campaign']),
    'Visits' => fn (array $r): string => format_count((int) $r['visits']),
    'Enquiries / sign-ups' => $leads,
], 'No visits from links with utm parameters yet.');
$table('First pages of visits that brought a lead', $report['landing_pages'], [
    'Page' => fn (array $r): string => e($r['path']),
    'Enquiries / sign-ups' => $leads,
], 'Known only for visitors who allowed marketing cookies in the cookie bar.');
$table('Where visitors come from', $report['referrers'], [
    'Site' => fn (array $r): string => e($r['site']),
    'Visits' => fn (array $r): string => format_count((int) $r['visits']),
]);
$table('Devices', $report['devices'], [
    'Device' => fn (array $r): string => e(t(['phone' => 'Phone', 'tablet' => 'Tablet', 'computer' => 'Computer'][$r['device']] ?? $r['device'])),
    'Visits' => fn (array $r): string => format_count((int) $r['visits']),
]);
$table('Pop-ups', $report['popups'], [
    'Pop-up' => fn (array $r): string => e($r['popup']) . ($r['active'] ? '' : ' <span class="stitek">' . e(t('inactive')) . '</span>'),
    'Views' => fn (array $r): string => format_count((int) $r['views']),
    'Conversions' => fn (array $r): string => format_count((int) $r['conversions']),
    'Conversion' => fn (array $r): string => $percent($r['conversion']),
], 'The site has no pop-ups.');
$table('Most read news', $report['news'], [
    'News item' => fn (array $r): string => '<a href="' . e($app->url('admin.php?module=news&action=edit&id=' . (int) $r['id'])) . '">' . e($r['title']) . '</a>',
    'Views' => fn (array $r): string => format_count((int) $r['views']),
]);
?>
</div>
<p class="smltxt"><?= e(t('Pop-up counters run since the pop-up was made or reset. Claude reads the same report with get_stats.')) ?> <?= e(t('Speed: the 75th percentile of what real visitors experienced – loading of the main content (LCP, good up to 2.5 s), layout shifts (CLS, good up to 0.1) and the response to interaction (INP, good up to 200 ms); values are the upper edge of a histogram bucket, so they never flatter.')) ?></p>
