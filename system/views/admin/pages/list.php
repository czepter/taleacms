<?php
/**
 * The pages overview (docs/specs/admin-lists.md).
 *
 * @var Talea\Core\App $app
 * @var Talea\Admin\Modules\Pages $module
 * @var string $csrf
 * @var list<array<string, mixed>> $pages
 * @var bool $trash     the trash is shown
 * @var string $status  '' | published | hidden | changes | trash
 * @var string $search
 * @var int $inTrash    number of pages in the trash
 * @var array{total: int, published: int, hidden: int, changes: int} $counts
 * @var array<int, int> $comments  unresolved comments from shared previews per page id (2.15)
 * @var list<string> $siteLanguages
 * @var string $language
 * @var bool $scored  the Page check feature is on: the score, keyword and link columns and the score filter
 * @var string $score  the score filter: '' | good | mid | low | none | hidden
 * @var array<int, array{in: int, out: int}> $links  links in and out per page id (Core\InternalLinks::pageCounts)
 * @var array<int, int> $orphans  page ids nothing links to (the keys)
 * @var bool $seoEdit  "Edit titles in the list": an edit row under every page, saved together by the form #seo-bulk
 * @var int $pageCount  pages of rows in edit mode (Pages::LIST_EDIT_ROWS each); 1 otherwise
 * @var int $pageNumber
 * @var array<int, array{clicks: int, impressions: int, position: ?float}|false|null>|null $inSearch  "Clicks · place" per page id
 *      (false: listed in the sitemap, not in search yet); null hides the column (Pages::searchColumn)
 */
$icon = require __DIR__ . '/../icons.php';
$home = $app->settings()->int('home_page');
$url = fn (array $s): string => ($s['language'] !== '' ? $s['language'] . '/' : '') . ((int) $s['page_id'] === $home ? '' : $s['slug']);
$keep = array_filter(['language' => $language, 'score' => $score]);
$tab = fn (string $value, string $label, int $count): array => ['label' => $label, 'count' => $count, 'url' => $module->url('', ($value !== '' ? ['status' => $value] : []) + $keep), 'active' => $status === $value];

$import = '<form class="inline" method="post" action="' . e($module->url('import')) . '" enctype="multipart/form-data">' . $csrf
    . '<label class="navigation">' . $icon('upload') . e(t('Import page (JSON)')) . ' <input type="file" name="file" accept="application/json,.json" data-submit-on-change></label></form>';
echo $app->view->render('admin/list/head', [
    'count' => (string) $counts['total'],
    'links' => [...$siteLanguages !== [] ? [[$module->url('translations'), t('Translations')]] : [],
        ...$trash || $seoEdit ? [] : [[$module->url('', $keep + ($status !== '' ? ['status' => $status] : []) + ['seo_edit' => '1']), t('Edit titles in the list')]]],
    'secondary' => $import,
    'primary' => [$module->url('new'), t('New page')],
]);
?>
<?php if ($counts['total'] > 0 || $inTrash > 0): ?>
<?= $app->view->render('admin/list/toolbar', [
    'tabsLabel' => t('Pages'),
    'tabs' => [$tab('', t('All'), $counts['total']), $tab('published', t('Published'), $counts['published']), $tab('hidden', t('Hidden'), $counts['hidden']),
        $tab('changes', t('Unpublished changes'), $counts['changes']), $tab('trash', t('Trash'), $inTrash)],
    'action' => $app->url('admin.php'),
    'hidden' => ['module' => 'pages'] + ($status !== '' ? ['status' => $status] : []) + ($seoEdit ? ['seo_edit' => '1'] : []),
    'filters' => $trash ? '' : $app->view->render('admin/language_filter', ['siteLanguages' => $siteLanguages, 'language' => $language, 'submitOnChange' => true])
        . ($scored ? '<label><span class="visually-hidden">' . e(t('Search score')) . '</span><select name="score" data-submit-on-change>' . implode('', array_map(fn (string $value, string $label): string => '<option value="' . $value . '"' . ($score === $value ? ' selected' : '') . '>' . e(t($label)) . '</option>',
            ['', 'good', 'mid', 'low', 'none', 'hidden'], ['Every score', 'Good (80+)', 'Needs work (50–79)', 'Poor (under 50)', 'No focus keyword', 'Hidden from search'])) . '</select></label>' : ''),
    'search' => $trash ? null : ['name' => 'search', 'value' => $search, 'placeholder' => t('Name or address'), 'label' => t('Search pages')],
]) ?>
<?php endif ?>
<?php if ($pages === [] && $trash): ?>
<?= $app->view->render('admin/empty', ['icon' => 'pages', 'heading' => t('The trash is empty.'), 'text' => t('Deleted pages stay here for 30 days, then they are deleted permanently.'), 'action' => [$module->url(), t('Back to pages')]]) ?>
<?php elseif ($pages === [] && ($search !== '' || $status !== '' || $score !== '')): ?>
<?= $app->view->render('admin/empty', ['icon' => 'pages', 'heading' => t('No page matches the search.'), 'text' => t('Try another word.'), 'action' => [$module->url(), t('Clear search')]]) ?>
<?php elseif ($pages === []): ?>
<?= $app->view->render('admin/empty', ['icon' => 'pages', 'heading' => t('No pages yet.'), 'text' => t('A business website usually consists of Home, About us, Services and Contact.'), 'action' => [$module->url('new'), t('Create the first page')]]) ?>
<?php elseif ($trash): ?>
<p class="small-text"><?= e(t('Pages in the trash are not on the site. A restored page comes back hidden; after 30 days it is permanently deleted from the trash.')) ?></p>
<div class="tab-wrap">
<table class="listing">
<thead><tr><th scope="col"><?= e(t('Page')) ?></th><th scope="col"><?= e(t('In trash since')) ?></th><th scope="col"><span class="visually-hidden"><?= e(t('Actions')) ?></span></th></tr></thead>
<tbody>
<?php foreach ($pages as $s): $fields = ['page_id' => $s['public_id'], 'title' => $s['title']]; ?>
<tr>
	<td class="cell-main"><div class="cell-text"><span class="cell-title" title="<?= e($s['title']) ?>"><?= bdi($s['title']) ?></span><span class="cell-sub">/<?= e($s['slug']) ?></span></div></td>
	<td class="cell-weak"><?= e(format_date($s['deleted_at'], true)) ?></td>
	<td class="cell-actions"><?= $app->view->render('admin/list/actions', [
        'id' => $s['public_id'], 'name' => $s['title'], 'edit' => null, 'csrf' => $csrf,
        'quick' => ['action' => $module->url('restore'), 'fields' => $fields, 'label' => t('Restore'), 'icon' => 'restore'],
        'menu' => [['post' => $module->url('delete_permanently'), 'fields' => $fields, 'label' => t('Delete permanently'), 'confirm' => t('Delete the page permanently? This cannot be undone.'), 'danger' => true]],
    ]) ?></td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<?php else: $columns = ($scored ? 9 : 6) + ($inSearch !== null ? 1 : 0); ?>
<?php if ($seoEdit): $editFilters = array_filter(['status' => $status, 'search' => $search]) + $keep; ?>
<form class="seo-bulk" id="seo-bulk" method="post" action="<?= e($module->url('seo_bulk_save')) ?>"><?= $csrf ?>
<?php foreach ($editFilters + ['page' => (string) $pageNumber] as $name => $value): // the save returns to this page of the list ?>
	<input type="hidden" name="<?= e($name) ?>" value="<?= e((string) $value) ?>">
<?php endforeach ?>
	<button class="btn" type="submit"><?= e(t('Save all changes')) ?></button>
	<a class="navigation" href="<?= e($module->url('', $keep + ($status !== '' ? ['status' => $status] : []))) ?>"><?= e(t('Cancel')) ?></a>
	<span class="help"><?= e(t('Only the rows you change are saved; nothing is published that was not before.')) ?></span>
</form>
<?php endif ?>
<div class="tab-wrap">
<table class="listing">
<thead><tr><th scope="col" class="cell-select"><input type="checkbox" data-select-all="bulk" form="bulk" aria-label="<?= e(t('Select all')) ?>"></th><th scope="col"><?= e(t('Page')) ?></th><th scope="col"><?= e(t('Status')) ?></th><?php if ($scored): ?><th scope="col"><?= e(t('Search score')) ?></th><th scope="col"><?= e(t('Focus keyword')) ?></th><th scope="col" title="<?= e(t('Links from your pages · links out')) ?>"><?= e(t('Links in · out')) ?></th><?php endif ?><?php if ($inSearch !== null): ?><th scope="col" title="<?= e(t('Clicks from search and the average place, last 28 days')) ?>"><?= e(t('Clicks · place')) ?></th><?php endif ?><th scope="col"><?= e(t('In navigation')) ?></th><th scope="col"><?= e(t('Changed')) ?></th><th scope="col"><span class="visually-hidden"><?= e(t('Actions')) ?></span></th></tr></thead>
<tbody>
<?php foreach ($pages as $s):
    $isHome = (int) $s['page_id'] === $home;
    $builder = $module->url('builder', ['id' => $s['public_id']]);
    $settings = $module->url('edit', ['id' => $s['public_id']]);
    $hasBuild = $s['build'] !== null || $s['build_draft'] !== null;
    $open = $hasBuild ? $builder : $settings;
    $address = $app->url($url($s)) . ($s['visible'] ? '' : '?build=draft');
    $fields = ['page_id' => $s['public_id'], 'title' => $s['title']];
    $scheduled = !$s['visible'] && $s['publish_at'];
    $notes = [];
    if ($s['build_draft'] !== null) {
        $notes[] = ['text' => t('Changes not published'), 'url' => $builder];
    }
    if ($s['publish_at'] && !$scheduled) {
        $notes[] = ['text' => t('from %s', format_date($s['publish_at'], true))];
    }
    if ($s['valid_until']) {
        $notes[] = ['text' => t('visible until %s', format_date($s['valid_until']))];
    }
    if ($s['review_by']) {
        $notes[] = ['text' => t('review by %s', format_date($s['review_by']))];
    }
    if (!empty($comments[(int) $s['page_id']])) {
        $notes[] = ['text' => t('%d comments', $comments[(int) $s['page_id']]), 'url' => $builder];
    }
    $menu = [['url' => $settings, 'label' => t('Settings and SEO')], ['url' => $address, 'label' => t('View on the site'), 'external' => true],
        ['url' => $module->url('new', ['parent' => $s['public_id']]), 'label' => t('New page under this one'), 'icon' => 'subpage'],
        ['post' => $module->url('duplicate'), 'fields' => $fields, 'label' => t('Duplicate'), 'icon' => 'copy'], '-',
        $isHome ? ['note' => t('The home page cannot be deleted.')]
            : ['post' => $module->url('delete'), 'fields' => $fields, 'label' => t('Move to trash'), 'confirm' => t('Move the page to the trash? It disappears from the site; you can restore it for 30 days.'), 'danger' => true]];
?>
<tr>
	<td class="cell-select"><input type="checkbox" name="selected[]" value="<?= e($s['public_id']) ?>" form="bulk" aria-label="<?= e(t('Select %s', $s['title'])) ?>"></td>
	<td class="cell-main"><div class="cell-row"<?= !empty($s['level']) ? ' style="padding-inline-start:' . ((int) $s['level'] - 1) * 20 . 'px"' : '' ?>>
		<?php if (!empty($s['level'])): ?><span class="cell-tree"><?= $icon('subpage') ?></span><?php endif ?>
		<?php if ($isHome): ?><span class="cell-icon" title="<?= e(t('home')) ?>"><?= $icon('home') ?><span class="visually-hidden"><?= e(t('home')) ?></span></span><?php endif ?>
		<div class="cell-text">
			<span class="cell-row"><a class="cell-title" href="<?= e($open) ?>" title="<?= e($s['title']) ?>"><?= bdi($s['title']) ?></a><?= $hasBuild ? '' : ' <span class="tag" title="' . e(t('A text page without the builder.')) . '">' . e(t('Text')) . '</span>' ?><?= $s['noindex'] && !$scored ? ' <span class="tag">noindex</span>' : '' ?></span>
			<a class="cell-sub" href="<?= e($address) ?>" target="_blank" rel="noopener"<?= $s['visible'] ? '' : ' title="' . e(t('Preview hidden page')) . '"' ?>>/<?= e($url($s)) ?> <?= $icon('out') ?></a>
		</div>
	</div></td>
	<td><?= $app->view->render('admin/list/status', ['kind' => $s['visible'] ? 'live' : ($scheduled ? 'planned' : 'hidden'),
        'label' => $s['visible'] ? t('Published') : ($scheduled ? t('from %s', format_date($s['publish_at'], true)) : t('Hidden')), 'notes' => $notes]) ?></td>
<?php if ($scored): $keyword = Talea\Core\SeoFields::keywords((string) $s['focus_keywords'])[0] ?? ''; $count = $links[(int) $s['page_id']] ?? ['in' => 0, 'out' => 0]; ?>
	<td><?php if ($s['noindex']): ?><span class="tag"><?= e(t('Hidden from search')) ?></span><?php elseif ($keyword === ''): ?><span class="website-score"><?= e(t('No keyword')) ?></span><?php elseif ($s['seo_score'] !== null): ?><span class="website-score website-score-<?= Talea\Core\ContentCheck::band((int) $s['seo_score']) ?>"><?= (int) $s['seo_score'] ?></span><?php else: ?><span class="cell-weak">–</span><?php endif ?></td>
	<td<?= $keyword === '' ? ' class="cell-weak"' : '' ?>><?= $keyword === '' ? '—' : bdi($keyword) ?></td>
	<td class="cell-links"><?= $count['in'] ?> · <?= $count['out'] ?><?php if (isset($orphans[(int) $s['page_id']])): ?><span class="status-note status-note-warning"><?= e(t('Nothing links here')) ?></span><?php endif ?></td>
<?php endif ?>
<?php if ($inSearch !== null): $found = $inSearch[(int) $s['page_id']] ?? null; ?>
	<td class="cell-links"><?php if (is_array($found)): ?><?= format_count($found['clicks']) ?> · <?= $found['position'] !== null ? format_count($found['position'], 1) : '—' ?><?php elseif ($found === false): ?><span class="status-note status-note-warning"><?= e(t('Not in search yet')) ?></span><?php else: ?><span class="cell-weak">—</span><?php endif ?></td>
<?php endif ?>
	<td><?php if ($s['in_menu']): ?><span title="<?= e(t('In navigation')) ?>"><?= $icon('check') ?><span class="visually-hidden"><?= e(t('Yes')) ?></span></span><?php else: ?><span class="visually-hidden"><?= e(t('No')) ?></span><?php endif ?></td>
	<td class="cell-weak"><?= e(format_ago($s['updated_at'] ?? null)) ?></td>
	<td class="cell-actions"><?= $app->view->render('admin/list/actions', ['id' => $s['public_id'], 'name' => $s['title'], 'edit' => $open, 'menu' => $menu, 'csrf' => $csrf]) ?></td>
</tr>
<?php if ($seoEdit): $field = fn (string $name): string => 'form="seo-bulk" name="rows[' . e($s['public_id']) . '][' . $name . ']"'; ?>
<tr class="seo-edit-row"><td colspan="<?= $columns ?>"><div class="seo-edit-grid">
	<label><?= e(t('Title in search results')) ?><input class="textfield" type="text" <?= $field('seo_title') ?> value="<?= e((string) $s['seo_title']) ?>" maxlength="200" placeholder="<?= e($s['title']) ?>"></label>
	<label><?= e(t('Description')) ?><textarea class="textbox" rows="2" <?= $field('description') ?> maxlength="300"><?= e((string) $s['description']) ?></textarea></label>
	<div class="seo-edit-options">
		<label><?= e(t('Focus keyword')) ?><input class="textfield" type="text" <?= $field('focus_keywords') ?> value="<?= e(implode(', ', Talea\Core\SeoFields::keywords((string) $s['focus_keywords']))) ?>"></label>
		<input type="hidden" <?= $field('key_page') ?> value="0"><label class="option"><input type="checkbox" <?= $field('key_page') ?> value="1"<?= $s['key_page'] ? ' checked' : '' ?>> <?= e(t('Key page')) ?></label>
		<input type="hidden" <?= $field('noindex') ?> value="0"><label class="option"><input type="checkbox" <?= $field('noindex') ?> value="1"<?= $s['noindex'] ? ' checked' : '' ?>> <?= e(t('Hide from search engines')) ?></label>
	</div>
</div></td></tr>
<?php endif ?>
<?php endforeach ?>
</tbody>
</table>
</div>
<?php if ($seoEdit && $pageCount > 1): ?>
<p class="pagination">
<?php for ($n = 1; $n <= $pageCount; $n++): ?>
	<?= $n === $pageNumber ? '<strong>[' . $n . ']</strong>' : '<a href="' . e($module->url('', $editFilters + ['seo_edit' => '1', 'page' => $n])) . '">' . $n . '</a>' ?>
<?php endfor ?>
</p>
<?php endif ?>
<?php
$languageSelect = '';
if ($siteLanguages !== []) {
    $languageSelect = '<select name="language" aria-label="' . e(t('Language version')) . '">';
    foreach ($siteLanguages as $i => $code) {
        $languageSelect .= '<option value="' . ($i === 0 ? '' : e($code)) . '">' . e(Talea\Core\Language::AVAILABLE[$code][0]) . ($i === 0 ? ' (' . e(t('default')) . ')' : '') . '</option>';
    }
    $languageSelect .= '</select>';
}
?>
<?= $app->view->render('admin/list/selection', ['form' => 'bulk', 'action' => $module->url('bulk'), 'csrf' => $csrf, 'extra' => $languageSelect, 'actions' => array_merge(
    [['value' => 'visible', 'label' => t('Publish'), 'kind' => 'primary'], ['value' => 'hide', 'label' => t('Hide')]],
    $siteLanguages !== [] ? [['value' => 'language', 'label' => t('Move to this language version')]] : [],
    [['value' => 'trash', 'label' => t('Move to trash'), 'kind' => 'danger', 'confirm' => t('Move the selected pages to the trash?')]],
)]) ?>
<?php endif ?>
