<?php
/**
 * Site audit: findings by kind, each with a link to fix it.
 *
 * @var Talea\Core\App $app
 * @var Talea\Admin\Modules\Audit $module
 * @var array<string, list<array<string, mixed>>> $groups
 * @var int $total
 * @var array{score: int, previous: ?int, passed: list<string>, failed: array<string, int>} $audit
 * @var string $category
 * @var array<string, int> $counts
 * @var array<string, mixed>|null $move  Core\MoveStatus: where a move of a site to this one stands; null when nothing was imported
 */
use Talea\Core\Audit;

$score = $audit['score'];
$level = $score >= 80 ? 'good' : ($score >= 50 ? 'work' : 'poor');
$shown = array_filter($audit['passed'], fn (string $k): bool => $category === '' || Audit::CATEGORY[$k] === $category);
$problems = $category === '' ? $total : ($counts[$category] ?? 0);
$tabs = ['' => t('All'), 'basics' => t('Basics'), 'advanced' => t('Advanced'), 'speed' => t('Speed'), 'security' => t('Security')];
?>
<div class="audit-summary">
	<div class="audit-ring audit-<?= $level ?>" style="--pct: <?= $score ?>" role="img" aria-label="<?= e(t('Site score: %d of 100', $score)) ?>"><strong><?= $score ?></strong></div>
	<div>
		<p><span class="badge audit-pill audit-<?= $level ?>"><?= e($level === 'good' ? t('Good') : ($level === 'work' ? t('Needs work') : t('Poor'))) ?></span><?php if ($audit['previous'] !== null): ?> <span class="small-text"><?= e(t('Last time: %d', $audit['previous'])) ?></span><?php endif ?></p>
		<div class="tiles">
			<div class="tiles-item"><strong><?= $problems ?></strong><span><?= e(t('problems')) ?></span></div>
			<div class="tiles-item"><strong><?= count($shown) ?></strong><span><?= e(t('checks passed')) ?></span></div>
		</div>
	</div>
</div>
<?php if ($move !== null): $report = $move['report']; ?>
<section class="panel-card">
<h2><?= e(t('Moving the site')) ?></h2>
<div class="tiles">
	<div class="tiles-item"><strong><?= (int) $move['pages_imported'] ?></strong><span><?= e(t('Imported pages')) ?></span></div>
	<div class="tiles-item"><strong><?= (int) $move['layout_pending'] ?></strong><span><?php if ($move['layout_pending'] > 0): ?><a href="<?= e($app->url('admin.php?module=pages&status=layout')) ?>"><?= e(t('Layout still to rebuild')) ?></a><?php else: ?><?= e(t('Layout still to rebuild')) ?><?php endif ?></span></div>
	<div class="tiles-item"><strong><?= (int) $move['starter_pages_left'] ?></strong><span><?php if ($move['starter_pages_left'] > 0): ?><a href="<?= e($app->url('admin.php?module=transfer')) ?>"><?= e(t('Starter pages left')) ?></a><?php else: ?><?= e(t('Starter pages left')) ?><?php endif ?></span></div>
	<div class="tiles-item"><strong><?= (int) $move['redirects'] ?></strong><span><?= e(t('Redirects')) ?></span></div>
	<div class="tiles-item"><strong><?= (int) $move['forms'] ?></strong><span><?= e(t('Forms')) ?></span></div>
</div>
<?php if ($report === null || !$report['done']): ?>
<p class="small-text"><?= e(t('No migration report yet – check the move before the domain is switched.')) ?> <a href="<?= e($app->url('admin.php?module=transfer')) ?>"><?= e(t('Import and export')) ?></a></p>
<?php elseif (!$report['reachable']): ?>
<p class="notice notice-error"><?= e(t('The old site could not be reached in the latest check.')) ?></p>
<?php elseif ($report['clean']): ?>
<p class="notice notice-ok"><?= e(t('The latest check of the move (%s) is clean.', format_date($report['checked_at'], true))) ?></p>
<?php else: ?>
<p class="notice"><?= e(t('The latest check of the move (%s) found %d errors and %d warnings.', format_date($report['checked_at'], true), (int) $report['errors'], (int) $report['warnings'])) ?></p>
<?php endif ?>
</section>
<?php endif ?>
<nav class="list-tabs" aria-label="<?= e(t('Categories')) ?>">
<?php foreach ($tabs as $slug => $label): ?>
	<a href="<?= e($module->url('', $slug === '' ? [] : ['category' => $slug])) ?>"<?= $slug === $category ? ' aria-current="page"' : '' ?>><?= e($label) ?> <span class="list-tab-count"><?= $slug === '' ? $total : ($counts[$slug] ?? 0) ?></span></a>
<?php endforeach ?>
</nav>
<p class="notice<?= $problems === 0 ? ' notice-ok' : '' ?>"><?= e($problems === 0 ? t('No problems found – links lead where they should, pages have descriptions and the builder checks pass.') : t('Found %d things to fix. Each one links to where you fix it; Claude can go through them too (site_audit).', $problems)) ?></p>
<?php foreach (Audit::KINDS as $kind => $name): if (($groups[$kind] ?? []) === []) { continue; } ?>
<h2><?= e(t($name)) ?> <span class="badge"><?= count($groups[$kind]) ?></span></h2>
<div class="tab-wrap"><table class="listing">
<thead><tr><th scope="col"><?= e(t('Where')) ?></th><th scope="col"><?= e(t('What')) ?></th><th scope="col"><?= e(t('Actions')) ?></th></tr></thead>
<tbody>
<?php foreach ($groups[$kind] as $f): ?>
<tr>
	<td><?= e($f['where']) ?></td>
	<td><?= e($f['message']) ?></td>
	<td class="actions"><a href="<?= e($f['edit']) ?>"><?= e(t('Fix')) ?></a><?php if ($f['url'] !== ''): ?> · <a href="<?= e($f['url']) ?>" target="_blank" rel="noopener"><?= e(t('Show on the site')) ?></a><?php endif ?></td>
</tr>
<?php endforeach ?>
</tbody></table></div>
<?php endforeach ?>
<?php if ($shown !== []): ?>
<details class="audit-passed">
<summary><?= e(t('%d checks passed', count($shown))) ?></summary>
<ul>
<?php foreach ($shown as $kind): ?><li><?= e(t(Audit::KINDS[$kind])) ?></li>
<?php endforeach ?>
</ul>
</details>
<?php endif ?>
<p class="small-text"><?= e(t('External links are checked in the background, one news item every five minutes. The audit runs each time you open this page.')) ?></p>
