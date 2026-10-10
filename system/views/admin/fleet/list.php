<?php
/**
 * The fleet console: all paired sites, the ones that need attention first (Fleet\Console::overview).
 *
 * @var Talea\Admin\Modules\Fleet $module
 * @var list<array<string, mixed>> $sites
 * @var int $total
 * @var int $needing
 * @var string $show attention | all
 * @var string $query
 * @var string $latest the newest version the console knows
 * @var string $pairingKey a pairing key made just now (shown once)
 */
use Talea\Fleet\Console;

$reasonLabels = require __DIR__ . '/reasons.php';
$severity = fn (string $r): string => Console::REASONS[$r] >= Console::REASONS['errors'] ? 'error' : (Console::REASONS[$r] >= Console::REASONS['warnings'] ? 'draft' : '');
?>
<?php if ($pairingKey !== ''): ?>
<div class="notice notice-ok"><p><?= e(t('Paste this pairing key on the site in Settings → Fleet console. It is valid for 24 hours and only once; it is not shown again.')) ?></p>
<p><code class="totp-key"><?= e($pairingKey) ?></code></p></div>
<?php endif ?>
<p class="notice<?= $total > 0 && $needing === 0 ? ' notice-ok' : '' ?>"><?= e($total === 0 ? t('No site reports to this console yet. Make a pairing key and paste it on the site.')
    : ($needing === 0 ? t('All %d sites are fine.', $total) : t('%d of %d sites need attention.', $needing, $total))) ?> <?= e(t('The newest version: %s.', $latest)) ?></p>
<form class="form" method="post" action="<?= e($module->url('pairing_key')) ?>">
<?= $csrf ?>
<p><button class="btn" type="submit"><?= e(t('Add a site')) ?></button>
<a class="navigation" href="<?= e($module->url('kit')) ?>"><?= e(t('Shared kit')) ?></a>
<?php if ($total > 0): ?>
<button class="navigation" type="submit" formaction="<?= e($module->url('check')) ?>"><?= e(t('Check all now')) ?></button>
<button class="navigation" type="submit" formaction="<?= e($module->url('allow')) ?>" data-confirm="<?= e(t('Every site that lets the console decide installs version %s within an hour, without waiting for the test sites. Continue?', $latest)) ?>"><?= e(t('Allow the new version everywhere now')) ?></button>
<?php endif ?></p>
</form>
<?php if ($total > 0): ?>
<nav class="tabs" aria-label="<?= e(t('Which sites')) ?>">
	<a href="<?= e($module->url('')) ?>"<?= $show === 'attention' ? ' class="active" aria-current="page"' : '' ?>><?= e(t('Need attention')) ?> (<?= $needing ?>)</a>
	<a href="<?= e($module->url('', ['show' => 'all'])) ?>"<?= $show === 'all' ? ' class="active" aria-current="page"' : '' ?>><?= e(t('All sites')) ?> (<?= $total ?>)</a>
</nav>
<form method="get" action="<?= e($app->url('admin.php')) ?>"><input type="hidden" name="module" value="fleet"><?php if ($show === 'all'): ?><input type="hidden" name="show" value="all"><?php endif ?>
	<label><?= e(t('Name or address contains:')) ?> <input class="textfield" type="search" name="q" value="<?= e($query) ?>" size="24"></label> <input class="btn" type="submit" value="<?= e(t('Filter')) ?>"></form>
<?php if ($sites === []): ?>
<p><?= e(t('Nothing needs attention.')) ?></p>
<?php else: ?>
<div class="tab-wrap"><table class="listing">
<thead><tr><th scope="col"><?= e(t('Site')) ?></th><th scope="col"><?= e(t('What needs attention')) ?></th><th scope="col"><?= e(t('Version')) ?></th><th scope="col"><?= e(t('Last report')) ?></th><th scope="col"><?= e(t('Enquiries waiting')) ?></th></tr></thead>
<tbody>
<?php foreach ($sites as $s): ?>
<tr>
	<td><a href="<?= e($module->url('detail', ['id' => $s['public_id']])) ?>"><strong><?= e((string) $s['name'] !== '' ? (string) $s['name'] : (string) $s['url']) ?></strong></a><br><span class="small-text"><?= e((string) $s['url']) ?><?= $s['ring'] === 'canary' ? ' · ' . e(t('test site')) : '' ?></span></td>
	<td><?php if ($s['reasons'] === []): ?><span class="badge badge-published"><?= e(t('ok')) ?></span><?php endif ?><?php foreach ($s['reasons'] as $r): ?><span class="badge<?= $severity($r) !== '' ? ' badge-' . $severity($r) : '' ?>"><?= e($reasonLabels[$r]) ?></span> <?php endforeach ?></td>
	<td><?= e((string) $s['version'] !== '' ? (string) $s['version'] : '–') ?><?php if ((string) $s['update_allowed'] !== ''): ?><br><span class="small-text"><?= e(t('allowed %s', (string) $s['update_allowed'])) ?></span><?php endif ?></td>
	<td><?= $s['last_seen'] !== null ? e(format_date(new DateTimeImmutable((string) $s['last_seen']), true)) : '–' ?></td>
	<td class="center"><?= isset($s['beat']['enquiries_unanswered']) ? (int) $s['beat']['enquiries_unanswered'] : '–' ?></td>
</tr>
<?php endforeach ?>
</tbody></table></div>
<?php endif ?>
<?php endif ?>
<p class="small-text"><?= e(t('Sites report every hour; the console checks every 5 minutes that each home page answers. Problems reach you by the alert e-mail (System status → Alerts).')) ?></p>
