<?php
/**
 * The head of an admin list (docs/specs/admin-lists.md): the count next to the heading and, on the right,
 * quiet links, an optional secondary control and the one primary button. Fills the layout's slots, prints nothing.
 *
 * @var Talea\Core\View $view
 * @var string|null $count
 * @var list<array{0: string, 1: string}> $links      url, label
 * @var string $secondary                             raw HTML (e.g. an import form)
 * @var array{0: string, 1: string}|null $primary     url, label
 */
$icon = require __DIR__ . '/../icons.php';
$links ??= [];
$secondary ??= '';
$primary ??= null;
$view->slot('count', isset($count) && $count !== null ? e($count) : '');
ob_start();
foreach ($links as [$url, $label]): ?>
<a class="quiet-link" href="<?= e($url) ?>"><?= e($label) ?></a>
<?php endforeach ?>
<?= $secondary ?>
<?php if ($primary !== null): ?>
<a class="btn" href="<?= e($primary[0]) ?>"><?= $icon('plus') ?><?= e($primary[1]) ?></a>
<?php endif;
$view->slot('actions', trim((string) ob_get_clean()));
