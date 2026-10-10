<?php
/**
 * Import and export: step 1 of the WordPress import (file) and export of the whole site.
 *
 * @var Talea\Admin\Modules\Transfer $module
 * @var Talea\Core\App $app
 * @var string $csrf
 * @var list<array{file:string, size:int, time:int, state:array<string,mixed>|null}> $files  WordPress exports in storage/import/
 * @var int $uploadLimit  how many bytes the server allows to upload through a form
 * @var bool $missingXml  the server lacks the extension for reading XML
 * @var list<array{file:string, size:int, time:int}> $exports
 * @var bool $hasZip
 * @var list<array{file:string, size:int, time:int, state:array<string,mixed>|null}> $taleaFiles  Talea exports in storage/import/
 * @var array{empty: bool, pages: int, news: int, items: int, media: int} $siteContent
 * @var list<array<string, mixed>> $webImports  imports from a website (2.6)
 * @var bool $canDownload  the server can download from other sites and has GD
 * @var list<string> $languages  additional language versions of the site
 * @var list<array<string, mixed>> $reports  migration parity reports (2.7)
 * @var array<string, class-string<Talea\Import\Source>> $sources  structured importers of other systems (3.0)
 * @var array<string, class-string<Talea\Import\Source&Talea\Import\Remote>> $remoteSources  those fetched from the site's API (Joomla, Drupal)
 * @var bool $canFetch  the server has curl, so it can read a site's API
 * @var list<array{file:string, source:string, size:int, time:int, state:array<string,mixed>|null}> $sourceFiles  their exports in storage/import/sources/
 */
$phase = [
    'download' => 'being fetched from the site', 'analysis' => 'being read', 'preview' => 'ready to import', 'import' => 'import in progress', 'done' => 'content imported',
    'images' => 'downloading images', 'images_done' => 'imported including images',
];
?>
<?php /* 3.2: one panel per task, folded until it is opened or has work in progress – the screen no longer stacks seven forms */ ?>
<details class="panel-collapsed"<?= $webImports !== [] ? ' open' : '' ?>>
<summary><h2><?= e(t('Import from a website')) ?></h2></summary>
<p><?= e(t('Enter the address of a site on any platform – Wix, Webnode, Jimdo, Squarespace, Joomla, Drupal or WordPress without an export. Its pages become builder pages with their images, and the old addresses redirect to the new ones. The pages stay hidden until you check and publish them; the design is not copied – the pages take this site’s look.')) ?></p>
<?php if (!$canDownload): ?>
<p class="notice notice-error"><?= e(t('This server cannot download from other sites (both curl and allow_url_fopen are missing, or the GD extension).')) ?></p>
<?php else: ?>
<form class="form" method="post" action="<?= e($module->url('web_start')) ?>">
<?= $csrf ?>
<div class="row"><label for="url"><?= e(t('Address of the site')) ?></label><div><input class="textfield wide" type="url" id="url" name="url" placeholder="https://www.example.com" required maxlength="300">
	<span class="help"><?= e(t('Talea reads the sitemap, or follows the site’s links when there is none – at most %s pages.', Talea\Core\WebImport::MAX_PAGES)) ?></span></div></div>
<?php if ($languages !== []): ?>
<div class="row"><label for="site_language"><?= e(t('Language version')) ?></label><div><select id="site_language" name="language"><option value=""><?= e(t('the main language')) ?></option>
<?php foreach ($languages as $code): ?><option value="<?= e($code) ?>"><?= e(Talea\Core\Language::AVAILABLE[$code][0] ?? $code) ?></option><?php endforeach ?></select></div></div>
<?php endif ?>
<div class="row"><span></span><div>
	<label><input type="checkbox" name="images" value="1" checked> <?= e(t('Download the images into Media')) ?></label><br>
	<label><input type="checkbox" name="redirects" value="1" checked> <?= e(t('Redirect the old addresses to the new pages')) ?></label><br>
	<label><input type="checkbox" name="news" value="1" checked> <?= e(t('Import blog posts as news (addresses like /blog/…, or with a publication date)')) ?></label>
</div></div>
<p class="buttons"><input class="btn" type="submit" value="<?= e(t('Find the pages')) ?>"></p>
</form>
<?php endif ?>
<?php if ($webImports !== []): ?>
<ul class="list-import">
<?php foreach ($webImports as $w): ?>
	<li><a href="<?= e($module->url('web_progress', ['id' => $w['id']])) ?>"><?= e($w['web']) ?></a> – <?= e(t(['finding' => 'finding pages', 'preview' => 'ready to import', 'import' => 'import in progress', 'done' => 'content imported'][$w['phase']] ?? '–')) ?>
		<form class="inline" method="post" action="<?= e($module->url('web_delete')) ?>"><?= $csrf ?><input type="hidden" name="id" value="<?= e($w['id']) ?>"><button class="navigation" type="submit"><?= e(t('Remove from the list')) ?></button></form></li>
<?php endforeach ?>
</ul>
<?php endif ?>
</details>

<details class="panel-collapsed"<?= $reports !== [] ? ' open' : '' ?>>
<summary><h2><?= e(t('Check the move before going live')) ?></h2></summary>
<p><?= e(t('Before you point the domain to this site, check the old site against it: every old address must lead somewhere, and no page may lose its search engine description, its form or most of its images. Nothing is changed – the check only reads.')) ?></p>
<?php if ($canDownload): ?>
<form class="form" method="post" action="<?= e($module->url('report_start')) ?>">
<?= $csrf ?>
<div class="row"><label for="old_site"><?= e(t('Address of the old site')) ?></label><div><input class="textfield wide" type="url" id="old_site" name="url" placeholder="https://www.example.com" required maxlength="300"></div></div>
<p class="buttons"><input class="btn" type="submit" value="<?= e(t('Check the move')) ?>"></p>
</form>
<?php endif ?>
<?php if ($reports !== []): ?>
<ul class="list-import">
<?php foreach ($reports as $r): ?>
	<li><a href="<?= e($module->url('report', ['id' => $r['id']])) ?>"><?= e($r['web']) ?></a> – <?= e($r['phase'] === 'done' ? t('checked %s', substr((string) ($r['completed'] ?? $r['created']), 0, 16)) : t('check in progress')) ?>
		<form class="inline" method="post" action="<?= e($module->url('report_delete')) ?>"><?= $csrf ?><input type="hidden" name="id" value="<?= e($r['id']) ?>"><button class="navigation" type="submit"><?= e(t('Remove from the list')) ?></button></form></li>
<?php endforeach ?>
</ul>
<?php endif ?>
</details>

<details class="panel-collapsed"<?= $files !== [] ? ' open' : '' ?>>
<summary><h2><?= e(t('Import from WordPress')) ?></h2></summary>
<?= $app->view->render('admin/transfer/steps', ['step' => 1]) ?>
<p><?= e(t('In WordPress, open Tools → Export, choose “All content” and download the .xml file. Then upload it here. Pages, posts (as news), categories and tags are converted and redirects from the old addresses are created; nothing changes on the site until you confirm the import in the next step.')) ?></p>
<?php if ($missingXml): ?>
<p class="notice notice-error"><?= e(t('The PHP extension xmlreader or dom is missing on the server – a WordPress export cannot be read without them.')) ?></p>
<?php else: ?>
<form class="form" method="post" enctype="multipart/form-data" action="<?= e($module->url('upload')) ?>">
<?= $csrf ?>
<div class="row"><label for="file"><?= e(t('WordPress export')) ?></label><div><input type="file" id="file" name="file" accept=".xml,text/xml,application/xml" required>
	<span class="help"><?= e(t('The server allows uploads of at most %s. Copy a larger file over FTP into the storage/import/ folder – it will appear in the list below.', Talea\Core\Files::size($uploadLimit))) ?></span></div></div>
<p class="buttons"><input class="btn" type="submit" value="<?= e(t('Upload and show preview')) ?>"></p>
</form>
<?php endif ?>

<?php if ($files !== []): ?>
<div class="tab-wrap">
<table class="listing">
<thead><tr><th scope="col"><?= e(t('File')) ?></th><th scope="col"><?= e(t('Size')) ?></th><th scope="col"><?= e(t('Uploaded')) ?></th><th scope="col"><?= e(t('Status')) ?></th><th scope="col"><?= e(t('Actions')) ?></th></tr></thead>
<tbody>
<?php foreach ($files as $s): $state = $s['status']; ?>
<tr>
	<td><?= e($s['file']) ?></td>
	<td class="number"><?= e(Talea\Core\Files::size($s['size'])) ?></td>
	<td class="number"><?= e(format_date(date('Y-m-d H:i:s', $s['time']), true)) ?></td>
	<td><?= $state === null ? '–' : e(t($phase[$state['phase']] ?? '–')) . ($state['phase'] === 'import' ? ' (' . (int) $state['position'] . ' / ' . (int) $state['total'] . ')' : '') ?></td>
	<td class="actions">
<?php if ($state !== null): ?>
		<a href="<?= e($module->url($state['phase'] === 'preview' ? 'preview' : 'progress', ['file' => $s['file']])) ?>"><?= e(t(in_array($state['phase'], ['done', 'images_done'], true) ? 'Result' : 'Continue')) ?></a>
<?php endif ?>
<?php if (!$missingXml): ?>
		<form class="inline" method="post" action="<?= e($module->url('select')) ?>"><?= $csrf ?><input type="hidden" name="file" value="<?= e($s['file']) ?>"><button class="navigation" type="submit"><?= e(t($state === null ? 'Show preview' : 'Read again')) ?></button></form>
<?php endif ?>
		<form class="inline" method="post" action="<?= e($module->url('delete_file')) ?>" data-confirm="<?= e(t('Delete the file %s? Content that has already been imported stays on the site.', $s['file'])) ?>"><?= $csrf ?><input type="hidden" name="file" value="<?= e($s['file']) ?>"><button class="navigation danger" type="submit"><?= e(t('Delete')) ?></button></form>
	</td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<p class="small-text"><?= e(t('You can import the same file repeatedly – whatever has already been imported is skipped. Delete the file when the import is finished; it contains e-mail addresses of authors and commenters from the old site.')) ?></p>
<?php endif ?>
</details>

<details class="panel-collapsed"<?= $sourceFiles !== [] ? ' open' : '' ?>>
<summary><h2><?= e(t('From another system')) ?></h2></summary>
<p><?= e(t('Moving from a system with its own export: posts become news items, pages become pages, categories and tags come along, old addresses redirect to the new ones. In the next step you see what the file contains and choose what becomes what; nothing changes on the site until you confirm.')) ?></p>
<form class="form" method="post" enctype="multipart/form-data" action="<?= e($module->url('source_upload')) ?>">
<?= $csrf ?>
<div class="row"><label for="system"><?= e(t('System')) ?></label><div><select id="system" name="system">
	<option value="wordpress">WordPress</option>
<?php foreach (array_diff_key($sources, $remoteSources) as $key => $class): ?>
	<option value="<?= e($key) ?>"><?= e($class::name()) ?></option>
<?php endforeach ?>
</select><span class="help"><?php foreach (array_diff_key($sources, $remoteSources) as $class): ?><?= e($class::name() . ': ' . t($class::hint())) ?> <?php endforeach ?></span></div></div>
<div class="row"><label for="file-system"><?= e(t('Export file')) ?></label><div><input type="file" id="file-system" name="file" accept=".xml,.json,.csv,text/xml,application/xml,application/json,text/csv" required>
	<span class="help"><?= e(t('The server allows uploads of at most %s. Copy a larger file over FTP into the storage/import/sources/ folder, named system-name.extension (for example ghost-blog.json) – it will appear in the list below.', Talea\Core\Files::size($uploadLimit))) ?></span></div></div>
<p class="buttons"><input class="btn" type="submit" value="<?= e(t('Upload and show preview')) ?>"></p>
</form>
<?php foreach ($remoteSources as $key => $class): ?>
<h3><?= e(t('From %s', $class::name())) ?></h3>
<p><?= e(t($class::hint())) ?></p>
<?php if (!$canFetch): ?>
<p class="notice notice-error"><?= e(t('The PHP extension curl is missing on the server – the site’s API cannot be read without it.')) ?></p>
<?php else: ?>
<form class="form" method="post" action="<?= e($module->url('source_fetch')) ?>" autocomplete="off">
<?= $csrf ?>
<input type="hidden" name="system" value="<?= e($key) ?>">
<div class="row"><label for="adresa-<?= e($key) ?>"><?= e(t('Site address')) ?></label><div><input class="textfield wide" type="url" id="adresa-<?= e($key) ?>" name="url" placeholder="https://www.example.com" maxlength="300" required></div></div>
<div class="row"><label for="token-<?= e($key) ?>"><?= e(t('API token')) ?></label><div><input class="textfield wide" type="password" id="token-<?= e($key) ?>" name="token" maxlength="500" autocomplete="off">
	<span class="help"><?= e(t($class::tokenHint())) ?> <?= e(t('The token is used only for this fetch and is not stored anywhere.')) ?></span></div></div>
<div class="row"><span class="caption"><?= e(t('What to fetch')) ?></span><div class="options">
<?php foreach ($class::steps() as $i => $label): ?>
	<label><input type="checkbox" name="steps[]" value="<?= e($i) ?>" checked<?= array_key_first($class::steps()) === $i ? ' disabled' : '' ?>> <?= e(t($label)) ?></label>
<?php endforeach ?>
</div></div>
<p class="buttons"><input class="btn" type="submit" value="<?= e(t('Fetch and show preview')) ?>"></p>
</form>
<?php endif ?>
<?php endforeach ?>
<?php if ($sourceFiles !== []): ?>
<div class="tab-wrap">
<table class="listing">
<thead><tr><th scope="col"><?= e(t('File')) ?></th><th scope="col"><?= e(t('System')) ?></th><th scope="col"><?= e(t('Size')) ?></th><th scope="col"><?= e(t('Uploaded')) ?></th><th scope="col"><?= e(t('Status')) ?></th><th scope="col"><?= e(t('Actions')) ?></th></tr></thead>
<tbody>
<?php foreach ($sourceFiles as $s): $state = $s['status']; ?>
<tr>
	<td><?= e($s['file']) ?></td>
	<td><?= e($sources[$s['source']]::name()) ?></td>
	<td class="number"><?= e(Talea\Core\Files::size($s['size'])) ?></td>
	<td class="number"><?= e(format_date(date('Y-m-d H:i:s', $s['time']), true)) ?></td>
	<td><?= $state === null ? '–' : e(t($phase[$state['phase']] ?? '–')) . ($state['phase'] === 'import' ? ' (' . (int) $state['position'] . ' / ' . (int) $state['total'] . ')' : '') ?></td>
	<td class="actions">
<?php if ($state !== null): ?>
		<a href="<?= e($module->url($state['phase'] === 'preview' ? 'source_preview' : 'source_progress', ['file' => $s['file']])) ?>"><?= e(t(in_array($state['phase'], ['done', 'images_done'], true) ? 'Result' : 'Continue')) ?></a>
<?php endif ?>
		<form class="inline" method="post" action="<?= e($module->url('source_select')) ?>"><?= $csrf ?><input type="hidden" name="file" value="<?= e($s['file']) ?>"><button class="navigation" type="submit"><?= e(t($state === null ? 'Show preview' : 'Read again')) ?></button></form>
		<form class="inline" method="post" action="<?= e($module->url('source_delete')) ?>" data-confirm="<?= e(t('Delete the file %s? Content that has already been imported stays on the site.', $s['file'])) ?>"><?= $csrf ?><input type="hidden" name="file" value="<?= e($s['file']) ?>"><button class="navigation danger" type="submit"><?= e(t('Delete')) ?></button></form>
	</td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<p class="small-text"><?= e(t('You can import the same file repeatedly – whatever has already been imported is skipped. Delete the file when the import is finished; it contains e-mail addresses of authors from the old site.')) ?></p>
<?php endif ?>
</details>

<details class="panel-collapsed"<?= $taleaFiles !== [] ? ' open' : '' ?>>
<summary><h2><?= e(t('Import from Talea')) ?></h2></summary>
<p><?= e(t('Moving a site from another Talea installation: upload its export (the .zip archive from Export of the whole site). Everything is imported – pages, news, collections, components, menus, the look and the media – into a new, empty site; user accounts and secrets are never part of an export.')) ?></p>
<?php if (!$siteContent['empty']): ?>
<p class="notice"><?= e(t('This site already has its own content, so an export cannot be imported here. Install Talea again and choose “Start from an export”.')) ?></p>
<?php else: ?>
<form class="form" method="post" enctype="multipart/form-data" action="<?= e($module->url('upload')) ?>">
<?= $csrf ?>
<div class="row"><label for="file-talea"><?= e(t('Talea export')) ?></label><div><input type="file" id="file-talea" name="file" accept=".zip,.json,application/zip,application/json" required>
	<span class="help"><?= e(t('The server allows uploads of at most %s. Copy a larger file over FTP into the storage/import/ folder – it will appear in the list below.', Talea\Core\Files::size($uploadLimit))) ?></span></div></div>
<p class="buttons"><input class="btn" type="submit" value="<?= e(t('Upload and show preview')) ?>"></p>
</form>
<?php endif ?>
<?php if ($taleaFiles !== []): ?>
<div class="tab-wrap">
<table class="listing">
<thead><tr><th scope="col"><?= e(t('File')) ?></th><th scope="col"><?= e(t('Size')) ?></th><th scope="col"><?= e(t('Uploaded')) ?></th><th scope="col"><?= e(t('Status')) ?></th><th scope="col"><?= e(t('Actions')) ?></th></tr></thead>
<tbody>
<?php foreach ($taleaFiles as $s): $state = $s['status']; ?>
<tr>
	<td><?= e($s['file']) ?></td>
	<td class="number"><?= e(Talea\Core\Files::size($s['size'])) ?></td>
	<td class="number"><?= e(format_date(date('Y-m-d H:i:s', $s['time']), true)) ?></td>
	<td><?= $state === null ? '–' : e(t(['preparing' => 'being read', 'preview' => 'ready to import', 'data' => 'import in progress', 'media' => 'import in progress', 'done' => 'imported'][$state['phase']] ?? '–')) ?></td>
	<td class="actions">
<?php if ($state !== null && $state['phase'] !== 'preparing'): ?>
		<a href="<?= e($module->url('talea', ['file' => $s['file']])) ?>"><?= e(t($state['phase'] === 'done' ? 'Result' : 'Continue')) ?></a>
<?php endif ?>
<?php if ($state === null || $state['phase'] === 'preview'): ?>
		<form class="inline" method="post" action="<?= e($module->url('talea_select')) ?>"><?= $csrf ?><input type="hidden" name="file" value="<?= e($s['file']) ?>"><button class="navigation" type="submit"><?= e(t($state === null ? 'Show preview' : 'Read again')) ?></button></form>
<?php endif ?>
		<form class="inline" method="post" action="<?= e($module->url('talea_delete')) ?>" data-confirm="<?= e(t('Delete the file %s? Content that has already been imported stays on the site.', $s['file'])) ?>"><?= $csrf ?><input type="hidden" name="file" value="<?= e($s['file']) ?>"><button class="navigation danger" type="submit"><?= e(t('Delete')) ?></button></form>
	</td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<?php endif ?>
</details>

<details class="panel-collapsed"<?= $exports !== [] ? ' open' : '' ?>>
<summary><h2><?= e(t('Export of the whole site')) ?></h2></summary>
<p><?= e(t('One archive gives you the whole site in an open format (JSON): pages, news, categories, tags, redirects, menus, collections, pop-ups, site parts, components, shared classes and uploaded files – as a content backup or for moving elsewhere. Enquiries, passwords, keys and accounts are not included.')) ?></p>
<?php if (!$hasZip): ?>
<p class="notice"><?= e(t('The PHP zip extension is missing on the server, so the export contains data only (JSON). Download the media/ folder over FTP.')) ?></p>
<?php endif ?>
<form method="post" action="<?= e($module->url('export')) ?>"><?= $csrf ?><p><button class="btn" type="submit"><?= e(t('Create export')) ?></button></p></form>
<?php if ($exports !== []): ?>
<div class="tab-wrap">
<table class="listing">
<thead><tr><th scope="col"><?= e(t('File')) ?></th><th scope="col"><?= e(t('Size')) ?></th><th scope="col"><?= e(t('Created')) ?></th><th scope="col"><?= e(t('Actions')) ?></th></tr></thead>
<tbody>
<?php foreach ($exports as $x): ?>
<tr>
	<td><?= e($x['file']) ?></td>
	<td class="number"><?= e(Talea\Core\Files::size($x['size'])) ?></td>
	<td class="number"><?= e(format_date(date('Y-m-d H:i:s', $x['time']), true)) ?></td>
	<td class="actions"><a href="<?= e($module->url('download', ['file' => $x['file']])) ?>"><?= e(t('Download')) ?></a>
		<form class="inline" method="post" action="<?= e($module->url('delete_export')) ?>" data-confirm="<?= e(t('Delete the export %s?', $x['file'])) ?>"><?= $csrf ?><input type="hidden" name="file" value="<?= e($x['file']) ?>"><button class="navigation danger" type="submit"><?= e(t('Delete')) ?></button></form></td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<p class="small-text"><?= e(t('The last three exports are kept. To restore this site, use the database backup in Settings → Backups and updates.')) ?></p>
<?php endif ?>
</details>
