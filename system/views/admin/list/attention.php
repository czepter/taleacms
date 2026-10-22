<?php
/**
 * A box above a list for what waits for the user (docs/specs/admin-lists.md): 404 addresses with a suggestion,
 * items to approve. Rendered only when there is something to do.
 *
 * @var string $id      id of the heading (also a link target)
 * @var string $title
 * @var string|null $meta
 * @var string $rows    raw HTML, one .attention-row each
 * @var string|null $aside  raw HTML next to the title (e.g. "Ignore all")
 */
?>
<section class="attention" aria-labelledby="<?= e($id) ?>">
<div class="attention-head"><h2 id="<?= e($id) ?>"><?= e($title) ?></h2><?php if (!empty($meta)): ?><span class="attention-meta"><?= e($meta) ?></span><?php endif ?><?= $aside ?? '' ?></div>
<?= $rows ?>
</section>
