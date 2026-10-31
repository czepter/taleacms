<?php
/**
 * The confirmed participants of a course as a standalone printable page (like the monthly report): Present is left empty for a pen.
 * Inline CSS only, the Print button is wired by image/print.js. Not part of the admin layout.
 *
 * @var string $title
 * @var array{course: string, dates: string, schedule: string, place: string} $meta
 * @var list<array{name: string, email: string, phone: string, seats: int}> $people
 * @var bool $phones  some confirmed registration has a phone number
 * @var string $printed  when the list was printed
 * @var string $script  image/print.js
 */
$meta = array_filter([$meta['schedule'], $meta['dates'], $meta['place']], fn (string $v): bool => $v !== '');
$count = array_sum(array_column($people, 'seats'));
?>
<!DOCTYPE html>
<html lang="<?= e(Talea\Core\Language::tag(Talea\Core\Language::code())) ?>"<?= Talea\Core\Language::dirAttribute(Talea\Core\Language::tag(Talea\Core\Language::code())) ?>>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<meta name="color-scheme" content="light">
<title><?= e($title) ?></title>
<style>
@page { size: A4; margin: 16mm 15mm; }
body { margin: 0; background: #fff; color: #000; font: 13px/1.45 system-ui, -apple-system, "Segoe UI", sans-serif; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
.sheet { padding: 40px 48px; max-width: 880px; }
h1 { font-size: 22px; margin: 0 0 4px; }
.meta { margin: 0; color: #333; }
table { width: 100%; border-collapse: collapse; margin-top: 20px; }
th, td { border-bottom: 1px solid #999; padding: 7px 8px; text-align: start; overflow-wrap: anywhere; }
th { border-bottom: 2px solid #000; font-weight: 600; }
thead { display: table-header-group; }
tr { break-inside: avoid; }
td.tick { width: 60px; border-inline-start: 1px solid #ccc; }
.bar { padding: .75rem 1rem; border-bottom: 1px solid #ccc; }
.bar button { padding: .5rem 1.25rem; border: 0; border-radius: .4rem; background: #121212; color: #fff; font: inherit; font-weight: 600; cursor: pointer; }
@media print { .bar { display: none; } .sheet { padding: 0; max-width: none; } }
</style>
</head>
<body>
<div class="bar"><button type="button" data-print><?= e(t('Print or save as PDF')) ?></button></div>
<main class="sheet">
<h1><?= e($title) ?></h1>
<?php if ($meta !== []): ?><p class="meta"><?= e(implode(' · ', $meta)) ?></p><?php endif ?>
<p class="meta" style="margin-top: 4px"><?= e(t('Confirmed participants: %d registrations, %d people · printed %s', count($people), $count, $printed)) ?></p>
<table>
<thead><tr><th>#</th><th><?= e(t('Name')) ?></th><th><?= e(t('Email')) ?></th><?php if ($phones): ?><th><?= e(t('Phone')) ?></th><?php endif ?><th><?= e(t('People')) ?></th><th><?= e(t('Present')) ?></th></tr></thead>
<tbody>
<?php foreach ($people as $i => $p): ?>
<tr><td><?= $i + 1 ?></td><td><?= e($p['name']) ?></td><td><?= e($p['email']) ?></td><?php if ($phones): ?><td><?= e($p['phone']) ?></td><?php endif ?><td><?= (int) $p['seats'] ?></td><td class="tick"></td></tr>
<?php endforeach ?>
</tbody>
</table>
</main>
<script src="<?= e($script) ?>" defer></script>
</body>
</html>
