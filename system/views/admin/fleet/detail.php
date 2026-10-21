<?php
/**
 * One site in the fleet console: what needs attention, its last report and the console's events about it.
 *
 * @var Talea\Admin\Modules\Fleet $module
 * @var array<string, mixed> $site a row of tl_fleet_sites + score, reasons, beat (the decoded report)
 * @var list<array<string, mixed>> $events
 */

$reasonLabels = require __DIR__ . '/reasons.php';
$beat = $site['beat'];
$date = fn (mixed $ts): string => is_int($ts) && $ts > 0 ? format_date((new DateTimeImmutable())->setTimestamp($ts), true) : '–';
$yes = fn (mixed $v): string => $v ? t('yes') : t('no');
?>
<p><a href="<?= e($module->url('')) ?>">← <?= e(t('All sites')) ?></a> · <a href="<?= e((string) $site['url']) ?>" target="_blank" rel="noopener"><?= e((string) $site['url']) ?></a> · <a href="<?= e((string) $site['url'] . '/admin.php') ?>" target="_blank" rel="noopener"><?= e(t('Administration of the site')) ?></a></p>
<p class="notice<?= $site['reasons'] === [] ? ' notice-ok' : '' ?>"><?php if ($site['reasons'] === []): ?><?= e(t('Nothing needs attention.')) ?><?php else: ?><?= e(t('Needs attention:')) ?> <?= e(implode(', ', array_map(fn (string $r): string => $reasonLabels[$r], $site['reasons']))) ?><?php endif ?></p>
<div class="tab-wrap"><table class="listing"><tbody>
	<tr><th scope="row"><?= e(t('Up')) ?></th><td><?= $site['up'] === null ? e(t('not checked yet')) : ((int) $site['up'] === 1 ? e(t('yes')) : '<span class="badge badge-error">' . e(t('no')) . '</span>') ?><?= $site['up_checked'] !== null ? ' · ' . e(t('checked %s', format_date(new DateTimeImmutable((string) $site['up_checked']), true))) . ((int) $site['up_status'] > 0 ? ' (HTTP ' . (int) $site['up_status'] . ')' : '') : '' ?></td></tr>
	<tr><th scope="row"><?= e(t('Last report')) ?></th><td><?= $site['last_seen'] !== null ? e(format_date(new DateTimeImmutable((string) $site['last_seen']), true)) : e(t('no report yet')) ?></td></tr>
	<tr><th scope="row"><?= e(t('Version')) ?></th><td><?= e((string) $site['version']) ?><?= !empty($beat['php']) ? ' · PHP ' . e((string) $beat['php']) : '' ?></td></tr>
	<tr><th scope="row"><?= e(t('Health')) ?></th><td><?= e(t(['ok' => 'Everything is fine.', 'warning' => 'warnings', 'error' => 'errors', '' => '–'][(string) $site['status']] ?? '–')) ?></td></tr>
	<tr><th scope="row"><?= e(t('Last backup')) ?></th><td><?= e($date($beat['last_backup'] ?? null)) ?> · <?= e(t('off-site copy: %s', $yes($beat['offsite_backup'] ?? false))) ?></td></tr>
	<tr><th scope="row"><?= e(t('Cron last called')) ?></th><td><?= e($date($beat['cron_last_run'] ?? null)) ?></td></tr>
	<?php if (($beat['visits_7_days'] ?? null) !== null): ?><tr><th scope="row"><?= e(t('Visits in 7 days')) ?></th><td><?= (int) $beat['visits_7_days'] ?></td></tr><?php endif ?>
	<?php if (($beat['enquiries_7_days'] ?? null) !== null): ?><tr><th scope="row"><?= e(t('Enquiries')) ?></th><td><?= e(t('%d in 7 days, %d waiting for an answer', (int) $beat['enquiries_7_days'], (int) ($beat['enquiries_unanswered'] ?? 0))) ?></td></tr><?php endif ?>
	<?php if (is_array($beat['audit'] ?? null)): ?><tr><th scope="row"><?= e(t('Site audit')) ?></th><td><?= array_sum($beat['audit']) === 0 ? e(t('no findings')) : e(implode(', ', array_map(fn (string $k, int $n): string => $k . ' ' . $n, array_keys($beat['audit']), $beat['audit']))) ?></td></tr><?php endif ?>
</tbody></table></div>
<?php if (($beat['problems'] ?? []) !== []): ?>
<h2><?= e(t('Problems the site reports')) ?></h2>
<div class="tab-wrap"><table class="listing"><tbody>
<?php foreach ($beat['problems'] as $p): ?>
	<tr><td class="center"><span class="badge badge-<?= ($p['status'] ?? '') === 'error' ? 'error' : 'draft' ?>"><?= ($p['status'] ?? '') === 'error' ? '✕' : '!' ?></span></td><td><strong><?= e((string) ($p['check'] ?? '')) ?></strong><br><span class="small-text"><?= e((string) ($p['group'] ?? '')) ?></span></td><td><?= e((string) ($p['detail'] ?? '')) ?></td></tr>
<?php endforeach ?>
</tbody></table></div>
<p class="small-text"><?= e(t('The site reports its problems in English; fix them in its administration.')) ?></p>
<?php endif ?>
<?php if (($beat['jobs_failing'] ?? []) !== []): ?><p class="notice notice-error"><?= e(t('Background jobs that fail: %s', implode(', ', $beat['jobs_failing']))) ?></p><?php endif ?>
<form class="form" method="post" action="<?= e($module->url('check')) ?>">
<?= $csrf ?>
<input type="hidden" name="id" value="<?= e($site['public_id']) ?>">
<p><button class="navigation" type="submit"><?= e(t('Check now')) ?></button>
<button class="navigation danger" type="submit" formaction="<?= e($module->url('remove')) ?>" data-confirm="<?= e(t('Remove the site from the console? Its reports will be refused until it is paired again.')) ?>"><?= e(t('Remove from the console')) ?></button></p>
</form>
<?php if ($events !== []): ?>
<h2><?= e(t('Events')) ?></h2>
<div class="tab-wrap"><table class="listing"><tbody>
<?php foreach ($events as $ev): ?>
	<tr><td><?= e(format_date(new DateTimeImmutable((string) $ev['created_at']), true)) ?></td><td><?= e((string) $ev['message']) ?></td></tr>
<?php endforeach ?>
</tbody></table></div>
<?php endif ?>
<p class="small-text"><?= e(t('Paired %s.', format_date(new DateTimeImmutable((string) $site['paired_at']), true))) ?> <?= e(t('Score %d (higher = more urgent).', (int) $site['score'])) ?></p>
