<?php
/**
 * Search engines → Performance (Admin\Modules\SearchPerformance).
 *
 * @var Talea\Core\App $app
 * @var Talea\Admin\Modules\SearchPerformance $module
 * @var list<string> $connected connected search services
 * @var string $engine both | google | bing
 * @var array<string, mixed>|null $summary SearchInsights::summary
 * @var string $root the site's address with the installation's folder (SearchInsights::path)
 */
if ($connected === []): ?>
<div class="notice notice-warning">
	<p><?= e(t('Connect Google Search Console or Bing Webmaster Tools to see how people find your site.')) ?></p>
	<p class="buttons"><a class="navigation" href="<?= e($app->url('admin.php?module=connectors')) ?>"><?= e(t('Open connections')) ?></a></p>
</div>
<?php return; endif ?>
<div class="search-head">
<nav class="list-tabs" aria-label="<?= e(t('Search engine')) ?>">
<?php foreach (['both' => 'Both', 'google' => 'Google', 'bing' => 'Bing'] as $key => $name): if ($key === 'both' ? count($connected) < 2 : !in_array($key, $connected, true)) { continue; } ?>
	<a href="<?= e($module->url('', ['engine' => $key])) ?>"<?= $engine === $key ? ' aria-current="page"' : '' ?>><?= e($key === 'both' ? t($name) : $name) ?></a>
<?php endforeach ?>
</nav>
<p class="search-connections small-text">
<?php foreach (['google' => 'Google Search Console', 'bing' => 'Bing Webmaster Tools'] as $key => $name): $on = in_array($key, $connected, true) ?>
	<span class="status status-<?= $on ? 'live' : 'off' ?>"><?= e($name) ?><?= $on ? '' : ' – ' . e(t('not connected')) ?></span>
<?php endforeach ?>
</p>
</div>
<?php if ($summary === null): ?>
<p><?= e(t('The first data arrives after tonight’s update.')) ?></p>
<?php return; endif;
if ($stale): ?>
<p class="notice notice-warning"><?= e(t('Last updated on %s.', format_date($summary['day']))) ?></p>
<?php endif;
$position = fn (?float $p): string => $p === null || $p <= 0 ? '—' : format_count($p, 1);
$totals = $summary['totals'];
if ($totals === null): ?>
<p><?= e(t('No search data for the last 28 days.')) ?></p>
<?php else:
$previous = $summary['previous'];
$change = fn (string $field): string => Talea\Core\SearchInsights::change($totals, $previous, $field);
$tiles = [['Clicks', format_count($totals['clicks']), 'clicks'], ['Impressions', format_count($totals['impressions']), 'impressions'],
    ['Click-through rate', format_count($totals['ctr'], 1) . ' %', 'ctr'], ['Average position', $position($totals['position']), 'position']];
?>
<div class="tiles">
<?php foreach ($tiles as [$name, $value, $field]): ?>
	<div class="tiles-item"><strong><?= e($value) ?></strong><span><?= e(t($name)) ?><?php if (($c = $change($field)) !== ''): ?> · <?= e($c) ?><?php endif ?></span></div>
<?php endforeach ?>
</div>
<?php if ($previous !== null): ?><p class="small-text"><?= e(t('28 days up to %s, compared with the 28 days before.', format_date($summary['to']))) ?></p><?php endif ?>
<?php if (count($summary['days']) > 1): ?><div class="search-box"><?= $app->view->render('admin/search_performance/chart', ['days' => $summary['days']]) ?></div><?php endif ?>
<?php endif ?>

<?php
$pageAnchor = fn (array $page): string => '<a href="' . e($app->url('admin.php?module=pages&action=website&id=' . $page['public_id'])) . '">' . e($page['title']) . '</a>';
// a search URL to its page: this site's host and folder only, keyed like $pages (SearchInsights::path)
$pageLink = function (?string $url) use ($pages, $root, $pageAnchor): string {
    if ($url === null || $url === '') {
        return '';
    }
    $path = Talea\Core\SearchInsights::path($url, $root);
    $page = $path === null ? null : $pages[$path] ?? null;

    return $page === null ? '<code>' . e($url) . '</code>' : $pageAnchor($page);
};
?>
<div class="search-columns">
<section>
	<h2><?= e(t('Almost on the first page')) ?></h2>
	<?php if ($opportunities === []): ?><p><?= e(t('No search is close to the first page yet.')) ?></p><?php else: ?>
	<div class="tab-wrap"><table class="listing"><thead><tr><th scope="col"><?= e(t('Search')) ?></th><th scope="col" class="number"><?= e(t('Position')) ?></th><th scope="col" class="number"><?= e(t('Impressions')) ?></th><th scope="col"><?= e(t('Page')) ?></th></tr></thead><tbody>
	<?php foreach ($opportunities as $o): ?>
		<tr><td><?= e($o['query']) ?></td><td class="number"><?= e($position($o['position'])) ?></td><td class="number"><?= e(format_count($o['impressions'])) ?></td><td><?= $pageLink($o['page']) ?></td></tr>
	<?php endforeach ?>
	</tbody></table></div>
	<?php endif ?>
</section>
<section>
	<h2><?= e(t('Seen often and clicked rarely')) ?></h2>
	<?php if ($lowCtr === []): ?><p><?= e(t('No page stands out.')) ?></p><?php else: ?>
	<div class="tab-wrap"><table class="listing"><thead><tr><th scope="col"><?= e(t('Page')) ?></th><th scope="col" class="number"><?= e(t('Impressions')) ?></th><th scope="col" class="number"><?= e(t('Click-through rate')) ?></th><th scope="col" class="number"><?= e(t('Position')) ?></th></tr></thead><tbody>
	<?php foreach ($lowCtr as $r): ?>
		<tr><td><?= $pageLink($r['page']) ?></td><td class="number"><?= e(format_count($r['impressions'])) ?></td><td class="number"><?= e(format_count($r['ctr'], 1)) ?> %</td><td class="number"><?= e($position($r['position'])) ?></td></tr>
	<?php endforeach ?>
	</tbody></table></div>
	<?php endif ?>
</section>
</div>

<h2><?= e(t('Top searches')) ?></h2>
<?php if ($queries === []): ?><p><?= e(t('No data yet.')) ?></p><?php else: ?>
<div class="tab-wrap"><table class="listing"><thead><tr><th scope="col"><?= e(t('Search')) ?></th><th scope="col" class="number"><?= e(t('Clicks')) ?></th><th scope="col" class="number"><?= e(t('Impressions')) ?></th><th scope="col" class="number"><?= e(t('Position')) ?></th><th scope="col"></th></tr></thead><tbody>
<?php foreach ($queries as $q): ?>
	<tr><td><?= e($q['key']) ?></td><td class="number"><?= e(format_count((int) $q['clicks'])) ?></td><td class="number"><?= e(format_count((int) $q['impressions'])) ?></td><td class="number"><?= e($position((float) $q['position'])) ?></td>
	<td><?php if (isset($focus[mb_strtolower((string) $q['key'])])): ?><span class="badge badge-published"><?= e(t('Focus keyword')) ?></span><?php else: ?><a href="<?= e($app->url('admin.php?' . http_build_query(['module' => 'pages', 'search' => $q['key']]))) ?>"><?= e(t('Use for a page')) ?></a><?php endif ?></td></tr>
<?php endforeach ?>
</tbody></table></div>
<?php endif ?>

<h2><?= e(t('Top pages')) ?></h2>
<?php if ($top === []): ?><p><?= e(t('No data yet.')) ?></p><?php else: ?>
<div class="tab-wrap"><table class="listing"><thead><tr><th scope="col"><?= e(t('Page')) ?></th><th scope="col" class="number"><?= e(t('Clicks')) ?></th><th scope="col" class="number"><?= e(t('Impressions')) ?></th><th scope="col" class="number"><?= e(t('Position')) ?></th><th scope="col" class="number"><?= e(t('Score')) ?></th></tr></thead><tbody>
<?php foreach ($top as $path => $r): $page = $pages[$path] ?? null ?>
	<tr><td><?= $page === null ? '<code>/' . e((string) $path) . '</code>' : $pageAnchor($page) ?></td><td class="number"><?= e(format_count($r['clicks'])) ?></td><td class="number"><?= e(format_count($r['impressions'])) ?></td><td class="number"><?= e($position($r['position'])) ?></td><td class="number"><?= $page === null || $page['seo_score'] === null ? '—' : e((string) $page['seo_score']) ?></td></tr>
<?php endforeach ?>
</tbody></table></div>
<?php endif ?>

<?php if ($sitemaps !== [] || $notInSearch !== null): ?>
<h2><?= e(t('In the search index')) ?></h2>
<?php foreach ($sitemaps as $s): ?>
<p><code><?= e($s['path']) ?></code> – <?= e(t('%s of %s addresses indexed.', format_count($s['indexed']), format_count($s['submitted']))) ?></p>
<?php endforeach;
if ($notInSearch !== null): ?>
<p><?= e($notInSearch === [] ? t('Every published page has appeared in search.') : t('%s published pages have not appeared in search yet.', count($notInSearch))) ?></p>
<?php endif;
endif ?>
