<?php
/**
 * The fleet console: all paired sites, the ones that need attention first (Fleet\Console::overview).
 *
 * @var Talea\Admin\Modules\Fleet $module
 * @var list<array<string, mixed>> $sites
 * @var list<array<string, mixed>> $inventory the rows of the list of all sites (Fleet\Inventory), filtered
 * @var array<string, list<string>> $values what each filter column holds
 * @var array<string, string> $filters
 * @var list<array<string, mixed>> $groups the groups of Today (Fleet\Today::groups); empty on the list of all sites
 * @var int $total
 * @var int $needing
 * @var string $show today | all
 * @var string $query
 * @var string $latest the version this console runs
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
    : ($needing === 0 ? t('All %d sites are fine.', $total) : t('%d of %d sites need attention.', $needing, $total))) ?> <?= e(t('This console runs version %s.', $latest)) ?></p>
<form class="form" method="post" action="<?= e($module->url('pairing_key')) ?>">
<?= $csrf ?>
<p class="navigation-row"><button class="btn" type="submit"><?= e(t('Add a site')) ?></button>
<a class="navigation" href="<?= e($module->url('kit')) ?>"><?= e(t('Shared kit')) ?></a>
<?php if ($total > 0): ?>
<button class="navigation" type="submit" formaction="<?= e($module->url('check')) ?>"><?= e(t('Check all now')) ?></button>
<?php endif ?></p>
</form>
<?php if ($total > 0): ?>
<?= $app->view->render('admin/fleet/tabs', ['module' => $module, 'current' => $show, 'needing' => $needing, 'total' => $total]) ?>
<?php if ($show === 'today'): ?>
<form method="get" action="<?= e($app->url('admin.php')) ?>"><input type="hidden" name="module" value="fleet">
	<label><?= e(t('Name or address contains:')) ?> <input class="textfield" type="search" name="q" value="<?= e($query) ?>" size="24"></label> <input class="btn" type="submit" value="<?= e(t('Filter')) ?>"></form>
<?php if ($groups === [] || $groups[0]['key'] === 'accepted'): ?>
<p><?= e(t('Nothing needs attention.')) ?></p>
<?php endif ?>
<?= $app->view->render('admin/fleet/today', ['module' => $module, 'groups' => $groups, 'reasonLabels' => $reasonLabels, 'csrf' => $csrf, 'app' => $app]) ?>
<?php else: ?>
<?= $app->view->render('admin/fleet/inventory', ['module' => $module, 'app' => $app, 'rows' => $inventory, 'values' => $values, 'filters' => $filters, 'query' => $query, 'reasonLabels' => $reasonLabels, 'severity' => $severity]) ?>
<?php endif ?>
<?php endif ?>
<p class="small-text"><?= e(t('Sites report every hour; the console checks every 5 minutes that each home page answers. Problems reach you by the alert e-mail (System status → Alerts).')) ?></p>
