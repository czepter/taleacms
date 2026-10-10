<?php
/**
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\Pages $module
 * @var string $csrf
 * @var list<array<string, mixed>> $pages
 * @var bool $trash     the trash is shown
 * @var string $search
 * @var int $inTrash    number of pages in the trash
 * @var array<int, int> $comments  unresolved comments from shared previews per page id (2.15)
 */
$home = $app->settings()->int('home_page');
$url = fn (array $s): string => ($s['language'] !== '' ? $s['language'] . '/' : '') . ((int) $s['page_id'] === $home ? '' : $s['slug']);
?>
<div class="navigation-row"><a class="btn" href="<?= e($module->url('new')) ?>"><?= e(t('New page')) ?></a>
	<form class="inline" method="post" action="<?= e($module->url('import')) ?>" enctype="multipart/form-data"><?= $csrf ?>
		<label class="navigation"><?= e(t('Import page (JSON)')) ?> <input type="file" name="file" accept="application/json,.json" data-submit-on-change></label></form>
<?php if ($siteLanguages !== []): ?>
	<a class="navigation" href="<?= e($module->url('translations')) ?>"><?= e(t('Translations')) ?></a>
<?php endif ?></div>
<?php if ($inTrash > 0 || $trash): // tabs only with the trash – "All" on its own makes no sense ?>
<nav class="tabs" aria-label="<?= e(t('Pages')) ?>">
	<a href="<?= e($module->url()) ?>"<?= $trash ? '' : ' class="active" aria-current="true"' ?>><?= e(t('All')) ?></a>
	<a href="<?= e($module->url('', ['status' => 'trash'])) ?>"<?= $trash ? ' class="active" aria-current="true"' : '' ?>><?= e(t('Trash')) ?> (<?= $inTrash ?>)</a>
</nav>
<?php endif ?>
<?php if (!$trash && ($pages !== [] || $search !== '' || $language !== '')): ?>
<form method="get" action="<?= e($app->url('admin.php')) ?>" class="center small-text">
	<input type="hidden" name="module" value="pages">
<?= $app->view->render('admin/language_filter', ['siteLanguages' => $siteLanguages, 'language' => $language]) ?>
	<label><?= e(t('Name or address contains:')) ?> <input class="textfield" type="search" name="search" value="<?= e($search) ?>" size="20"></label>
	<input class="btn" type="submit" value="<?= e(t('Filter')) ?>">
</form>
<br>
<?php endif ?>
<?php if ($pages === [] && $trash): ?>
<?= $app->view->render('admin/empty', ['icon' => 'pages', 'heading' => t('The trash is empty.'), 'text' => t('Deleted pages stay here for 30 days, then they are deleted permanently.'), 'action' => [$module->url(), t('Back to pages')]]) ?>
<?php elseif ($pages === [] && $search !== ''): ?>
<?= $app->view->render('admin/empty', ['icon' => 'pages', 'heading' => t('No page matches the search.'), 'text' => t('Try another word.'), 'action' => [$module->url(), t('Clear search')]]) ?>
<?php elseif ($pages === []): ?>
<?= $app->view->render('admin/empty', ['icon' => 'pages', 'heading' => t('No pages yet.'), 'text' => t('A business website usually consists of Home, About us, Services and Contact.'), 'action' => [$module->url('new'), t('Create the first page')]]) ?>
<?php elseif ($trash): ?>
<p class="small-text"><?= e(t('Pages in the trash are not on the site. A restored page comes back hidden; after 30 days it is permanently deleted from the trash.')) ?></p>
<div class="tab-wrap">
<table class="listing">
<thead><tr><th scope="col"><?= e(t('Name')) ?></th><th scope="col"><?= e(t('URL')) ?></th><th scope="col"><?= e(t('In trash since')) ?></th><th scope="col"><?= e(t('Actions')) ?></th></tr></thead>
<tbody>
<?php foreach ($pages as $s): ?>
<tr class="unpublished">
	<td><?= e($s['title']) ?></td>
	<td>/<?= e($s['slug']) ?></td>
	<td class="number"><?= e(format_date($s['deleted_at'], true)) ?></td>
	<td class="actions">
		<form class="inline" method="post" action="<?= e($module->url('restore')) ?>"><?= $csrf ?><input type="hidden" name="page_id" value="<?= (int) $s['page_id'] ?>"><input type="hidden" name="title" value="<?= e($s['title']) ?>"><button class="navigation" type="submit"><?= e(t('Restore')) ?></button></form> ·
		<form class="inline" method="post" action="<?= e($module->url('delete_permanently')) ?>" data-confirm="<?= e(t('Delete the page permanently? This cannot be undone.')) ?>"><?= $csrf ?><input type="hidden" name="page_id" value="<?= (int) $s['page_id'] ?>"><input type="hidden" name="title" value="<?= e($s['title']) ?>"><button class="navigation danger" type="submit"><?= e(t('Delete permanently')) ?></button></form>
	</td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<?php else: ?>
<div class="tab-wrap">
<table class="listing">
<thead><tr><th scope="col"><input type="checkbox" data-select-all="hromadne" aria-label="<?= e(t('Select all')) ?>"></th><th scope="col"><?= e(t('Name')) ?></th><th scope="col"><?= e(t('URL')) ?></th><th scope="col"><?= e(t('Status')) ?></th><th scope="col"><?= e(t('In navigation')) ?></th><th scope="col"><?= e(t('Actions')) ?></th></tr></thead>
<tbody>
<?php foreach ($pages as $s): ?>
<tr<?= $s['visible'] ? '' : ' class="unpublished"' ?>>
	<td><input type="checkbox" name="selected[]" value="<?= (int) $s['page_id'] ?>" form="hromadne" aria-label="<?= e(t('Select %s', $s['title'])) ?>"></td>
	<td><?= !empty($s['level']) ? '<span class="padding-tree" style="padding-inline-start:' . ((int) $s['level'] - 1) * 1.2 . 'em">↳ </span>' : '' ?><a href="<?= e($module->url('edit', ['id' => $s['page_id']])) ?>"><?= e($s['title']) ?></a><?= (int) $s['page_id'] === $home ? ' <span class="badge">' . e(t('home')) . '</span>' : '' ?><?= $s['build'] !== null || $s['build_draft'] !== null ? ' <span class="badge badge-published">' . e(t('builder')) . '</span>' : '' ?><?= $s['build_draft'] !== null ? ' <span class="badge badge-draft" title="' . e(t('The builder has changes that are not on the site yet.')) . '">' . e(t('unpublished changes')) . '</span>' : '' ?><?= $s['noindex'] ? ' <span class="badge">noindex</span>' : '' ?><?= $s['publish_at'] ? ' <span class="badge badge-draft" title="' . e(t('Publishes automatically')) . '">' . e(t('from %s', format_date($s['publish_at'], true))) . '</span>' : '' ?><?= $s['valid_until'] ? ' <span class="badge badge-draft" title="' . e(t('Hides itself the day after.')) . '">' . e(t('true until %s', format_date($s['valid_until']))) . '</span>' : '' ?><?= $s['review_by'] ? ' <span class="badge badge-draft" title="' . e(t('Asks for a review on this day.')) . '">' . e(t('review by %s', format_date($s['review_by']))) . '</span>' : '' ?><?= !empty($comments[(int) $s['page_id']]) ? ' <a class="badge badge-draft" href="' . e($module->url('builder', ['id' => $s['page_id']])) . '" title="' . e(t('Comments from people with a preview link, waiting in the builder.')) . '">' . e(t('%d comments', $comments[(int) $s['page_id']])) . '</a>' : '' ?></td>
	<td><a href="<?= e($app->url($url($s)) . ($s['visible'] ? '' : '?build=draft')) ?>" target="_blank" rel="noopener"<?= $s['visible'] ? '' : ' title="' . e(t('Preview hidden page')) . '"' ?>>/<?= e($url($s)) ?></a></td>
	<td><span class="badge badge-<?= $s['visible'] ? 'published' : 'draft' ?>"><?= e(t($s['visible'] ? 'published' : 'hidden')) ?></span></td>
	<td><?= e(t($s['in_menu'] ? 'Yes' : 'No')) ?></td>
	<td class="actions"><a href="<?= e($module->url('builder', ['id' => $s['page_id']])) ?>"><?= e(t('Builder')) ?></a> · <a href="<?= e($module->url('edit', ['id' => $s['page_id']])) ?>"><?= e(t('Settings')) ?></a> ·
		<a href="<?= e($module->url('new', ['parent' => $s['page_id']])) ?>" title="<?= e(t('New page under this one')) ?>"><?= e(t('Subpage')) ?></a> ·
		<form class="inline" method="post" action="<?= e($module->url('duplicate')) ?>"><?= $csrf ?><input type="hidden" name="page_id" value="<?= (int) $s['page_id'] ?>"><input type="hidden" name="title" value="<?= e($s['title']) ?>"><button class="navigation" type="submit"><?= e(t('Duplicate')) ?></button></form>
<?php if ((int) $s['page_id'] !== $home): ?> ·
		<form class="inline" method="post" action="<?= e($module->url('delete')) ?>" data-confirm="<?= e(t('Move the page to the trash? It disappears from the site; you can restore it for 30 days.')) ?>"><?= $csrf ?><input type="hidden" name="page_id" value="<?= (int) $s['page_id'] ?>"><input type="hidden" name="title" value="<?= e($s['title']) ?>"><button class="navigation danger" type="submit"><?= e(t('Delete')) ?></button></form>
<?php endif ?></td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<?= $app->view->render('admin/bulk_actions', ['csrf' => $csrf, 'action' => $module->url('bulk'), 'siteLanguages' => $siteLanguages, 'actions' => [
    'visible' => t('Publish'), 'hide' => t('Hide'), 'trash' => t('Move to trash')]]) ?>
<?php endif ?>
