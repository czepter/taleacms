<?php
/**
 * The actions of one row (docs/specs/admin-lists.md): the pencil, an optional one-click action and the "⋯" menu
 * (Popover API, no script). Destructive menu items come last, after a separator, and keep their data-confirm.
 *
 * @var string $id          the row's public id (names the popover)
 * @var string $name        the row's title, for the labels
 * @var string|null $edit   URL of the main editor
 * @var array{action: string, fields: array<string, string>, label: string, icon: string, filled?: bool}|null $quick
 * @var list<array<string, mixed>|string> $menu  ['url', 'label', 'icon'?, 'external'?] | ['post', 'fields', 'label', 'icon'?, 'confirm'?, 'danger'?] | ['note'] | '-'
 * @var string $csrf
 */
$icon = require __DIR__ . '/../icons.php';
$edit ??= null;
$quick ??= null;
$hidden = function (array $fields): string {
    $html = '';
    foreach ($fields as $field => $value) {
        $html .= '<input type="hidden" name="' . e($field) . '" value="' . e($value) . '">';
    }

    return $html;
};
?>
<div class="row-actions">
<?php if ($quick !== null): ?>
<form class="inline" method="post" action="<?= e($quick['action']) ?>"><?= $csrf ?><?= $hidden($quick['fields']) ?><button class="icon-btn<?= empty($quick['filled']) ? '' : ' filled' ?>" type="submit" aria-label="<?= e($quick['label'] . ': ' . $name) ?>" title="<?= e($quick['label']) ?>"><?= $icon($quick['icon']) ?></button></form>
<?php endif ?>
<?php if ($edit !== null): ?>
<a class="icon-btn" href="<?= e($edit) ?>" aria-label="<?= e(t('Edit: %s', $name)) ?>" title="<?= e(t('Edit')) ?>"><?= $icon('edit') ?></a>
<?php endif ?>
<?php if ($menu !== []): ?>
<button class="icon-btn" type="button" popovertarget="row-menu-<?= e($id) ?>" aria-label="<?= e(t('More actions for %s', $name)) ?>"><?= $icon('more') ?></button>
<div class="row-menu" id="row-menu-<?= e($id) ?>" popover>
<?php foreach ($menu as $item): ?>
<?php if ($item === '-'): ?>
<hr role="separator">
<?php elseif (isset($item['note'])): ?>
<p class="row-menu-note"><?= e($item['note']) ?></p>
<?php elseif (isset($item['post'])): ?>
<form method="post" action="<?= e($item['post']) ?>"<?= isset($item['confirm']) ? ' data-confirm="' . e($item['confirm']) . '"' : '' ?>><?= $csrf ?><?= $hidden($item['fields'] ?? []) ?><button<?= empty($item['danger']) ? '' : ' class="danger"' ?> type="submit"><?= $icon($item['icon'] ?? (empty($item['danger']) ? 'check' : 'trash')) ?><?= e($item['label']) ?></button></form>
<?php else: ?>
<a href="<?= e($item['url']) ?>"<?= empty($item['external']) ? '' : ' target="_blank" rel="noopener"' ?>><?= $icon($item['icon'] ?? (empty($item['external']) ? 'edit' : 'out')) ?><?= e($item['label']) ?></a>
<?php endif ?>
<?php endforeach ?>
</div>
<?php endif ?>
</div>
