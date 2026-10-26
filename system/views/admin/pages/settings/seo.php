<?php
/**
 * The SEO tab of the settings sidebar: the score and what to fix (seo/checks.php), then the form – the search result preview
 * over the search title and description with their length meters, the focus keywords (chips with a script, one per line
 * without) and the key page – and how a shared link looks. admin.js checks the unsaved fields live (data-seo-live on the
 * form); without a script the tab shows the saved state. key_page=0 goes before its checkbox, so unticking clears it.
 *
 * @var Talea\Core\App $app
 * @var Closure(string): string $link the Website URL of another tab (settings.php)
 * @var array<string, mixed> $page
 * @var string $formStart
 * @var string $host
 * @var bool $scored
 * @var array<string, mixed> $seo Pages::seoTab()
 */
$dir = $seo['dir'];
// characters against what search results show in full; the bar turns to a warning past it (admin.js keeps it current)
$meter = function (string $value, int $max): string {
    $length = mb_strlen($value);

    return '<span class="seo-meter' . ($length > $max ? ' warning' : '') . '" data-meter="' . $max . '"><i style="--fill: ' . min(100, (int) round($length / $max * 100)) . '%"></i><span>' . $length . ' / ' . $max . '</span></span>';
};
$shareTitle = (string) $page['share_title'] !== '' ? (string) $page['share_title'] : ((string) $page['seo_title'] !== '' ? (string) $page['seo_title'] : (string) $page['title']);
$image = (string) $page['image'] !== '' ? (string) $page['image'] : $app->settings()->get('share_image');
?>
<div aria-live="polite"><?= $app->view->render('admin/seo/checks', ['app' => $app, 'scored' => $scored] + $seo) ?></div>
<?= $formStart ?>
<section class="website-settings-section" aria-labelledby="seo-serp-heading">
	<h3 class="seo-heading" id="seo-serp-heading"><span><?= e(t('Search result')) ?></span><a href="<?= e($link('advanced')) ?>"><?= e(t('Advanced')) ?></a></h3>
<?= $app->view->render('admin/seo/serp', $seo) ?>
	<div class="website-field seo-field"><label for="ws-seo-title"><?= e(t('Search engine title')) ?></label><input class="textfield" type="text" id="ws-seo-title" name="seo_title" value="<?= e((string) $page['seo_title']) ?>" maxlength="200" placeholder="<?= e(t('empty = page name')) ?>"<?= $dir ?>><?= $meter((string) $page['seo_title'], 60) ?></div>
	<div class="website-field seo-field"><label for="ws-description"><?= e(t('Search engine description')) ?></label><textarea class="textbox" id="ws-description" name="description" rows="3" maxlength="300"<?= $dir ?>><?= e((string) $page['description']) ?></textarea><?= $meter((string) $page['description'], 160) ?><span class="help"><?= e(t('One or two sentences on what visitors will find on the page (up to 160 characters).')) ?></span></div>
</section>
<section class="website-settings-section" aria-label="<?= e(t('Focus keywords')) ?>">
	<div class="website-field"><label for="ws-keywords"><?= e(t('Focus keywords')) ?></label><textarea class="textbox" id="ws-keywords" name="focus_keywords" rows="3" data-chips<?= $dir ?>><?= e((string) $page['focus_keywords']) ?></textarea><span class="help"><?= e(t('One per line, up to five. The page is checked against the first.')) ?></span></div>
	<div class="website-field"><input type="hidden" name="key_page" value="0"><label class="website-option"><input type="checkbox" name="key_page" value="1"<?= $page['key_page'] ? ' checked' : '' ?>> <?= e(t('Key page')) ?></label><span class="help"><?= e(t('Marks one of the most important pages of the site.')) ?></span></div>
</section>
</form>
<?php /* "In search · 28 days" (clicks, impressions and the queries of the page) goes here, with the search data of a later task */ ?>
<section class="website-settings-section" aria-labelledby="seo-share-heading">
	<h3 class="seo-heading" id="seo-share-heading"><span><?= e(t('When shared')) ?></span><a href="<?= e($link('sharing')) ?>"><?= e(t('Edit')) ?></a></h3>
	<a class="seo-share" href="<?= e($link('sharing')) ?>">
<?php if ($image !== ''): ?>
		<img src="<?= e(preg_match('#^(https?:)?/#', $image) ? $image : $app->url($image)) ?>" alt="">
<?php else: ?>
		<span aria-hidden="true"></span>
<?php endif ?>
		<span><strong<?= $dir ?>><?= e($shareTitle) ?></strong><?= e($host) ?></span>
	</a>
</section>
