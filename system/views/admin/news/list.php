<?php
/**
 * @var Talea\Core\App $app
 * @var Talea\Admin\Modules\News $module
 * @var string $csrf
 * @var list<array<string, mixed>> $news
 * @var int $total
 * @var int $pageNumber
 * @var int $pageCount
 * @var list<array<string, mixed>> $category
 * @var array{category:string, language:string, search:string, status:string} $filter
 * @var list<string> $siteLanguages  language versions of the site (empty = the site has only one language)
 * @var int $inTrash  number of news items in the trash (within the signed-in user's scope)
 * @var int $toPublish  authors' drafts waiting to be published (seen by editors and administrators)
 */
$trash = $filter['status'] === 'trash';
$pageUrl = fn (int $s): string => $module->url('', array_filter($filter) + ['page' => $s]);
?>
<p class="navigation-row"><a class="btn" href="<?= e($module->url('new')) ?>"><?= e(t('New news item')) ?></a>
<?php if ($app->auth()->hasModule('categories')): ?>
	<a class="navigation" href="<?= e($app->url('admin.php?module=categories')) ?>"><?= e(t('Categories')) ?></a>
<?php endif ?>
<?php if ($app->auth()->hasModule('tags')): ?>
	<a class="navigation" href="<?= e($app->url('admin.php?module=tags')) ?>"><?= e(t('Tags')) ?></a>
<?php endif ?>
	<a class="navigation" href="<?= e($module->url('links')) ?>"><?= e(t('Broken links')) ?></a></p>

<nav class="tabs" aria-label="<?= e(t('News status')) ?>">
<?php foreach (['' => 'All', 'published' => 'Published', 'scheduled' => 'Scheduled', 'drafts' => 'Drafts'] as $key => $name): ?>
	<a href="<?= e($module->url('', array_filter(['status' => $key]))) ?>"<?= $filter['status'] === $key ? ' class="active" aria-current="true"' : '' ?>><?= e(t($name)) ?></a>
<?php endforeach ?>
<?php if ($toPublish > 0 || $filter['status'] === 'awaiting_publication'): ?>
	<a href="<?= e($module->url('', ['status' => 'awaiting_publication'])) ?>"<?= $filter['status'] === 'awaiting_publication' ? ' class="active" aria-current="true"' : '' ?>><?= e(t('Awaiting publication')) ?> (<?= $toPublish ?>)</a>
<?php endif ?>
<?php if ($inTrash > 0 || $trash): ?>
	<a href="<?= e($module->url('', ['status' => 'trash'])) ?>"<?= $trash ? ' class="active" aria-current="true"' : '' ?>><?= e(t('Trash')) ?> (<?= $inTrash ?>)</a>
<?php endif ?>
</nav>
<form method="get" action="<?= e($app->url('admin.php')) ?>" class="center small-text">
	<input type="hidden" name="module" value="news">
	<input type="hidden" name="status" value="<?= e($filter['status']) ?>">
<?php if (count($category) > 1): ?>
	<label><?= e(t('Category:')) ?>
		<select name="category">
			<option value="0"><?= e(t('all')) ?></option>
<?php foreach ($category as $k): ?>
			<option value="<?= e($k['public_id']) ?>"<?= $filter['category'] === $k['public_id'] ? ' selected' : '' ?>><?= e($k['name']) ?></option>
<?php endforeach ?>
		</select>
	</label>
<?php endif ?>
<?php if ($siteLanguages !== []): ?>
	<label><?= e(t('Language:')) ?>
		<select name="language">
			<option value=""><?= e(t('all')) ?></option>
<?php foreach ($siteLanguages as $code): ?>
			<option value="<?= e($code) ?>"<?= $filter['language'] === $code ? ' selected' : '' ?>><?= e(\Talea\Core\Language::AVAILABLE[$code][0]) ?></option>
<?php endforeach ?>
		</select>
	</label>
<?php endif ?>
	<label><?= e(t('Headline contains:')) ?> <input class="textfield" type="search" name="search" value="<?= e($filter['search']) ?>" size="20"></label>
	<input class="btn" type="submit" value="<?= e(t('Filter')) ?>">
	(<?= e(t('Total:')) ?> <?= $total ?>)
</form>
<br>

<?php if ($news === [] && $trash): ?>
<?= $app->view->render('admin/empty', ['icon' => 'article', 'heading' => t('The trash is empty.'), 'text' => t('Deleted news items stay here for 30 days, then they are deleted permanently.'), 'action' => [$module->url(), t('Back to news')]]) ?>
<?php elseif ($news === []): ?>
<?php if (array_filter($filter) !== []): ?>
<?= $app->view->render('admin/empty', ['icon' => 'article', 'heading' => t('No news item matches the filter.'), 'text' => t('Try another word, category or status.'), 'action' => [$module->url(), t('Clear filter')]]) ?>
<?php else: ?>
<?= $app->view->render('admin/empty', ['icon' => 'article', 'heading' => t('There are no news items yet.'), 'text' => t('Until you publish a news item, it stays a draft that nobody sees on the site.'), 'action' => [$module->url('new'), t('Write the first news item')]]) ?>
<?php endif ?>
<?php elseif ($trash): ?>
<p class="small-text"><?= e(t('News items in the trash are not on the site. A restored news item comes back as a draft; after 30 days it is permanently deleted from the trash.')) ?></p>
<form method="post" id="restore-one" action="<?= e($module->url('restore')) ?>"><?= $csrf ?></form>
<form method="post" action="<?= e($module->url('restore')) ?>">
<?= $csrf ?>
<div class="tab-wrap">
<table class="listing">
<thead>
<tr><th scope="col"><?= e(t('Title')) ?></th><th scope="col"><?= e(t('Categories')) ?></th><th scope="col"><?= e(t('In trash since')) ?></th><th scope="col"><?= e(t('Actions')) ?></th><th scope="col" class="center"><?= e(t('Select')) ?></th></tr>
</thead>
<tbody>
<?php foreach ($news as $c): ?>
<tr class="unpublished">
	<td><?= e($c['title']) ?></td>
	<td><?= e($c['category_name']) ?></td>
	<td class="number"><?= e(format_date($c['deleted_at'], true)) ?></td>
	<td class="actions"><button class="navigation" type="submit" form="restore-one" name="delete[]" value="<?= e($c['public_id']) ?>"><?= e(t('Restore')) ?></button></td>
	<td class="center"><input type="checkbox" name="delete[]" value="<?= e($c['public_id']) ?>" aria-label="<?= e(t('Select')) ?>: <?= e($c['title']) ?>"></td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<p class="media-bulk">
	<?= e(t('With selected:')) ?>
	<input class="btn" type="submit" value="<?= e(t('Restore')) ?>">
<?php if ($app->auth()->canPublish()): ?>
	<button class="navigation danger" type="submit" formaction="<?= e($module->url('delete_permanently')) ?>" data-confirm="<?= e(t('Delete the selected news items permanently? This cannot be undone.')) ?>"><?= e(t('Delete permanently')) ?></button>
<?php endif ?>
</p>
</form>
<?php else: ?>
<form method="post" action="<?= e($module->url('delete')) ?>">
<?= $csrf ?>
<div class="tab-wrap">
<table class="listing">
<thead>
<tr><th scope="col"><?= e(t('Title')) ?></th><th scope="col"><?= e(t('Categories')) ?></th><th scope="col"><?= e(t('Author')) ?></th><th scope="col"><?= e(t('Publish date')) ?></th><th scope="col"><?= e(t('Status')) ?></th><th scope="col"><?= e(t('Actions')) ?></th><th scope="col"><?= e(t('Select')) ?></th></tr>
</thead>
<tbody>
<?php foreach ($news as $c): ?>
<tr<?= $c['visible'] ? '' : ' class="unpublished"' ?>>
	<td><a href="<?= e($module->url('edit', ['id' => $c['public_id']])) ?>"><?= e($c['title']) ?></a><?= $c['valid_until'] ? ' <span class="badge badge-draft" title="' . e(t('Hides itself the day after.')) . '">' . e(t('true until %s', format_date($c['valid_until']))) . '</span>' : '' ?><?= $c['review_by'] ? ' <span class="badge badge-draft" title="' . e(t('Asks for a review on this day.')) . '">' . e(t('review by %s', format_date($c['review_by']))) . '</span>' : '' ?></td>
	<td><?= e($c['category_name']) ?></td>
	<td><?= e($c['author_name'] ?: $c['author_login']) ?></td>
	<td class="number"><?= e(format_date($c['published_at'], true)) ?></td>
<?php if (!$c['visible'] && (int) $c['author_level'] === 0 && $app->auth()->canPublish()): // an author's draft: waiting for an editor to publish it ?>
	<td><span class="badge badge-pending" title="<?= e(t('News authors cannot publish – review this news item and publish it.')) ?>"><?= e(t('awaiting publication')) ?></span></td>
<?php else: ?>
	<td><span class="badge badge-<?= !$c['visible'] ? 'draft' : (strtotime($c['published_at']) > time() ? 'plan' : 'published') ?>"><?= e(t(!$c['visible'] ? 'draft' : (strtotime($c['published_at']) > time() ? 'scheduled' : 'published'))) ?></span></td>
<?php endif ?>
	<td class="actions"><a href="<?= e($module->url('edit', ['id' => $c['public_id']])) ?>"><?= e(t('Edit')) ?></a> · <a href="<?= e($app->url('news/' . $c['slug'] . '?preview=1')) ?>" target="_blank" rel="noopener"><?= e(t('Preview')) ?></a> ·
<?php if ((int) $c['social_open'] > 0): // social post drafts not posted yet (2.13) ?>
		<a href="<?= e($module->url('edit', ['id' => $c['public_id']])) ?>#social-posts" title="<?= e(t('Social post drafts waiting to be posted')) ?>"><?= e(t('Social posts')) ?> (<?= (int) $c['social_open'] ?>)</a> ·
<?php endif ?>
		<button class="navigation" type="submit" formaction="<?= e($module->url('duplicate')) ?>" name="news_id" value="<?= e($c['public_id']) ?>" formnovalidate><?= e(t('Duplicate')) ?></button></td>
	<td class="center"><input type="checkbox" name="delete[]" value="<?= e($c['public_id']) ?>" aria-label="<?= e(t('Select')) ?>: <?= e($c['title']) ?>"></td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<p class="media-bulk bulk">
	<label><?= e(t('With selected:')) ?>
	<select name="bulk">
<?php if ($app->auth()->canPublish()): ?>
		<option value="publish"><?= e(t('Publish')) ?></option>
<?php endif ?>
		<option value="draft"><?= e(t('Back to draft')) ?></option>
		<option value="category"><?= e(t('Category…')) ?></option>
		<option value="trash"><?= e(t('Move to trash')) ?></option>
	</select></label>
	<select name="category" aria-label="<?= e(t('Category')) ?>">
<?php foreach ($category as $t): ?>
		<option value="<?= e($t['public_id']) ?>"><?= e($t['name']) ?><?= $t['language'] !== '' ? ' (' . e(strtoupper($t['language'])) . ')' : '' ?></option>
<?php endforeach ?>
	</select>
	<button class="navigation" type="submit" formaction="<?= e($module->url('bulk')) ?>" data-confirm="<?= e(t('Apply the action to the selected items?')) ?>"><?= e(t('Apply')) ?></button>
	<button class="navigation danger" type="submit"><?= e(t('Delete selected')) ?></button>
</p>
</form>

<?php if ($pageCount > 1): ?>
<p class="pagination">
<?php for ($s = 1; $s <= $pageCount; $s++): ?>
	<?= $s === $pageNumber ? '<strong>[' . $s . ']</strong>' : '<a href="' . e($pageUrl($s)) . '">' . $s . '</a>' ?>
<?php endfor ?>
</p>
<?php endif ?>
<?php endif ?>
