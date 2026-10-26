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
 * @var bool $scored the Page check feature is on: the score and what to fix are shown
 * @var array<string, mixed> $seo Pages::seoTab()
 * @var array<string, mixed>|null $inSearch Google's numbers and searches for the page (Pages::searchFor); null hides the block
 * @var Talea\Admin\Modules\Pages $module
 * @var string $csrf
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
<?php if ($scored): ?>
<div aria-live="polite"><?= $app->view->render('admin/seo/checks', ['app' => $app] + $seo) ?></div>
<?php endif ?>
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
<?php if ($inSearch !== null): $focus = array_map(mb_strtolower(...), Talea\Core\SeoFields::keywords((string) $page['focus_keywords'])); $totals = $inSearch['totals']; ?>
<section class="website-settings-section" aria-labelledby="seo-search-heading">
	<h3 class="seo-heading" id="seo-search-heading"><span><?= e(t('In search · 28 days')) ?></span><a href="<?= e($app->url('admin.php?module=search_performance')) ?>"><?= e(t('All searches')) ?></a></h3>
<?php if ($totals === null && $inSearch['queries'] === []): ?>
	<p class="help"><?= e(t('Google Search Console has not shown this page in search in these 28 days.')) ?></p>
<?php else: ?>
	<dl class="seo-search-stats">
		<div><dt><?= e(t('Clicks')) ?></dt><dd><?= format_count($totals['clicks'] ?? 0) ?></dd></div>
		<div><dt><?= e(t('Shown')) ?></dt><dd><?= format_count($totals['impressions'] ?? 0) ?></dd></div>
		<div><dt><?= e(t('Place')) ?></dt><dd><?= ($totals['position'] ?? null) !== null ? format_count($totals['position'], 1) : '—' ?></dd></div>
	</dl>
<?php if ($inSearch['queries'] !== []): ?>
	<ul class="seo-search-queries" aria-label="<?= e(t('Searches that led to this page')) ?>">
<?php foreach ($inSearch['queries'] as $q): ?>
		<li><span<?= $dir ?>><?= e($q['query']) ?></span><span class="small-text" title="<?= e(t('Clicks · place')) ?>"><?= format_count($q['clicks']) ?> · <?= format_count($q['position'], 1) ?></span>
<?php if (in_array(mb_strtolower($q['query']), $focus, true)): ?>
			<span class="tag"><?= e(t('Focus keyword')) ?></span>
<?php else: ?>
			<form method="post" action="<?= e($module->url('seo_keyword_add')) ?>"><?= $csrf ?><input type="hidden" name="id" value="<?= e($page['public_id']) ?>"><input type="hidden" name="keyword" value="<?= e($q['query']) ?>"><button class="navigation" type="submit" aria-label="<?= e(t('Add “%s” as a focus keyword', $q['query'])) ?>"><?= e(t('Add as keyword')) ?></button></form>
<?php endif ?>
		</li>
<?php endforeach ?>
	</ul>
<?php endif ?>
	<p class="help"><?= e(t('From Google Search Console.')) ?></p>
<?php endif ?>
</section>
<?php endif ?>
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
