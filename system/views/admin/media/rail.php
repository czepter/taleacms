<?php
/**
 * The left menu of Media: the views over the whole library, then the folder tree (docs/specs/media-library.md).
 *
 * @var Talea\Core\App $app
 * @var Talea\Admin\Modules\Media $module
 * @var array<string, mixed> $filter
 * @var array{total: int, recent: int, missing: int, waiting: int, unused: int} $counts
 * @var list<array<string, mixed>> $folders  Core\MediaFolders::all()
 * @var list<array<string, mixed>> $path     breadcrumb of the current folder (ancestors open, the folder itself too)
 * @var array<string, mixed>|null $current
 */
$icon = require __DIR__ . '/../icons.php';
$open = array_column($path, 'folder_id');
$across = !$filter['level'];
$view = fn (string $label, array $params, bool $active, ?int $count = null, string $kind = ''): string => '<a class="rail-link" href="' . e($module->url('', $params)) . '"' . ($active ? ' aria-current="page"' : '') . '><span class="label">' . e($label) . '</span>'
    . ($count === null ? '' : '<span class="rail-count' . ($kind !== '' && $count > 0 ? ' ' . $kind : '') . '">' . format_count($count) . '</span>') . '</a>';
$branch = function (?int $parent) use (&$branch, $folders, $open, $current, $module, $icon): void {
    foreach (Talea\Core\MediaFolders::children($folders, $parent) as $f) {
        $kids = Talea\Core\MediaFolders::children($folders, $f['folder_id']);
        $isOpen = in_array($f['folder_id'], $open, true);
?>
<li><a class="rail-link" href="<?= e($module->url('', ['folder' => $f['public_id']])) ?>"<?= $current !== null && $current['folder_id'] === $f['folder_id'] ? ' aria-current="page"' : '' ?>>
	<span class="name"><span class="twist" aria-hidden="true"><?= $kids === [] ? '' : $icon($isOpen ? 'down' : 'next') ?></span><span class="label" title="<?= e($f['name']) ?>"><?= bdi($f['name']) ?></span></span>
	<span class="rail-count"><?= format_count($f['count']) ?></span></a>
<?php if ($kids !== []): ?>
	<ul class="media-tree-sub<?= $isOpen ? '' : ' closed' ?>"><?php $branch($f['folder_id']) ?></ul>
<?php endif ?>
</li>
<?php
    }
};
?>
<nav class="media-rail" aria-label="<?= e(t('Media')) ?>">
	<?= $view(t('All media'), [], !$across && $current === null, $counts['total']) ?>
	<?= $view(t('Recently uploaded'), ['recent' => 1], $filter['recent'], $counts['recent']) ?>
	<div class="rail-sep"></div>
	<?= $view(t('Missing description'), ['missing' => 1], $filter['missing'], $counts['missing'], 'missing') ?>
	<?= $view(t('Unused'), ['unused' => 1], $filter['unused'], $counts['unused']) ?>
<?php if ($counts['waiting'] > 0 || $filter['waiting']): ?>
	<?= $view(t('Waiting for approval'), ['waiting' => 1], $filter['waiting'], $counts['waiting'], 'review') ?>
<?php endif ?>
	<a class="rail-link" href="<?= e($module->url('cleanup')) ?>"><span class="label"><?= e(t('Clean up')) ?></span></a>
	<div class="rail-heading"><span><?= e(t('Folders')) ?></span><button class="icon-btn" type="button" popovertarget="folder-new" aria-label="<?= e(t('New folder')) ?>"><?= $icon('plus') ?></button></div>
<?php if ($folders !== []): ?>
	<label class="rail-filter"><span class="visually-hidden"><?= e(t('Find a folder')) ?></span><?= $icon('search') ?><input type="search" placeholder="<?= e(t('Find a folder')) ?>" data-folder-filter="media-tree" autocomplete="off"></label>
	<ul class="media-tree" id="media-tree"><?php $branch(null) ?></ul>
<?php endif ?>
</nav>
