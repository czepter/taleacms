<?php
/**
 * The migration parity report (2.7): finding the old site's pages, checking them in batches (the form submits itself,
 * data-auto-submit in image/admin.js), then the result – problems first, then the checks of the whole site.
 *
 * @var Kaleta\Admin\Modules\Transfer $module
 * @var Kaleta\Core\App $app
 * @var string $csrf
 * @var array<string, mixed> $state  Core\MigrationReport
 * @var array{souhrn: array<string, int>, radky: list<array<string, mixed>>, web: list<array{zprava: string, uprava: string}>} $result
 */
use Kaleta\Core\MigrationReport;

$s = $result['summary'];
$severityClass = ['error' => 'badge badge-error', 'warning' => 'badge badge-draft', 'info' => 'badge'];
?>
<p><?= e(t('Old site: %s', $state['web'])) ?></p>
<?php if ($state['phase'] !== 'done'): ?>
<p class="notice" role="status"><?= e($state['phase'] === 'finding' ? t('Finding the pages of the old site. Keep this page open, it continues by itself.')
    : t('Checking: %s of %s addresses. Keep this page open, it continues by itself.', count($state['rows']), count($state['urls']))) ?></p>
<?php if ($state['phase'] === 'check'): ?><progress class="transfer-progress" max="<?= max(1, count($state['urls'])) ?>" value="<?= count($state['rows']) ?>"></progress><?php endif ?>
<form method="post" action="<?= e($module->url('report', ['id' => $state['id']])) ?>" data-auto-submit="400"><?= $csrf ?>
	<p><button class="btn" type="submit"><?= e(t('Continue')) ?></button></p></form>
<?php else: ?>
<?php if ($s['failed'] === 0 && $s['warnings'] === 0 && $result['web'] === []): ?>
<p class="notice notice-ok"><?= e(t('Everything moved: every old address leads to a published page and nothing was lost.')) ?></p>
<?php elseif ($s['failed'] > 0): ?>
<p class="notice notice-error"><?= e(t('Not ready to go live: %s problems would cost visitors or search engines something.', $s['failed'])) ?></p>
<?php else: ?>
<p class="notice"><?= e(t('Nearly ready: check the warnings below.')) ?></p>
<?php endif ?>
<div class="tiles">
	<div class="tiles-item"><strong><?= $s['urls'] ?></strong><span><?= e(t('Old addresses')) ?></span></div>
	<div class="tiles-item"><strong><?= $s['ok'] ?></strong><span><?= e(t('Same address')) ?></span></div>
	<div class="tiles-item"><strong><?= $s['redirected'] ?></strong><span><?= e(t('Redirected')) ?></span></div>
	<div class="tiles-item"><strong><?= $s['hidden'] ?></strong><span><?= e(t('Not published yet')) ?></span></div>
	<div class="tiles-item"><strong><?= $s['missing'] ?></strong><span><?= e(t('Missing')) ?></span></div>
</div>
<?php if ($result['web'] !== []): ?>
<h2><?= e(t('The whole site')) ?></h2>
<ul>
<?php foreach ($result['web'] as $c): ?>
	<li><?= e($c['message']) ?> <a href="<?= e($app->url($c['fix'])) ?>"><?= e(t('Fix')) ?></a></li>
<?php endforeach ?>
</ul>
<?php endif ?>
<h2><?= e(t('Old addresses')) ?></h2>
<div class="tab-wrap"><table class="listing">
<thead><tr><th scope="col"><?= e(t('Old address')) ?></th><th scope="col"><?= e(t('On this site')) ?></th><th scope="col"><?= e(t('Problems')) ?></th></tr></thead>
<tbody>
<?php foreach ($result['rows'] as $r): ?>
	<tr>
		<td><a href="<?= e($state['web'] . $r['old']) ?>" rel="noopener noreferrer" target="_blank"><?= e($r['old']) ?></a><?php if ($r['old_title'] !== ''): ?><br><span class="small-text"><?= e($r['old_title']) ?></span><?php endif ?></td>
		<td><?php if ($r['new'] !== ''): ?><a href="<?= e(str_starts_with((string) $r['new'], 'http') ? $r['new'] : $app->url(ltrim((string) $r['new'], '/'))) ?>"><?= e($r['new']) ?></a><?php else: ?>–<?php endif ?><?php if ($r['new_title'] !== ''): ?><br><span class="small-text"><?= e($r['new_title']) ?></span><?php endif ?></td>
		<td><?php if ($r['problems'] === []): ?><span class="badge badge-published"><?= e(t('OK')) ?></span><?php else: ?><ul>
			<?php foreach ($r['problems'] as $p): ?><li><span class="<?= e($severityClass[MigrationReport::PROBLEMS[$p] ?? 'info']) ?>"><?= e(t(['error' => 'Error', 'warning' => 'Check', 'info' => 'Note'][MigrationReport::PROBLEMS[$p] ?? 'info'])) ?></span> <?= e(MigrationReport::describe($p)) ?></li><?php endforeach ?>
		</ul><?php endif ?></td>
	</tr>
<?php endforeach ?>
</tbody></table></div>
<p><?= e(t('Fix what is missing – redirects in Redirects, descriptions and forms on the pages – and check again. Claude can do it through the connection (migration_report).')) ?></p>
<form method="post" action="<?= e($module->url('report_start')) ?>"><?= $csrf ?><input type="hidden" name="url" value="<?= e($state['web']) ?>">
	<p class="navigation-row"><button class="btn" type="submit"><?= e(t('Check again')) ?></button> <a class="navigation" href="<?= e($module->url()) ?>"><?= e(t('Back to Import and export')) ?></a></p></form>
<?php endif ?>
