<?php
/**
 * The search result preview of the SEO tab: the address, the title as the site composes it (Core\SeoTitle::compose) and the
 * description. The live check (pages/seo_check) returns it after the checks; admin.js replaces [data-seo-serp] with it.
 *
 * @var string $serpTitle
 * @var string $serpDescription '' = search engines take a passage of the text
 * @var string $serpAddress host and path of the page
 * @var string $dir the dir attribute of the page's language
 */
?>
<div class="seo-serp" data-seo-serp>
	<small><?= e($serpAddress) ?></small>
	<strong<?= $dir ?>><?= e($serpTitle) ?></strong>
<?php if ($serpDescription !== ''): ?>
	<p<?= $dir ?>><?= e($serpDescription) ?></p>
<?php else: ?>
	<p class="help"><?= e(t('No description – search engines show a passage of the text.')) ?></p>
<?php endif ?>
</div>
