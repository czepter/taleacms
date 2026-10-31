<?php
/**
 * One folder of the level: a mosaic of its newest images, the name, the counts and the "⋯" menu (Rename and Move open a popover
 * with a form, Delete asks first and is for administrators).
 *
 * @var Talea\Core\App $app
 * @var Talea\Admin\Modules\Media $module
 * @var string $csrf
 * @var array<string, mixed> $folder      a row of Core\MediaFolders::all()
 * @var list<string> $thumbs              up to three thumbnail paths, newest first
 * @var int $subs                         folders directly inside
 * @var list<array<string, mixed>> $tree  the whole tree (Core\MediaFolders::tree), for the Move select
 * @var string $level                     public id of the folder whose level is shown ('' = the top level): Rename and Move return to it
 * @var list<int> $below                  folder_ids below this folder (they cannot take it)
 * @var array<int, string> $publicOf       folder_id => public id
 */
$icon = require __DIR__ . '/../icons.php';
$id = $folder['public_id'];
$meta = array_filter([$subs > 0 ? count_text($subs, '1 folder', '%d folders') : '', count_text($folder['count'], '1 file', '%d files')]);
?>
<div class="media-folder-card" data-media-drop data-id="<?= e($id) ?>" data-name="<?= e($folder['name']) ?>" data-below="<?= e(implode(' ', array_map(fn (int $b): string => $publicOf[$b] ?? '', $below))) ?>">
	<span class="media-mosaic<?= $thumbs === [] ? ' empty' : '' ?>" aria-hidden="true" draggable="true" data-media-drag="folder">
<?php if ($thumbs === []): ?>
		<?= $icon('categories') ?>
<?php else: for ($i = 0; $i < 3; $i++): ?>
		<span><?php if (isset($thumbs[$i])): ?><img src="<?= e($app->url($thumbs[$i])) ?>" alt="" loading="lazy" draggable="false"><?php endif ?></span>
<?php endfor; endif ?>
	</span>
	<a class="media-folder-name" draggable="true" data-media-drag="folder" href="<?= e($module->url('', ['folder' => $id])) ?>"><?= $icon('categories') ?><span title="<?= e($folder['name']) ?>"><?= bdi($folder['name']) ?></span></a>
	<span class="media-folder-meta">
		<span><?= e(implode(' · ', $meta)) ?><?= $folder['newest'] !== null ? ' · ' . e(t('Changed %s', format_ago($folder['newest']))) : '' ?></span>
<?php if ($folder['missing'] > 0): ?>
		<span class="media-folder-missing"><?= e(t('%d without description', $folder['missing'])) ?></span>
<?php endif ?>
	</span>
	<button class="icon-btn media-tile-more" type="button" popovertarget="folder-menu-<?= e($id) ?>" aria-label="<?= e(t('More actions for %s', $folder['name'])) ?>"><?= $icon('more') ?></button>
	<div class="row-menu" id="folder-menu-<?= e($id) ?>" popover>
		<button type="button" popovertarget="folder-rename-<?= e($id) ?>"><?= $icon('edit') ?><?= e(t('Rename')) ?></button>
		<button type="button" popovertarget="folder-move-<?= e($id) ?>"><?= $icon('subpage') ?><?= e(t('Move')) ?></button>
<?php if ($app->auth()->isAdmin()): ?>
		<hr role="separator">
		<form method="post" action="<?= e($module->url('folder_delete')) ?>" data-confirm="<?= e(t('Delete the folder? Its files and subfolders move up one level.')) ?>"><?= $csrf ?><input type="hidden" name="folder_id" value="<?= e($id) ?>"><button class="danger" type="submit"><?= $icon('trash') ?><?= e(t('Delete folder')) ?></button></form>
<?php endif ?>
	</div>
	<form class="media-pop" id="folder-rename-<?= e($id) ?>" popover method="post" action="<?= e($module->url('folder')) ?>"><?= $csrf ?>
		<input type="hidden" name="folder_id" value="<?= e($id) ?>"><input type="hidden" name="level" value="<?= e($level) ?>">
		<label for="rename-<?= e($id) ?>"><?= e(t('Folder name')) ?></label>
		<input class="textfield" type="text" id="rename-<?= e($id) ?>" name="name" value="<?= e($folder['name']) ?>" maxlength="100" required autofocus>
		<span class="media-pop-buttons"><button class="navigation" type="button" popovertarget="folder-rename-<?= e($id) ?>" popovertargetaction="hide"><?= e(t('Cancel')) ?></button><button class="btn" type="submit"><?= e(t('Rename')) ?></button></span>
	</form>
	<form class="media-pop" id="folder-move-<?= e($id) ?>" popover method="post" action="<?= e($module->url('folder_move')) ?>"><?= $csrf ?>
		<input type="hidden" name="folder_id" value="<?= e($id) ?>"><input type="hidden" name="level" value="<?= e($level) ?>">
		<label for="move-<?= e($id) ?>"><?= e(t('Move “%s” into', $folder['name'])) ?></label>
		<select id="move-<?= e($id) ?>" name="parent"><?= $app->view->render('admin/media/folder_options', ['tree' => $tree, 'topLabel' => t('Top level'), 'selected' => $folder['parent_id'], 'disabled' => [$folder['folder_id'], ...$below]]) ?></select>
		<span class="media-pop-buttons"><button class="navigation" type="button" popovertarget="folder-move-<?= e($id) ?>" popovertargetaction="hide"><?= e(t('Cancel')) ?></button><button class="btn" type="submit"><?= e(t('Move')) ?></button></span>
	</form>
</div>
