<?php
/**
 * @var Talea\Core\App $app
 * @var Talea\Admin\Modules\ShortLinks $module
 * @var string $csrf
 * @var list<array<string, mixed>> $links  rows plus label_target, is_public, address
 * @var bool $filtered  only the links of one content
 */
$icon = require __DIR__ . '/../icons.php';
echo $app->view->render('admin/list/head', ['count' => (string) count($links), 'primary' => [$module->url('', ['new' => 1]), t('New short link')]]);
?>
<?php if ($filtered): ?>
<p class="navigation-row"><a class="navigation" href="<?= e($module->url()) ?>">← <?= e(t('All short links')) ?></a></p>
<?php endif ?>
<?php if ($links === []): ?>
<?= $app->view->render('admin/empty', ['icon' => 'redirects', 'heading' => t('No short links yet.'), 'text' => t('A short link is a short address for print, such as a flyer or a poster, with a QR code. It leads to a page or any address and adds campaign parameters that stay off the paper.')]) ?>
<?php else: ?>
<div class="tab-wrap">
<table class="listing">
<thead><tr><th scope="col"><?= e(t('Short address')) ?></th><th scope="col"><?= e(t('Target')) ?></th><th scope="col"><?= e(t('Campaign')) ?></th><th scope="col"><?= e(t('Used')) ?></th><th scope="col"><span class="visually-hidden"><?= e(t('Actions')) ?></span></th></tr></thead>
<tbody>
<?php foreach ($links as $n => $z): $editUrl = $module->url('', ['edit' => $z['public_id']]); $utm = array_filter(array_map(fn (string $k): string => (string) $z[$k], Talea\Core\ShortLinks::UTM), fn (string $v): bool => $v !== ''); ?>
<tr>
	<td class="cell-main"><div class="cell-row"><a class="cell-title cell-code" href="<?= e($editUrl) ?>">/<?= e($z['path']) ?></a> <code class="visually-hidden" id="short-link-<?= $n ?>"><?= e($z['address']) ?></code><button class="navigation" type="button" data-copy="#short-link-<?= $n ?>"><?= e(t('Copy')) ?></button></div>
		<?= $z['label'] !== '' ? '<span class="cell-sub">' . e($z['label']) . '</span>' : '' ?></td>
	<td><span class="cell-row"><?= $icon('out') ?><span class="cell-sub" title="<?= e($z['label_target']) ?>"><?= e($z['label_target']) ?></span><?= !$z['is_public'] ? ' <span class="tag" title="' . e(t('The content is hidden or in the trash: visitors of the short link land on the home page.')) . '">' . e(t('Target not public')) . '</span>' : '' ?></span></td>
	<td class="cell-weak"><?= e(implode(' / ', $utm)) ?></td>
	<td class="number"><?= (int) $z['hits'] ?>×<?= $z['last_hit_at'] !== null ? '<br><span class="cell-sub">' . e(format_ago($z['last_hit_at'])) . '</span>' : '' ?></td>
	<td class="cell-actions"><?= $app->view->render('admin/list/actions', ['id' => $z['public_id'], 'name' => '/' . $z['path'], 'edit' => $editUrl, 'csrf' => $csrf, 'menu' => [
        ['url' => $module->url('qr', ['id' => $z['public_id']]), 'label' => t('QR code (SVG)'), 'icon' => 'download'],
        '-',
        ['post' => $module->url('delete'), 'fields' => ['short_link_id' => $z['public_id']], 'label' => t('Delete'), 'confirm' => t('Delete the short link? Printed copies of it will then end with a 404 error.'), 'danger' => true],
    ]]) ?></td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<?php endif ?>
