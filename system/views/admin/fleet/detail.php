<?php
/**
 * One site in the fleet console: what needs attention, its last report, its update ring and the console's events about it.
 *
 * @var Kaleta\Admin\Modules\Fleet $module
 * @var array<string, mixed> $site a row of ka_fleet_sites + score, reasons, beat (the decoded report)
 * @var list<array<string, mixed>> $events
 * @var string $latest
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
	<tr><th scope="row"><?= e(t('Version')) ?></th><td><?= e((string) $site['version']) ?><?= ($beat['update_available'] ?? null) ? ' · ' . e(t('%s is available', (string) $beat['update_available'])) : '' ?><?= !empty($beat['php']) ? ' · PHP ' . e((string) $beat['php']) : '' ?></td></tr>
	<?php if (!empty($beat['update_problem'])): ?><tr><th scope="row"><?= e(t('Last update')) ?></th><td><span class="badge badge-error"><?= e(t('failed')) ?></span> <?= e((string) $beat['update_problem']) ?></td></tr><?php endif ?>
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
<form class="form" method="post" action="<?= e($module->url('ring')) ?>">
<?= $csrf ?>
<input type="hidden" name="id" value="<?= (int) $site['id'] ?>">
<fieldset>
<legend><?= e(t('Updates')) ?></legend>
<?php if ((int) $site['manage_updates'] !== 1): ?>
<p><?= e(t('This site decides about its updates itself (Settings → Fleet console on the site).')) ?></p>
<?php else: ?>
<div class="row"><label for="ring"><?= e(t('Ring')) ?></label><div><select id="ring" name="ring">
	<option value="normal"<?= $site['ring'] !== 'canary' ? ' selected' : '' ?>><?= e(t('normal – after the test sites ran the version for 48 hours without problems')) ?></option>
	<option value="canary"<?= $site['ring'] === 'canary' ? ' selected' : '' ?>><?= e(t('test site – gets a new version first')) ?></option>
</select><span class="help"><?= e(t('Security releases go to every site at once.')) ?></span></div></div>
<p><button class="btn" type="submit"><?= e(t('Save')) ?></button>
<?php if (version_compare($latest, (string) $site['version'], '>')): ?>
<button class="navigation" type="submit" formaction="<?= e($module->url('allow')) ?>"><?= e(t('Allow version %s now', $latest)) ?></button>
<?php endif ?></p>
<?php endif ?>
</fieldset>
<p><button class="navigation" type="submit" formaction="<?= e($module->url('check')) ?>"><?= e(t('Check now')) ?></button>
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
