<?php
/**
 * @var Talea\Admin\Modules\Sites $module
 * @var Talea\Core\App $app
 * @var string $csrf
 * @var list<array<string, mixed>> $sites
 * @var list<array<string, mixed>> $trash
 * @var int $current  the site this request belongs to
 * @var int $mediaWithoutAlt  images of the owner without a description
 * @var int $limit  TALEA_MAX_SITES, 0 = none
 * @var bool $agency  agency mode (owner column, tiles)
 * @var bool $operator  the user is an operator (owner filter, Move)
 * @var array<string, string> $ownerNames  owner public id => name
 * @var string $ownerFilter
 * @var string $search
 * @var int $siteCount
 * @var int $ownerCount
 * @var int $ownerLimit  TALEA_MAX_OWNERS, 0 = none
 */
$state = function (array $s): string {
    if (!$s['ready'] && ($s['import_error'] ?? '') !== '') {
        return '<span class="badge badge-error">' . e(t('Import failed')) . '</span>';
    }
    if (!$s['ready'] && ($s['import_file'] ?? '') !== '') {
        $p = Talea\Core\SiteImports::progress($s);

        return '<span class="badge badge-pending">' . e(t('Importing… %d %%', (int) ($p['percent'] ?? 0))) . '</span>';
    }
    if (!$s['ready']) {
        return '<span class="badge badge-pending">' . e(t('Setting up…')) . '</span>';
    }

    return '<span class="badge' . ($s['status'] === 'live' ? ' badge-published' : ($s['status'] === 'paused' ? ' badge-draft' : '')) . '">' . e(t(['live' => 'Live', 'paused' => 'Paused', 'archived' => 'Archived'][$s['status']] ?? $s['status'])) . '</span>';
};
$title = fn (array $s): string => $s['name'] !== '' ? (string) $s['name'] : (string) $s['slug'];
?>
<?= $app->view->render('admin/list/head', ['count' => (string) count($sites), 'links' => array_merge([[$module->url('import'), t('Import a Talea archive')]], Talea\Core\Owners::isFirst($app->db()) ? [] : [[$module->url('files'), t('Backups and exports')]]), 'primary' => [$module->url('new'), t('New site')]]) ?>
<?= $app->view->render('admin/list/toolbar', ['tabsLabel' => t('Sites'), 'tabs' => [['label' => t('Sites'), 'url' => $module->url(), 'active' => true], ['label' => t('Overview'), 'url' => $module->url('overview'), 'active' => false]]]) ?>
<?php $unverified = array_sum(array_column($sites, 'unverified')); ?>
<?php if ($unverified > 0): ?>
<div class="notice notice-warning" role="status"><?= e(t('%d addresses are waiting for verification. A site does not answer on an address until it has been verified.', $unverified)) ?></div>
<?php endif ?>
<?php if ($agency): ?>
<div class="site-tiles">
	<div class="site-tile"><span class="site-tile-label"><?= e(t('Sites')) ?></span><strong><?= (int) $siteCount ?><?= $limit > 0 ? ' / ' . $limit : '' ?></strong></div>
	<?php if ($operator): ?><div class="site-tile"><span class="site-tile-label"><?= e(t('Owners')) ?></span><strong><?= (int) $ownerCount ?><?= $ownerLimit > 0 ? ' / ' . $ownerLimit : '' ?></strong></div><?php endif ?>
</div>
<form class="site-filter" method="get" action="<?= e($module->url()) ?>">
	<input type="hidden" name="module" value="<?= e($module::IDENT) ?>">
	<input type="search" name="search" value="<?= e($search) ?>" placeholder="<?= e(t('Search sites')) ?>" aria-label="<?= e(t('Search sites')) ?>">
	<?php if ($operator): ?><select name="owner" aria-label="<?= e(t('Owner')) ?>" data-submit-on-change><option value=""><?= e(t('All owners')) ?></option><?php foreach ($ownerNames as $pid => $name): ?><option value="<?= e($pid) ?>"<?= (string) $pid === $ownerFilter ? ' selected' : '' ?>><?= bdi($name) ?></option><?php endforeach ?></select><?php endif ?>
	<button class="navigation" type="submit"><?= e(t('Search')) ?></button>
</form>
<?php endif ?>
<p class="small-text"><?= e(t('Every site has its own pages, news, settings and look. Users, media files, components and facts are shared by all sites. New sites are set up in the background by the scheduled tasks.')) ?></p>
<div class="tab-wrap"><table class="listing">
<thead><tr><th scope="col"><?= e(t('Site')) ?></th><?php if ($agency): ?><th scope="col"><?= e(t('Owner')) ?></th><?php endif ?><th scope="col"><?= e(t('Addresses')) ?></th><th scope="col"><?= e(t('Last backup')) ?></th><th scope="col"><?= e(t('Status')) ?></th><th scope="col"><?= e(t('Storage')) ?></th><th scope="col"><?= e(t('Open enquiries')) ?></th><th scope="col"><?= e(t('Broken links')) ?></th><th scope="col"><span class="visually-hidden"><?= e(t('Actions')) ?></span></th></tr></thead>
<tbody>
<?php foreach ($sites as $s): $edit = $module->url('edit', ['id' => $s['public_id']]); $host = $s['domains'][0]['host'] ?? ''; $here = (int) $s['site_id'] === $current; $own = $s['own']; ?>
<tr>
	<td class="cell-main"><div class="cell-text"><?php if ($own): ?><a class="cell-title" href="<?= e($edit) ?>"><?= bdi($title($s)) ?></a><?php else: ?><span class="cell-title"><?= bdi($title($s)) ?></span><?php endif ?><?= $here ? '<span class="cell-sub">' . e(t('You are working on this site')) . '</span>' : '' ?><?= ($s['import_error'] ?? '') !== '' ? '<span class="cell-sub" role="alert">' . bdi((string) $s['import_error']) . '</span>' : '' ?></div></td>
	<?php if ($agency): ?><td class="cell-weak"><?= bdi((string) $s['owner_name']) ?></td><?php endif ?>
	<td class="cell-weak"><?= $host !== '' ? bdi($host) : e(t('none yet')) ?><?= count($s['domains']) > 1 ? ' <span class="cell-sub">' . e(t('+ %d more', count($s['domains']) - 1)) . '</span>' : '' ?><?= $s['unverified'] > 0 ? ' <span class="badge badge-pending">' . e(t('%d open', $s['unverified'])) . '</span>' : '' ?></td>
	<td class="cell-weak"><?php if (!$s['ready']): ?><?php elseif ($s['backup'] === 0): ?><span class="badge badge-error"><?= e(t('none')) ?></span><?php else: ?><?= e(format_date(date('Y-m-d H:i:s', $s['backup']))) ?><?= time() - $s['backup'] > 2 * 86400 ? ' <span class="badge badge-error">' . e(t('old')) . '</span>' : '' ?><?php endif ?></td>
	<td><?= $state($s) ?></td>
	<td class="number"><?= $s['ready'] ? e(Talea\Core\Files::size((int) $s['bytes'])) : '' ?></td>
	<td class="number"><?= $s['health'] ? (int) $s['health']['enquiries'] : '' ?></td>
	<td class="number"><?= $s['health'] ? (int) $s['health']['broken_links'] : '' ?></td>
	<td class="cell-actions"><?php if ($own): ?><?= $app->view->render('admin/list/actions', ['id' => 'site-' . $s['public_id'], 'name' => $title($s), 'edit' => $edit, 'csrf' => $csrf,
		'menu' => array_merge(
			$s['ready'] && !$here && $host !== '' ? [['post' => $module->url('handoff'), 'fields' => ['id' => $s['public_id']], 'label' => t('Open'), 'icon' => 'out']] : [],
			// the two pages below are built elsewhere; point them at the screens that exist today
			[['url' => $edit, 'label' => t('Address and visibility')], ['url' => $module->url('import'), 'label' => t('Import and export')]],
			$operator ? [['url' => $app->url('admin.php?' . http_build_query(['module' => 'owners', 'action' => 'transfer', 'site' => $s['public_id']])), 'label' => t('Move')]] : [],
			$s['site_id'] != 1 && !$here ? array_merge(['-'],
				$s['status'] !== 'archived' ? [['post' => $module->url('archive'), 'fields' => ['id' => $s['public_id']], 'label' => t('Archive'), 'icon' => 'check', 'confirm' => t('Archive this site? It stops answering and can be set live again on its edit page.')]] : [],
				[['post' => $module->url('trash'), 'fields' => ['id' => $s['public_id']], 'label' => t('Move to trash'), 'confirm' => t('Move this site to the trash? It is deleted for good after 30 days.'), 'danger' => true]]) : [],
		)]) ?><?php endif ?></td>
</tr>
<?php endforeach ?>
</tbody>
</table></div>
<p class="small-text"><?= $limit > 0 ? e(t('The installation allows %d sites.', $limit)) . ' ' : '' ?><?= e(t('Images without a description (shared by all sites): %d.', $mediaWithoutAlt)) ?> <a href="<?= e($module->url('usage')) ?>"><?= e(t('Download the usage per month (CSV)')) ?></a></p>
<?php if ($trash !== []): ?>
<h2><?= e(t('Trash')) ?></h2>
<p class="small-text"><?= e(t('A site in the trash does not answer and is deleted for good after 30 days.')) ?></p>
<div class="tab-wrap"><table class="listing">
<tbody>
<?php foreach ($trash as $s): ?>
<tr>
	<td class="cell-main"><div class="cell-text"><span class="cell-title"><?= bdi($title($s)) ?></span><span class="cell-sub"><?= e(t('In the trash since %s', format_date((string) $s['trashed_at']))) ?></span></div></td>
	<td class="cell-actions"><form class="inline" method="post" action="<?= e($module->url('restore')) ?>"><?= $csrf ?><input type="hidden" name="id" value="<?= e($s['public_id']) ?>"><button class="navigation" type="submit"><?= e(t('Restore')) ?></button></form></td>
</tr>
<?php endforeach ?>
</tbody>
</table></div>
<?php endif ?>
