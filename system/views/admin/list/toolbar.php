<?php
/**
 * Status tabs on the left, filters and search on the right (docs/specs/admin-lists.md). Search submits on Enter;
 * a visually hidden button keeps the form usable for keyboards and screen readers.
 *
 * @var string $tabsLabel
 * @var list<array{label: string, count?: int, url: string, active: bool}> $tabs
 * @var string $action                                  GET target, normally admin.php
 * @var array<string, string> $hidden                    fields the GET form keeps (module, status …)
 * @var array{name: string, value: string, placeholder: string, label: string}|null $search
 * @var string $filters                                 raw HTML of selects with data-submit-on-change
 */
$icon = require __DIR__ . '/../icons.php';
$search ??= null;
$filters ??= '';
$hidden ??= [];
?>
<div class="list-toolbar">
<?php if ($tabs !== []): ?>
<nav class="list-tabs" aria-label="<?= e($tabsLabel) ?>">
<?php foreach ($tabs as $tab): ?>
<a href="<?= e($tab['url']) ?>"<?= $tab['active'] ? ' aria-current="page"' : '' ?>><?= e($tab['label']) ?><?= isset($tab['count']) ? ' <span class="list-tab-count">' . (int) $tab['count'] . '</span>' : '' ?></a>
<?php endforeach ?>
</nav>
<?php endif ?>
<?php if ($search !== null || $filters !== ''): ?>
<form class="list-filters" method="get" action="<?= e($action ?? 'admin.php') ?>">
<?php foreach ($hidden as $name => $value): ?>
<input type="hidden" name="<?= e($name) ?>" value="<?= e($value) ?>">
<?php endforeach ?>
<?= $filters ?>
<?php if ($search !== null): ?>
<label class="list-search"><span class="visually-hidden"><?= e($search['label']) ?></span><?= $icon('b-search') ?><input type="search" name="<?= e($search['name']) ?>" value="<?= e($search['value']) ?>" placeholder="<?= e($search['placeholder']) ?>"></label>
<button type="submit" class="visually-hidden"><?= e(t('Search')) ?></button>
<?php endif ?>
</form>
<?php endif ?>
</div>
