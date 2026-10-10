<?php
/**
 * The site's popups with counters.
 *
 * @var Talea\Core\App $app
 * @var Talea\Admin\Modules\Popups $module
 * @var string $csrf
 * @var list<array<string, mixed>> $popups
 */
use Talea\Builder\Popups;

?>
<p class="navigation-row"><a class="btn" href="<?= e($module->url('new')) ?>"><?= e(t('New pop-up')) ?></a></p>
<?php if ($popups === []): ?>
<?= $app->view->render('admin/empty', ['icon' => 'popups', 'heading' => t('No pop-ups yet.'), 'text' => t('A window over the page for a newsletter sign-up, a download, an announcement or an offer. You build the content in the builder and set when and where it shows. The counters work without cookies.'), 'action' => [$module->url('new'), t('Create a pop-up')]]) ?>
<?php else: ?>
<div class="tab-wrap">
<table class="listing">
<thead><tr><th scope="col"><?= e(t('Pop-up')) ?></th><th scope="col"><?= e(t('When it shows')) ?></th><th scope="col"><?= e(t('Status')) ?></th><th scope="col"><?= e(t('Views')) ?></th><th scope="col"><?= e(t('Closes')) ?></th><th scope="col"><?= e(t('Conversions')) ?></th><th scope="col"><?= e(t('Actions')) ?></th></tr></thead>
<tbody>
<?php foreach ($popups as $p): ?>
<?php
    $unit = Popups::TRIGGERS[$p['trigger_type']][1] ?? '';
    $when = t(Popups::TRIGGERS[$p['trigger_type']][0] ?? '') . ($unit !== '' ? ': ' . $p['value'] . ' ' . t($unit) : '');
?>
<tr>
	<td><a href="<?= e($module->url('builder', ['id' => $p['public_id']])) ?>"><strong><?= e($p['name']) ?></strong></a><br><span class="help"><?= e(t(Popups::TYPES[$p['type']][0] ?? '')) ?> · <code>#popup-<?= e($p['slug']) ?></code></span></td>
	<td><?= e($when) ?><br><span class="help"><?= e($p['rules']['where'] === 'all' ? t('on the whole site') : t('in selected places')) ?><?= $p['trigger_type'] !== 'click' ? ' · ' . e(t(Popups::FREQUENCIES[$p['frequency']] ?? '')) : '' ?></span></td>
	<td><?php if ($p['active']): ?><span class="badge badge-published"><?= e(t('on')) ?></span><?php elseif ($p['build'] === null): ?><span class="badge badge-draft"><?= e(t('unpublished')) ?></span><?php else: ?><span class="badge"><?= e(t('off')) ?></span><?php endif ?><?= $p['build_draft'] !== null && $p['build'] !== null && $p['build_draft'] !== $p['build'] ? ' <span class="badge badge-draft">' . e(t('unpublished changes')) . '</span>' : '' ?><?= $p['valid_until'] ? ' <span class="badge badge-draft" title="' . e(t('Hides itself the day after.')) . '">' . e(t('true until %s', format_date($p['valid_until']))) . '</span>' : '' ?><?= $p['review_by'] ? ' <span class="badge badge-draft" title="' . e(t('Asks for a review on this day.')) . '">' . e(t('review by %s', format_date($p['review_by']))) . '</span>' : '' ?></td>
	<td class="number"><?= (int) $p['impressions'] ?></td>
	<td class="number"><?= (int) $p['closes'] ?></td>
	<td class="number"><?= (int) $p['conversions'] ?><?= $p['impressions'] > 0 ? ' <span class="help">(' . e(t('%d%%', (int) round($p['conversions'] / $p['impressions'] * 100))) . ')</span>' : '' ?></td>
	<td class="actions">
		<a href="<?= e($module->url('builder', ['id' => $p['public_id']])) ?>"><?= e(t('Edit in the builder')) ?></a> · <a href="<?= e($module->url('edit', ['id' => $p['public_id']])) ?>"><?= e(t('Settings')) ?></a>
		· <form class="inline" method="post" action="<?= e($module->url('toggle')) ?>"><?= $csrf ?><input type="hidden" name="popup_id" value="<?= e($p['public_id']) ?>"><button class="navigation" type="submit"><?= e($p['active'] ? t('Turn off') : t('Turn on')) ?></button></form>
	</td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<p class="help"><?= e(t('A conversion is a form sent or a newsletter sign-up in the pop-up. The counters work without cookies and without any visitor data.')) ?></p>
<?php endif ?>
