<?php
/**
 * @var Talea\Core\App $app
 * @var Talea\Admin\Modules\MissingPages $module
 * @var string $csrf
 * @var list<array{path: string, count: int, last_seen_at: string, referrer: string, first_seen_at: ?string}> $rows
 * @var string $status  pending | ignored
 * @var string $sort  hits | new
 * @var array<string, array{to: string, score: int}> $suggestions  the page each address most likely meant (Core\RedirectMatcher)
 * @var array{pending: int, ignored: int} $counts
 * @var array{new: int, visits: int, redirected: int, ignored: int} $tiles
 */
$view->slot('count', '');
$view->slot('actions', '<a class="navigation" href="' . e($app->url('admin.php?module=redirects#automatic')) . '">' . e(t('Settings')) . '</a>'
    . '<form class="inline" method="post" action="' . e($module->url('clear')) . '" data-confirm="' . e(t('Clear the whole list? Ignored addresses stay ignored.')) . '">' . $csrf . '<button class="navigation danger" type="submit">' . e(t('Clear the list')) . '</button></form>');
?>
<p class="small-text"><?= e(t('Addresses visitors asked for that do not exist. Redirect the ones people really use; ignore the rest.')) ?></p>
<div class="tiles">
<div class="tiles-item"><strong><?= $tiles['new'] ?></strong><span><?= e(t('New addresses this week')) ?></span></div>
<div class="tiles-item"><strong><?= $tiles['visits'] ?></strong><span><?= e(t('Visits that ended here')) ?></span></div>
<div class="tiles-item"><strong><?= $tiles['redirected'] ?></strong><span><?= e(t('Already redirected')) ?></span></div>
<div class="tiles-item"><strong><?= $tiles['ignored'] ?></strong><span><?= e(t('Ignored')) ?></span></div>
</div>
<?= $app->view->render('admin/list/toolbar', ['tabsLabel' => t('Filter'), 'action' => $app->url('admin.php'), 'hidden' => ['module' => 'missing_pages', 'status' => $status],
    'tabs' => [
        ['label' => t('To decide'), 'count' => $counts['pending'], 'url' => $module->url('', array_filter(['sort' => $sort === 'hits' ? '' : $sort])), 'active' => $status === 'pending'],
        ['label' => t('Ignored'), 'count' => $counts['ignored'], 'url' => $module->url('', array_filter(['status' => 'ignored', 'sort' => $sort === 'hits' ? '' : $sort])), 'active' => $status === 'ignored'],
    ],
    'filters' => '<select name="sort" data-submit-on-change aria-label="' . e(t('Sort')) . '"><option value="hits"' . ($sort === 'hits' ? ' selected' : '') . '>' . e(t('Most visits first')) . '</option><option value="new"' . ($sort === 'new' ? ' selected' : '') . '>' . e(t('Newest first')) . '</option></select>']) ?>
<?php if ($rows === []): ?>
<?= $app->view->render('admin/empty', ['icon' => 'redirects', 'heading' => t($status === 'ignored' ? 'Nothing ignored.' : 'No missing pages.'), 'text' => t('Addresses that visitors repeatedly could not find show up here.')]) ?>
<?php else: ?>
<div class="tab-wrap">
<table class="listing">
<thead><tr><th scope="col"><?= e(t('Address')) ?></th><th scope="col"><?= e(t('Visits')) ?></th><th scope="col"><?= e(t('Last visit')) ?></th><th scope="col"><span class="visually-hidden"><?= e(t('Actions')) ?></span></th></tr></thead>
<tbody>
<?php foreach ($rows as $n): $tip = $suggestions[$n['path']] ?? null;
    // where the visitor came from and when the address first showed up share the line under the address, so the table fits beside the redirect form
    $sub = array_filter([$n['referrer'] === '' ? '' : (str_starts_with($n['referrer'], '/') ? e(t('your site: %s', $n['referrer'])) : '<bdi>' . e($n['referrer']) . '</bdi>'),
        $n['first_seen_at'] !== null ? e(t('First seen %s', format_date($n['first_seen_at']))) : '']); ?>
<tr>
	<td class="cell-main"><div class="cell-text"><code class="cell-title" title="/<?= e($n['path']) ?>">/<?= e($n['path']) ?></code><?php if ($sub !== []): ?><span class="cell-sub"><?= implode(' · ', $sub) ?></span><?php endif ?></div></td>
	<td class="number"><strong><?= (int) $n['count'] ?></strong></td>
	<td class="cell-weak"><?= e(format_ago($n['last_seen_at'])) ?></td>
	<td class="cell-actions"><?php if ($status === 'pending'): ?><div class="row-actions">
		<form class="inline" method="post" action="<?= e($module->url('redirect')) ?>"><?= $csrf ?><input type="hidden" name="path" value="<?= e($n['path']) ?>">
			<input class="textfield" type="text" name="to" value="<?= $tip !== null ? e('/' . $tip['to']) : '' ?>" placeholder="<?= e(t('/new-address')) ?>" aria-label="<?= e(t('Redirect to')) ?>" size="16" required>
			<?php if ($tip !== null): ?><span class="tag"><?= e(t('score %d', $tip['score'])) ?></span><?php endif ?>
			<button class="btn" type="submit"><?= e(t('Redirect')) ?></button></form>
		<form class="inline" method="post" action="<?= e($module->url('ignore')) ?>"><?= $csrf ?><input type="hidden" name="path" value="<?= e($n['path']) ?>"><button class="navigation" type="submit" aria-label="<?= e(t('Ignore: %s', '/' . $n['path'])) ?>" title="<?= e(t('Ignore – nothing replaces it, or it is a bot probing for other systems. It will not come back.')) ?>"><?= e(t('Ignore')) ?></button></form>
	</div><?php endif ?></td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<?php if ($status === 'pending'): ?>
<form class="inline" method="post" action="<?= e($module->url('ignore_all')) ?>" data-confirm="<?= e(t('Ignore all these addresses? The warning on the start screen goes away until a new address appears.')) ?>"><?= $csrf ?><button class="navigation" type="submit"><?= e(t('Ignore all')) ?></button></form>
<?php endif ?>
<?php endif ?>
