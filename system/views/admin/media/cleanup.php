<?php
/**
 * Media clean-up (2.14): unused files, oversized images, duplicates and images without a description.
 * The checkboxes of unused files and duplicate copies all belong to one delete form under the lists (form="cleanup"),
 * so the tables can keep their own small forms (make smaller).
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\Media $module
 * @var string $csrf
 * @var list<array<string, mixed>> $unused
 * @var list<array<string, mixed>> $oversized
 * @var list<list<array<string, mixed>>> $duplicates
 * @var list<array<string, mixed>> $withoutAlt      images without alt the signed-in user may describe (at most Media::ALT_BATCH)
 * @var int $withoutAltTotal
 * @var int $total
 * @var bool $canShrink
 * @var callable $canEdit  fn (array $row): bool
 */
use Kaleta\Core\Files;
use Kaleta\Core\MediaHygiene;

$thumbnail = fn (array $o): string => $o['thumb_path'] === ''
    ? '<span class="gallery-file cleanup-file">' . e(strtoupper(pathinfo($o['image_path'], PATHINFO_EXTENSION))) . '</span>'
    : '<img src="' . e($app->url($o['thumb_path'])) . '" alt="" loading="lazy" width="80">';
$file = fn (array $o): string => '<a href="' . e($app->url($o['image_path'])) . '" target="_blank" rel="noopener">' . e(basename($o['image_path'])) . '</a>'
    . '<br><small>' . ($o['thumb_path'] === '' ? '' : (int) $o['image_width'] . '&times;' . (int) $o['image_height'] . ' &middot; ') . e(Files::size((int) $o['image_size'])) . ' &middot; ' . e(format_date($o['created_at'])) . '</small>';
$deletable = 0;
?>
<p class="navigation-row"><a class="navigation" href="<?= e($module->url()) ?>"><?= e(t('Back to media')) ?></a></p>
<p class="small-text"><?= e(t('%d files in Media. The site found what can be cleaned up – nothing changes until you decide.', $total)) ?></p>

<h2><?= e(t('Unused files')) ?> (<?= count($unused) ?>)</h2>
<?php if ($unused === []): ?>
<p class="help"><?= e(t('Every file is used somewhere on the site.')) ?></p>
<?php else: ?>
<p class="help"><?= e(t('Nothing on the site points at these files: no page, build, news item, collection item, component, pop-up, newsletter or setting. Media has no trash – deleted files cannot be restored.')) ?></p>
<div class="tab-wrap">
<table class="listing cleanup">
<thead><tr><th scope="col"><input type="checkbox" data-select-all="cleanup" aria-label="<?= e(t('Select all')) ?>"></th><th scope="col"></th><th scope="col"><?= e(t('File')) ?></th><th scope="col"><?= e(t('Name (alternative text)')) ?></th></tr></thead>
<tbody>
<?php foreach ($unused as $o): $editable = $canEdit($o); $deletable += $editable ? 1 : 0; ?>
<tr>
	<td><?php if ($editable): ?><input type="checkbox" name="selected[]" value="<?= (int) $o['media_id'] ?>" form="cleanup" aria-label="<?= e(t('Delete %s', basename($o['image_path']))) ?>"><?php endif ?></td>
	<td><?= $thumbnail($o) ?></td>
	<td><?= $file($o) ?></td>
	<td><?= e($o['name'] !== '' ? $o['name'] : t('untitled')) ?></td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<?php endif ?>

<h2><?= e(t('Duplicates')) ?> (<?= count($duplicates) ?>)</h2>
<?php if ($duplicates === []): ?>
<p class="help"><?= e(t('No file is uploaded twice.')) ?></p>
<?php else: ?>
<p class="help"><?= e(t('The same file uploaded more than once. Keep the one the site uses; an unused copy can be ticked and deleted below.')) ?></p>
<div class="tab-wrap">
<table class="listing cleanup">
<thead><tr><th scope="col"></th><th scope="col"></th><th scope="col"><?= e(t('File')) ?></th><th scope="col"><?= e(t('Used')) ?></th></tr></thead>
<tbody>
<?php foreach ($duplicates as $i => $group): foreach ($group as $j => $o): $editable = $canEdit($o) && $o['used_at'] === 0; $deletable += $editable ? 1 : 0; ?>
<tr<?= $j === 0 && $i > 0 ? ' class="cleanup-group"' : '' ?>>
	<td><?php if ($editable): ?><input type="checkbox" name="selected[]" value="<?= (int) $o['media_id'] ?>" form="cleanup" aria-label="<?= e(t('Delete %s', basename($o['image_path']))) ?>"><?php endif ?></td>
	<td><?= $thumbnail($o) ?></td>
	<td><?= $file($o) ?></td>
	<td><?= $o['used_at'] > 0 ? e(t('used %s×', (int) $o['used_at'])) . ($o['used_in'] !== [] ? '<br><small>' . e(implode(', ', $o['used_in'])) . '</small>' : '') : e(t('unused')) ?></td>
</tr>
<?php endforeach; endforeach ?>
</tbody>
</table>
</div>
<?php endif ?>

<?php if ($deletable > 0): ?>
<form id="cleanup" class="media-bulk" method="post" action="<?= e($module->url('bulk')) ?>" data-confirm="<?= e(t('Delete the selected files for good? Media has no trash. Files the site still uses are skipped.')) ?>">
	<?= $csrf ?>
	<input type="hidden" name="bulk" value="smaz">
	<input type="hidden" name="back" value="cleanup">
	<button class="navigation danger" type="submit"><?= e(t('Delete selected files')) ?></button>
</form>
<?php endif ?>

<h2><?= e(t('Oversized images')) ?> (<?= count($oversized) ?>)</h2>
<?php if ($oversized === []): ?>
<p class="help"><?= e(t('No image is larger than %s or wider than %d px.', Files::size(MediaHygiene::OVERSIZED_BYTES), MediaHygiene::OVERSIZED_WIDTH)) ?></p>
<?php else: ?>
<p class="help"><?= e(t('Images over %s or wider than %d px slow pages down. “Make smaller” re-encodes the image to %d px in place – its address and every page that shows it stay.', Files::size(MediaHygiene::OVERSIZED_BYTES), MediaHygiene::OVERSIZED_WIDTH, Kaleta\Core\Images::MAX_SIDE)) ?></p>
<div class="tab-wrap">
<table class="listing cleanup">
<thead><tr><th scope="col"></th><th scope="col"><?= e(t('File')) ?></th><th scope="col"><?= e(t('Used')) ?></th><th scope="col"><?= e(t('Actions')) ?></th></tr></thead>
<tbody>
<?php foreach ($oversized as $o): ?>
<tr>
	<td><?= $thumbnail($o) ?></td>
	<td><?= $file($o) ?></td>
	<td><?= e($o['used_at'] > 0 ? t('used %s×', (int) $o['used_at']) : t('unused')) ?></td>
	<td class="actions"><?php if ($canShrink && $canEdit($o) && preg_match('/\.(jpg|png|webp)$/', $o['image_path'])): ?>
		<form class="inline" method="post" action="<?= e($module->url('shrink')) ?>"><?= $csrf ?><input type="hidden" name="media_id" value="<?= (int) $o['media_id'] ?>"><button class="navigation" type="submit"><?= e(t('Make smaller')) ?></button></form>
<?php else: ?><span class="help"><?= e(t('cannot be made smaller here')) ?></span><?php endif ?></td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<?php endif ?>

<h2><?= e(t('Images without a description')) ?> (<?= $withoutAltTotal ?>)</h2>
<?php if ($withoutAlt === []): ?>
<p class="help"><?= e($withoutAltTotal === 0 ? t('Every image has a description for blind visitors (alt).') : t('Only the owner of an image or an administrator can describe it.')) ?></p>
<?php else: ?>
<p class="help"><?= e(t('Describe what each image shows – screen readers and search engines read it. Empty fields are skipped; the rest is saved together.')) ?><?= $withoutAltTotal > count($withoutAlt) ? ' ' . e(t('Showing the first %d of %d.', count($withoutAlt), $withoutAltTotal)) : '' ?></p>
<form class="form" method="post" action="<?= e($module->url('save_alts')) ?>">
	<?= $csrf ?>
	<div class="tab-wrap">
	<table class="listing cleanup">
	<thead><tr><th scope="col"></th><th scope="col"><?= e(t('File')) ?></th><th scope="col"><?= e(t('Description for blind users (alt)')) ?></th></tr></thead>
	<tbody>
<?php foreach ($withoutAlt as $o): ?>
	<tr>
		<td><?= $thumbnail($o) ?></td>
		<td><?= $file($o) ?></td>
		<td><input class="textfield wide" type="text" name="alt[<?= (int) $o['media_id'] ?>]" maxlength="150" aria-label="<?= e(t('Description of image %s', basename($o['image_path']))) ?>"></td>
	</tr>
<?php endforeach ?>
	</tbody>
	</table>
	</div>
	<p class="buttons"><button class="btn" type="submit"><?= e(t('Save descriptions')) ?></button></p>
</form>
<?php endif ?>
