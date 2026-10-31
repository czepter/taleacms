<?php
/**
 * Media: the left menu, then the folders of this level above its files (docs/specs/media-library.md).
 *
 * @var Talea\Core\App $app
 * @var Talea\Admin\Modules\Media $module
 * @var string $csrf
 * @var list<array<string, mixed>> $images  the files shown
 * @var int $total      files of the list (all of them, not only the shown)
 * @var int $show       how many are shown (parameter show)
 * @var string $limit
 * @var array<string, mixed> $filter
 * @var list<array<string, mixed>> $folders  Core\MediaFolders::all()
 * @var array<string, mixed>|null $current   the folder whose level is shown
 * @var list<array<string, mixed>> $path     its breadcrumb
 * @var list<array<string, mixed>> $cards    the folders of this level
 * @var array<int, int> $subfolders          folder_id => folders directly inside
 * @var array<int, list<string>> $thumbnails folder_id => newest thumbnails
 * @var array{total: int, recent: int, missing: int, waiting: int, unused: int} $counts
 * @var string|null $newsItem  title of the news item used as the filter
 * @var array<string, mixed>|null $item  the file opened with ?item= (the details panel)
 * @var array{prev: ?string, next: ?string, used: list<string>, canEdit: bool}|null $panel
 * @var array{places: array<string, array<int, int|null>>, message: string}|null $undo  the move the Undo notice offers
 */
$icon = require __DIR__ . '/../icons.php';
$tree = Talea\Core\MediaFolders::tree($folders);
$names = array_column($folders, 'name', 'folder_id');
$across = !$filter['level'];
$table = $filter['view'] === 'table';

// the state of the screen as parameters, and a link that changes some of it (null removes a parameter)
$state = fn (): array => array_filter([
    'folder' => $filter['folder_public'] !== null && $filter['folder_public'] !== '0' ? $filter['folder_public'] : null, 'article' => $filter['article_public'],
    'unused' => $filter['unused'] ? 1 : null, 'waiting' => $filter['waiting'] ? 1 : null, 'recent' => $filter['recent'] ? 1 : null, 'missing' => $filter['missing'] ? 1 : null,
    'type' => $filter['type'] !== '' ? $filter['type'] : null, 'search' => $filter['search'] !== '' ? $filter['search'] : null, 'sort' => $filter['sort'] !== 'newest' ? $filter['sort'] : null,
    'view' => $filter['view'] === 'table' || $filter['missing'] ? $filter['view'] : null, 'show' => $show > Talea\Admin\Modules\Media::SHOW_STEP ? $show : null,
], fn ($v): bool => $v !== null);
$link = function (array $change = [], string $anchor = '') use ($module, $state): string {
    $params = $state();
    foreach ($change as $name => $value) {
        if ($value === null) {
            unset($params[$name]);
        } else {
            $params[$name] = $value;
        }
    }

    return $module->url('', $params) . $anchor;
};
$itemUrl = fn (array $o): string => $link(['item' => $o['public_id']]);

// page head: the guide link comes from the layout, then New folder and the primary Upload
$app->view->slot('actions', '<button class="navigation" type="button" popovertarget="folder-new">' . $icon('categories') . e(t('New folder')) . '</button>'
    . '<button class="btn" type="button" popovertarget="media-upload" data-upload-pick>' . $icon('upload') . e(t('Upload')) . '</button>');

// the heading of the files section
$files = match (true) {
    $filter['search'] !== '' => t('Search results'), $filter['recent'] => t('Recently uploaded'), $filter['missing'] => t('Missing description'), $filter['unused'] => t('Unused'),
    $filter['waiting'] => t('Waiting for approval'), $filter['article'] > 0 => t('Files'), $current !== null => t('Files in %s', $current['name']), default => t('Not in a folder'),
};
$folderIdHere = $current['public_id'] ?? '';
$folderNameHere = $current['name'] ?? t('All media');
$publicOf = array_column($folders, 'public_id', 'folder_id');
$accept = 'image/jpeg,image/png,image/webp,image/gif,image/svg+xml,.svg,.' . implode(',.', Talea\Core\Files::FILE_EXTENSIONS);
$formats = t('JPG, PNG, WebP and GIF images as well as downloadable attachments (PDF, documents, spreadsheets, ZIP, audio, video), up to %s per file. Large photos are scaled down to %s px and location data is removed.', $limit, Talea\Core\Images::MAX_SIDE);
// data-media-screen: image/media.js (upload tray, drop to upload, drag into folders) reads the addresses and the folder from here; data-level '*' = a view across folders, where no level is current
?>
<div class="media" data-media-screen data-upload-url="<?= e($module->url('upload', ['format' => 'json'])) ?>" data-move-url="<?= e($module->url('bulk', ['format' => 'json'])) ?>" data-folder-move-url="<?= e($module->url('folder_move', ['format' => 'json'])) ?>" data-item-url="<?= e($module->url()) ?>" data-describe-url="<?= e($module->url('', ['recent' => 1, 'view' => 'table'])) ?>" data-folder="<?= e($folderIdHere) ?>" data-folder-name="<?= e($folderNameHere) ?>" data-level="<?= $across ? '*' : e($folderIdHere) ?>">
<?= $app->view->render('admin/media/rail', ['app' => $app, 'module' => $module, 'filter' => $filter, 'counts' => $counts, 'folders' => $folders, 'path' => $path, 'current' => $current]) ?>

<div class="media-content">
<?php if ($undo !== null): ?>
<form class="notice notice-ok media-undo" method="post" action="<?= e($module->url('undo_move')) ?>" role="status"><?= $csrf ?><span><?= e($undo['message']) ?></span><button class="navigation" type="submit"><?= e(t('Undo')) ?></button></form>
<?php endif ?>
<?php if ($newsItem !== null): ?>
<p class="notice"><?= e(t('Images used in the news item “%s”.', $newsItem)) ?> <a href="<?= e($module->url()) ?>"><?= e(t('Show all media')) ?></a></p>
<?php endif ?>
<?php if ($filter['waiting']): ?>
<p class="notice"><?= e(t('Files Claude uploaded over a connection limited to drafts. They are not public yet: signed-in users and draft previews see them. A file goes public with the content that uses it when that is published, or here with Publish file. Files nobody approves are deleted after %d days.', Talea\Core\MediaStaging::DAYS)) ?></p>
<?php endif ?>

<div class="media-crumbs">
	<nav class="media-path" aria-label="<?= e(t('Folder path')) ?>">
<?php if ($path === [] && !$across): ?>
		<span aria-current="page"><?= e(t('All media')) ?></span>
<?php else: ?>
		<a href="<?= e($module->url()) ?>" data-media-drop data-id="" data-name="<?= e(t('All media')) ?>"><?= e(t('All media')) ?></a>
<?php foreach ($path as $i => $part): $last = $i === array_key_last($path) && !$across; ?>
		<span class="sep" aria-hidden="true">/</span>
<?php if ($last): ?><span aria-current="page"><?= bdi($part['name']) ?></span><?php else: ?><a href="<?= e($module->url('', ['folder' => $part['public_id']])) ?>" data-media-drop data-id="<?= e($part['public_id']) ?>" data-name="<?= e($part['name']) ?>"><?= bdi($part['name']) ?></a><?php endif ?>
<?php endforeach ?>
<?php if ($across): ?>
		<span class="sep" aria-hidden="true">/</span><span aria-current="page"><?= e($files) ?></span>
<?php endif ?>
<?php endif ?>
	</nav>
	<span class="media-count"><?php
        $fileCount = $current === null && !$across ? $counts['total'] : $total; // at the top level the count is the whole library
        echo e(implode(' · ', array_filter([$cards !== [] ? count_text(count($cards), '1 folder', '%d folders') : '', count_text($fileCount, '1 file', '%d files')])));
    ?></span>
</div>

<form class="media-toolbar" method="get" action="<?= e($app->url('admin.php')) ?>" role="search">
	<input type="hidden" name="module" value="media">
<?php foreach (array_diff_key($state(), ['search' => 1, 'sort' => 1, 'show' => 1]) as $name => $value): ?>
	<input type="hidden" name="<?= e($name) ?>" value="<?= e((string) $value) ?>">
<?php endforeach ?>
	<label class="list-search"><span class="visually-hidden"><?= e(t('Search media')) ?></span><?= $icon('b-search') ?><input type="search" name="search" value="<?= e($filter['search']) ?>" placeholder="<?= e(t('Search all folders and files')) ?>"></label>
	<button type="submit" class="visually-hidden"><?= e(t('Search')) ?></button>
	<span class="media-chips" role="group" aria-label="<?= e(t('Type')) ?>">
<?php foreach (Talea\Admin\Modules\Media::TYPES as $key => $label): ?>
		<a class="media-chip" href="<?= e($link(['type' => $filter['type'] === $key ? null : $key, 'show' => null])) ?>"<?= $filter['type'] === $key ? ' aria-current="true"' : '' ?>><?= e(t($label)) ?></a>
<?php endforeach ?>
	</span>
	<span class="media-toolbar-end">
		<select name="sort" aria-label="<?= e(t('Order')) ?>" data-submit-on-change>
<?php foreach (Talea\Admin\Modules\Media::SORT_ORDERS as $key => [$sortName]): ?>
			<option value="<?= e($key) ?>"<?= $filter['sort'] === $key ? ' selected' : '' ?>><?= e(t($sortName)) ?></option>
<?php endforeach ?>
		</select>
		<span class="media-seg" role="group" aria-label="<?= e(t('View')) ?>">
			<a href="<?= e($link(['view' => $filter['missing'] ? 'grid' : null])) ?>" aria-label="<?= e(t('Grid')) ?>" title="<?= e(t('Grid')) ?>"<?= !$table ? ' aria-current="true"' : '' ?>><?= $icon('grid') ?></a>
			<a href="<?= e($link(['view' => 'table'])) ?>" aria-label="<?= e(t('Table')) ?>" title="<?= e(t('Table')) ?>"<?= $table ? ' aria-current="true"' : '' ?>><?= $icon('list') ?></a>
		</span>
	</span>
</form>

<?php if (!$across): ?>
<section class="media-section" aria-labelledby="media-folders-title">
	<div class="media-section-head"><h2 id="media-folders-title"><?= e($current !== null ? t('Folders in %s', $current['name']) : t('Folders')) ?><span><?= format_count(count($cards)) ?></span></h2></div>
	<div class="media-folder-grid">
<?php foreach ($cards as $folder): ?>
<?= $app->view->render('admin/media/folder_card', ['app' => $app, 'module' => $module, 'csrf' => $csrf, 'folder' => $folder, 'thumbs' => $thumbnails[$folder['folder_id']] ?? [], 'subs' => $subfolders[$folder['folder_id']] ?? 0,
    'tree' => $tree, 'level' => $folderIdHere, 'below' => Talea\Core\MediaFolders::descendants($folders, $folder['folder_id']), 'publicOf' => $publicOf]) ?>
<?php endforeach ?>
		<button class="media-folder-new" type="button" popovertarget="folder-new"><?= $icon('plus') ?><?= e(t('New folder')) ?></button>
	</div>
</section>
<?php endif ?>

<section class="media-section" id="media-files" aria-labelledby="media-files-title">
	<div class="media-section-head">
		<h2 id="media-files-title"><?= e($files) ?><span><?= format_count($total) ?></span></h2>
<?php if ($images !== []): ?>
		<label class="media-select-all"><input type="checkbox" data-select-all="bulk" form="bulk"> <?= e(t('Select all')) ?></label>
<?php endif ?>
	</div>
<?php if ($images === [] && $counts['total'] === 0): ?>
<?= $app->view->render('admin/empty', ['icon' => 'media', 'heading' => t('No images.'), 'text' => t('Upload your first photos with the Upload button – or drag them straight into the text in the editor.')]) ?>
<?php elseif ($images === [] && $across): ?>
<?= $app->view->render('admin/empty', ['icon' => 'media', 'heading' => t('Nothing matches.'), 'text' => t('Try another word or another view.'), 'action' => [$module->url(), t('Show all media')]]) ?>
<?php elseif ($images === []): ?>
	<p class="media-none"><?= e($filter['type'] !== '' ? t('No files of this type here.') : t('No files here yet. Upload some, or move files into this folder.')) ?></p>
<?php elseif ($table): ?>
	<div class="tab-wrap">
	<table class="listing media-table">
		<thead><tr><th scope="col" class="cell-select"><span class="visually-hidden"><?= e(t('Select')) ?></span></th><th scope="col"><?= e(t('File')) ?></th><th scope="col"><?= e(t('Description')) ?></th><th scope="col" class="cell-used"><?= e(t('Used')) ?></th><th scope="col"><?= e(t('Size')) ?></th><th scope="col"><span class="visually-hidden"><?= e(t('Details')) ?></span></th></tr></thead>
		<tbody>
<?php foreach ($images as $o): $isImage = $o['thumb_path'] !== ''; $label = $o['name'] !== '' ? $o['name'] : basename($o['image_path']); ?>
		<tr id="m-<?= e($o['public_id']) ?>">
			<td class="cell-select"><input type="checkbox" name="selected[]" value="<?= e($o['public_id']) ?>" form="bulk" aria-label="<?= e(t('Select %s', $label)) ?>"></td>
			<td class="cell-main"><div class="media-file" draggable="true" data-media-drag="file" data-id="<?= e($o['public_id']) ?>">
				<a class="media-mini" href="<?= e($itemUrl($o)) ?>" tabindex="-1" aria-hidden="true" draggable="false"><?php if ($isImage): ?><img draggable="false" src="<?= e($app->url($o['thumb_path'])) ?>" alt="" loading="lazy"><?php else: ?><span><?= e(strtoupper(pathinfo($o['image_path'], PATHINFO_EXTENSION))) ?></span><?php endif ?></a>
				<div class="cell-text"><a class="cell-title" draggable="false" href="<?= e($itemUrl($o)) ?>" title="<?= e(basename($o['image_path'])) ?>"><?= bdi(basename($o['image_path'])) ?></a>
				<span class="cell-sub"><?= e($o['folder_id'] !== null ? ($names[(int) $o['folder_id']] ?? '') : t('Not in a folder')) ?><?php if ($o['staged_at'] !== null): ?> · <span class="media-flag-text media-flag-waiting"><?= e(t('Waiting for approval')) ?></span><?php if ($app->auth()->canPublish()): ?> <button class="navigation" type="submit" form="bulk" formaction="<?= e($module->url('approve')) ?>" name="media_id" value="<?= e($o['public_id']) ?>"><?= e(t('Publish file')) ?></button><?php endif ?><?php endif ?></span></div>
			</div></td>
			<td class="cell-input"><?php if ($isImage): ?><input class="textfield media-description" type="text" value="<?= e((string) $o['name']) ?>" maxlength="150" placeholder="<?= e(t('Describe what the image shows')) ?>" aria-label="<?= e(t('Description of image %s', basename($o['image_path']))) ?>" data-description-media="<?= e($o['public_id']) ?>" data-address="<?= e($module->url('save_caption')) ?>" form=""><?php else: ?><span class="cell-weak">–</span><?php endif ?></td>
			<td class="cell-weak cell-used"><span<?= $o['used_in'] !== [] ? ' title="' . e(t('Used in: %s', implode(', ', $o['used_in']))) . '"' : '' ?>><?= e((int) $o['used_at'] > 0 ? t('used %s×', (int) $o['used_at']) : t('unused')) ?></span></td>
			<td class="cell-weak"><?= e(Talea\Core\Files::size((int) $o['image_size'])) ?></td>
			<td class="cell-actions"><a class="icon-btn" href="<?= e($itemUrl($o)) ?>" aria-label="<?= e(t('Details of %s', $label)) ?>"><?= $icon('next') ?></a></td>
		</tr>
<?php endforeach ?>
		</tbody>
	</table>
	</div>
<?php else: ?>
	<div class="media-grid">
<?php foreach ($images as $o): $isImage = $o['thumb_path'] !== ''; $label = $o['name'] !== '' ? $o['name'] : basename($o['image_path']); ?>
		<div class="media-tile<?= $item !== null && $item['public_id'] === $o['public_id'] ? ' open' : '' ?>" id="m-<?= e($o['public_id']) ?>" draggable="true" data-media-drag="file" data-id="<?= e($o['public_id']) ?>">
			<div class="media-tile-image">
			<label class="media-tile-check"><input type="checkbox" name="selected[]" value="<?= e($o['public_id']) ?>" form="bulk" aria-label="<?= e(t('Select %s', $label)) ?>"></label>
			<a class="media-thumb<?= $isImage ? '' : ' file' ?>" href="<?= e($itemUrl($o)) ?>" aria-label="<?= e(t('Details of %s', $label)) ?>" draggable="false"><?php if ($isImage): ?><img draggable="false" src="<?= e($app->url($o['thumb_path'])) ?>" alt="<?= e($o['name']) ?>" loading="lazy" width="<?= (int) $o['thumb_width'] ?>" height="<?= (int) $o['thumb_height'] ?>"><?php else: ?><span><?= e(strtoupper(pathinfo($o['image_path'], PATHINFO_EXTENSION))) ?></span><?php endif ?></a>
			<span class="media-tile-flags">
<?php if ($isImage && $o['name'] === ''): ?><span class="media-flag media-flag-alt"><?= e(t('No description')) ?></span><?php endif ?>
<?php if ((int) $o['used_at'] === 0): ?><span class="media-flag media-flag-unused"><?= e(t('Unused')) ?></span><?php endif ?>
<?php if ($o['staged_at'] !== null): ?><span class="media-flag media-flag-waiting"><?= e(t('Waiting for approval')) ?></span><?php endif ?>
			</span>
			</div>
			<span class="media-tile-name<?= $o['name'] === '' ? ' empty' : '' ?>" title="<?= e($label) ?>"><?= bdi($label) ?></span>
			<span class="media-tile-meta"><span<?= $o['used_in'] !== [] ? ' title="' . e(t('Used in: %s', implode(', ', $o['used_in']))) . '"' : '' ?>><?= $isImage ? (int) $o['image_width'] . '&times;' . (int) $o['image_height'] . ' &middot; ' : '' ?><?= e(Talea\Core\Files::size((int) $o['image_size'])) ?><?= (int) $o['used_at'] > 0 ? ' &middot; ' . e(t('used %s×', (int) $o['used_at'])) : '' ?></span></span>
<?php if ($o['staged_at'] !== null && $app->auth()->canPublish()): ?>
			<button class="navigation media-tile-approve" type="submit" form="bulk" formaction="<?= e($module->url('approve')) ?>" name="media_id" value="<?= e($o['public_id']) ?>"><?= e(t('Publish file')) ?></button>
<?php endif ?>
		</div>
<?php endforeach ?>
	</div>
<?php endif ?>
<?php if ($images !== [] && count($images) < $total): ?>
	<div class="media-more-row">
		<span><?= e(t('Showing %d of %d', count($images), $total)) ?></span>
<?php if ($show < Talea\Admin\Modules\Media::MAX_SHOW): ?>
		<a class="navigation" href="<?= e($link(['show' => min(Talea\Admin\Modules\Media::MAX_SHOW, $show + Talea\Admin\Modules\Media::SHOW_STEP)], '#m-' . end($images)['public_id'])) ?>"><?= e(t('Show more')) ?></a>
<?php endif ?>
	</div>
<?php endif ?>
</section>

<?php if ($images !== []): ?>
<?= $app->view->render('admin/list/selection', ['form' => 'bulk', 'action' => $module->url('bulk'), 'csrf' => $csrf,
    'extra' => '<select name="to_folder" aria-label="' . e(t('Target folder')) . '">' . $app->view->render('admin/media/folder_options', ['tree' => $tree, 'topLabel' => t('Not in a folder')]) . '</select>',
    'actions' => [['value' => 'move', 'label' => t('Move to folder'), 'kind' => 'primary'],
        ['value' => 'delete', 'label' => t('Delete'), 'kind' => 'danger', 'confirm' => t('Really delete the selected files? Files the site still uses are skipped.')]]]) ?>
<?php endif ?>

</div>
<?php if ($item !== null): ?>
<?= $app->view->render('admin/media/panel', ['app' => $app, 'module' => $module, 'csrf' => $csrf, 'o' => $item, 'panel' => $panel, 'tree' => $tree,
    'listState' => $state(), 'closeUrl' => $link(['item' => null]), 'prevUrl' => $panel['prev'] !== null ? $link(['item' => $panel['prev']]) : null, 'nextUrl' => $panel['next'] !== null ? $link(['item' => $panel['next']]) : null]) ?>
<?php endif ?>
<input type="file" accept="<?= e($accept) ?>" multiple hidden data-upload-input>
<div class="media-drop-overlay" data-drop-overlay hidden aria-hidden="true">
	<div class="drop-box"><?= $icon('upload') ?><strong data-drop-title data-pattern="<?= e(t('Drop to upload to %s')) ?>"><?= e(t('Drop to upload to %s', $folderNameHere)) ?></strong><p><?= e($formats) ?></p></div>
</div>
</div>

<form class="media-pop" id="folder-new" popover method="post" action="<?= e($module->url('folder')) ?>"><?= $csrf ?>
	<input type="hidden" name="parent" value="<?= e($folderIdHere) ?>">
	<label for="folder-new-name"><?= e($current !== null ? t('New folder in %s', $current['name']) : t('New folder')) ?></label>
	<input class="textfield" type="text" id="folder-new-name" name="name" maxlength="100" required autofocus>
	<span class="media-pop-buttons"><button class="navigation" type="button" popovertarget="folder-new" popovertargetaction="hide"><?= e(t('Cancel')) ?></button><button class="btn" type="submit"><?= e(t('Create folder')) ?></button></span>
</form>

<?php // without JavaScript the Upload button opens this form; with it the button opens the file chooser and the upload tray (image/media.js) ?>
<form class="media-pop media-upload" id="media-upload" popover method="post" enctype="multipart/form-data" action="<?= e($module->url('upload')) ?>"><?= $csrf ?>
	<input type="hidden" name="folder_id" value="<?= e($folderIdHere) ?>">
	<label for="files"><?= e($current !== null ? t('Upload to %s', $current['name']) : t('Upload images and attachments')) ?></label>
	<input type="file" id="files" name="files[]" accept="<?= e($accept) ?>" multiple required>
	<span class="help"><?= e($formats) ?></span>
	<span class="media-pop-buttons"><button class="navigation" type="button" popovertarget="media-upload" popovertargetaction="hide"><?= e(t('Cancel')) ?></button><input class="btn" type="submit" value="<?= e(t('Upload')) ?>"></span>
</form>
<script src="<?= e($app->url('image/media.js')) ?>?v=<?= e(TALEA_VERSION) ?>" defer></script>
