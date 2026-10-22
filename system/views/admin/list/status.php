<?php
/**
 * A dot plus a word, and a note below when something needs attention (docs/specs/admin-lists.md).
 *
 * @var string $kind        live | hidden | draft | planned | review | error | off
 * @var string $label
 * @var string|null $note
 * @var string|null $noteKind  warning (default) | error
 * @var string|null $noteUrl
 */
$note ??= null;
$class = 'status-note status-note-' . (($noteKind ?? 'warning') === 'error' ? 'error' : 'warning');
?>
<span class="status status-<?= e($kind) ?>"><?= e($label) ?></span><?php if ($note !== null && $note !== ''): ?>
<?php if (!empty($noteUrl)): ?><a class="<?= $class ?>" href="<?= e($noteUrl) ?>"><?= e($note) ?></a><?php else: ?><span class="<?= $class ?>"><?= e($note) ?></span><?php endif ?>
<?php endif ?>
