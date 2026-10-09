<?php
/**
 * Import from a website (2.6): finding the pages, the preview, the import in batches and the result. While it runs, the
 * form submits itself (data-auto-submit in image/admin.js) – each submission is one batch.
 *
 * @var Kaleta\Admin\Modules\Transfer $module
 * @var Kaleta\Core\App $app
 * @var string $csrf
 * @var array<string, mixed> $state  Core\WebImport
 */
$v = $state['result'];
$urls = array_keys($state['urls']);
?>
<p><?= e(t('Site: %s', $state['web'])) ?></p>
<?php if ($state['phase'] === 'finding'): ?>
<p class="notice" role="status"><?= e(t('Finding pages: %s found so far. Keep this page open, it continues by itself.', count($urls))) ?></p>
<form method="post" action="<?= e($module->url('web_progress', ['id' => $state['id']])) ?>" data-auto-submit="400"><?= $csrf ?>
	<p><button class="btn" type="submit"><?= e(t('Continue')) ?></button></p></form>
<?php elseif ($state['phase'] === 'preview'): ?>
<?php if ($urls === []): ?>
<p class="notice notice-error"><?= e(t('No pages were found. Check the address – the site has to be reachable from this server.')) ?></p>
<?php else: ?>
<p class="notice notice-ok"><?= e(t('%s pages found. They will be imported as hidden pages; nothing changes for visitors until you publish them.', count($urls))) ?></p>
<div class="tab-wrap"><table class="listing"><thead><tr><th scope="col"><?= e(t('Address')) ?></th></tr></thead><tbody>
<?php foreach (array_slice($urls, 0, 40) as $url): ?>
	<tr><td><?= e('/' . Kaleta\Core\WebImport::path($url)) ?></td></tr>
<?php endforeach ?>
</tbody></table></div>
<?php if (count($urls) > 40): ?><p class="small-text"><?= e(t('… and %s more.', count($urls) - 40)) ?></p><?php endif ?>
<form method="post" action="<?= e($module->url('web_run')) ?>"><?= $csrf ?><input type="hidden" name="id" value="<?= e($state['id']) ?>">
	<p><button class="btn" type="submit"><?= e(t('Import %s pages', count($urls))) ?></button></p></form>
<?php endif ?>
<?php elseif ($state['phase'] === 'import'): ?>
<p class="notice" role="status"><?= e(t('Importing: %s of %s pages. Keep this page open, it continues by itself.', (int) $state['position'], count($urls))) ?></p>
<progress class="transfer-progress" max="<?= max(1, count($urls)) ?>" value="<?= (int) $state['position'] ?>"></progress>
<form method="post" action="<?= e($module->url('web_progress', ['id' => $state['id']])) ?>" data-auto-submit="400"><?= $csrf ?>
	<p><button class="btn" type="submit"><?= e(t('Continue')) ?></button></p></form>
<?php else: ?>
<p class="notice notice-ok"><?= e(t('The import is finished. The pages are hidden until you check and publish them.')) ?></p>
<div class="tiles">
	<div class="tiles-item"><strong><?= (int) $v['pages'] ?></strong><span><?= e(t('New pages')) ?></span></div>
	<div class="tiles-item"><strong><?= (int) $v['articles'] ?></strong><span><?= e(t('New news items')) ?></span></div>
	<div class="tiles-item"><strong><?= (int) $v['images'] ?></strong><span><?= e(t('Images in Media')) ?></span></div>
	<div class="tiles-item"><strong><?= (int) $v['redirects'] ?></strong><span><?= e(t('Redirects from old addresses')) ?></span></div>
	<div class="tiles-item"><strong><?= (int) $v['skipped'] ?></strong><span><?= e(t('Skipped (already imported earlier)')) ?></span></div>
</div>
<?php if ($state['errors'] !== []): ?>
<details class="advanced"><summary><?= e(t('%s pages could not be imported', (int) $v['failed'])) ?></summary><ul>
<?php foreach ($state['errors'] as $row): ?><li><?= e($row) ?></li><?php endforeach ?>
</ul></details>
<?php endif ?>
<p><?= e(t('Next: check the pages, put the ones you keep into the menu and publish them. Claude can match their look to the design system and tidy the texts through the connection.')) ?></p>
<p class="navigation-row"><a class="navigation" href="<?= e($app->url('admin.php?module=pages')) ?>"><?= e(t('Show pages')) ?></a> <a class="navigation" href="<?= e($module->url()) ?>"><?= e(t('Back to Import and export')) ?></a></p>
<?php endif ?>
