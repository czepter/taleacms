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
 * @var list<array{key: string, label: string, type: string, options: list<string>}> $choiceFields the collection's choice fields: each one is a filter
 * @var array<string, string> $fieldFilter field key => the chosen option
 * @var bool $trash the trash is shown
 * @var bool $noticeBoard an official notice board (2.11): its notices are never deleted
 * @var int $inTrash items in the trash
 * @var array<int, array{0: int, 1: int}>|null $downloads  document library (2.11): idp => [downloads in 30 days, total]; null = not a library
 */
$icon = require __DIR__ . '/../icons.php';
$links = [[$module->url(), t('All collections')]];
if ($app->auth()->isAdmin()) {
    $links[] = [$module->url('edit', ['id' => $k['public_id']]), t('Fields and settings')];
    if ($k['detail']) {
        $links[] = [$module->url('builder', ['id' => $k['public_id']]), t('Detail template')];
        foreach ($languages as $code) {
            $links[] = [$module->url('builder', ['id' => $k['public_id'], 'language' => $code]), t('Detail template (%s)', strtoupper($code))];
        }
    }
}
echo $app->view->render('admin/list/head', ['count' => (string) count($items), 'links' => $links, 'primary' => [$module->url('item', ['id' => $k['public_id']]), t('Add item')]]);
$filtered = $language !== '' || $fieldFilter !== [];
$filters = $trash ? '' : $app->view->render('admin/language_filter', ['siteLanguages' => $siteLanguages, 'language' => $language, 'submitOnChange' => true]);
foreach ($trash ? [] : $choiceFields as $f) {
    $filters .= '<select name="field_' . e($f['key']) . '" aria-label="' . e($f['label']) . '" data-submit-on-change><option value="">' . e($f['label']) . ': ' . e(t('all')) . '</option>';
    foreach ($f['options'] as $option) {
        $filters .= '<option value="' . e($option) . '"' . (($fieldFilter[$f['key']] ?? null) === $option ? ' selected' : '') . '>' . e($option) . '</option>';
    }
    $filters .= '</select>';
}
if ($filtered) {
    $filters .= ' <a href="' . e($module->url('items', ['id' => $k['public_id']])) . '">' . e(t('Clear filter')) . '</a>';
}
if ($inTrash > 0 || $trash || $filters !== '') {
    echo $app->view->render('admin/list/toolbar', ['tabsLabel' => t('Items'), 'tabs' => $inTrash > 0 || $trash ? [
        ['label' => t('All'), 'url' => $module->url('items', ['id' => $k['public_id']]), 'active' => !$trash],
        ['label' => t('Trash'), 'count' => $inTrash, 'url' => $module->url('items', ['id' => $k['public_id'], 'status' => 'trash']), 'active' => $trash],
    ] : [], 'action' => $app->url('admin.php'), 'hidden' => ['module' => 'collections', 'action' => 'items', 'id' => $k['public_id']],
        'filters' => $filters]);
}
// the row's picture and a short summary from the collection's own fields
$fields = is_array($k['fields'] ?? null) ? $k['fields'] : (json_decode((string) ($k['fields'] ?? ''), true) ?: []);
$thumb = function (array $p) use ($fields, $app): ?string {
    $data = json_decode((string) ($p['data'] ?? ''), true) ?: [];
    $candidates = [(string) ($p['image'] ?? '')];
    foreach ($fields as $f) {
        if (($f['type'] ?? '') === 'image') {
            $candidates[] = (string) ($data[$f['key']] ?? '');
        }
    }
    foreach ($candidates as $src) {
        if (preg_match('#^/?media/[A-Za-z0-9/_.-]+$#', $src) && !str_contains($src, '..')) {
            return $app->url(ltrim($src, '/'));
        }
    }

    return null;
};
$summary = function (array $p) use ($fields): string {
    $data = json_decode((string) ($p['data'] ?? ''), true) ?: [];
    $parts = [];
    foreach ($fields as $f) {
        $value = $data[$f['key']] ?? '';
        if (in_array($f['type'] ?? '', ['text', 'number', 'date'], true) && is_scalar($value) && trim((string) $value) !== '') {
            $parts[] = mb_strimwidth(trim((string) $value), 0, 60, '…');
        }
        if (count($parts) === 2) {
            break;
        }
    }

    return implode(' · ', $parts);
};
?>
<?php if ($trash): ?>
<?php if ($items === []): ?>
<?= $app->view->render('admin/empty', ['icon' => 'collections', 'heading' => t('The trash is empty.'), 'text' => t('Deleted items stay here for 30 days, then they are deleted permanently.'), 'action' => [$module->url('items', ['id' => $k['public_id']]), t('Back to items')]]) ?>
<?php else: ?>
<p class="small-text"><?= e(t('Items in the trash are not on the site. A restored item comes back hidden; after 30 days it is permanently deleted from the trash.')) ?></p>
<div class="tab-wrap">
<table class="listing">
<thead><tr><th scope="col"><?= e(t('Item')) ?></th><th scope="col"><?= e(t('In trash since')) ?></th><th scope="col"><span class="visually-hidden"><?= e(t('Actions')) ?></span></th></tr></thead>
<tbody>
<?php foreach ($items as $p): $fieldsOf = ['collection_id' => $k['public_id'], 'item_id' => $p['public_id']]; ?>
<tr>
	<td class="cell-main"><div class="cell-text"><span class="cell-row"><span class="cell-title"><?= e($p['name']) ?></span><?= $p['language'] !== '' ? ' <span class="tag">' . e(strtoupper($p['language'])) . '</span>' : '' ?></span></div></td>
	<td class="cell-weak"><?= e(format_date((string) $p['deleted_at'], true)) ?></td>
	<td class="cell-actions"><?= $app->view->render('admin/list/actions', ['id' => $p['public_id'], 'name' => $p['name'], 'edit' => null, 'csrf' => $csrf,
        'quick' => ['action' => $module->url('restore_item'), 'fields' => $fieldsOf, 'label' => t('Restore'), 'icon' => 'restore'],
        'menu' => [['post' => $module->url('delete_item_permanently'), 'fields' => $fieldsOf, 'label' => t('Delete permanently'), 'confirm' => t('Delete the item permanently? This cannot be undone.'), 'danger' => true]]]) ?></td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<?php endif ?>
<?php else: ?>
<?php if ($items === [] && !$filtered): ?>
<?= $app->view->render('admin/empty', ['icon' => 'collections', 'heading' => t('The collection is empty.'), 'text' => t('Add the first item – then put it on the site with the Collection list element in the builder.'), 'action' => [$module->url('item', ['id' => $k['public_id']]), t('Add item')]]) ?>
<?php elseif ($items === []): ?>
<?= $app->view->render('admin/empty', ['icon' => 'collections', 'heading' => t('No item matches the filter.'), 'text' => '', 'action' => [$module->url('items', ['id' => $k['public_id']]), t('Clear filter')]]) ?>
<?php else: ?>
<div class="tab-wrap">
<table class="listing">
<thead><tr><th scope="col" class="cell-select"><input type="checkbox" data-select-all="bulk" form="bulk" aria-label="<?= e(t('Select all')) ?>"></th><th scope="col"><?= e(t('Item')) ?></th><th scope="col"><?= e(t('Order')) ?></th><?php if ($downloads !== null): ?><th scope="col" title="<?= e(t('Downloads of the stable address /…/latest: the last 30 days / total. Bots are not counted.')) ?>"><?= e(t('Downloads (30 days / total)')) ?></th><?php endif ?><th scope="col"><?= e(t('Status')) ?></th><th scope="col"><span class="visually-hidden"><?= e(t('Actions')) ?></span></th></tr></thead>
<tbody>
<?php foreach ($items as $p):
    $edit = $module->url('item', ['id' => $k['public_id'], 'item' => $p['public_id']]);
    $address = ($p['language'] !== '' ? $p['language'] . '/' : '') . $k['slug'] . '/' . $p['slug'];
    $fieldsOf = ['collection_id' => $k['public_id'], 'item_id' => $p['public_id']];
    $src = $thumb($p);
    $notes = [];
    if ($p['valid_until']) {
        $notes[] = ['text' => t('visible until %s', format_date($p['valid_until']))];
    }
    if ($p['review_by']) {
        $notes[] = ['text' => t('review by %s', format_date($p['review_by']))];
    }
    $menu = [];
    if ($k['detail'] && $p['visible']) {
        $menu[] = ['url' => $app->url($address), 'label' => t('View on the site'), 'external' => true];
        if ($downloads !== null) {
            $menu[] = ['url' => $app->url($address . '/latest'), 'label' => t('Download'), 'icon' => 'download'];
        }
    }
    $menu[] = ['post' => $module->url('duplicate_item'), 'fields' => $fieldsOf, 'label' => t('Duplicate'), 'icon' => 'copy'];
    $menu[] = '-';
    $menu[] = empty($noticeBoard) ? ['post' => $module->url('delete_item'), 'fields' => $fieldsOf, 'label' => t('Move to trash'), 'confirm' => t('Move the item to the trash? It disappears from the site; you can restore it for 30 days.'), 'danger' => true]
        : ['note' => t('Notices stay in the archive – change the takedown date instead.')];
?>
<tr>
	<td class="cell-select"><input type="checkbox" name="selected[]" value="<?= e($p['public_id']) ?>" form="bulk" aria-label="<?= e(t('Select %s', $p['name'])) ?>"></td>
	<td class="cell-main"><div class="cell-row">
		<span class="cell-thumb"><?= $src !== null ? '<img src="' . e($src) . '" alt="" loading="lazy" width="44" height="44">' : $icon('media') ?></span>
		<div class="cell-text"><span class="cell-row"><a class="cell-title" href="<?= e($edit) ?>" title="<?= e($p['name']) ?>"><?= e($p['name']) ?></a><?= $p['language'] !== '' ? ' <span class="tag">' . e(strtoupper($p['language'])) . '</span>' : '' ?></span><?php if (($sub = $summary($p)) !== ''): ?><span class="cell-sub"><?= e($sub) ?></span><?php endif ?></div>
	</div></td>
	<td class="number"><?= (int) $p['sort_order'] ?></td>
<?php if ($downloads !== null): ?>
	<td class="number download"><?= (int) ($downloads[(int) $p['item_id']][0] ?? 0) ?> / <?= (int) ($downloads[(int) $p['item_id']][1] ?? 0) ?></td>
<?php endif ?>
	<td><?= $app->view->render('admin/list/status', ['kind' => $p['visible'] ? 'live' : 'hidden', 'label' => $p['visible'] ? t('Published') : t('Hidden'), 'notes' => $notes]) ?></td>
	<td class="cell-actions"><?= $app->view->render('admin/list/actions', ['id' => $p['public_id'], 'name' => $p['name'], 'edit' => $edit, 'menu' => $menu, 'csrf' => $csrf]) ?></td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
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
<?= $app->view->render('admin/list/selection', ['form' => 'bulk', 'action' => $module->url('bulk_items'), 'csrf' => $csrf, 'hidden' => ['collection_id' => $k['public_id']], 'extra' => $languageSelect, 'actions' => array_merge(
    [['value' => 'visible', 'label' => t('Publish'), 'kind' => 'primary'], ['value' => 'hide', 'label' => t('Hide')]],
    $siteLanguages !== [] ? [['value' => 'language', 'label' => t('Move to this language version')]] : [],
    empty($noticeBoard) ? [['value' => 'trash', 'label' => t('Move to trash'), 'kind' => 'danger', 'confirm' => t('Move the selected items to the trash?')]] : [],
)]) ?>
<?php endif ?>
<?php endif ?>
