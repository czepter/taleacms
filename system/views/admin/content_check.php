<?php
/**
 * Content check of a page or news item (2.14, Core\ContentCheck): the saved version, computed on the server.
 *
 * @var list<array{check: string, ok: bool, message: string}> $results  [] = not saved yet
 */
?>
<fieldset class="kontrola kontrola-obsahu" data-kontrola-obsahu>
<legend><?= e(t('Content check')) ?></legend>
<?php if ($results === []): ?>
<p class="napoveda"><?= e(t('Save first – the check looks at the saved version.')) ?></p>
<?php else: ?>
<ul class="kontrola-seznam">
<?php foreach ($results as $r): ?>
	<li class="<?= $r['ok'] ? 'ok' : 'varovani' ?>" data-kontrola="<?= e($r['check']) ?>"><span aria-hidden="true"><?= $r['ok'] ? '✓' : '!' ?></span> <?= e($r['message']) ?></li>
<?php endforeach ?>
</ul>
<p class="napoveda"><?= e(t('Of the saved version. Search engines show about 60 characters of a title and 160 of a description.')) ?></p>
<?php endif ?>
</fieldset>
