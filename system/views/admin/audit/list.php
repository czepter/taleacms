<?php
/**
 * Site audit: findings by kind, each with a link to fix it.
 *
 * @var Talea\Admin\Modules\Audit $module
 * @var array<string, list<array<string, mixed>>> $groups
 * @var int $total
 */
use Talea\Core\Audit;

?>
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
<p class="small-text"><?= e(t('External links are checked in the background, one news item every five minutes. The audit runs each time you open this page.')) ?></p>
