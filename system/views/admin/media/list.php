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
 * @var array<string, mixed>|null $item  the file opened with ?item= (the edit form below the list until the details panel)
 */
$icon = require __DIR__ . '/../icons.php';
$tree = Talea\Core\MediaFolders::tree($folders);
$names = array_column($folders, 'name', 'folder_id');
$across = !$filter['level'];
$table = $filter['view'] === 'table';

// a link that keeps the state of the screen and changes some of it (null removes a parameter)
$link = function (array $change = [], string $anchor = '') use ($module, $filter): string {
    $params = array_filter([
        'folder' => $filter['folder_public'] !== null && $filter['folder_public'] !== '0' ? $filter['folder_public'] : null, 'article' => $filter['article_public'],
        'unused' => $filter['unused'] ? 1 : null, 'waiting' => $filter['waiting'] ? 1 : null, 'recent' => $filter['recent'] ? 1 : null, 'missing' => $filter['missing'] ? 1 : null,
        'type' => $filter['type'] !== '' ? $filter['type'] : null, 'search' => $filter['search'] !== '' ? $filter['search'] : null, 'sort' => $filter['sort'] !== 'newest' ? $filter['sort'] : null,
        'view' => $filter['view'] === 'table' || $filter['missing'] ? $filter['view'] : null,
    ], fn ($v): bool => $v !== null);
    foreach ($change as $name => $value) {
        if ($value === null) {
            unset($params[$name]);
        } else {
            $params[$name] = $value;
        }
    }

    return $module->url('', $params) . $anchor;
};
$itemUrl = fn (array $o): string => $link(['item' => $o['public_id']], '#edit');

// page head: the guide link comes from the layout, then New folder and the primary Upload
$app->view->slot('actions', '<button class="navigation" type="button" popovertarget="folder-new">' . $icon('categories') . e(t('New folder')) . '</button>'
    . '<button class="btn" type="button" popovertarget="media-upload">' . $icon('upload') . e(t('Upload')) . '</button>');

// the heading of the files section
$files = match (true) {
    $filter['search'] !== '' => t('Search results'), $filter['recent'] => t('Recently uploaded'), $filter['missing'] => t('Missing description'), $filter['unused'] => t('Unused'),
    $filter['waiting'] => t('Waiting for approval'), $filter['article'] > 0 => t('Files'), $current !== null => t('Files in %s', $current['name']), default => t('Not in a folder'),
};
$folderIdHere = $current['public_id'] ?? '';
?>
<div class="media">
<?= $app->view->render('admin/media/rail', ['app' => $app, 'module' => $module, 'filter' => $filter, 'counts' => $counts, 'folders' => $folders, 'path' => $path, 'current' => $current]) ?>

<div class="media-content">
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
		<a href="<?= e($module->url()) ?>"><?= e(t('All media')) ?></a>
<?php foreach ($path as $i => $part): $last = $i === array_key_last($path) && !$across; ?>
		<span class="sep" aria-hidden="true">/</span>
<?php if ($last): ?><span aria-current="page"><?= bdi($part['name']) ?></span><?php else: ?><a href="<?= e($module->url('', ['folder' => $part['public_id']])) ?>"><?= bdi($part['name']) ?></a><?php endif ?>
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
		<a class="chip" href="<?= e($link(['type' => $filter['type'] === $key ? null : $key, 'show' => null])) ?>"<?= $filter['type'] === $key ? ' aria-current="true"' : '' ?>><?= e(t($label)) ?></a>
<?php endforeach ?>
	</span>
	<span class="media-toolbar-end">
		<select name="sort" aria-label="<?= e(t('Order')) ?>" data-submit-on-change>
<?php foreach (Talea\Admin\Modules\Media::SORT_ORDERS as $key => [$sortName]): ?>
			<option value="<?= e($key) ?>"<?= $filter['sort'] === $key ? ' selected' : '' ?>><?= e(t($sortName)) ?></option>
<?php endforeach ?>
		</select>
		<span class="seg" role="group" aria-label="<?= e(t('View')) ?>">
			<a href="<?= e($link(['view' => $filter['missing'] ? 'grid' : null])) ?>" aria-label="<?= e(t('Grid')) ?>" title="<?= e(t('Grid')) ?>"<?= !$table ? ' aria-current="true"' : '' ?>><?= $icon('grid') ?></a>
			<a href="<?= e($link(['view' => 'table'])) ?>" aria-label="<?= e(t('Table')) ?>" title="<?= e(t('Table')) ?>"<?= $table ? ' aria-current="true"' : '' ?>><?= $icon('list') ?></a>
		</span>
	</span>
</form>

<?php if (!$across): ?>
<section class="media-section" aria-labelledby="media-folders-title">
	<div class="media-section-head"><h2 id="media-folders-title"><?= e($current !== null ? t('Folders in %s', $current['name']) : t('Folders')) ?><span><?= format_count(count($cards)) ?></span></h2></div>
	<div class="folder-grid">
<?php foreach ($cards as $folder): ?>
<?= $app->view->render('admin/media/folder_card', ['app' => $app, 'module' => $module, 'csrf' => $csrf, 'folder' => $folder, 'thumbs' => $thumbnails[$folder['folder_id']] ?? [], 'subs' => $subfolders[$folder['folder_id']] ?? 0,
    'tree' => $tree, 'below' => Talea\Core\MediaFolders::descendants($folders, $folder['folder_id'])]) ?>
<?php endforeach ?>
		<button class="folder-new" type="button" popovertarget="folder-new"><?= $icon('plus') ?><?= e(t('New folder')) ?></button>
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
		<thead><tr><th scope="col" class="cell-select"><span class="visually-hidden"><?= e(t('Select')) ?></span></th><th scope="col"><?= e(t('File')) ?></th><th scope="col"><?= e(t('Description')) ?></th><th scope="col"><?= e(t('Used')) ?></th><th scope="col"><?= e(t('Size')) ?></th><th scope="col"><span class="visually-hidden"><?= e(t('Details')) ?></span></th></tr></thead>
		<tbody>
<?php foreach ($images as $o): $isImage = $o['thumb_path'] !== ''; $label = $o['name'] !== '' ? $o['name'] : basename($o['image_path']); ?>
		<tr id="m-<?= e($o['public_id']) ?>">
			<td class="cell-select"><input type="checkbox" name="selected[]" value="<?= e($o['public_id']) ?>" form="bulk" aria-label="<?= e(t('Select %s', $label)) ?>"></td>
			<td class="cell-main"><div class="media-file">
				<a class="media-mini" href="<?= e($itemUrl($o)) ?>" tabindex="-1" aria-hidden="true"><?php if ($isImage): ?><img src="<?= e($app->url($o['thumb_path'])) ?>" alt="" loading="lazy"><?php else: ?><span><?= e(strtoupper(pathinfo($o['image_path'], PATHINFO_EXTENSION))) ?></span><?php endif ?></a>
				<div class="cell-text"><a class="cell-title" href="<?= e($itemUrl($o)) ?>" title="<?= e(basename($o['image_path'])) ?>"><?= bdi(basename($o['image_path'])) ?></a>
				<span class="cell-sub"><?= e($o['folder_id'] !== null ? ($names[(int) $o['folder_id']] ?? '') : t('Not in a folder')) ?><?php if ($o['staged_at'] !== null): ?> · <span class="flag-text flag-waiting"><?= e(t('Waiting for approval')) ?></span><?php if ($app->auth()->canPublish()): ?> <button class="navigation" type="submit" form="bulk" formaction="<?= e($module->url('approve')) ?>" name="media_id" value="<?= e($o['public_id']) ?>"><?= e(t('Publish file')) ?></button><?php endif ?><?php endif ?></span></div>
			</div></td>
			<td class="cell-input"><?php if ($isImage): ?><input class="textfield media-description" type="text" value="<?= e((string) $o['name']) ?>" maxlength="150" placeholder="<?= e(t('Describe what the image shows')) ?>" aria-label="<?= e(t('Description of image %s', basename($o['image_path']))) ?>" data-description-media="<?= e($o['public_id']) ?>" data-address="<?= e($module->url('save_caption')) ?>" form=""><?php else: ?><span class="cell-weak">–</span><?php endif ?></td>
			<td class="cell-weak"><span<?= $o['used_in'] !== [] ? ' title="' . e(t('Used in: %s', implode(', ', $o['used_in']))) . '"' : '' ?>><?= e((int) $o['used_at'] > 0 ? t('used %s×', (int) $o['used_at']) : t('unused')) ?></span></td>
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
		<div class="tile" id="m-<?= e($o['public_id']) ?>">
			<div class="tile-image">
			<label class="tile-check"><input type="checkbox" name="selected[]" value="<?= e($o['public_id']) ?>" form="bulk" aria-label="<?= e(t('Select %s', $label)) ?>"></label>
			<a class="thumb<?= $isImage ? '' : ' file' ?>" href="<?= e($itemUrl($o)) ?>" aria-label="<?= e(t('Details of %s', $label)) ?>"><?php if ($isImage): ?><img src="<?= e($app->url($o['thumb_path'])) ?>" alt="<?= e($o['name']) ?>" loading="lazy" width="<?= (int) $o['thumb_width'] ?>" height="<?= (int) $o['thumb_height'] ?>"><?php else: ?><span><?= e(strtoupper(pathinfo($o['image_path'], PATHINFO_EXTENSION))) ?></span><?php endif ?></a>
			<span class="tile-flags">
<?php if ($isImage && $o['name'] === ''): ?><span class="flag flag-alt"><?= e(t('No description')) ?></span><?php endif ?>
<?php if ((int) $o['used_at'] === 0): ?><span class="flag flag-unused"><?= e(t('Unused')) ?></span><?php endif ?>
<?php if ($o['staged_at'] !== null): ?><span class="flag flag-waiting"><?= e(t('Waiting for approval')) ?></span><?php endif ?>
			</span>
			</div>
			<span class="tile-name<?= $o['name'] === '' ? ' empty' : '' ?>" title="<?= e($label) ?>"><?= bdi($label) ?></span>
			<span class="tile-meta"><span<?= $o['used_in'] !== [] ? ' title="' . e(t('Used in: %s', implode(', ', $o['used_in']))) . '"' : '' ?>><?= $isImage ? (int) $o['image_width'] . '&times;' . (int) $o['image_height'] . ' &middot; ' : '' ?><?= e(Talea\Core\Files::size((int) $o['image_size'])) ?><?= (int) $o['used_at'] > 0 ? ' &middot; ' . e(t('used %s×', (int) $o['used_at'])) : '' ?></span></span>
<?php if ($o['staged_at'] !== null && $app->auth()->canPublish()): ?>
			<button class="navigation tile-approve" type="submit" form="bulk" formaction="<?= e($module->url('approve')) ?>" name="media_id" value="<?= e($o['public_id']) ?>"><?= e(t('Publish file')) ?></button>
<?php endif ?>
		</div>
<?php endforeach ?>
	</div>
<?php endif ?>
<?php if ($images !== [] && count($images) < $total): ?>
	<div class="more-row">
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

<?php if ($item !== null): $o = $item; ?>
<form class="form" id="edit" method="post" action="<?= e($module->url('save')) ?>">
	<?= $csrf ?>
	<input type="hidden" name="media_id" value="<?= e($o['public_id']) ?>">
	<div class="row"><label for="name"><?= e(t('Name (alternative text)')) ?></label><div><input class="textfield wide" type="text" id="name" name="name" value="<?= e($o['name']) ?>" maxlength="150"><span class="help"><?= e(t('Describe what is in the image - screen readers and search engines read it.')) ?></span></div></div>
	<div class="row"><label for="description"><?= e(t('Caption below the image')) ?></label><input class="textfield wide" type="text" id="description" name="description" value="<?= e($o['description']) ?>" maxlength="500"></div>
	<div class="row"><label for="author"><?= e(t('Image credit')) ?></label><div><input class="textfield wide" type="text" id="author" name="author" value="<?= e($o['author'] ?? '') ?>" maxlength="120"><span class="help"><?= e(t('Shown under a news item\'s main photo unless it has its own photo credit.')) ?></span></div></div>
<?php if ($o['thumb_path'] !== '' && !str_ends_with($o['image_path'], '.svg')): [$ox, $oy] = array_map('intval', explode(' ', str_replace('%', '', $o['focal_point'] ?: '50% 50%'))) + [1 => 50]; ?>
	<div class="row"><span class="caption"><?= e(t('Crop focal point')) ?></span><div>
		<div class="focal" data-focal><img src="<?= e($app->url($o['thumb_path'])) ?>" alt=""><span class="focal-point" style="left:<?= $ox ?>%;top:<?= $oy ?>%"></span></div>
		<label><?= e(t('Horizontal')) ?> <input class="textfield" type="number" name="focus_x" min="0" max="100" value="<?= $ox ?>" size="3"> %</label>
		<label><?= e(t('Vertical')) ?> <input class="textfield" type="number" name="focus_y" min="0" max="100" value="<?= $oy ?>" size="3"> %</label>
		<span class="help"><?= e(t('Click in the preview on what must stay visible when the photo is cropped to another shape (card, section background).')) ?></span></div></div>
<?php endif ?>
	<p class="buttons"><input class="btn" type="submit" value="<?= e(t('Save')) ?>"></p>
</form>
<?php if (preg_match('/\.(jpg|png|webp)$/', $o['image_path']) && $o['staged_at'] === null): ?>
<form class="form" method="post" action="<?= e($module->url('replace')) ?>" enctype="multipart/form-data">
	<?= $csrf ?>
	<input type="hidden" name="media_id" value="<?= e($o['public_id']) ?>">
	<div class="row"><label for="file-replacement"><?= e(t('Replace file')) ?></label><div><input type="file" id="file-replacement" name="file" accept="image/jpeg,image/png,image/webp" required>
		<span class="help"><?= e(t('The new photo appears everywhere the old one is used – the file address does not change.')) ?></span></div></div>
	<p class="buttons"><input class="navigation" type="submit" value="<?= e(t('Replace')) ?>"></p>
</form>
<?php endif ?>
<?php endif ?>
</div>
</div>

<form class="folder-pop" id="folder-new" popover method="post" action="<?= e($module->url('folder')) ?>"><?= $csrf ?>
	<input type="hidden" name="parent" value="<?= e($folderIdHere) ?>">
	<label for="folder-new-name"><?= e($current !== null ? t('New folder in %s', $current['name']) : t('New folder')) ?></label>
	<input class="textfield" type="text" id="folder-new-name" name="name" maxlength="100" required autofocus>
	<span class="folder-pop-buttons"><button class="navigation" type="button" popovertarget="folder-new" popovertargetaction="hide"><?= e(t('Cancel')) ?></button><button class="btn" type="submit"><?= e(t('Create folder')) ?></button></span>
</form>

<form class="folder-pop media-upload" id="media-upload" popover method="post" enctype="multipart/form-data" action="<?= e($module->url('upload')) ?>" data-upload><?= $csrf ?>
	<input type="hidden" name="folder_id" value="<?= e($folderIdHere) ?>">
	<label for="files"><?= e($current !== null ? t('Upload to %s', $current['name']) : t('Upload images and attachments')) ?></label>
	<input type="file" id="files" name="files[]" accept="image/jpeg,image/png,image/webp,image/gif,image/svg+xml,.svg,<?= e('.' . implode(',.', Talea\Core\Files::FILE_EXTENSIONS)) ?>" multiple required>
	<span class="help"><?= e(t('JPG, PNG, WebP and GIF images as well as downloadable attachments (PDF, documents, spreadsheets, ZIP, audio, video), up to %s per file. Large photos are scaled down to %s px and location data is removed.', $limit, Talea\Core\Images::MAX_SIDE)) ?></span>
	<span class="folder-pop-buttons"><button class="navigation" type="button" popovertarget="media-upload" popovertargetaction="hide"><?= e(t('Cancel')) ?></button><input class="btn" type="submit" value="<?= e(t('Upload')) ?>"></span>
</form>
