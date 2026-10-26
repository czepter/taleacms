<?php
/**
 * @var Talea\Core\App $app
 * @var Talea\Admin\Modules\Redirects $module
 * @var string $csrf
 * @var list<array<string, mixed>> $records
 * @var bool $autoOn  the daily job creates the sure redirects by itself
 * @var int $threshold  the score a candidate needs for that
 * @var int $total
 * @var int $pageNumber
 * @var int $pageCount
 * @var string $search
 * @var string $status  all | active | off | auto
 * @var array{all: int, active: int, off: int, auto: int} $counts
 */
$path = fn (string $a): string => preg_match('#^https?://#i', $a) ? $a : '/' . $a;
$icon = require __DIR__ . '/../icons.php';
echo $app->view->render('admin/list/head', ['count' => (string) $total, 'primary' => [$module->url('', ['new' => 1]), t('New redirect')]]);
?>
<?= $app->view->render('admin/list/toolbar', ['tabsLabel' => t('Redirects'), 'action' => $app->url('admin.php'), 'hidden' => ['module' => 'redirects', 'status' => $status],
    'tabs' => array_map(fn (string $key, string $name): array => ['label' => t($name), 'count' => $counts[$key], 'url' => $module->url('', array_filter(['status' => $key === 'all' ? '' : $key, 'search' => $search])), 'active' => $status === $key],
        ['all', 'active', 'off', 'auto'], ['All', 'Active', 'Off', 'Made by Talea']),
    'search' => ['name' => 'search', 'value' => $search, 'placeholder' => t('Address contains'), 'label' => t('Search redirects')]]) ?>
<?php if ($records === []): ?>
<?= $app->view->render('admin/empty', ['icon' => 'redirects', 'heading' => t($search !== '' ? 'Nothing found.' : 'No redirects yet.'), 'text' => t('Nothing to do – when you change the address of a page or news item, a redirect is created automatically.')]) ?>
<?php else: ?>
<div class="tab-wrap">
<table class="listing">
<thead><tr><th scope="col"><?= e(t('Old address')) ?></th><th scope="col"><?= e(t('Target')) ?></th><th scope="col"><?= e(t('Used')) ?></th><th scope="col"><?= e(t('Created')) ?></th><th scope="col"><span class="visually-hidden"><?= e(t('Actions')) ?></span></th></tr></thead>
<tbody>
<?php foreach ($records as $z): $editUrl = $module->url('', ['edit' => $z['public_id']]); ?>
<tr>
	<td class="cell-main"><div class="cell-row"><a class="cell-title cell-code" href="<?= e($editUrl) ?>" title="/<?= e($z['from_path']) ?>">/<?= e($z['from_path']) ?></a><?= $z['auto_score'] !== null ? ' <span class="tag" title="' . e(t('Created by the site itself from an address visitors could not find. Delete it to undo.')) . '">' . e(t('automatic, score %d', (int) $z['auto_score'])) . '</span>' : '' ?></div></td>
	<td><span class="cell-row"><?php if ((int) $z['type'] >= 400): ?><span class="cell-sub"><?= e(t($z['type'] == 410 ? 'Gone' : 'Unavailable for legal reasons')) ?></span><?php else: ?><?= $icon('out') ?><code class="cell-sub" title="<?= e($path($z['to_path'])) ?>"><?= e($path($z['to_path'])) ?></code><?php endif ?>
		<?= (int) $z['type'] !== 301 ? ' <span class="tag">' . (int) $z['type'] . '</span>' : '' ?><?= ($z['match_type'] ?? 'exact') !== 'exact' ? ' <span class="tag">' . e(t(['contains' => 'Contains', 'starts' => 'Starts with', 'ends' => 'Ends with', 'regex' => 'Regular expression'][$z['match_type']] ?? $z['match_type'])) . '</span>' : '' ?><?= !$z['active'] ? ' <span class="tag">' . e(t('Off')) . '</span>' : '' ?></span></td>
	<td class="number"><?= (int) $z['hits'] ?>×<?= $z['last_hit_at'] !== null ? '<br><span class="cell-sub">' . e(format_ago($z['last_hit_at'])) . '</span>' : '' ?></td>
	<td class="cell-weak"><?= e(format_ago($z['created_at'])) ?></td>
	<td class="cell-actions"><?= $app->view->render('admin/list/actions', ['id' => $z['public_id'], 'name' => '/' . $z['from_path'], 'edit' => $editUrl, 'csrf' => $csrf, 'menu' => [
        ['url' => $module->url('test', ['id' => $z['public_id']]), 'label' => t('Test'), 'icon' => 'b-search'],
        ['post' => $module->url('duplicate'), 'fields' => ['redirect_id' => $z['public_id']], 'label' => t('Duplicate'), 'icon' => 'copy'],
        ['post' => $module->url('toggle'), 'fields' => ['redirect_id' => $z['public_id'], 'status' => $status === 'all' ? '' : $status, 'search' => $search], 'label' => t($z['active'] ? 'Turn off' : 'Turn on'), 'icon' => 'check'],
        '-',
        ['post' => $module->url('delete'), 'fields' => ['redirect_id' => $z['public_id'], 'title' => '/' . $z['from_path']], 'label' => t('Delete'), 'confirm' => t('Delete the redirect? The old address will then end with a 404 error.'), 'danger' => true],
    ]]) ?></td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<?php if ($pageCount > 1): ?>
<p class="pagination">
<?php for ($s = 1; $s <= $pageCount; $s++): ?>
	<?= $s === $pageNumber ? '<strong>[' . $s . ']</strong>' : '<a href="' . e($module->url('', array_filter(['search' => $search, 'page' => $s]))) . '">' . $s . '</a>' ?>
<?php endfor ?>
</p>
<?php endif ?>
<?php endif ?>
<p class="small-text"><?= e(t('A redirect is only used when nothing exists at the old address. It is created automatically when the address of a page, news item or category changes.')) ?></p>
<details class="panel-collapsed" id="automatic">
<summary><?= e(t('Create redirects for missing addresses by themselves')) ?><?= $autoOn ? ' · ' . e(t('on')) : '' ?></summary>
<form class="form" method="post" action="<?= e($module->url('settings')) ?>">
<?= $csrf ?>
<div class="row"><label for="redirect_auto"><?= e(t('By themselves')) ?></label><div><label><input type="checkbox" id="redirect_auto" name="redirect_auto" value="1"<?= $autoOn ? ' checked' : '' ?>> <?= e(t('Create redirects for missing addresses by themselves')) ?></label>
	<span class="help"><?= e(t('Once a day the site redirects an address visitors could not find to the page it is sure they meant (the same address written differently, moved to another section or language). Such a redirect is marked automatic; deleting it undoes it. Less certain candidates stay below as suggestions.')) ?></span></div></div>
<div class="row"><label for="redirect_auto_threshold"><?= e(t('Needed score')) ?></label><div><input class="textfield" type="number" id="redirect_auto_threshold" name="redirect_auto_threshold" value="<?= $threshold ?>" min="50" max="100" size="4"> <span class="help"><?= e(t('0–100; 100 = the same address written differently, 95 = another section or language, 90 = the same last part of the address, lower = a similar address.')) ?></span></div></div>
<p class="buttons"><input class="btn" type="submit" value="<?= e(t('Save settings')) ?>"></p>
</form>
</details>
