<?php
/**
 * Media: folders and filters on the left, upload and the image grid on the right.
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\Media $module
 * @var string $csrf
 * @var list<array<string, mixed>> $images
 * @var int $pageNumber
 * @var int $pageCount
 * @var int $total
 * @var string $limit
 * @var array{section: ?int, section_public: ?string, article: int, article_public: ?string, unused: bool, search: string, sort: string} $filter
 * @var list<array<string, mixed>> $folders
 * @var string|null $newsItem  title of the news item used as the filter
 */
$activeFolder = null;
foreach ($folders as $s) {
    if ((int) $s['folder_id'] === $filter['section']) {
        $activeFolder = $s;
    }
}
$params = array_filter(['section' => $filter['section_public'], 'article' => $filter['article_public'], 'unused' => $filter['unused'] ? 1 : null,
    'search' => $filter['search'] !== '' ? $filter['search'] : null, 'sort' => $filter['sort'] !== 'newest' ? $filter['sort'] : null], fn ($v): bool => $v !== null);
$isAll = $filter['section'] === null && $filter['article'] === 0 && !$filter['unused'];
?>
<div class="media">
<nav class="media-folders" aria-label="<?= e(t('Folders')) ?>">
	<a href="<?= e($module->url()) ?>"<?= $isAll ? ' class="active"' : '' ?>><?= e(t('All media')) ?></a>
	<a href="<?= e($module->url('', ['section' => 0])) ?>"<?= $filter['section'] === 0 ? ' class="active"' : '' ?>><?= e(t('Uncategorized')) ?></a>
	<a href="<?= e($module->url('', ['unused' => 1])) ?>"<?= $filter['unused'] ? ' class="active"' : '' ?>><?= e(t('Unused')) ?></a>
	<a href="<?= e($module->url('cleanup')) ?>"><?= e(t('Clean-up')) ?></a>
	<strong><?= e(t('Folders')) ?></strong>
<?php foreach ($folders as $s): ?>
	<a href="<?= e($module->url('', ['section' => $s['public_id']])) ?>"<?= $activeFolder === $s ? ' class="active"' : '' ?>><?= e($s['name']) ?> <small>(<?= (int) $s['count'] ?>)</small></a>
<?php endforeach ?>
	<form method="post" action="<?= e($module->url('folder')) ?>">
		<?= $csrf ?>
		<input class="textfield" type="text" name="name" placeholder="<?= e(t('new folder')) ?>" maxlength="100" required aria-label="<?= e(t('New folder name')) ?>">
		<button class="navigation" type="submit"><?= e(t('Add')) ?></button>
	</form>
</nav>

<div class="media-content">
<?php if ($newsItem !== null): ?>
<p class="notice"><?= e(t('Images used in the news item “%s”.', $newsItem)) ?> <a href="<?= e($module->url()) ?>"><?= e(t('Show all media')) ?></a></p>
<?php endif ?>
<?php if ($activeFolder !== null): ?>
<div class="media-folder-edit">
	<form method="post" action="<?= e($module->url('folder')) ?>"><?= $csrf ?><input type="hidden" name="folder_id" value="<?= e($activeFolder['public_id']) ?>"><input class="textfield" type="text" name="name" value="<?= e($activeFolder['name']) ?>" maxlength="100" required aria-label="<?= e(t('Folder name')) ?>"> <button class="navigation" type="submit"><?= e(t('Rename')) ?></button></form>
<?php if ($app->auth()->isAdmin()): ?>
	<form method="post" action="<?= e($module->url('folder_delete')) ?>" data-confirm="<?= e(t('Delete the folder? Its images will be kept and become uncategorized.')) ?>"><?= $csrf ?><input type="hidden" name="folder_id" value="<?= e($activeFolder['public_id']) ?>"><button class="navigation danger" type="submit"><?= e(t('Delete folder')) ?></button></form>
<?php endif ?>
</div>
<?php endif ?>

<form class="upload" method="post" enctype="multipart/form-data" action="<?= e($module->url('upload')) ?>" data-upload>
	<?= $csrf ?>
	<input type="hidden" name="folder_id" value="<?= e($activeFolder['public_id'] ?? '') ?>">
	<label for="files"><strong><?= e(t('Upload images and attachments')) ?><?= $activeFolder !== null ? ' – ' . e($activeFolder['name']) : '' ?></strong> <?= e(t('– select files, or drag them here')) ?></label>
	<input type="file" id="files" name="files[]" accept="image/jpeg,image/png,image/webp,image/gif,image/svg+xml,.svg,<?= e('.' . implode(',.', Kaleta\Core\Files::FILE_EXTENSIONS)) ?>" multiple required>
	<input class="btn" type="submit" value="<?= e(t('Upload')) ?>">
	<span class="help"><?= e(t('JPG, PNG, WebP and GIF images as well as downloadable attachments (PDF, documents, spreadsheets, ZIP, audio, video), up to %s per file. Large photos are scaled down to %s px and location data is removed.', $limit, Kaleta\Core\Images::MAX_SIDE)) ?></span>
</form>

<form class="navigation-row media-search" method="get" action="<?= e($app->url('admin.php')) ?>" role="search">
	<input type="hidden" name="module" value="media">
<?php foreach (array_diff_key($params, ['search' => 1, 'sort' => 1]) as $k => $v): ?>
	<input type="hidden" name="<?= e($k) ?>" value="<?= e((string) $v) ?>">
<?php endforeach ?>
	<input class="textfield" type="search" name="search" value="<?= e($filter['search']) ?>" placeholder="<?= e(t('Search name, description or file')) ?>" aria-label="<?= e(t('Search media')) ?>">
	<select name="sort" aria-label="<?= e(t('Order')) ?>" data-submit-on-change>
<?php foreach (Kaleta\Admin\Modules\Media::SORT_ORDERS as $key => [$sortName]): ?>
		<option value="<?= e($key) ?>"<?= $filter['sort'] === $key ? ' selected' : '' ?>><?= e(t($sortName)) ?></option>
<?php endforeach ?>
	</select>
	<button class="navigation" type="submit"><?= e(t('Filter')) ?></button>
</form>
<?php if ($images === []): ?>
<?= $app->view->render('admin/empty', ['icon' => 'media', 'heading' => t('No images.'), 'text' => t('Upload your first photos with the form above – or drag them straight into the text in the editor.')]) ?>
<?php else: ?>
<form method="post" action="<?= e($module->url('bulk')) ?>">
<?= $csrf ?>
<div class="gallery-grid">
<?php foreach ($images as $o): ?>
	<figure class="gallery-item">
<?php if ($o['thumb_path'] === ''): ?>
		<a class="gallery-file" href="<?= e($app->url($o['image_path'])) ?>" target="_blank" rel="noopener"><span><?= e(strtoupper(pathinfo($o['image_path'], PATHINFO_EXTENSION))) ?></span></a>
<?php else: ?>
		<a href="<?= e($app->url($o['image_path'])) ?>" target="_blank" rel="noopener"><img src="<?= e($app->url($o['thumb_path'])) ?>" alt="<?= e($o['name']) ?>" loading="lazy" width="<?= (int) $o['thumb_width'] ?>" height="<?= (int) $o['thumb_height'] ?>"></a>
<?php endif ?>
		<figcaption>
			<strong title="<?= e($o['name']) ?>"><?= e($o['name'] !== '' ? $o['name'] : t('untitled')) ?></strong>
			<span><?= $o['thumb_path'] === '' ? '' : (int) $o['image_width'] . '&times;' . (int) $o['image_height'] . ' &middot; ' ?><?= e(Kaleta\Core\Files::size((int) $o['image_size'])) ?> &middot; <span<?= $o['used_in'] !== [] ? ' title="' . e(t('Used in: %s', implode(', ', $o['used_in']))) . '"' : '' ?>><?= e((int) $o['used_at'] > 0 ? t('used %s×', (int) $o['used_at']) : t('unused')) ?></span></span>
<?php if ($o['thumb_path'] !== ''): ?>
			<input class="gallery-description" type="text" value="<?= e((string) $o['name']) ?>" maxlength="150" placeholder="<?= e(t('Description for blind users (alt)')) ?>" aria-label="<?= e(t('Description of image %s', $o['name'])) ?>" data-description-media="<?= e($o['public_id']) ?>" data-address="<?= e($module->url('save_caption')) ?>" form="">
<?php endif ?>
			<span><label><input type="checkbox" name="selected[]" value="<?= e($o['public_id']) ?>"> <?= e(t('select')) ?></label> &middot; <a href="<?= e($module->url('list', $params + ['edit' => $o['public_id'], 'page' => $pageNumber])) ?>#edit"><?= e(t('description')) ?></a></span>
		</figcaption>
	</figure>
<?php endforeach ?>
</div>
<p class="media-bulk">
	<?= e(t('With selected:')) ?>
	<select name="to_folder" aria-label="<?= e(t('Target folder')) ?>">
		<option value="0"><?= e(t('– uncategorized –')) ?></option>
<?php foreach ($folders as $s): ?>
		<option value="<?= e($s['public_id']) ?>"><?= e($s['name']) ?></option>
<?php endforeach ?>
	</select>
	<button class="navigation" type="submit" name="bulk" value="presun"><?= e(t('Move to folder')) ?></button>
	<button class="navigation danger" type="submit" name="bulk" value="smaz" data-confirm="<?= e(t('Really delete the selected files? Files the site still uses are skipped.')) ?>"><?= e(t('Delete')) ?></button>
</p>
</form>

<?php foreach ($images as $o): if ($o['public_id'] !== $app->request->get('edit')) { continue; } ?>
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
<?php if (preg_match('/\.(jpg|png|webp)$/', $o['image_path'])): ?>
<form class="form" method="post" action="<?= e($module->url('replace')) ?>" enctype="multipart/form-data">
	<?= $csrf ?>
	<input type="hidden" name="media_id" value="<?= e($o['public_id']) ?>">
	<div class="row"><label for="file-replacement"><?= e(t('Replace file')) ?></label><div><input type="file" id="file-replacement" name="file" accept="image/jpeg,image/png,image/webp" required>
		<span class="help"><?= e(t('The new photo appears everywhere the old one is used – the file address does not change.')) ?></span></div></div>
	<p class="buttons"><input class="navigation" type="submit" value="<?= e(t('Replace')) ?>"></p>
</form>
<?php endif ?>
<?php endforeach ?>

<?php if ($pageCount > 1): ?>
<p class="pagination">
<?php for ($s = 1; $s <= $pageCount; $s++): ?>
	<?= $s === $pageNumber ? '<strong>[' . $s . ']</strong>' : '<a href="' . e($module->url('', $params + ['page' => $s])) . '">' . $s . '</a>' ?>
<?php endfor ?>
</p>
<?php endif ?>
<?php endif ?>
</div>
</div>
