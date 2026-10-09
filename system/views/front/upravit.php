<?php
/**
 * Editing a page or news item directly on the site: the same editor as in the admin, embedded in the site template.
 * It is saved with a normal form to the admin (action uloz_text), so the same permissions and versions apply.
 *
 * @var Kaleta\Core\App $app
 * @var string $typ       novinka | stranka
 * @var array<string, mixed> $zaznam
 * @var string $akce      url for saving
 * @var string $zpet      url to return to after saving or cancelling
 * @var bool $chyba       saving failed (empty title)
 */
?>
<link rel="stylesheet" href="<?= e($app->url('image/editor.css')) ?>?v=<?= e(KALETA_VERSION) ?>">
<article class="clanek clanek-cely ka-upravit-text ka-ui">
	<form method="post" action="<?= e($action) ?>">
		<input type="hidden" name="_csrf" value="<?= e($app->session->csrfToken()) ?>">
		<input type="hidden" name="id" value="<?= (int) ($zaznam['news_id'] ?? $zaznam['page_id']) ?>">
		<input type="hidden" name="zpet" value="<?= e($zpet) ?>">
<?php if ($error): ?>
		<p class="ka-upravit-hlaska"><?= e(t('The title must not be empty.')) ?></p>
<?php endif ?>
		<p><label for="ka-titulek"><?= e(t('Titulek')) ?></label>
			<input class="ka-upravit-titulek" type="text" id="ka-titulek" name="title" value="<?= e($zaznam['title']) ?>" maxlength="200" required></p>
<?php if ($type === 'novinka'): ?>
		<p><label for="ka-uvod"><?= e(t('Lead')) ?></label>
			<textarea id="ka-uvod" name="intro" rows="4" data-editor="maly"><?= e($zaznam['intro']) ?></textarea></p>
<?php endif ?>
		<p><label for="ka-text"><?= e(t('Text')) ?></label>
			<textarea id="ka-text" name="text" rows="18" data-editor><?= e($zaznam['text']) ?></textarea></p>
		<div class="ka-upravit-lista">
			<button class="ka-tl" type="submit"><?= e(t('Uložit')) ?></button>
			<a class="ka-tl ka-tl-vedlejsi" href="<?= e($zpet) ?>"><?= e(t('Cancel')) ?></a>
			<a class="ka-upravit-vse" href="<?= e($app->url('admin.php?module=' . ($type === 'novinka' ? 'news' : 'pages') . '&action=edit&id=' . (int) ($zaznam['news_id'] ?? $zaznam['page_id']))) ?>"><?= e(t('All settings in the administration')) ?></a>
		</div>
	</form>
</article>
<script src="<?= e($app->url('image/editor.js')) ?>?v=<?= e(KALETA_VERSION) ?>" data-admin-url="<?= e($app->url('admin.php')) ?>" defer></script>
