<?php
/**
 * Import of a Kaleta export (1.8): preview, progress in batches and the result. Until it is done, the form submits
 * itself (data-auto-submit in image/admin.js) – each submission is one batch.
 *
 * @var Kaleta\Admin\Modules\Transfer $module
 * @var Kaleta\Core\App $app
 * @var string $csrf
 * @var array<string, mixed> $state
 * @var string $error  already translated error of the last batch (the import stopped)
 * @var array{empty: bool, pages: int, news: int, items: int, media: int} $siteContent
 */
$h = $state['header'];
$counts = $state['counts'];
$labels = [
    'pages' => 'Pages', 'news' => 'News', 'categories' => 'Categories', 'tags' => 'Tags', 'collections' => 'Collections', 'collection_items' => 'Collection items',
    'components' => 'Components', 'classes' => 'Shared classes', 'site_parts' => 'Site parts', 'menus' => 'Menus', 'popups' => 'Pop-ups', 'redirects' => 'Redirects', 'media' => 'Media',
];
$tablesDone = min(count(Kaleta\Core\SiteImport::TABLES), (int) $state['table']);
$rowsTotal = max(1, array_sum($counts));
$rowsDone = array_sum(array_map(fn (array $v): int => (int) ($v['ok'] ?? 0) + (int) ($v['skipped'] ?? 0), $state['result']));
?>
<h2><?= e(t('Import from Kaleta')) ?></h2>
<?= $app->view->render('admin/transfer/steps', ['step' => $state['phase'] === 'preview' ? 2 : 3]) ?>
<?php if ($error !== ''): ?>
<p class="notice notice-error"><?= e(t('The import has stopped:')) ?> <?= e($error) ?></p>
<?php if ($state['backup'] !== ''): ?>
<p><?= e(t('The state before the import is in backup %s (Settings → Backups and updates).', $state['backup'])) ?></p>
<?php endif ?>
<p class="navigation-row"><a class="navigation" href="<?= e($module->url()) ?>"><?= e(t('Back to Import and export')) ?></a></p>
<?php elseif ($state['phase'] === 'preview'): ?>
<p><?= e(t('Export of the site “%s” from Kaleta %s, created %s.', $h['name'], $h['kaleta'], $h['created_at'] !== '' ? format_date(date('Y-m-d H:i:s', (int) strtotime($h['created_at'])), true) : '–')) ?></p>
<div class="tiles">
<?php foreach ($labels as $table => $label): if (($counts[$table] ?? 0) === 0) { continue; } ?>
	<div class="tiles-item"><strong><?= (int) $counts[$table] ?></strong><span><?= e(t($label)) ?></span></div>
<?php endforeach ?>
	<div class="tiles-item"><strong><?= (int) $state['media_total'] ?></strong><span><?= e(t('Files in media/')) ?></span></div>
</div>
<?php if ($state['media_total'] === 0 && ($counts['media'] ?? 0) > 0): ?>
<p class="notice"><?= e(t('The export has no files – copy the media/ folder from the old site over FTP before or after the import.')) ?></p>
<?php endif ?>
<p><?= e(t('The import replaces all content of this site: pages, news, collections, components, menus, site parts, pop-ups, redirects and the media library, and takes over the site name, company details, languages and the look. User accounts, passwords, keys and the settings of mail and backups stay as they are on this site – the export never contains them. Imported news will be yours.')) ?></p>
<?php if (!$siteContent['empty']): ?>
<p class="notice notice-error"><?= e(t('This site already has its own content (pages: %d, news: %d, collection items: %d, media: %d). A Kaleta export can be imported only into a new, empty site – install Kaleta again and choose “Start from an export”.', $siteContent['pages'], $siteContent['news'], $siteContent['items'], $siteContent['media'])) ?></p>
<?php else: ?>
<form class="form" method="post" action="<?= e($module->url('kaleta_run')) ?>">
<?= $csrf ?>
<input type="hidden" name="file" value="<?= e($state['file']) ?>">
<p><label><input type="checkbox" name="confirmation" value="1" required> <?= e(t('Replace the content of this site with the export. A database backup is made first.')) ?></label></p>
<p class="buttons"><input class="btn" type="submit" value="<?= e(t('Import')) ?>"></p>
</form>
<?php endif ?>
<?php elseif ($state['phase'] === 'data' || $state['phase'] === 'media'): ?>
<?php if ($state['phase'] === 'data'): ?>
<p class="notice" role="status"><?= e(t('Importing: %s of %s items. Keep this page open, it continues by itself.', $rowsDone, array_sum($counts))) ?></p>
<progress class="transfer-progress" max="<?= $rowsTotal ?>" value="<?= $rowsDone ?>"></progress>
<?php else: ?>
<p class="notice" role="status"><?= e(t('Copying files into media/: %s of %s. Keep this page open, it continues by itself.', (int) $state['media']['saved'] + (int) $state['media']['skipped'], (int) $state['media_total'])) ?></p>
<progress class="transfer-progress" max="<?= max(1, (int) $state['media_total']) ?>" value="<?= (int) $state['media']['saved'] + (int) $state['media']['skipped'] ?>"></progress>
<?php endif ?>
<form method="post" action="<?= e($module->url('kaleta')) ?>" data-auto-submit="400">
	<?= $csrf ?>
	<input type="hidden" name="file" value="<?= e($state['file']) ?>">
	<p><button class="btn" type="submit"><?= e(t('Continue')) ?></button></p>
</form>
<?php else: ?>
<p class="notice notice-ok"><?= e(t('The site has been imported.')) ?></p>
<div class="tiles">
<?php foreach ($labels as $table => $label): $v = $state['result'][$table] ?? null; if ($v === null) { continue; } ?>
	<div class="tiles-item"><strong><?= (int) ($v['ok'] ?? 0) ?></strong><span><?= e(t($label)) ?><?= (int) ($v['skipped'] ?? 0) > 0 ? ' – ' . e(t('%d skipped', (int) $v['skipped'])) : '' ?></span></div>
<?php endforeach ?>
	<div class="tiles-item"><strong><?= (int) $state['media']['saved'] ?></strong><span><?= e(t('Files in media/')) ?></span></div>
</div>
<?php if ($state['errors'] !== []): ?>
<details class="advanced"><summary><?= e(t('Files that could not be saved')) ?></summary><ul>
<?php foreach ($state['errors'] as $row): ?>
	<li><?= e($row) ?></li>
<?php endforeach ?>
</ul></details>
<?php endif ?>
<p><?= e(t('The state before the import is in backup %s (Settings → Backups and updates). Check the site, then delete the export file – it is no longer needed.', $state['backup'])) ?></p>
<p class="navigation-row"><a class="navigation" href="<?= e($app->url('')) ?>"><?= e(t('View site')) ?></a> <a class="navigation" href="<?= e($module->url()) ?>"><?= e(t('Back to Import and export')) ?></a></p>
<?php endif ?>
