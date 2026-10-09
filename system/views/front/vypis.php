<?php
/**
 * News list: /novinky, category, tag, search results (and the site's front page when the site has no home page).
 *
 * @var string $nadpis
 * @var string $popis  HTML intro above the list (category description, topic page)
 * @var list<array<string, mixed>> $novinky
 * @var int $celkem
 * @var int $strana
 * @var int $stran
 * @var callable(int): string $strankaUrl
 * @var string|null $hledano  the searched text; null = not a search
 * @var list<array{titulek:string, seo_link:string, uryvek?:string}> $nalezeneStranky  pages and collection items matching the search
 * @var callable(string): string $url
 */
?>
<header class="vypis-hlavicka">
	<h1><?= e($nadpis) ?></h1>
<?php if ($popis !== ''): ?>
	<div class="perex"><?= $popis ?></div>
<?php endif ?>
<?php if ($hledano !== null): ?>
	<form class="hledani" method="get" action="<?= e($url('hledani')) ?>" role="search">
		<input type="search" name="q" value="<?= e($hledano) ?>" minlength="3" maxlength="100" aria-label="<?= e(t('Search text')) ?>" required>
		<button type="submit"><?= e(t('Hledat')) ?></button>
	</form>
<?php if ($hledano !== ''): ?>
	<p><?= mb_strlen($hledano) < 3 ? e(t('Enter at least 3 characters.')) : e(t('Found: %s', $celkem + count($nalezeneStranky))) ?></p>
<?php endif ?>
<?php endif ?>
</header>

<?php if ($nalezeneStranky !== []): ?>
<ul class="vypis-stranky">
<?php foreach ($nalezeneStranky as $s): ?>
	<li><a href="<?= e($url($s['slug'])) ?>"><?= e($s['title']) ?></a><?php if (($s['uryvek'] ?? '') !== ''): ?><p><?= e($s['uryvek']) ?></p><?php endif ?></li>
<?php endforeach ?>
</ul>
<?php endif ?>

<?php if ($novinky === [] && $hledano === null): ?>
<p><?= e(t('There are no news yet.')) ?></p>
<?php endif ?>
<?php if ($novinky !== []): ?>
<div class="novinky-mrizka">
<?php foreach ($novinky as $n): $adresa = $url('novinky/' . $n['slug']); ?>
	<article class="novinka-karta">
<?php if ($n['image'] !== ''): ?>
		<a class="novinka-karta-obrazek" href="<?= e($adresa) ?>" tabindex="-1" aria-hidden="true"><img src="<?= e($n['image']) ?>"<?= ($n['obrazek_srcset'] ?? '') !== '' ? ' srcset="' . e($n['obrazek_srcset']) . '" sizes="auto, (max-width: 700px) 100vw, 400px"' : '' ?> alt="" loading="lazy"></a>
<?php endif ?>
		<p class="novinka-info"><time datetime="<?= e(date('c', strtotime($n['datum']))) ?>"><?= e(format_date($n['datum'])) ?></time> · <a href="<?= e($url('novinky/kategorie/' . $n['tema_seo'])) ?>"><?= e($n['tema_jm']) ?></a></p>
		<h2><a href="<?= e($adresa) ?>"><?= e($n['title']) ?></a></h2>
		<div class="perex"><?= $n['intro'] ?></div>
	</article>
<?php endforeach ?>
</div>
<?php endif ?>

<?php if ($stran > 1): ?>
<nav class="strankovani" aria-label="<?= e(t('Pagination')) ?>">
<?php if ($strana > 1): ?>
	<a href="<?= e($strankaUrl($strana - 1)) ?>" rel="prev">&laquo; <?= e(t('newer')) ?></a>
<?php endif ?>
	<span aria-current="page"><?= e(t('page %s of %s', $strana, $stran)) ?></span>
<?php if ($strana < $stran): ?>
	<a href="<?= e($strankaUrl($strana + 1)) ?>" rel="next"><?= e(t('older')) ?> &raquo;</a>
<?php endif ?>
</nav>
<?php endif ?>
