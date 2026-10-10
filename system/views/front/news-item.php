<?php
/**
 * The full news item. The page frame is the system's own, it cannot be overridden.
 *
 * @var array<string, mixed> $newsItem  columns of ka_news + category_name, category_slug, author_name, stitky (nazev, seo_link),
 *                                     image_srcset, image_alt, image_caption_html, faq_html - ready-made HTML, just output it
 * @var callable(string): string $url
 * @var list<array<string, mixed>> $souvisejici
 */
?>
<article class="news">
	<header>
		<p class="news-info">
			<time datetime="<?= e(date('c', strtotime($newsItem['published_at']))) ?>"><?= e(format_date($newsItem['published_at'])) ?></time>
			· <a href="<?= e($url('news/category/' . $newsItem['category_slug'])) ?>"><?= e($newsItem['category_name']) ?></a>
<?php if ($newsItem['author_name'] !== null): ?>
			· <?= e($newsItem['author_name']) ?>
<?php endif ?>
		</p>
		<h1><?= e($newsItem['title']) ?></h1>
	</header>
<?php if ($newsItem['image'] !== ''): ?>
	<figure class="news-image"><img src="<?= e($newsItem['image']) ?>"<?= ($newsItem['image_srcset'] ?? '') !== '' ? ' srcset="' . e($newsItem['image_srcset']) . '" sizes="(max-width: 900px) 100vw, 900px"' : '' ?> alt="<?= e($newsItem['image_alt'] ?? '') ?>" fetchpriority="high"><?= $newsItem['image_caption_html'] ?? '' ?></figure>
<?php endif ?>
	<div class="lead"><?= $newsItem['intro'] ?></div>
<?php if (!empty($newsItem['updated_at'])): ?>
	<p class="news-updated"><?= e(t('Updated')) ?> <?= e(format_date($newsItem['updated_at'], true)) ?></p>
<?php endif ?>
	<div class="text"><?= $newsItem['text'] ?></div>
	<?= $newsItem['faq_html'] ?? '' ?>
<?php if (!empty($newsItem['tags'])): ?>
	<p class="news-tags"><?php foreach ($newsItem['tags'] as $st): ?><a href="<?= e($url('news/tag/' . $st['slug'])) ?>" rel="tag">#<?= e($st['name']) ?></a> <?php endforeach ?></p>
<?php endif ?>
<?php if ($souvisejici !== []): ?>
	<aside class="related">
		<h2><?= e(t('More news')) ?></h2>
		<ul>
<?php foreach ($souvisejici as $s): ?>
			<li><a href="<?= e($url('news/' . $s['slug'])) ?>"><?= e($s['title']) ?></a> <small><?= e(format_date($s['published_at'])) ?></small></li>
<?php endforeach ?>
		</ul>
	</aside>
<?php endif ?>
</article>
