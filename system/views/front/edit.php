<?php
/**
 * Editing a page or news item directly on the site: the same editor as in the admin, embedded in the site template.
 * It is saved with a normal form to the admin (action uloz_text), so the same permissions and versions apply.
 *
 * @var Talea\Core\App $app
 * @var string $type       news | page
 * @var array<string, mixed> $record
 * @var string $action      url for saving
 * @var string $back      url to return to after saving or cancelling
 * @var bool $error       saving failed (empty title)
 */
$recordId = $app->db()->publicId($type === 'news' ? 'news' : 'pages', (int) ($record['news_id'] ?? $record['page_id']));
?>
<link rel="stylesheet" href="<?= e($app->url('image/editor.css')) ?>?v=<?= e(TALEA_VERSION) ?>">
<article class="article article-full tl-edit-text tl-ui">
	<form method="post" action="<?= e($action) ?>">
		<input type="hidden" name="_csrf" value="<?= e($app->session->csrfToken()) ?>">
		<input type="hidden" name="id" value="<?= e($recordId) ?>">
		<input type="hidden" name="back" value="<?= e($back) ?>">
<?php if ($error): ?>
		<p class="tl-edit-notice"><?= e(t('The title must not be empty.')) ?></p>
<?php endif ?>
		<p><label for="tl-title"><?= e(t('Title')) ?></label>
			<input class="tl-edit-title" type="text" id="tl-title" name="title" value="<?= e($record['title']) ?>" maxlength="200" required></p>
<?php if ($type === 'news'): ?>
		<p><label for="tl-intro"><?= e(t('Lead')) ?></label>
			<textarea id="tl-intro" name="intro" rows="4" data-editor="small"><?= e($record['intro']) ?></textarea></p>
<?php endif ?>
		<p><label for="tl-text"><?= e(t('Text')) ?></label>
			<textarea id="tl-text" name="text" rows="18" data-editor><?= e($record['text']) ?></textarea></p>
		<div class="tl-edit-bar">
			<button class="tl-btn" type="submit"><?= e(t('Save')) ?></button>
			<a class="tl-btn tl-btn-secondary" href="<?= e($back) ?>"><?= e(t('Cancel')) ?></a>
			<a class="tl-edit-all" href="<?= e($app->url('admin.php?module=' . ($type === 'news' ? 'news' : 'pages') . '&action=edit&id=' . $recordId)) ?>"><?= e(t('All settings in the administration')) ?></a>
		</div>
	</form>
</article>
<script src="<?= e($app->url('image/editor.js')) ?>?v=<?= e(TALEA_VERSION) ?>" data-admin-url="<?= e($app->url('admin.php')) ?>" defer></script>
