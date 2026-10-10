<?php
/**
 * News list: /news, category, tag, search results (and the site's front page when the site has no home page).
 *
 * @var string $heading
 * @var string $description  HTML intro above the list (category description, topic page)
 * @var list<array<string, mixed>> $news
 * @var int $total
 * @var int $page
 * @var int $pagesCount
 * @var callable(int): string $pageUrl
 * @var string|null $searched  the searched text; null = not a search
 * @var list<array{title:string, slug:string, snippet?:string}> $foundPages  pages and collection items matching the search
 * @var callable(string): string $url
 */
?>
<header class="listing-header">
	<h1><?= e($heading) ?></h1>
<?php if ($description !== ''): ?>
	<div class="lead"><?= $description ?></div>
<?php endif ?>
<?php if ($searched !== null): ?>
	<form class="search" method="get" action="<?= e($url('search')) ?>" role="search">
		<input type="search" name="q" value="<?= e($searched) ?>" minlength="3" maxlength="100" aria-label="<?= e(t('Search text')) ?>" required>
		<button type="submit"><?= e(t('Search')) ?></button>
	</form>
<?php if ($searched !== ''): ?>
	<p><?= mb_strlen($searched) < 3 ? e(t('Enter at least 3 characters.')) : e(t('Found: %s', $total + count($foundPages))) ?></p>
<?php endif ?>
<?php endif ?>
</header>

<?php if ($foundPages !== []): ?>
<ul class="listing-pages">
<?php foreach ($foundPages as $s): ?>
	<li><a href="<?= e($url($s['slug'])) ?>"><?= e($s['title']) ?></a><?php if (($s['snippet'] ?? '') !== ''): ?><p><?= e($s['snippet']) ?></p><?php endif ?></li>
<?php endforeach ?>
</ul>
<?php endif ?>

<?php if ($news === [] && $searched === null): ?>
<p><?= e(t('There are no news yet.')) ?></p>
<?php endif ?>
<?php if ($news !== []): ?>
<div class="news-grid">
<?php foreach ($news as $n): $address = $url('news/' . $n['slug']); ?>
	<article class="news-card">
<?php if ($n['image'] !== ''): ?>
		<a class="news-card-image" href="<?= e($address) ?>" tabindex="-1" aria-hidden="true"><img src="<?= e($n['image']) ?>"<?= ($n['obrazek_srcset'] ?? '') !== '' ? ' srcset="' . e($n['obrazek_srcset']) . '" sizes="auto, (max-width: 700px) 100vw, 400px"' : '' ?> alt="" loading="lazy"></a>
<?php endif ?>
		<p class="news-info"><time datetime="<?= e(date('c', strtotime($n['published_at']))) ?>"><?= e(format_date($n['published_at'])) ?></time> · <a href="<?= e($url('news/category/' . $n['category_slug'])) ?>"><?= e($n['category_name']) ?></a></p>
		<h2><a href="<?= e($address) ?>"><?= e($n['title']) ?></a></h2>
		<div class="lead"><?= $n['intro'] ?></div>
	</article>
<?php endforeach ?>
</div>
<?php endif ?>

<?php if ($pagesCount > 1): ?>
<nav class="pagination" aria-label="<?= e(t('Pagination')) ?>">
<?php if ($page > 1): ?>
	<a href="<?= e($pageUrl($page - 1)) ?>" rel="prev">&laquo; <?= e(t('newer')) ?></a>
<?php endif ?>
	<span aria-current="page"><?= e(t('page %s of %s', $page, $pagesCount)) ?></span>
<?php if ($page < $pagesCount): ?>
	<a href="<?= e($pageUrl($page + 1)) ?>" rel="next"><?= e(t('older')) ?> &raquo;</a>
<?php endif ?>
</nav>
<?php endif ?>
