<?php
/**
 * The list of all sites as an inventory: what each runs, with a filter per column and a CSV download (Fleet\Inventory).
 *
 * @var Talea\Admin\Modules\Fleet $module
 * @var list<array<string, mixed>> $rows Inventory::row() results, filtered
 * @var array<string, list<string>> $values what each filter column holds across the fleet
 * @var array<string, string> $filters
 * @var string $query
 * @var array<string, string> $reasonLabels
 * @var callable(string): string $severity the badge class of a reason
 */
use Talea\Fleet\Inventory;

$active = array_filter($filters, fn (string $v): bool => $v !== '') + ($query !== '' ? ['q' => $query] : []);
$date = fn (?string $d): string => $d !== null && $d !== '' ? e(format_date(substr($d, 0, 10))) : '–';
?>
<form method="get" action="<?= e($app->url('admin.php')) ?>"><input type="hidden" name="module" value="fleet"><input type="hidden" name="show" value="all">
<p class="navigation-row">
<?php foreach (Inventory::COLUMNS as $key => $label): ?>
	<label><?= e(t($label)) ?> <select name="<?= e($key) ?>"><option value=""><?= e(t('any')) ?></option>
<?php foreach ($values[$key] as $value): ?>
		<option value="<?= e($value) ?>"<?= ($filters[$key] ?? '') === $value ? ' selected' : '' ?>><?= e($value) ?></option>
<?php endforeach ?>
	</select></label>
<?php endforeach ?>
	<label><?= e(t('Name or address contains:')) ?> <input class="textfield" type="search" name="q" value="<?= e($query) ?>" size="24"></label>
	<input class="btn" type="submit" value="<?= e(t('Filter')) ?>">
	<a class="navigation" href="<?= e($module->url('inventory_csv', $active)) ?>"><?= e(t('Download CSV')) ?></a></p>
</form>
<?php if ($rows === []): ?>
<p><?= e(t('No site matches the filter.')) ?> <a href="<?= e($module->url('', ['show' => 'all'])) ?>"><?= e(t('Show all sites')) ?></a></p>
<?php else: ?>
<div class="tab-wrap"><table class="listing">
<thead><tr><th scope="col"><?= e(t('Site')) ?></th><th scope="col"><?= e(t('What needs attention')) ?></th><th scope="col"><?= e(t('Version')) ?></th><th scope="col"><?= e(t('PHP')) ?></th><th scope="col"><?= e(t('Database')) ?></th><th scope="col"><?= e(t('Web server')) ?></th><th scope="col"><?= e(t('Features')) ?></th><th scope="col"><?= e(t('Languages')) ?></th><th scope="col"><?= e(t('Certificate ends')) ?></th><th scope="col"><?= e(t('Domain registration ends')) ?></th><th scope="col"><?= e(t('Last report')) ?></th><th scope="col"><?= e(t('Enquiries waiting')) ?></th></tr></thead>
<tbody>
<?php foreach ($rows as $r): ?>
<tr>
	<td><a href="<?= e($module->url('detail', ['id' => $r['public_id']])) ?>"><strong><?= e($r['name'] !== '' ? $r['name'] : $r['url']) ?></strong></a><br><span class="small-text"><?= e($r['url']) ?></span><br>
		<a class="small-text" href="<?= e($r['url'] . '/admin.php') ?>" target="_blank" rel="noopener"><?= e(t('Administration of the site')) ?></a></td>
	<td><?php if ($r['attention'] === [] && $r['accepted'] === []): ?><span class="badge badge-published"><?= e(t('ok')) ?></span><?php endif ?><?php foreach ($r['attention'] as $reason): ?><span class="badge<?= $severity($reason) !== '' ? ' badge-' . $severity($reason) : '' ?>"><?= e($reasonLabels[$reason]) ?></span> <?php endforeach ?><?php if ($r['accepted'] !== []): ?><span class="badge"><?= e(t('accepted')) ?></span><?php endif ?></td>
	<td><?= e($r['version'] !== '' ? $r['version'] : '–') ?></td>
	<td><?= e($r['php'] !== '' ? $r['php'] : '–') ?></td>
	<td><?= e($r['database'] !== '' ? $r['database'] : '–') ?></td>
	<td><?= e($r['web_server'] !== '' ? $r['web_server'] : '–') ?></td>
	<td><span class="small-text"><?= $r['features'] === [] ? '–' : e(implode(', ', $r['features'])) ?></span></td>
	<td><?= $r['languages'] === [] ? '–' : e(implode(', ', $r['languages'])) ?></td>
	<td><?= $date($r['certificate_expires']) ?></td>
	<td><?= $date($r['domain_expires']) ?></td>
	<td><?= $r['last_report'] !== null ? e(format_date(new DateTimeImmutable((string) $r['last_report']), true)) : '–' ?></td>
	<td class="center"><?= $r['enquiries_waiting'] !== null ? (int) $r['enquiries_waiting'] : '–' ?></td>
</tr>
<?php endforeach ?>
</tbody></table></div>
<?php endif ?>
