<?php
/**
 * The details of one file, next to the files (docs/specs/media-library.md): preview with the focal point, Replace / Download /
 * Copy address, the description fields, the folder, where the file is used, the file info, Save and Delete.
 *
 * @var Talea\Core\App $app
 * @var Talea\Admin\Modules\Media $module
 * @var string $csrf
 * @var array<string, mixed> $o         the media row
 * @var array{prev: ?string, next: ?string, used: list<string>, canEdit: bool} $panel
 * @var list<array<string, mixed>> $tree  Core\MediaFolders::tree()
 * @var array<string, scalar> $listState  the parameters of the list the panel was opened from (Save returns to them)
 * @var string $closeUrl
 * @var string|null $prevUrl
 * @var string|null $nextUrl
 */
$icon = require __DIR__ . '/../icons.php';
$isImage = $o['thumb_path'] !== '';
$waiting = $o['staged_at'] !== null;
$file = basename($o['image_path']);
$extension = strtolower(pathinfo($o['image_path'], PATHINFO_EXTENSION));
$used = $panel['used'];
$editable = $panel['canEdit'];
$focal = $isImage && $extension !== 'svg';
[$ox, $oy] = array_map('intval', explode(' ', str_replace('%', '', $o['focal_point'] ?: '50% 50%'))) + [1 => 50];
$address = $app->request->origin() . $app->url($o['image_path']);
$nav = fn (?string $url, string $name, string $label): string => $url === null
    ? '<span class="icon-btn" aria-disabled="true">' . $icon($name) . '<span class="visually-hidden">' . e($label) . '</span></span>'
    : '<a class="icon-btn" href="' . e($url) . '" aria-label="' . e($label) . '" title="' . e($label) . '">' . $icon($name) . '</a>';
?>
<aside class="media-panel" aria-label="<?= e(t('Details')) ?>" data-media-panel>
	<div class="media-panel-head">
		<h2 title="<?= e($file) ?>"><?= bdi($file) ?></h2>
		<div class="media-panel-tools">
			<?= $nav($prevUrl, 'prev', t('Previous file')) ?>
			<?= $nav($nextUrl, 'next', t('Next file')) ?>
			<a class="icon-btn" href="<?= e($closeUrl) ?>" aria-label="<?= e(t('Close details')) ?>" title="<?= e(t('Close details')) ?>"><?= $icon('close') ?></a>
		</div>
	</div>
	<form method="post" action="<?= e($module->url('save')) ?>" id="media-details-form">
		<?= $csrf ?>
		<input type="hidden" name="media_id" value="<?= e($o['public_id']) ?>">
		<input type="hidden" name="list" value="<?= e(http_build_query($listState)) ?>">
		<div class="media-panel-body">
<?php if ($focal): ?>
			<div>
				<div class="focal" data-focal><img src="<?= e($app->url($o['thumb_path'])) ?>" alt="" width="<?= (int) $o['thumb_width'] ?>" height="<?= (int) $o['thumb_height'] ?>"><span class="focal-point" style="left:<?= $ox ?>%;top:<?= $oy ?>%"></span></div>
				<input type="hidden" name="focus_x" value="<?= $ox ?>"><input type="hidden" name="focus_y" value="<?= $oy ?>">
				<p class="media-preview-help"><?= e(t('Click in the preview on what must stay visible when the photo is cropped to another shape (card, section background).')) ?></p>
			</div>
<?php elseif ($isImage): ?>
			<div class="media-panel-preview"><img src="<?= e($app->url($o['thumb_path'])) ?>" alt=""></div>
<?php else: ?>
			<div class="media-panel-preview file"><span><?= e(strtoupper($extension)) ?></span></div>
<?php endif ?>
			<div class="media-actions">
<?php if ($waiting): ?>
				<span class="media-flag media-flag-waiting"><?= e(t('Waiting for approval')) ?></span>
<?php if ($app->auth()->canPublish()): ?>
				<button class="navigation" type="submit" form="media-approve"><?= $icon('check') ?><?= e(t('Publish file')) ?></button>
<?php endif ?>
<?php elseif ($editable && preg_match('/^(jpg|png|webp)$/', $extension)): ?>
				<button class="navigation" type="button" popovertarget="media-replace"><?= $icon('restore') ?><?= e(t('Replace file')) ?></button>
<?php endif ?>
				<a class="navigation" href="<?= e($app->url($o['image_path'])) ?>" download><?= $icon('download') ?><?= e(t('Download')) ?></a>
				<button class="navigation" type="button" data-copy="#media-address"><?= $icon('copy') ?><?= e(t('Copy address')) ?></button>
			</div>
			<div class="field">
				<label for="media-name"><?= e(t('Description (alternative text)')) ?></label>
				<input class="textfield wide" id="media-name" type="text" name="name" value="<?= e($o['name']) ?>" maxlength="150"<?= $editable ? '' : ' disabled' ?>>
				<span class="help"><?= e(t('Describe what is in the image - screen readers and search engines read it.')) ?></span>
			</div>
			<div class="field">
				<label for="media-caption"><?= e(t('Caption below the image')) ?></label>
				<input class="textfield wide" id="media-caption" type="text" name="description" value="<?= e($o['description']) ?>" maxlength="500"<?= $editable ? '' : ' disabled' ?>>
			</div>
			<div class="field">
				<label for="media-author"><?= e(t('Image credit')) ?></label>
				<input class="textfield wide" id="media-author" type="text" name="author" value="<?= e($o['author'] ?? '') ?>" maxlength="120"<?= $editable ? '' : ' disabled' ?>>
				<span class="help"><?= e(t('Shown under a news item\'s main photo unless it has its own photo credit.')) ?></span>
			</div>
			<div class="field">
				<label for="media-folder"><?= e(t('Folder')) ?></label>
				<select id="media-folder" name="folder_id"<?= $editable ? '' : ' disabled' ?>><?= $app->view->render('admin/media/folder_options', ['tree' => $tree, 'topLabel' => t('Not in a folder'), 'selected' => $o['folder_id'] === null ? null : (int) $o['folder_id']]) ?></select>
			</div>
			<div class="field">
				<div class="media-section-title"><span><?= e($used === [] ? t('Used in') : (count($used) === 1 ? t('Used in 1 place') : t('Used in %d places', count($used)))) ?></span></div>
<?php if ($used === []): ?>
				<p class="help"><?= e(t('Not used anywhere.')) ?></p>
<?php else: ?>
				<ul class="media-used">
<?php foreach ($used as $place): ?>
					<li><?= e($place) ?></li>
<?php endforeach ?>
				</ul>
<?php endif ?>
			</div>
			<dl class="media-info">
				<dt><?= e(t('File')) ?></dt><dd><?= bdi($file) ?></dd>
<?php if ($isImage && $extension !== 'svg'): ?>
				<dt><?= e(t('Dimensions')) ?></dt><dd><?= (int) $o['image_width'] ?> &times; <?= (int) $o['image_height'] ?> px</dd>
<?php endif ?>
				<dt><?= e(t('Size')) ?></dt><dd><?= e(Talea\Core\Files::size((int) $o['image_size'])) ?></dd>
				<dt><?= e(t('Uploaded')) ?></dt><dd><?= e(format_date($o['created_at'], true)) ?></dd>
				<dt><?= e(t('Address')) ?></dt><dd><code id="media-address"><?= e($address) ?></code></dd>
			</dl>
		</div>
		<div class="media-panel-foot">
<?php if ($editable): ?>
			<button class="btn" type="submit"><?= e(t('Save')) ?></button>
			<button class="navigation danger end" type="submit" form="media-delete" data-confirm="<?= e(t('Delete this file? This cannot be undone.')) ?>"<?= $used !== [] ? ' disabled' : '' ?>><?= e(t('Delete')) ?></button>
<?php if ($used !== []): ?>
			<span class="media-foot-note"><?= e(count($used) === 1 ? t('The file is used in 1 place. Remove it there first to delete it.') : t('The file is used in %d places. Remove it there first to delete it.', count($used))) ?></span>
<?php endif ?>
<?php else: ?>
			<span class="media-foot-note"><?= e(t('You cannot edit this image.')) ?></span>
<?php endif ?>
		</div>
	</form>
	<form id="media-delete" method="post" action="<?= e($module->url('bulk')) ?>" hidden><?= $csrf ?><input type="hidden" name="bulk" value="delete"><input type="hidden" name="selected[]" value="<?= e($o['public_id']) ?>"></form>
<?php if ($waiting): ?>
	<form id="media-approve" method="post" action="<?= e($module->url('approve')) ?>" hidden><?= $csrf ?><input type="hidden" name="media_id" value="<?= e($o['public_id']) ?>"></form>
<?php elseif ($editable && preg_match('/^(jpg|png|webp)$/', $extension)): ?>
	<form class="media-pop" id="media-replace" popover method="post" action="<?= e($module->url('replace')) ?>" enctype="multipart/form-data"><?= $csrf ?>
		<input type="hidden" name="media_id" value="<?= e($o['public_id']) ?>"><input type="hidden" name="list" value="<?= e(http_build_query($listState)) ?>">
		<label for="media-replacement"><?= e(t('Replace file')) ?></label>
		<input type="file" id="media-replacement" name="file" accept="image/jpeg,image/png,image/webp" required>
		<span class="help"><?= e(t('The new photo appears everywhere the old one is used – the file address does not change.')) ?></span>
		<span class="media-pop-buttons"><button class="navigation" type="button" popovertarget="media-replace" popovertargetaction="hide"><?= e(t('Cancel')) ?></button><input class="btn" type="submit" value="<?= e(t('Replace')) ?>"></span>
	</form>
<?php endif ?>
</aside>
