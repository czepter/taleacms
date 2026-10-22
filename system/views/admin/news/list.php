<?php
/**
 * The news overview (docs/specs/admin-lists.md).
 *
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
 * @var array{total: int, published: int, scheduled: int, drafts: int} $counts
 */
$trash = $filter['status'] === 'trash';
$pageUrl = fn (int $s): string => $module->url('', array_filter($filter) + ['page' => $s]);
$auth = $app->auth();
$keep = array_filter(['category' => $filter['category'], 'language' => $filter['language']]);
$tab = fn (string $value, string $label, int $count): array => ['label' => $label, 'count' => $count, 'url' => $module->url('', array_filter(['status' => $value]) + $keep), 'active' => $filter['status'] === $value];
$initials = fn (string $name): string => mb_strtoupper(mb_substr(trim($name), 0, 2));

echo $app->view->render('admin/list/head', [
    'count' => (string) $counts['total'],
    'links' => array_values(array_filter([
        $auth->hasModule('categories') ? [$app->url('admin.php?module=categories'), t('Categories')] : null,
        $auth->hasModule('tags') ? [$app->url('admin.php?module=tags'), t('Tags')] : null,
        [$module->url('links'), t('Broken links')],
    ])),
    'primary' => [$module->url('new'), t('New news item')],
]);

$filters = '';
if (count($category) > 1) {
    $filters .= '<select name="category" aria-label="' . e(t('Category')) . '" data-submit-on-change><option value="">' . e(t('All categories')) . '</option>';
    foreach ($category as $k) {
        $filters .= '<option value="' . e($k['public_id']) . '"' . ($filter['category'] === $k['public_id'] ? ' selected' : '') . '>' . e($k['name']) . '</option>';
    }
    $filters .= '</select>';
}
if ($siteLanguages !== []) {
    $filters .= '<select name="language" aria-label="' . e(t('Language')) . '" data-submit-on-change><option value="">' . e(t('All languages')) . '</option>';
    foreach ($siteLanguages as $code) {
        $filters .= '<option value="' . e($code) . '"' . ($filter['language'] === $code ? ' selected' : '') . '>' . e(\Talea\Core\Language::AVAILABLE[$code][0]) . '</option>';
    }
    $filters .= '</select>';
}
$tabs = [$tab('', t('All'), $counts['total']), $tab('published', t('Published'), $counts['published']), $tab('scheduled', t('Scheduled'), $counts['scheduled']), $tab('drafts', t('Drafts'), $counts['drafts'])];
if ($toPublish > 0 || $filter['status'] === 'awaiting_publication') {
    $tabs[] = $tab('awaiting_publication', t('Awaiting publication'), $toPublish);
}
$tabs[] = $tab('trash', t('Trash'), $inTrash);
?>
<?= $app->view->render('admin/list/toolbar', [
    'tabsLabel' => t('News status'), 'tabs' => $tabs, 'action' => $app->url('admin.php'),
    'hidden' => ['module' => 'news'] + array_filter(['status' => $filter['status']]),
    'filters' => $trash ? '' : $filters,
    'search' => $trash ? null : ['name' => 'search', 'value' => $filter['search'], 'placeholder' => t('Search titles'), 'label' => t('Search news')],
]) ?>
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
<div class="tab-wrap">
<table class="listing">
<thead><tr><th scope="col" class="cell-select"><input type="checkbox" data-select-all="bulk" form="bulk" aria-label="<?= e(t('Select all')) ?>"></th><th scope="col"><?= e(t('News item')) ?></th><th scope="col"><?= e(t('In trash since')) ?></th><th scope="col"><span class="visually-hidden"><?= e(t('Actions')) ?></span></th></tr></thead>
<tbody>
<?php foreach ($news as $c): ?>
<tr>
	<td class="cell-select"><input type="checkbox" name="selected[]" value="<?= e($c['public_id']) ?>" form="bulk" aria-label="<?= e(t('Select %s', $c['title'])) ?>"></td>
	<td class="cell-main"><div class="cell-text"><span class="cell-title" title="<?= e($c['title']) ?>"><?= bdi($c['title']) ?></span><span class="cell-sub"><?= bdi($c['category_name']) ?></span></div></td>
	<td class="cell-weak"><?= e(format_date($c['deleted_at'], true)) ?></td>
	<td class="cell-actions"><?= $app->view->render('admin/list/actions', [
        'id' => $c['public_id'], 'name' => $c['title'], 'edit' => null, 'csrf' => $csrf,
        'quick' => ['action' => $module->url('restore'), 'fields' => ['delete[]' => $c['public_id']], 'label' => t('Restore'), 'icon' => 'restore'],
        'menu' => $auth->canPublish() ? [['post' => $module->url('delete_permanently'), 'fields' => ['delete[]' => $c['public_id']], 'label' => t('Delete permanently'), 'confirm' => t('Delete the news item permanently? This cannot be undone.'), 'danger' => true]] : [],
    ]) ?></td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<?= $app->view->render('admin/list/selection', ['form' => 'bulk', 'action' => $module->url('restore'), 'csrf' => $csrf, 'actions' => array_merge(
    [['label' => t('Restore'), 'kind' => 'primary']],
    $auth->canPublish() ? [['label' => t('Delete permanently'), 'kind' => 'danger', 'formaction' => $module->url('delete_permanently'), 'confirm' => t('Delete the selected news items permanently? This cannot be undone.')]] : [],
)]) ?>
<?php else: ?>
<div class="tab-wrap">
<table class="listing">
<thead><tr><th scope="col" class="cell-select"><input type="checkbox" data-select-all="bulk" form="bulk" aria-label="<?= e(t('Select all')) ?>"></th><th scope="col"><?= e(t('News item')) ?></th><th scope="col"><?= e(t('Author')) ?></th><th scope="col"><?= e(t('Date')) ?></th><th scope="col"><?= e(t('Status')) ?></th><th scope="col"><span class="visually-hidden"><?= e(t('Actions')) ?></span></th></tr></thead>
<tbody>
<?php foreach ($news as $c):
    $edit = $module->url('edit', ['id' => $c['public_id']]);
    $author = (string) ($c['author_name'] ?: $c['author_login']);
    $planned = $c['visible'] && strtotime((string) $c['published_at']) > time();
    [$kind, $label] = match (true) {
        !$c['visible'] && (int) $c['author_level'] === 0 && $auth->canPublish() => ['review', t('Awaiting publication')],
        !$c['visible'] => ['draft', t('Draft')],
        $planned => ['planned', t('Scheduled')],
        default => ['live', t('Published')],
    };
    $notes = [];
    if ($c['valid_until']) {
        $notes[] = ['text' => t('visible until %s', format_date($c['valid_until']))];
    }
    if ($c['review_by']) {
        $notes[] = ['text' => t('review by %s', format_date($c['review_by']))];
    }
    if ((int) $c['social_open'] > 0) { // social post drafts not posted yet (2.13)
        $notes[] = ['text' => t('Social posts') . ' (' . (int) $c['social_open'] . ')', 'url' => $edit . '#social-posts'];
    }
    $fields = ['delete[]' => $c['public_id']];
?>
<tr>
	<td class="cell-select"><input type="checkbox" name="selected[]" value="<?= e($c['public_id']) ?>" form="bulk" aria-label="<?= e(t('Select %s', $c['title'])) ?>"></td>
	<td class="cell-main"><div class="cell-text"><a class="cell-title" href="<?= e($edit) ?>" title="<?= e($c['title']) ?>"><?= bdi($c['title']) ?></a><span class="cell-sub"><?= bdi($c['category_name']) ?></span></div></td>
	<td><span class="cell-row"><span class="cell-icon avatar" aria-hidden="true"><?= e($initials($author)) ?></span><?= bdi($author) ?></span></td>
	<td class="cell-weak"><?= e($planned ? t('from %s', format_date($c['published_at'], true)) : format_date($c['published_at'], true)) ?></td>
	<td><?= $app->view->render('admin/list/status', ['kind' => $kind, 'label' => $label, 'notes' => $notes]) ?></td>
	<td class="cell-actions"><?= $app->view->render('admin/list/actions', ['id' => $c['public_id'], 'name' => $c['title'], 'edit' => $edit, 'csrf' => $csrf, 'menu' => [
        ['url' => $app->url('news/' . $c['slug'] . '?preview=1'), 'label' => t('Preview'), 'icon' => 'eye', 'external' => true],
        ['post' => $module->url('duplicate'), 'fields' => ['news_id' => $c['public_id']], 'label' => t('Duplicate'), 'icon' => 'copy'], '-',
        ['post' => $module->url('delete'), 'fields' => $fields, 'label' => t('Move to trash'), 'confirm' => t('Move the news item to the trash? You can restore it for 30 days.'), 'danger' => true],
    ]]) ?></td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<?php
$categorySelect = '<select name="category" aria-label="' . e(t('Category')) . '">';
foreach ($category as $k) {
    $categorySelect .= '<option value="' . e($k['public_id']) . '">' . e($k['name']) . ($k['language'] !== '' ? ' (' . e(strtoupper($k['language'])) . ')' : '') . '</option>';
}
$categorySelect .= '</select>';
?>
<?= $app->view->render('admin/list/selection', ['form' => 'bulk', 'action' => $module->url('bulk'), 'csrf' => $csrf, 'extra' => $categorySelect, 'actions' => array_merge(
    $auth->canPublish() ? [['value' => 'publish', 'label' => t('Publish'), 'kind' => 'primary']] : [],
    [['value' => 'draft', 'label' => t('Back to draft')], ['value' => 'category', 'label' => t('Move to this category')],
        ['value' => 'trash', 'label' => t('Move to trash'), 'kind' => 'danger', 'confirm' => t('Move the selected news items to the trash?')]],
)]) ?>
<?php if ($pageCount > 1): ?>
<p class="pagination">
<?php for ($s = 1; $s <= $pageCount; $s++): ?>
	<?= $s === $pageNumber ? '<strong>[' . $s . ']</strong>' : '<a href="' . e($pageUrl($s)) . '">' . $s . '</a>' ?>
<?php endfor ?>
</p>
<?php endif ?>
<?php endif ?>
