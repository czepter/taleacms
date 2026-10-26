<?php
/**
 * The Sharing tab of the settings sidebar: how a link to the page looks in a chat or a post, its own title, description and
 * image (empty = the search title and description, the site-wide image). The card size is site-wide and only shown here.
 *
 * @var Talea\Core\App $app
 * @var array<string, mixed> $page
 * @var string $formStart
 * @var string $host
 */
$dir = Talea\Core\Language::dirAttribute(Talea\Core\Language::ofContent($app->settings(), (string) $page['language']));
$title = (string) $page['share_title'] !== '' ? (string) $page['share_title'] : ((string) $page['seo_title'] !== '' ? (string) $page['seo_title'] : (string) $page['title']);
$description = (string) $page['share_description'] !== '' ? (string) $page['share_description'] : (string) $page['description'];
$image = (string) $page['image'];
?>
<?= $formStart ?>
<section class="website-settings-section" aria-label="<?= e(t('Sharing')) ?>">
	<div class="website-share">
<?php if ($image !== ''): ?>
		<img src="<?= e(preg_match('#^(https?:)?/#', $image) ? $image : $app->url($image)) ?>" alt="">
<?php else: ?>
		<span class="website-share-empty" aria-hidden="true"></span>
<?php endif ?>
		<div><small><?= e($host) ?></small><strong<?= $dir ?>><?= e($title) ?></strong><?php if ($description !== ''): ?><span<?= $dir ?>><?= e($description) ?></span><?php endif ?></div>
	</div>
	<div class="website-field"><label for="ws-share-title"><?= e(t('Title')) ?></label><input class="textfield" type="text" id="ws-share-title" name="share_title" value="<?= e((string) $page['share_title']) ?>" maxlength="200" placeholder="<?= e((string) $page['seo_title'] !== '' ? (string) $page['seo_title'] : (string) $page['title']) ?>"<?= $dir ?>></div>
	<div class="website-field"><label for="ws-share-description"><?= e(t('Description')) ?></label><textarea class="textbox" id="ws-share-description" name="share_description" rows="3" maxlength="320" placeholder="<?= e((string) $page['description']) ?>"<?= $dir ?>><?= e((string) $page['share_description']) ?></textarea><span class="help"><?= e(t('Empty = the title and description for search engines.')) ?></span></div>
	<div class="website-field"><label for="ws-image"><?= e(t('Sharing image')) ?></label><input class="textfield" type="text" id="ws-image" name="image" value="<?= e($image) ?>" maxlength="255" placeholder="<?= e(t('empty = default image from Settings')) ?>" data-image><span class="help">1200 × 630 px</span></div>
	<p class="help"><?= e(t('Card size: %s (the same for the whole site).', $app->settings()->get('seo_card') === 'small' ? t('small image') : t('large image'))) ?></p>
</section>
</form>
