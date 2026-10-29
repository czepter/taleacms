<?php
/**
 * @var Talea\Admin\Modules\Sites $module
 * @var Talea\Core\App $app
 * @var string $csrf
 * @var list<array<string, mixed>> $sites
 * @var list<array<string, mixed>> $trash
 * @var int $current  the site this request belongs to
 */
$state = function (array $s): string {
    if (!$s['ready']) {
        return '<span class="badge badge-pending">' . e(t('Setting up…')) . '</span>';
    }

    return '<span class="badge' . ($s['status'] === 'live' ? ' badge-published' : ($s['status'] === 'paused' ? ' badge-draft' : '')) . '">' . e(t(['live' => 'Live', 'paused' => 'Paused', 'archived' => 'Archived'][$s['status']] ?? $s['status'])) . '</span>';
};
$title = fn (array $s): string => $s['name'] !== '' ? (string) $s['name'] : (string) $s['slug'];
?>
<?= $app->view->render('admin/list/head', ['count' => (string) count($sites), 'primary' => [$module->url('new'), t('New site')]]) ?>
<?= $app->view->render('admin/list/toolbar', ['tabsLabel' => t('Sites'), 'tabs' => [['label' => t('Sites'), 'url' => $module->url(), 'active' => true], ['label' => t('Overview'), 'url' => $module->url('overview'), 'active' => false]]]) ?>
<p class="small-text"><?= e(t('Every site has its own pages, news, settings and look. Users, media files, components and facts are shared by all sites. New sites are set up in the background by the scheduled tasks.')) ?></p>
<div class="tab-wrap"><table class="listing">
<thead><tr><th scope="col"><?= e(t('Site')) ?></th><th scope="col"><?= e(t('State')) ?></th><th scope="col"><?= e(t('Primary host name')) ?></th><th scope="col"><?= e(t('Storage')) ?></th><th scope="col"><span class="visually-hidden"><?= e(t('Actions')) ?></span></th></tr></thead>
<tbody>
<?php foreach ($sites as $s): $edit = $module->url('edit', ['id' => $s['public_id']]); $host = $s['domains'][0]['host'] ?? ''; $here = (int) $s['site_id'] === $current; ?>
<tr>
	<td class="cell-main"><div class="cell-text"><a class="cell-title" href="<?= e($edit) ?>"><?= bdi($title($s)) ?></a><?= $here ? '<span class="cell-sub">' . e(t('You are working on this site')) . '</span>' : '' ?></div></td>
	<td><?= $state($s) ?></td>
	<td class="cell-weak"><?= $host !== '' ? bdi($host) : e(t('none yet')) ?></td>
	<td class="number"><?= $s['ready'] ? e(Talea\Core\Files::size((int) $s['bytes'])) : '' ?></td>
	<td class="cell-actions"><?= $app->view->render('admin/list/actions', ['id' => 'site-' . $s['public_id'], 'name' => $title($s), 'edit' => $edit, 'csrf' => $csrf,
		'quick' => $s['ready'] && !$here && $host !== '' ? ['action' => $module->url('handoff'), 'fields' => ['id' => $s['public_id']], 'label' => t('Open this site'), 'icon' => 'out'] : null,
		'menu' => $s['site_id'] != 1 && !$here ? [['post' => $module->url('trash'), 'fields' => ['id' => $s['public_id']], 'label' => t('Move to trash'), 'confirm' => t('Move this site to the trash? It is deleted for good after 30 days.'), 'danger' => true]] : []]) ?></td>
</tr>
<?php endforeach ?>
</tbody>
</table></div>
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
