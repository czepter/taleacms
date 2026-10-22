<?php
/**
 * @var Talea\Core\App $app
 * @var Talea\Admin\Modules\Redirects $module
 * @var string $csrf
 * @var list<array<string, mixed>> $records
 * @var list<array<string, mixed>> $notFound  urls that ended with a 404 error in the last 60 days
 * @var array<string, array{to: string, score: int}> $suggestions  the page each missing address most likely meant (Core\RedirectMatcher)
 * @var bool $autoOn  the daily job creates the sure redirects by itself
 * @var int $threshold  the score a candidate needs for that
 * @var int $total
 * @var int $pageNumber
 * @var int $pageCount
 * @var string $search
 */
$path = fn (string $a): string => preg_match('#^https?://#i', $a) ? $a : '/' . $a;
$icon = require __DIR__ . '/../icons.php';
echo $app->view->render('admin/list/head', ['count' => (string) $total, 'primary' => [$module->url('', ['new' => 1]), t('New redirect')]]);
?>
<?php if ($notFound !== []):
    ob_start();
    foreach ($notFound as $n): $tip = $suggestions[$n['path']] ?? null; ?>
<div class="attention-row">
	<code class="attention-path" title="/<?= e($n['path']) ?>">/<?= e($n['path']) ?></code>
	<span class="cell-sub"><?= e(t('%d hits', (int) $n['count'])) ?> · <?= e(format_ago($n['last_seen_at'])) ?></span>
	<span class="attention-tip"><?php if ($tip !== null): ?><?= $icon('out') ?> <code title="/<?= e($tip['to']) ?>">/<?= e($tip['to']) ?></code> <span class="tag"><?= e(t('score %d', $tip['score'])) ?></span><?php else: ?><span class="cell-sub"><?= e(t('no similar page')) ?></span><?php endif ?></span>
	<span class="row-actions">
		<?php if ($tip !== null): ?><form class="inline" method="post" action="<?= e($module->url('save')) ?>"><?= $csrf ?><input type="hidden" name="from_path" value="<?= e('/' . $n['path']) ?>"><input type="hidden" name="to_path" value="<?= e('/' . $tip['to']) ?>"><input type="hidden" name="type" value="301"><button class="btn" type="submit"><?= e(t('Create redirect')) ?></button></form><?php endif ?>
		<a class="<?= $tip !== null ? 'quiet-link' : 'navigation' ?>" href="<?= e($module->url('', ['from' => $n['path']])) ?>" title="<?= e(t('Opens a new redirect with this old address filled in. You add the page that replaced it.')) ?>"><?= e(t($tip !== null ? 'Elsewhere' : 'Redirect')) ?></a>
		<form class="inline" method="post" action="<?= e($module->url('ignore')) ?>"><?= $csrf ?><input type="hidden" name="path" value="<?= e($n['path']) ?>"><button class="icon-btn" type="submit" aria-label="<?= e(t('Ignore: %s', '/' . $n['path'])) ?>" title="<?= e(t('Ignore – nothing replaces it, or it is a bot probing for other systems. It will not come back.')) ?>"><?= $icon('close') ?></button></form>
	</span>
</div>
<?php endforeach;
    $rows = (string) ob_get_clean();
    echo $app->view->render('admin/list/attention', ['id' => 'not-found', 'title' => t('Addresses visitors could not find (404)') . ' · ' . count($notFound), 'meta' => t('last 60 days'), 'rows' => $rows,
        'aside' => '<form class="inline attention-aside" method="post" action="' . e($module->url('ignore_all')) . '" data-confirm="' . e(t('Ignore all these addresses? The warning on the start screen goes away until a new address appears.')) . '">' . $csrf . '<button class="navigation" type="submit">' . e(t('Ignore all')) . '</button></form>']);
endif ?>
<?= $app->view->render('admin/list/toolbar', ['tabs' => [], 'tabsLabel' => '', 'action' => $app->url('admin.php'), 'hidden' => ['module' => 'redirects'],
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
	<td><span class="cell-row"><?= $icon('out') ?><code class="cell-sub" title="<?= e($path($z['to_path'])) ?>"><?= e($path($z['to_path'])) ?></code><?= (int) ($z['type'] ?? 301) === 302 ? ' <span class="tag">302</span>' : '' ?></span></td>
	<td class="number"><?= (int) $z['hits'] ?>×</td>
	<td class="cell-weak"><?= e(format_ago($z['created_at'])) ?></td>
	<td class="cell-actions"><?= $app->view->render('admin/list/actions', ['id' => $z['public_id'], 'name' => '/' . $z['from_path'], 'edit' => $editUrl, 'csrf' => $csrf, 'menu' => [
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
