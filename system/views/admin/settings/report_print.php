<?php
/**
 * The printable monthly report (#161, Core\ReportPage): a standalone A4 document the browser prints or saves as PDF, and the same
 * file to download. Inline CSS only, black on white; the agency's logo is the only image. The page numbers and the site and month
 * at the foot of every page come from the @page margin boxes; a table row, a short section and a heading with what follows it
 * never split across pages. Not part of the admin layout; texts in the site's default language.
 *
 * @var string $language
 * @var string $title
 * @var string $monthName
 * @var string $siteName
 * @var string $siteAddress the site address without the scheme
 * @var string $agency the agency's name, or empty
 * @var string $agencyLogo absolute URL of the agency logo, or empty
 * @var list<string> $agencyContacts the agency's web, e-mail and phone
 * @var string $generated when the report was made
 * @var list<array{key: string, title: string, blocks: list<array<string, mixed>>}> $sections
 * @var string $script image/print.js for the Print button; empty in the downloaded file
 */
// a text inside a CSS string of the page margin boxes: no quote, backslash, control character or "</style>" can get out of it
$cssString = static fn (string $text): string => '"' . preg_replace_callback('/["\\\\<\x00-\x1F\x7F]/', static fn (array $m): string => '\\' . dechex(ord($m[0])) . ' ', $text) . '"';
?>
<!DOCTYPE html>
<html lang="<?= e(Talea\Core\Language::tag($language)) ?>"<?= Talea\Core\Language::dirAttribute(Talea\Core\Language::tag($language)) ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="color-scheme" content="light">
<title><?= e($title) ?></title>
<style>
@page {
	size: A4 portrait;
	margin: 16mm 15mm 18mm;
	@bottom-left { content: <?= $cssString($siteName . ' · ' . $monthName) ?>; font: 8pt system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif; color: #666; }
	@bottom-right { content: counter(page) " / " counter(pages); font: 8pt system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif; color: #666; }
}
* { box-sizing: border-box; }
html, body { margin: 0; padding: 0; }
body {
	background: #e9e9e9;
	color: #121212;
	font: 10pt/1.45 system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif;
	-webkit-print-color-adjust: exact;
	print-color-adjust: exact;
}
.sheet { width: 210mm; max-width: 100%; margin: 10mm auto; padding: 16mm 15mm 14mm; background: #fff; box-shadow: 0 2px 24px rgba(0, 0, 0, .18); }
.head { display: flex; justify-content: space-between; align-items: flex-end; gap: 8mm; padding-bottom: 5mm; border-bottom: .6mm solid #121212; }
.brand img { display: block; max-height: 14mm; max-width: 60mm; width: auto; height: auto; margin-bottom: 2.5mm; }
.brand .name { font-size: 12pt; font-weight: 600; }
.brand .contacts { color: #555; font-size: 8.5pt; }
.title { text-align: end; }
.title .kind { margin: 0; color: #555; font-size: 8.5pt; font-weight: 600; letter-spacing: .08em; text-transform: uppercase; }
.title h1 { margin: 1mm 0 0; font-size: 20pt; line-height: 1.1; letter-spacing: -.01em; }
.title .site { margin: 1.5mm 0 0; font-size: 10.5pt; }
.title .site span { color: #555; }
.made { margin: 3mm 0 0; color: #555; font-size: 8.5pt; }
.part { margin-top: 7mm; break-inside: avoid-page; }
.part.long { break-inside: auto; }
h2 { margin: 0 0 2.5mm; padding-top: 2mm; border-top: .3mm solid #ccc; font-size: 12.5pt; break-after: avoid-page; }
h3 { margin: 4mm 0 1.5mm; font-size: 10pt; break-after: avoid-page; }
table { width: 100%; border-collapse: collapse; }
thead { display: table-header-group; }
tr { break-inside: avoid-page; }
th, td { padding: 1.3mm 3mm 1.3mm 0; border-bottom: .25mm solid #e2e2e2; text-align: start; vertical-align: top; overflow-wrap: break-word; }
th { color: #555; font-size: 8.5pt; font-weight: 600; border-bottom-color: #bbb; }
td.number, th.number { text-align: end; padding-inline-end: 0; white-space: nowrap; font-variant-numeric: tabular-nums; }
table.pairs td:last-child { text-align: end; padding-inline-end: 0; white-space: nowrap; font-variant-numeric: tabular-nums; }
table.pairs td.nested { padding-inline-start: 5mm; color: #444; }
table.data { font-size: 9pt; }
.facts { display: grid; grid-template-columns: repeat(4, 1fr); gap: 2.5mm; margin: 0; }
.facts div { margin: 0; padding: 2.5mm 3mm; border: .3mm solid #ddd; border-radius: 1.5mm; break-inside: avoid; }
.facts dt { color: #555; font-size: 8pt; }
.facts dd { margin: .5mm 0 0; font-size: 15pt; font-weight: 700; font-variant-numeric: tabular-nums; }
.note { margin: 2mm 0 0; color: #555; font-size: 8.5pt; }
ul.items { margin: 0; padding-inline-start: 5mm; }
ul.items li { margin: 0 0 1mm; break-inside: avoid; }
.foot { margin-top: 9mm; padding-top: 3mm; border-top: .3mm solid #ccc; color: #555; font-size: 8pt; break-inside: avoid; }
.bar { position: sticky; top: 0; z-index: 1; display: flex; justify-content: center; gap: 1rem; align-items: center; padding: .75rem 1rem; background: #fff; border-bottom: 1px solid #ccc; font-size: 1rem; }
.bar button { padding: .5rem 1.25rem; border: 0; border-radius: .4rem; background: #121212; color: #fff; font: inherit; font-weight: 600; cursor: pointer; }
@media (prefers-color-scheme: dark) { body { background: #2a2a2a; } .bar { background: #1b1b1b; color: #eee; border-color: #444; } .bar button { background: #f6f4ee; color: #121212; } }
@media screen and (max-width: 220mm) { .sheet { margin: 0; padding: 8mm 5mm; box-shadow: none; } .head { flex-direction: column; align-items: flex-start; } .title { text-align: start; } .facts { grid-template-columns: repeat(2, 1fr); } }
@media print {
	body { background: #fff; }
	.bar { display: none; }
	.sheet { width: auto; max-width: none; margin: 0; padding: 0; box-shadow: none; }
}
</style>
</head>
<body>
<?php if ($script !== ''): ?>
<div class="bar"><button type="button" data-print><?= e(t('Print or save as PDF')) ?></button></div>
<?php endif ?>
<main class="sheet">
<header class="head">
<div class="brand">
<?php if ($agencyLogo !== ''): ?><img src="<?= e($agencyLogo) ?>" alt="<?= e($agency) ?>"><?php endif ?>
<div class="name"><?= e($agency !== '' ? $agency : $siteName) ?></div>
<?php if ($agencyContacts !== []): ?><div class="contacts"><?= e(implode(' · ', $agencyContacts)) ?></div><?php endif ?>
</div>
<div class="title">
<p class="kind"><?= e(t('Website report')) ?></p>
<h1><?= e($monthName) ?></h1>
<p class="site"><?= e($siteName) ?> <span>· <?= e($siteAddress) ?></span></p>
</div>
</header>
<p class="made"><?= e(t('Made %s', $generated)) ?></p>
<?php foreach ($sections as $section):
    $long = array_filter($section['blocks'], static fn (array $b): bool => $b['type'] === 'table' && count($b['rows']) > 8) !== []; ?>
<section class="part<?= $long ? ' long' : '' ?>" id="<?= e($section['key']) ?>">
<h2><?= e($section['title']) ?></h2>
<?php foreach ($section['blocks'] as $block): ?>
<?php if ($block['type'] === 'facts'): ?>
<dl class="facts"><?php foreach ($block['items'] as [$label, $value]): ?><div><dt><?= e($label) ?></dt><dd><?= e($value) ?></dd></div><?php endforeach ?></dl>
<?php elseif ($block['type'] === 'pairs'): ?>
<table class="pairs"><tbody>
<?php foreach ($block['rows'] as $row): ?><tr><td<?= !empty($row[2]) ? ' class="nested"' : '' ?>><?= e($row[0]) ?></td><td><?= e($row[1]) ?></td></tr>
<?php endforeach ?></tbody></table>
<?php elseif ($block['type'] === 'table'): ?>
<h3><?= e($block['caption']) ?></h3>
<table class="data"><thead><tr><?php foreach ($block['head'] as $i => $heading): ?><th scope="col"<?= in_array($i, $block['numeric'], true) ? ' class="number"' : '' ?>><?= e($heading) ?></th><?php endforeach ?></tr></thead><tbody>
<?php foreach ($block['rows'] as $row): ?><tr><?php foreach ($row as $i => $cell): ?><td<?= in_array($i, $block['numeric'], true) ? ' class="number"' : '' ?>><?= e((string) $cell) ?></td><?php endforeach ?></tr>
<?php endforeach ?></tbody></table>
<?php elseif ($block['type'] === 'list'): ?>
<ul class="items"><?php foreach ($block['items'] as $item): ?><li><?= e($item) ?></li><?php endforeach ?></ul>
<?php else: ?>
<p class="note"><?= e($block['text']) ?></p>
<?php endif ?>
<?php endforeach ?>
</section>
<?php endforeach ?>
<footer class="foot"><?= e(t('A summary of what happened to the website %s in %s: counts, titles and page addresses only – no personal data.', $siteName, $monthName)) ?></footer>
</main>
<?php if ($script !== ''): ?><script src="<?= e($script) ?>" defer></script><?php endif ?>
</body>
</html>
