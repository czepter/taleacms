<?php
/**
 * Import from another system (3.0, Import\Batch), step 3: progress in batches (reading the file, importing content,
 * downloading images) and the result. Until it is done, the form submits itself (data-auto-submit in image/admin.js) –
 * each submission is one batch. The same pattern as the WordPress import (progress.php).
 *
 * @var Kaleta\Admin\Modules\Transfer $module
 * @var Kaleta\Core\App $app
 * @var string $csrf
 * @var array<string, mixed> $state
 * @var class-string<Kaleta\Import\Source> $source
 * @var string $error  already translated error of the last batch (the import stopped)
 * @var bool $canDownload  the server can download (curl or allow_url_fopen) and has GD
 * @var string $domain  domain of the old site
 * @var bool $anyHost  the source keeps images on a CDN, so they are downloaded from any public host
 */
$v = $state['result'];
$o = $state['images'];
$running = in_array($state['phase'], ['download', 'analysis', 'import', 'images'], true);
$f = $state['download'];
?>
<?= $app->view->render('admin/transfer/steps', ['step' => $state['phase'] === 'download' ? 1 : ($state['phase'] === 'analysis' ? 2 : 3)]) ?>
<?php if ($error !== ''): ?>
<p class="notice notice-error"><?= e(t('The import has stopped:')) ?> <?= e($error) ?></p>
<p class="navigation-row"><a class="navigation" href="<?= e($module->url()) ?>"><?= e(t('Back to Import and export')) ?></a></p>
<?php elseif ($running): ?>
<?php if ($state['phase'] === 'download'): ?>
<p class="notice" role="status"><?= e(t('Fetching from %s: step %s of %s (%s), %s pages and %s items so far. Keep this page open, it continues by itself.', (string) ($f['web'] ?? ''), min((int) ($f['step'] ?? 0) + 1, count($f['steps'] ?? [])), count($f['steps'] ?? []), t((string) ($source::steps()[$f['steps'][$f['step']] ?? ''] ?? '')), (int) ($f['page'] ?? 0), (int) ($f['items'] ?? 0))) ?></p>
<?php elseif ($state['phase'] === 'analysis'): ?>
<p class="notice" role="status"><?= e(t('Reading file %s: %s items processed. Keep this page open.', $state['file'], (int) $state['position'])) ?></p>
<?php elseif ($state['phase'] === 'import'): ?>
<p class="notice" role="status"><?= e(t('Importing: %s of %s items. Keep this page open, it continues by itself.', (int) $state['position'], (int) $state['total'])) ?></p>
<progress class="transfer-progress" max="<?= max(1, (int) $state['total']) ?>" value="<?= (int) $state['position'] ?>"></progress>
<?php else: ?>
<p class="notice" role="status"><?= e(t('Downloading images from the old site: %s of %s news items and pages done, %s images downloaded. Keep this page open, I will continue automatically.', (int) $o['done'], (int) $o['total'], (int) $o['downloaded'])) ?></p>
<progress class="transfer-progress" max="<?= max(1, (int) $o['total']) ?>" value="<?= (int) $o['done'] ?>"></progress>
<?php endif ?>
<form method="post" action="<?= e($module->url('source_progress', ['file' => $state['file']])) ?>" data-auto-submit="600">
	<?= $csrf ?>
	<p><button class="btn" type="submit"><?= e(t('Continue')) ?></button></p>
</form>
<?php else: ?>
<p class="notice notice-ok"><?= e(t('The content import is finished.')) ?></p>
<div class="tiles">
	<div class="tiles-item"><strong><?= (int) $v['articles'] ?></strong><span><?= e(t('New news items')) ?></span></div>
	<div class="tiles-item"><strong><?= (int) $v['pages'] ?></strong><span><?= e(t('New pages')) ?></span></div>
	<div class="tiles-item"><strong><?= (int) $v['categories'] ?></strong><span><?= e(t('New categories')) ?></span></div>
	<div class="tiles-item"><strong><?= (int) $v['tags'] ?></strong><span><?= e(t('New tags')) ?></span></div>
	<div class="tiles-item"><strong><?= (int) $v['redirects'] ?></strong><span><?= e(t('Redirects from old addresses')) ?></span></div>
	<div class="tiles-item"><strong><?= (int) $v['skipped'] ?></strong><span><?= e(t('Skipped (already imported earlier)')) ?></span></div>
</div>
<p class="navigation-row"><a class="navigation" href="<?= e($app->url('admin.php?module=news')) ?>"><?= e(t('Show news')) ?></a> <a class="navigation" href="<?= e($app->url('admin.php?module=pages')) ?>"><?= e(t('Show pages')) ?></a> <a class="navigation" href="<?= e($module->url()) ?>"><?= e(t('Back to Import and export')) ?></a></p>

<h2><?= e(t('Images from the old site')) ?></h2>
<?php if ($state['phase'] === 'images_done'): ?>
<p class="notice <?= (int) $o['failed'] > 0 ? 'notice-warning' : 'notice-ok' ?>"><?= e(t('%s images downloaded, %s failed.', (int) $o['downloaded'], (int) $o['failed'])) ?></p>
<?php if ($o['errors'] !== []): ?>
<details class="advanced"><summary><?= e(t('Latest images that could not be downloaded')) ?></summary><ul>
<?php foreach ($o['errors'] as $row): ?>
	<li><?= e($row) ?></li>
<?php endforeach ?>
</ul></details>
<?php endif ?>
<?php endif ?>
<?php if (!$canDownload): ?>
<p class="notice"><?= e(t('This server cannot download files from other sites (both curl and allow_url_fopen are missing, or the GD extension). Move the images manually: upload them to Media and replace them in the articles.')) ?></p>
<?php elseif ($domain === '' && !$anyHost): ?>
<p class="notice"><?= e(t('The file does not contain the address of the old site, so the images cannot be downloaded.')) ?></p>
<?php else: ?>
<p><?= e($anyHost
    ? t('News and pages still show images from the old site. Downloading saves the main news images and images in texts to Media (resized, with thumbnails and WebP) and rewrites the links in the texts. Images are downloaded from the image hosts of %s, which must still be available.', $source::name())
    : t('News and pages still show images from the old site. Downloading saves the main news images and images in texts to Media (resized, with thumbnails and WebP) and rewrites the links in the texts. Images are downloaded only from the domain %s, and the old site must still be available.', $domain)) ?></p>
<form method="post" action="<?= e($module->url('source_images')) ?>"><?= $csrf ?><input type="hidden" name="file" value="<?= e($state['file']) ?>">
	<p><button class="btn" type="submit"><?= e(t($state['phase'] === 'images_done' ? 'Try downloading again' : 'Download images from the old site')) ?></button></p></form>
<?php endif ?>
<?php endif ?>
