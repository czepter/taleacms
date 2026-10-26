<?php
/**
 * Site audit: findings by kind, each with a link to fix it.
 *
 * @var Talea\Admin\Modules\Audit $module
 * @var array<string, list<array<string, mixed>>> $groups
 * @var int $total
 * @var array{score: int, previous: ?int, passed: list<string>, failed: array<string, int>} $audit
 * @var string $category
 * @var array<string, int> $counts
 */
use Talea\Core\Audit;

$score = $audit['score'];
$level = $score >= 80 ? 'good' : ($score >= 50 ? 'work' : 'poor');
$tabs = ['' => t('All'), 'basics' => t('Basics'), 'advanced' => t('Advanced'), 'speed' => t('Speed'), 'security' => t('Security')];
?>
<div class="audit-summary">
	<div class="audit-ring audit-<?= $level ?>" style="--pct: <?= $score ?>" role="img" aria-label="<?= e(t('Site score: %d of 100', $score)) ?>"><strong><?= $score ?></strong></div>
	<div>
		<p><span class="badge audit-pill audit-<?= $level ?>"><?= e($level === 'good' ? t('Good') : ($level === 'work' ? t('Needs work') : t('Poor'))) ?></span><?php if ($audit['previous'] !== null): ?> <span class="small-text"><?= e(t('Last time: %d', $audit['previous'])) ?></span><?php endif ?></p>
		<div class="tiles">
			<div class="tiles-item"><strong><?= $total ?></strong><span><?= e(t('problems')) ?></span></div>
			<div class="tiles-item"><strong><?= count($audit['passed']) ?></strong><span><?= e(t('checks passed')) ?></span></div>
		</div>
	</div>
</div>
<nav class="list-tabs" aria-label="<?= e(t('Categories')) ?>">
<?php foreach ($tabs as $slug => $label): ?>
	<a href="<?= e($module->url('', $slug === '' ? [] : ['category' => $slug])) ?>"<?= $slug === $category ? ' aria-current="page"' : '' ?>><?= e($label) ?> <span class="list-tab-count"><?= $slug === '' ? $total : ($counts[$slug] ?? 0) ?></span></a>
<?php endforeach ?>
</nav>
<p class="notice<?= $total === 0 ? ' notice-ok' : '' ?>"><?= e($total === 0 ? t('No problems found – links lead where they should, pages have descriptions and the builder checks pass.') : t('Found %d things to fix. Each one links to where you fix it; Claude can go through them too (site_audit).', $total)) ?></p>
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
<?php $shown = array_filter($audit['passed'], fn (string $k): bool => $category === '' || Audit::CATEGORY[$k] === $category); if ($shown !== []): ?>
<details class="audit-passed">
<summary><?= e(t('%d checks passed', count($shown))) ?></summary>
<ul>
<?php foreach ($shown as $kind): ?><li><?= e(t(Audit::KINDS[$kind])) ?></li>
<?php endforeach ?>
</ul>
</details>
<?php endif ?>
<p class="small-text"><?= e(t('External links are checked in the background, one news item every five minutes. The audit runs each time you open this page.')) ?></p>
