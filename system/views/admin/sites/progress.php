<?php
/**
 * What the import of a new site is doing: a bar and the steps (done, running, waiting) with their numbers. The script of the page
 * (image/admin.js, data-import-status) asks the status address every 2 seconds and redraws; the site is opened when it is ready.
 *
 * @var Talea\Admin\Modules\Sites $module
 * @var array<string, mixed> $site
 * @var array{state: string, percent: int, steps: list<array{label: string, state: string}>, error: string, waiting: string} $status
 * @var string $statusUrl
 */
$name = $site['name'] !== '' ? (string) $site['name'] : (string) $site['slug'];
$waiting = ['starting' => t('The import is starting. If nothing happens, it begins with the next run of the scheduled tasks (every 5 minutes).'),
    'stale' => t('The scheduled tasks have not run for a while, so the import has not started. Check that the cron job of the installation is running.')];
?>
<p class="navigation-row"><a class="navigation" href="<?= e($module->url()) ?>"><?= e(t('Back to sites')) ?></a></p>
<h2><?= bdi($name) ?></h2>
<?php if ($status['state'] === 'failed'): ?>
<p class="notice notice-error" role="alert"><?= e(t('The import stopped: %s', $status['error'])) ?> <?= e(t('Move this site to the trash and import the archive again.')) ?></p>
<?php else: ?>
<div data-import-status="<?= e($statusUrl) ?>">
	<p class="notice notice-pending" role="status"><?= e(t('The archive is being imported in the background. This page updates itself; you can leave it, the other sites keep working.')) ?></p>
	<progress class="transfer-progress" max="100" value="<?= (int) $status['percent'] ?>"></progress>
	<p><strong data-import-percent><?= (int) $status['percent'] ?> %</strong></p>
	<ol class="transfer-steps" data-import-steps>
<?php foreach ($status['steps'] as $i => $step): ?>
		<li<?= $step['state'] !== '' ? ' class="' . e($step['state']) . '"' . ($step['state'] === 'active' ? ' aria-current="step"' : '') : '' ?>><span><?= $i + 1 ?></span> <?= e($step['label']) ?></li>
<?php endforeach ?>
	</ol>
	<p class="small-text" data-import-waiting data-text-starting="<?= e($waiting['starting']) ?>" data-text-stale="<?= e($waiting['stale']) ?>"><?= e($waiting[$status['waiting']] ?? '') ?></p>
	<noscript><p><a class="btn" href="<?= e($module->url('progress', ['id' => $site['public_id']])) ?>"><?= e(t('Refresh')) ?></a></p></noscript>
</div>
<?php endif ?>
