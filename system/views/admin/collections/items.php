<?php
/**
 * Collection items.
 *
 * @var Talea\Core\App $app
 * @var Talea\Admin\Modules\Collections $module
 * @var string $csrf
 * @var array<string, mixed> $k
 * @var list<array<string, mixed>> $items
 * @var list<string> $languages other languages of the site (a detail template for each one separately)
 * @var list<string> $siteLanguages all languages of the site for the filter (empty = a single language)
 * @var string $language language selected in the filter ('' = all)
 * @var bool $trash the trash is shown
 * @var bool $noticeBoard an official notice board (2.11): its notices are never deleted
 * @var int $inTrash items in the trash
 * @var array<int, array{0: int, 1: int}>|null $downloads  document library (2.11): idp => [downloads in 30 days, total]; null = not a library
 */
?>
<p class="navigation-row"><a class="btn" href="<?= e($module->url('item', ['id' => $k['public_id']])) ?>"><?= e(t('Add item')) ?></a>
	<a class="navigation" href="<?= e($module->url()) ?>"><?= e(t('All collections')) ?></a>
<?php if ($app->auth()->isAdmin()): ?>
	<a class="navigation" href="<?= e($module->url('edit', ['id' => $k['public_id']])) ?>"><?= e(t('Fields and settings')) ?></a>
<?php if ($k['detail']): ?>
	<a class="navigation" href="<?= e($module->url('builder', ['id' => $k['public_id']])) ?>"><?= e(t('Detail template')) ?></a>
<?php foreach ($languages as $language): ?>
	<a class="navigation" href="<?= e($module->url('builder', ['id' => $k['public_id'], 'language' => $language])) ?>"><?= e(t('Detail template (%s)', strtoupper($language))) ?></a>
<?php endforeach ?>
<?php endif ?>
<?php endif ?></p>
<?php if ($inTrash > 0 || $trash): ?>
<nav class="tabs" aria-label="<?= e(t('Items')) ?>">
	<a href="<?= e($module->url('items', ['id' => $k['public_id']])) ?>"<?= $trash ? '' : ' class="active" aria-current="true"' ?>><?= e(t('All')) ?></a>
	<a href="<?= e($module->url('items', ['id' => $k['public_id'], 'status' => 'trash'])) ?>"<?= $trash ? ' class="active" aria-current="true"' : '' ?>><?= e(t('Trash')) ?> (<?= $inTrash ?>)</a>
</nav>
<?php endif ?>
<?php if ($trash): ?>
<?php if ($items === []): ?>
<?= $app->view->render('admin/empty', ['icon' => 'collections', 'heading' => t('The trash is empty.'), 'text' => t('Deleted items stay here for 30 days, then they are deleted permanently.'), 'action' => [$module->url('items', ['id' => $k['public_id']]), t('Back to items')]]) ?>
<?php else: ?>
<p class="small-text"><?= e(t('Items in the trash are not on the site. A restored item comes back hidden; after 30 days it is permanently deleted from the trash.')) ?></p>
<div class="tab-wrap">
<table class="listing">
<thead><tr><th scope="col"><?= e(t('Name')) ?></th><th scope="col"><?= e(t('In trash since')) ?></th><th scope="col"><?= e(t('Actions')) ?></th></tr></thead>
<tbody>
<?php foreach ($items as $p): ?>
<tr class="unpublished">
	<td><?= e($p['name']) ?><?= $p['language'] !== '' ? ' <span class="badge">' . e(strtoupper($p['language'])) . '</span>' : '' ?></td>
	<td class="number"><?= e(format_date((string) $p['deleted_at'], true)) ?></td>
	<td class="actions">
		<form class="inline" method="post" action="<?= e($module->url('restore_item')) ?>"><?= $csrf ?><input type="hidden" name="collection_id" value="<?= e($k['public_id']) ?>"><input type="hidden" name="item_id" value="<?= e($p['public_id']) ?>"><button class="navigation" type="submit"><?= e(t('Restore')) ?></button></form> ·
		<form class="inline" method="post" action="<?= e($module->url('delete_item_permanently')) ?>" data-confirm="<?= e(t('Delete the item permanently? This cannot be undone.')) ?>"><?= $csrf ?><input type="hidden" name="collection_id" value="<?= e($k['public_id']) ?>"><input type="hidden" name="item_id" value="<?= e($p['public_id']) ?>"><button class="navigation danger" type="submit"><?= e(t('Delete permanently')) ?></button></form>
	</td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<?php endif ?>
<?php else: ?>
<?php if ($siteLanguages !== []): ?>
<form method="get" action="<?= e($app->url('admin.php')) ?>" class="center small-text"><input type="hidden" name="module" value="collections"><input type="hidden" name="action" value="items"><input type="hidden" name="id" value="<?= e($k['public_id']) ?>"><?= $app->view->render('admin/language_filter', ['siteLanguages' => $siteLanguages, 'language' => $language, 'submitOnChange' => true]) ?></form>
<?php endif ?>
<?php if ($items === [] && $language === ''): ?>
<?= $app->view->render('admin/empty', ['icon' => 'collections', 'heading' => t('The collection is empty.'), 'text' => t('Add the first item – then put it on the site with the Collection list element in the builder.'), 'action' => [$module->url('item', ['id' => $k['public_id']]), t('Add item')]]) ?>
<?php else: ?>
<div class="tab-wrap">
<table class="listing">
<thead><tr><th scope="col"><input type="checkbox" data-select-all="bulk" aria-label="<?= e(t('Select all')) ?>"></th><th scope="col"><?= e(t('Name')) ?></th><th scope="col"><?= e(t('Order')) ?></th><?php if ($downloads !== null): ?><th scope="col" title="<?= e(t('Downloads of the stable address /…/latest: the last 30 days / total. Bots are not counted.')) ?>"><?= e(t('Downloads (30 days / total)')) ?></th><?php endif ?><th scope="col"><?= e(t('Status')) ?></th><th scope="col"><?= e(t('Actions')) ?></th></tr></thead>
<tbody>
<?php foreach ($items as $p): ?>
<tr<?= $p['visible'] ? '' : ' class="unpublished"' ?>>
	<td><input type="checkbox" name="selected[]" value="<?= e($p['public_id']) ?>" form="bulk" aria-label="<?= e(t('Select %s', $p['name'])) ?>"></td>
	<td><a href="<?= e($module->url('item', ['id' => $k['public_id'], 'item' => $p['public_id']])) ?>"><?= e($p['name']) ?></a><?= $p['language'] !== '' ? ' <span class="badge">' . e(strtoupper($p['language'])) . '</span>' : '' ?><?= $p['valid_until'] ? ' <span class="badge badge-draft" title="' . e(t('Hides itself the day after.')) . '">' . e(t('true until %s', format_date($p['valid_until']))) . '</span>' : '' ?><?= $p['review_by'] ? ' <span class="badge badge-draft" title="' . e(t('Asks for a review on this day.')) . '">' . e(t('review by %s', format_date($p['review_by']))) . '</span>' : '' ?></td>
	<td><?= (int) $p['sort_order'] ?></td>
<?php if ($downloads !== null): ?>
	<td class="number download"><?= (int) ($downloads[(int) $p['item_id']][0] ?? 0) ?> / <?= (int) ($downloads[(int) $p['item_id']][1] ?? 0) ?></td>
<?php endif ?>
	<td><span class="badge badge-<?= $p['visible'] ? 'published' : 'draft' ?>"><?= e(t($p['visible'] ? 'published' : 'hidden')) ?></span></td>
	<td class="actions"><?php if ($k['detail'] && $p['visible']): ?><a href="<?= e($app->url(($p['language'] !== '' ? $p['language'] . '/' : '') . $k['slug'] . '/' . $p['slug'])) ?>" target="_blank" rel="noopener"><?= e(t('Show')) ?></a> · <?php endif ?><?php if ($downloads !== null && $k['detail'] && $p['visible']): ?><a href="<?= e($app->url(($p['language'] !== '' ? $p['language'] . '/' : '') . $k['slug'] . '/' . $p['slug'] . '/latest')) ?>" title="<?= e(t('The stable address of the current file – it stays the same when a new version replaces the file.')) ?>"><?= e(t('Download')) ?></a> · <?php endif ?>
		<form class="inline" method="post" action="<?= e($module->url('duplicate_item')) ?>"><?= $csrf ?><input type="hidden" name="collection_id" value="<?= e($k['public_id']) ?>"><input type="hidden" name="item_id" value="<?= e($p['public_id']) ?>"><button class="navigation" type="submit"><?= e(t('Duplicate')) ?></button></form><?php if (!empty($noticeBoard)): ?>
		<span class="help" title="<?= e(t('Notices stay in the archive – change the takedown date instead.')) ?>">· <?= e(t('kept in the archive')) ?></span><?php else: ?> ·
		<form class="inline" method="post" action="<?= e($module->url('delete_item')) ?>" data-confirm="<?= e(t('Move the item to the trash? It disappears from the site; you can restore it for 30 days.')) ?>"><?= $csrf ?><input type="hidden" name="collection_id" value="<?= e($k['public_id']) ?>"><input type="hidden" name="item_id" value="<?= e($p['public_id']) ?>"><button class="navigation danger" type="submit"><?= e(t('Delete')) ?></button></form><?php endif ?></td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<?= $app->view->render('admin/bulk_actions', ['csrf' => $csrf, 'action' => $module->url('bulk_items'), 'siteLanguages' => $siteLanguages, 'hidden' => ['collection_id' => $k['public_id']],
    'actions' => ['visible' => t('Publish'), 'hide' => t('Hide')] + (empty($noticeBoard) ? ['trash' => t('Move to trash')] : [])]) ?>
<?php endif ?>
<?php endif ?>
