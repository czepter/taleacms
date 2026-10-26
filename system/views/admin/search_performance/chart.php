<?php
/**
 * Line chart of clicks (solid) and impressions (dashed) by day; points are built here, no script.
 *
 * @var list<array{day: string, clicks: int, impressions: int}> $days
 */
$count = count($days);
$points = function (string $field) use ($days, $count): string {
    $max = max(1, ...array_column($days, $field));
    $out = [];
    foreach ($days as $i => $d) {
        $out[] = round($count > 1 ? 1000 * $i / ($count - 1) : 500, 1) . ',' . round(196 - 192 * $d[$field] / $max, 1);
    }

    return implode(' ', $out);
};
$best = $days === [] ? null : array_reduce($days, fn (?array $c, array $d): array => $c === null || $d['clicks'] > $c['clicks'] ? $d : $c);
$label = $best === null ? '' : t('%s clicks and %s impressions over %s days; the most clicks on %s (%s).', format_count(array_sum(array_column($days, 'clicks'))), format_count(array_sum(array_column($days, 'impressions'))), $count, format_date($best['day']), format_count($best['clicks']));
if ($count < 2) {
    return;
}
?>
<svg class="search-chart" viewBox="0 0 1000 200" preserveAspectRatio="none" role="img" aria-label="<?= e($label) ?>">
	<polyline class="search-chart-impressions" points="<?= e($points('impressions')) ?>"/>
	<polyline class="search-chart-clicks" points="<?= e($points('clicks')) ?>"/>
</svg>
<p class="search-legend small-text"><span class="search-legend-clicks"><?= e(t('Clicks')) ?></span> <span class="search-legend-impressions"><?= e(t('Impressions')) ?></span></p>
