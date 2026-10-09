<?php
/**
 * Content check of a page or news item (2.14, Core\ContentCheck): the saved version, computed on the server.
 *
 * @var list<array{check: string, ok: bool, message: string}> $results  [] = not saved yet
 */
?>
<fieldset class="check check-content" data-check-content>
<legend><?= e(t('Content check')) ?></legend>
<?php if ($results === []): ?>
<p class="help"><?= e(t('Save first – the check looks at the saved version.')) ?></p>
<?php else: ?>
<ul class="check-list">
<?php foreach ($results as $r): ?>
	<li class="<?= $r['ok'] ? 'ok' : 'warning' ?>" data-check="<?= e($r['check']) ?>"><span aria-hidden="true"><?= $r['ok'] ? '✓' : '!' ?></span> <?= e($r['message']) ?></li>
<?php endforeach ?>
</ul>
<p class="help"><?= e(t('Of the saved version. Search engines show about 60 characters of a title and 160 of a description.')) ?></p>
<?php endif ?>
</fieldset>
