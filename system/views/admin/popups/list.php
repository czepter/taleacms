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

echo $app->view->render('admin/list/head', ['count' => (string) count($popups), 'primary' => [$module->url('new'), t('New pop-up')]]);
?>
<?php if ($popups === []): ?>
<?= $app->view->render('admin/empty', ['icon' => 'popups', 'heading' => t('No pop-ups yet.'), 'text' => t('A window over the page for a newsletter sign-up, a download, an announcement or an offer. You build the content in the builder and set when and where it shows. The counters work without cookies.'), 'action' => [$module->url('new'), t('Create a pop-up')]]) ?>
<?php else: ?>
<div class="tab-wrap">
<table class="listing">
<thead><tr><th scope="col"><?= e(t('On')) ?></th><th scope="col"><?= e(t('Pop-up')) ?></th><th scope="col"><?= e(t('When it shows')) ?></th><th scope="col"><?= e(t('Effect')) ?></th><th scope="col"><span class="visually-hidden"><?= e(t('Actions')) ?></span></th></tr></thead>
<tbody>
<?php foreach ($popups as $p):
    $unit = Popups::TRIGGERS[$p['trigger_type']][1] ?? '';
    $when = t(Popups::TRIGGERS[$p['trigger_type']][0] ?? '') . ($unit !== '' ? ': ' . $p['value'] . ' ' . t($unit) : '');
    $where = ($p['rules']['where'] === 'all' ? t('on the whole site') : t('in selected places')) . ($p['trigger_type'] !== 'click' ? ' · ' . t(Popups::FREQUENCIES[$p['frequency']] ?? '') : '');
    $builder = $module->url('builder', ['id' => $p['public_id']]);
    $notes = [];
    if ($p['build_draft'] !== null && $p['build'] !== null && $p['build_draft'] !== $p['build']) {
        $notes[] = ['text' => t('Changes not published'), 'url' => $builder];
    }
    if ($p['valid_until']) {
        $notes[] = ['text' => t('visible until %s', format_date($p['valid_until']))];
    }
    if ($p['review_by']) {
        $notes[] = ['text' => t('review by %s', format_date($p['review_by']))];
    }
    $views = (int) $p['impressions'];
    $rate = $views > 0 ? (int) round($p['conversions'] / $views * 100) : 0;
?>
<tr>
	<td><?php if ($p['build'] === null): ?><span class="switch-placeholder" title="<?= e(t('Publish the pop-up in the builder first – then you can turn it on.')) ?>"><span class="visually-hidden"><?= e(t('unpublished')) ?></span></span><?php else: ?><?= $app->view->render('admin/list/switch', ['action' => $module->url('toggle'), 'fields' => ['popup_id' => $p['public_id']], 'on' => (bool) $p['active'], 'label' => t('%s on', $p['name']), 'csrf' => $csrf]) ?><?php endif ?></td>
	<td class="cell-main"><div class="cell-text"><a class="cell-title" href="<?= e($builder) ?>"><?= e($p['name']) ?></a><span class="cell-sub"><?= e(t(Popups::TYPES[$p['type']][0] ?? '')) ?> · <code>#popup-<?= e($p['slug']) ?></code></span><?php foreach ($notes as $n): ?><?= !empty($n['url']) ? '<a class="status-note status-note-warning" href="' . e($n['url']) . '">' . e($n['text']) . '</a>' : '<span class="status-note status-note-warning">' . e($n['text']) . '</span>' ?><?php endforeach ?></div></td>
	<td><div class="cell-text"><span><?= e($when) ?></span><span class="cell-sub"><?= e($where) ?></span></div></td>
	<td><?php if ($views === 0): ?><span class="cell-sub"><?= e(t('no views yet')) ?></span><?php else: ?><div class="effect">
		<span class="effect-head"><span><?= e(t('%d conversions', (int) $p['conversions'])) ?></span><span class="cell-sub"><?= e(t('%d%%', $rate)) ?></span></span>
		<meter min="0" max="100" value="<?= $rate ?>" aria-label="<?= e(t('Conversion rate')) ?>"><?= $rate ?> %</meter>
		<span class="cell-sub"><?= e(t('%d views · %d closed', $views, (int) $p['closes'])) ?></span>
	</div><?php endif ?></td>
	<td class="cell-actions"><?= $app->view->render('admin/list/actions', ['id' => $p['public_id'], 'name' => $p['name'], 'edit' => $builder, 'csrf' => $csrf, 'menu' => [
        ['url' => $module->url('edit', ['id' => $p['public_id']]), 'label' => t('Settings')],
        ['post' => $module->url('reset'), 'fields' => ['popup_id' => $p['public_id']], 'label' => t('Reset the counters'), 'icon' => 'restore', 'confirm' => t('Reset the counters of views, closes and conversions?')], '-',
        ['post' => $module->url('delete'), 'fields' => ['popup_id' => $p['public_id']], 'label' => t('Delete'), 'confirm' => t('Delete the pop-up? It disappears from the site at once.'), 'danger' => true],
    ]]) ?></td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<p class="help"><?= e(t('A conversion is a form sent or a newsletter sign-up in the pop-up. The counters work without cookies and without any visitor data.')) ?></p>
<?php endif ?>
