<?php
/**
 * A dot plus a word, and notes below when something needs attention (docs/specs/admin-lists.md).
 *
 * @var string $kind        live | hidden | draft | planned | review | error | off
 * @var string $label
 * @var string|null $note   one note (shorthand for $notes)
 * @var string|null $noteKind  warning (default) | error
 * @var string|null $noteUrl
 * @var list<array{text: string, kind?: string, url?: string|null}> $notes  kind: warning (default) | error | neutral (a plain fact, not a problem)
 */
$notes ??= [];
if (isset($note) && $note !== null && $note !== '') {
    array_unshift($notes, ['text' => $note, 'kind' => $noteKind ?? 'warning', 'url' => $noteUrl ?? null]);
}
?>
<span class="status status-<?= e($kind) ?>"><?= e($label) ?></span><?php foreach ($notes as $n): $class = 'status-note status-note-' . (in_array($n['kind'] ?? 'warning', ['error', 'neutral'], true) ? $n['kind'] : 'warning'); ?>
<?php if (!empty($n['url'])): ?><a class="<?= $class ?>" href="<?= e($n['url']) ?>"><?= e($n['text']) ?></a><?php else: ?><span class="<?= $class ?>"><?= e($n['text']) ?></span><?php endif ?>
<?php endforeach ?>
