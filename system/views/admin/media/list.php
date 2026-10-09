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
 * @var array{section: ?int, article: int, unused: bool, search: string, sort: string} $filter
 * @var list<array<string, mixed>> $folders
 * @var string|null $newsItem  title of the news item used as the filter
 */
$activeFolder = null;
foreach ($folders as $s) {
    if ((int) $s['folder_id'] === $filter['section']) {
        $activeFolder = $s;
    }
}
$params = array_filter(['section' => $filter['section'], 'article' => $filter['article'] ?: null, 'unused' => $filter['unused'] ? 1 : null,
    'search' => $filter['search'] !== '' ? $filter['search'] : null, 'sort' => $filter['sort'] !== 'nove' ? $filter['sort'] : null], fn ($v): bool => $v !== null);
$isAll = $filter['section'] === null && $filter['article'] === 0 && !$filter['unused'];
?>
<div class="media">
<nav class="media-slozky" aria-label="<?= e(t('Folders')) ?>">
	<a href="<?= e($module->url()) ?>"<?= $isAll ? ' class="aktivni"' : '' ?>><?= e(t('All media')) ?></a>
	<a href="<?= e($module->url('', ['section' => 0])) ?>"<?= $filter['section'] === 0 ? ' class="aktivni"' : '' ?>><?= e(t('Uncategorized')) ?></a>
	<a href="<?= e($module->url('', ['unused' => 1])) ?>"<?= $filter['unused'] ? ' class="aktivni"' : '' ?>><?= e(t('Unused')) ?></a>
	<a href="<?= e($module->url('cleanup')) ?>"><?= e(t('Clean-up')) ?></a>
	<strong><?= e(t('Folders')) ?></strong>
<?php foreach ($folders as $s): ?>
	<a href="<?= e($module->url('', ['section' => $s['folder_id']])) ?>"<?= $activeFolder === $s ? ' class="aktivni"' : '' ?>><?= e($s['name']) ?> <small>(<?= (int) $s['pocet'] ?>)</small></a>
<?php endforeach ?>
	<form method="post" action="<?= e($module->url('folder')) ?>">
		<?= $csrf ?>
		<input class="textpole" type="text" name="name" placeholder="<?= e(t('new folder')) ?>" maxlength="100" required aria-label="<?= e(t('New folder name')) ?>">
		<button class="navigace" type="submit"><?= e(t('Add')) ?></button>
	</form>
</nav>

<div class="media-obsah">
<?php if ($newsItem !== null): ?>
<p class="hlaska"><?= e(t('Images used in the news item “%s”.', $newsItem)) ?> <a href="<?= e($module->url()) ?>"><?= e(t('Show all media')) ?></a></p>
<?php endif ?>
<?php if ($activeFolder !== null): ?>
<div class="media-slozka-uprava">
	<form method="post" action="<?= e($module->url('folder')) ?>"><?= $csrf ?><input type="hidden" name="folder_id" value="<?= (int) $activeFolder['folder_id'] ?>"><input class="textpole" type="text" name="nazev" value="<?= e($activeFolder['name']) ?>" maxlength="100" required aria-label="<?= e(t('Folder name')) ?>"> <button class="navigace" type="submit"><?= e(t('Rename')) ?></button></form>
<?php if ($app->auth()->isAdmin()): ?>
	<form method="post" action="<?= e($module->url('folder_delete')) ?>" data-potvrdit="<?= e(t('Delete the folder? Its images will be kept and become uncategorized.')) ?>"><?= $csrf ?><input type="hidden" name="folder_id" value="<?= (int) $activeFolder['folder_id'] ?>"><button class="navigace nebezpecne" type="submit"><?= e(t('Delete folder')) ?></button></form>
<?php endif ?>
</div>
<?php endif ?>

<form class="nahravani" method="post" enctype="multipart/form-data" action="<?= e($module->url('upload')) ?>" data-nahravani>
	<?= $csrf ?>
	<input type="hidden" name="folder_id" value="<?= (int) ($activeFolder['folder_id'] ?? 0) ?>">
	<label for="soubory"><strong><?= e(t('Upload images and attachments')) ?><?= $activeFolder !== null ? ' – ' . e($activeFolder['name']) : '' ?></strong> <?= e(t('– select files, or drag them here')) ?></label>
	<input type="file" id="soubory" name="soubory[]" accept="image/jpeg,image/png,image/webp,image/gif,image/svg+xml,.svg,<?= e('.' . implode(',.', Kaleta\Core\Files::FILE_EXTENSIONS)) ?>" multiple required>
	<input class="tl" type="submit" value="<?= e(t('Upload')) ?>">
	<span class="napoveda"><?= e(t('JPG, PNG, WebP and GIF images as well as downloadable attachments (PDF, documents, spreadsheets, ZIP, audio, video), up to %s per file. Large photos are scaled down to %s px and location data is removed.', $limit, Kaleta\Core\Images::MAX_SIDE)) ?></span>
</form>

<form class="navigace-radek media-hledani" method="get" action="<?= e($app->url('admin.php')) ?>" role="search">
	<input type="hidden" name="module" value="media">
<?php foreach (array_diff_key($params, ['search' => 1, 'sort' => 1]) as $k => $v): ?>
	<input type="hidden" name="<?= e($k) ?>" value="<?= e((string) $v) ?>">
<?php endforeach ?>
	<input class="textpole" type="search" name="search" value="<?= e($filter['search']) ?>" placeholder="<?= e(t('Search name, description or file')) ?>" aria-label="<?= e(t('Search media')) ?>">
	<select name="sort" aria-label="<?= e(t('Order')) ?>" data-odeslat-pri-zmene>
<?php foreach (Kaleta\Admin\Modules\Media::SORT_ORDERS as $key => [$sortName]): ?>
		<option value="<?= e($key) ?>"<?= $filter['sort'] === $key ? ' selected' : '' ?>><?= e(t($sortName)) ?></option>
<?php endforeach ?>
	</select>
	<button class="navigace" type="submit"><?= e(t('Filter')) ?></button>
</form>
<?php if ($images === []): ?>
<?= $app->view->render('admin/empty', ['icon' => 'media', 'heading' => t('No images.'), 'text' => t('Upload your first photos with the form above – or drag them straight into the text in the editor.')]) ?>
<?php else: ?>
<form method="post" action="<?= e($module->url('bulk')) ?>">
<?= $csrf ?>
<div class="galerie-mrizka">
<?php foreach ($images as $o): ?>
	<figure class="galerie-polozka">
<?php if ($o['thumb_path'] === ''): ?>
		<a class="galerie-soubor" href="<?= e($app->url($o['image_path'])) ?>" target="_blank" rel="noopener"><span><?= e(strtoupper(pathinfo($o['image_path'], PATHINFO_EXTENSION))) ?></span></a>
<?php else: ?>
		<a href="<?= e($app->url($o['image_path'])) ?>" target="_blank" rel="noopener"><img src="<?= e($app->url($o['thumb_path'])) ?>" alt="<?= e($o['name']) ?>" loading="lazy" width="<?= (int) $o['thumb_width'] ?>" height="<?= (int) $o['thumb_height'] ?>"></a>
<?php endif ?>
		<figcaption>
			<strong title="<?= e($o['name']) ?>"><?= e($o['name'] !== '' ? $o['name'] : t('untitled')) ?></strong>
			<span><?= $o['thumb_path'] === '' ? '' : (int) $o['image_width'] . '&times;' . (int) $o['image_height'] . ' &middot; ' ?><?= e(Kaleta\Core\Files::size((int) $o['image_size'])) ?> &middot; <span<?= $o['kde'] !== [] ? ' title="' . e(t('Used in: %s', implode(', ', $o['kde']))) . '"' : '' ?>><?= e((int) $o['used_at'] > 0 ? t('used %s×', (int) $o['used_at']) : t('unused')) ?></span></span>
<?php if ($o['thumb_path'] !== ''): ?>
			<input class="galerie-popis" type="text" value="<?= e((string) $o['name']) ?>" maxlength="150" placeholder="<?= e(t('Description for blind users (alt)')) ?>" aria-label="<?= e(t('Description of image %s', $o['name'])) ?>" data-popis-media="<?= (int) $o['media_id'] ?>" data-adresa="<?= e($module->url('save_caption')) ?>" form="">
<?php endif ?>
			<span><label><input type="checkbox" name="oznacene[]" value="<?= (int) $o['media_id'] ?>"> <?= e(t('select')) ?></label> &middot; <a href="<?= e($module->url('list', $params + ['edit' => $o['media_id'], 'page' => $pageNumber])) ?>#uprav"><?= e(t('description')) ?></a></span>
		</figcaption>
	</figure>
<?php endforeach ?>
</div>
<p class="media-hromadne">
	<?= e(t('With selected:')) ?>
	<select name="do_sekce" aria-label="<?= e(t('Target folder')) ?>">
		<option value="0"><?= e(t('– uncategorized –')) ?></option>
<?php foreach ($folders as $s): ?>
		<option value="<?= (int) $s['folder_id'] ?>"><?= e($s['name']) ?></option>
<?php endforeach ?>
	</select>
	<button class="navigace" type="submit" name="provest" value="presun"><?= e(t('Move to folder')) ?></button>
	<button class="navigace nebezpecne" type="submit" name="provest" value="smaz" data-potvrdit="<?= e(t('Really delete the selected files? Files the site still uses are skipped.')) ?>"><?= e(t('Delete')) ?></button>
</p>
</form>

<?php foreach ($images as $o): if ((int) $o['media_id'] !== $app->request->getInt('edit')) { continue; } ?>
<form class="formular" id="uprav" method="post" action="<?= e($module->url('save')) ?>">
	<?= $csrf ?>
	<input type="hidden" name="media_id" value="<?= (int) $o['media_id'] ?>">
	<div class="radek"><label for="nazev"><?= e(t('Name (alternative text)')) ?></label><div><input class="textpole siroke" type="text" id="nazev" name="name" value="<?= e($o['name']) ?>" maxlength="150"><span class="napoveda"><?= e(t('Describe what is in the image - screen readers and search engines read it.')) ?></span></div></div>
	<div class="radek"><label for="popis"><?= e(t('Caption below the image')) ?></label><input class="textpole siroke" type="text" id="popis" name="description" value="<?= e($o['description']) ?>" maxlength="500"></div>
	<div class="radek"><label for="autor"><?= e(t('Image credit')) ?></label><div><input class="textpole siroke" type="text" id="autor" name="author" value="<?= e($o['author'] ?? '') ?>" maxlength="120"><span class="napoveda"><?= e(t('Shown under a news item\'s main photo unless it has its own photo credit.')) ?></span></div></div>
<?php if ($o['thumb_path'] !== '' && !str_ends_with($o['image_path'], '.svg')): [$ox, $oy] = array_map('intval', explode(' ', str_replace('%', '', $o['focal_point'] ?: '50% 50%'))) + [1 => 50]; ?>
	<div class="radek"><span class="popisek"><?= e(t('Crop focal point')) ?></span><div>
		<div class="ohnisko" data-ohnisko><img src="<?= e($app->url($o['thumb_path'])) ?>" alt=""><span class="ohnisko-bod" style="left:<?= $ox ?>%;top:<?= $oy ?>%"></span></div>
		<label><?= e(t('Horizontal')) ?> <input class="textpole" type="number" name="ohnisko_x" min="0" max="100" value="<?= $ox ?>" size="3"> %</label>
		<label><?= e(t('Vertical')) ?> <input class="textpole" type="number" name="ohnisko_y" min="0" max="100" value="<?= $oy ?>" size="3"> %</label>
		<span class="napoveda"><?= e(t('Click in the preview on what must stay visible when the photo is cropped to another shape (card, section background).')) ?></span></div></div>
<?php endif ?>
	<p class="tlacitka"><input class="tl" type="submit" value="<?= e(t('Save')) ?>"></p>
</form>
<?php if (preg_match('/\.(jpg|png|webp)$/', $o['image_path'])): ?>
<form class="formular" method="post" action="<?= e($module->url('replace')) ?>" enctype="multipart/form-data">
	<?= $csrf ?>
	<input type="hidden" name="media_id" value="<?= (int) $o['media_id'] ?>">
	<div class="radek"><label for="soubor-nahrada"><?= e(t('Replace file')) ?></label><div><input type="file" id="soubor-nahrada" name="soubor" accept="image/jpeg,image/png,image/webp" required>
		<span class="napoveda"><?= e(t('The new photo appears everywhere the old one is used – the file address does not change.')) ?></span></div></div>
	<p class="tlacitka"><input class="navigace" type="submit" value="<?= e(t('Replace')) ?>"></p>
</form>
<?php endif ?>
<?php endforeach ?>

<?php if ($pageCount > 1): ?>
<p class="strankovani">
<?php for ($s = 1; $s <= $pageCount; $s++): ?>
	<?= $s === $pageNumber ? '<strong>[' . $s . ']</strong>' : '<a href="' . e($module->url('', $params + ['page' => $s])) . '">' . $s . '</a>' ?>
<?php endfor ?>
</p>
<?php endif ?>
<?php endif ?>
</div>
</div>
