<?php
/**
 * The full news item. A layout can override it with its own file novinka.php.
 *
 * @var array<string, mixed> $novinka  columns of ka_novinky + tema_jm, tema_seo, autor_jm, stitky (nazev, seo_link),
 *                                     obrazek_srcset, obrazek_alt, obrazek_popisek_html, faq_html - ready-made HTML, just output it
 * @var callable(string): string $url
 * @var list<array<string, mixed>> $souvisejici
 */
?>
<article class="novinka">
	<header>
		<p class="novinka-info">
			<time datetime="<?= e(date('c', strtotime($novinka['datum']))) ?>"><?= e(format_date($novinka['datum'])) ?></time>
			· <a href="<?= e($url('novinky/kategorie/' . $novinka['tema_seo'])) ?>"><?= e($novinka['tema_jm']) ?></a>
<?php if ($novinka['autor_jm'] !== null): ?>
			· <?= e($novinka['autor_jm']) ?>
<?php endif ?>
		</p>
		<h1><?= e($novinka['title']) ?></h1>
	</header>
<?php if ($novinka['image'] !== ''): ?>
	<figure class="novinka-obrazek"><img src="<?= e($novinka['image']) ?>"<?= ($novinka['obrazek_srcset'] ?? '') !== '' ? ' srcset="' . e($novinka['obrazek_srcset']) . '" sizes="(max-width: 900px) 100vw, 900px"' : '' ?> alt="<?= e($novinka['obrazek_alt'] ?? '') ?>" fetchpriority="high"><?= $novinka['obrazek_popisek_html'] ?? '' ?></figure>
<?php endif ?>
	<div class="perex"><?= $novinka['intro'] ?></div>
<?php if (!empty($novinka['updated_at'])): ?>
	<p class="novinka-aktualizovano"><?= e(t('Updated')) ?> <?= e(format_date($novinka['updated_at'], true)) ?></p>
<?php endif ?>
	<div class="text"><?= $novinka['text'] ?></div>
	<?= $novinka['faq_html'] ?? '' ?>
<?php if (!empty($novinka['stitky'])): ?>
	<p class="novinka-stitky"><?php foreach ($novinka['stitky'] as $st): ?><a href="<?= e($url('novinky/stitek/' . $st['slug'])) ?>" rel="tag">#<?= e($st['nazev']) ?></a> <?php endforeach ?></p>
<?php endif ?>
<?php if ($souvisejici !== []): ?>
	<aside class="souvisejici">
		<h2><?= e(t('More news')) ?></h2>
		<ul>
<?php foreach ($souvisejici as $s): ?>
			<li><a href="<?= e($url('novinky/' . $s['slug'])) ?>"><?= e($s['title']) ?></a> <small><?= e(format_date($s['datum'])) ?></small></li>
<?php endforeach ?>
		</ul>
	</aside>
<?php endif ?>
</article>
