<?php
/**
 * 404 page: search and the main pages of the site, so the visitor does not leave empty-handed.
 *
 * @var callable(string): string $url
 * @var list<array{title:string, slug:string}> $stranky
 */
?>
<header class="listing-header"><h1><?= e(t('Page not found')) ?></h1></header>
<p><?= e(t('The page you are looking for is not here. It may have a different address.')) ?></p>
<form class="search" method="get" action="<?= e($url('search')) ?>" role="search">
	<input type="search" name="q" placeholder="<?= e(t('Search text')) ?>" aria-label="<?= e(t('Search text')) ?>" minlength="3" required>
	<button type="submit"><?= e(t('Search')) ?></button>
</form>
<?php if ($pages !== []): ?>
<ul>
<?php foreach ($pages as $s): ?>
	<li><a href="<?= e($url($s['slug'])) ?>"><?= e($s['title']) ?></a></li>
<?php endforeach ?>
<?php if ($news ?? true): ?>
	<li><a href="<?= e($url('news')) ?>"><?= e(t('News')) ?></a></li>
<?php endif ?>
</ul>
<?php endif ?>
