<?php
/**
 * The SEO tab of Website mode: how the chosen page appears in a search result and when it is shared, its title,
 * description, sharing image and indexing, and what to fix. Saved by Pages::actionSeoSave.
 *
 * @var Talea\Core\App $app
 * @var Talea\Admin\Modules\Pages $module
 * @var string $csrf
 * @var array<string, mixed> $page the page with seo_title, description, image, noindex
 * @var string $url the page's address on the site
 * @var bool $home it is the home page
 * @var string $siteName
 * @var string $origin the site's scheme and host
 */
$title = (string) $page['seo_title'] !== '' ? (string) $page['seo_title'] : ($home ? $siteName : $page['title'] . ' – ' . $siteName);
$description = (string) $page['description'];
$checks = [];
if (mb_strlen($title) > 60) {
    $checks[] = [false, t('The title has %d characters – search results cut it after about 60.', mb_strlen($title))];
}
if ($description === '') {
    $checks[] = [false, t('No description – search engines then pick a sentence of the page themselves.')];
} elseif (mb_strlen($description) > 160) {
    $checks[] = [false, t('The description has %d characters – search results cut it after about 160.', mb_strlen($description))];
}
if ((string) $page['image'] === '') {
    $checks[] = [false, t('No image for sharing – the site-wide one or none is used.')];
}
if ((bool) $page['noindex']) {
    $checks[] = [false, t('Hidden from search engines (noindex).')];
}
if ($checks === []) {
    $checks[] = [true, t('Title, description and sharing image are set.')];
}
$host = (string) preg_replace('#^https?://#', '', $origin);
?>
<div class="website-frame website-seo">
	<div class="website-bar">
		<div class="website-bar-start"></div>
		<div class="website-bar-title"><strong><?= e($page['title']) ?></strong><span><?= e(t('How this page appears outside your site')) ?></span></div>
		<div class="website-bar-end"><a class="menu-tool" href="<?= e($url) ?>" target="_blank" rel="noopener" title="<?= e(t('Open the live page')) ?>" aria-label="<?= e(t('Open the live page')) ?>"><svg class="icon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round" aria-hidden="true"><path d="M7 4v16l13-8z"/></svg></a></div>
	</div>
	<div class="website-seo-body">
		<form class="website-seo-form" method="post" action="<?= e($module->url('seo_save')) ?>">
			<?= $csrf ?>
			<input type="hidden" name="id" value="<?= e($page['public_id']) ?>">
			<label><span><?= e(t('Title in search results')) ?></span><input class="textfield" type="text" name="seo_title" maxlength="200" value="<?= e((string) $page['seo_title']) ?>" placeholder="<?= e($title) ?>"></label>
			<label><span><?= e(t('Description')) ?></span><textarea class="textfield" name="description" rows="4" maxlength="300"><?= e($description) ?></textarea></label>
			<label><span><?= e(t('Image for sharing')) ?></span><input class="textfield" type="text" name="image" maxlength="255" value="<?= e((string) $page['image']) ?>" placeholder="media/…"></label>
			<label class="website-seo-check"><input type="checkbox" name="noindex" value="1"<?= $page['noindex'] ? ' checked' : '' ?>> <?= e(t('Hide from search engines (noindex)')) ?></label>
			<p class="navigation-row"><button class="btn" type="submit"><?= e(t('Save')) ?></button> <a class="navigation" href="<?= e($module->url('edit', ['id' => $page['public_id']])) ?>"><?= e(t('Page settings')) ?></a></p>
		</form>
		<div class="website-seo-preview">
			<section aria-labelledby="seo-result-heading">
				<h2 id="seo-result-heading"><?= e(t('Search result')) ?></h2>
				<div class="seo-result"><span><?= e($host) ?></span><strong><?= e($title) ?></strong><p><?= e($description !== '' ? mb_strimwidth($description, 0, 170, '…') : t('(the search engine picks a sentence of the page)')) ?></p></div>
			</section>
			<section aria-labelledby="seo-share-heading">
				<h2 id="seo-share-heading"><?= e(t('Shared in a chat or post')) ?></h2>
				<div class="seo-share">
<?php if ((string) $page['image'] !== ''): ?>
					<img src="<?= e(preg_match('#^(https?:)?/#', (string) $page['image']) ? (string) $page['image'] : $app->url((string) $page['image'])) ?>" alt="">
<?php else: ?>
					<span class="seo-share-empty" aria-hidden="true"></span>
<?php endif ?>
					<span><?= e($host) ?></span><strong><?= e($title) ?></strong>
				</div>
			</section>
			<section aria-labelledby="seo-checks-heading">
				<h2 id="seo-checks-heading"><?= e(t('Checks for this page')) ?></h2>
				<ul class="seo-checks">
<?php foreach ($checks as [$ok, $text]): ?>
					<li class="<?= $ok ? 'seo-ok' : 'seo-todo' ?>"><?= e($text) ?></li>
<?php endforeach ?>
				</ul>
			</section>
		</div>
	</div>
</div>
