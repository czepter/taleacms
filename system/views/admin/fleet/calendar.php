<?php
/**
 * The calendar of the fleet console: when the certificates and domain registrations of the sites end (Fleet\Expiry).
 *
 * @var Talea\Admin\Modules\Fleet $module
 * @var array{year: int, month: int, previous: string, next: string, weeks: list<list<array{day: ?string, entries: list<array<string, mixed>>}>>} $calendar
 * @var list<array{site: array<string, mixed>, kind: string, date: string, days: int}> $dates
 * @var int $now
 * @var array{needing: int, total: int} $counts
 */
use Talea\Core\Hours;
use Talea\Fleet\Expiry;

$months = [1 => 'January', 'February', 'March', 'April', 'May', 'June', 'July', 'August', 'September', 'October', 'November', 'December'];
$kinds = ['certificate' => t('certificate'), 'domain' => t('domain registration')];
$name = fn (array $site): string => (string) $site['name'] !== '' ? (string) $site['name'] : (string) $site['url'];
?>
<?= $app->view->render('admin/fleet/tabs', ['module' => $module, 'current' => 'calendar', 'needing' => $counts['needing'], 'total' => $counts['total']]) ?>
<p class="navigation-row"><a class="navigation" href="<?= e($module->url('calendar', ['month' => $calendar['previous']])) ?>">← <?= e(t('previous month')) ?></a>
<strong><?= e(t($months[$calendar['month']]) . ' ' . $calendar['year']) ?></strong>
<a class="navigation" href="<?= e($module->url('calendar', ['month' => $calendar['next']])) ?>"><?= e(t('next month')) ?> →</a></p>
<div class="tab-wrap"><table class="listing fleet-month">
<thead><tr><?php foreach (Hours::DAYS as $day): ?><th scope="col"><?= e(mb_substr(t($day), 0, 2)) ?></th><?php endforeach ?></tr></thead>
<tbody>
<?php foreach ($calendar['weeks'] as $week): ?>
<tr>
<?php foreach ($week as $cell): ?>
	<td><?php if ($cell['day'] !== null): ?><span class="fleet-day"><?= (int) substr($cell['day'], 8, 2) ?></span>
<?php foreach ($cell['entries'] as $d): ?>
		<a class="badge badge-<?= Expiry::severity($d['kind'], $d['date'], $now) === 'error' || $d['days'] < 0 ? 'error' : 'draft' ?>" href="<?= e($module->url('detail', ['id' => $d['site']['public_id']])) ?>"><?= e($name($d['site']) . ' · ' . $kinds[$d['kind']]) ?></a>
<?php endforeach ?>
<?php endif ?></td>
<?php endforeach ?>
</tr>
<?php endforeach ?>
</tbody></table></div>
<h2><?= e(t('All dates')) ?></h2>
<?php if ($dates === []): ?>
<p><?= e(t('No certificate or domain date is known yet.')) ?></p>
<?php else: ?>
<div class="tab-wrap"><table class="listing">
<tbody>
<?php foreach ($dates as $d): ?>
<tr>
	<td><?= e(format_date(new DateTimeImmutable($d['date']))) ?></td>
	<td><?= e($d['days'] >= 0 ? t('in %d days', $d['days']) : t('%d days ago', -$d['days'])) ?></td>
	<td><span class="badge<?= Expiry::severity($d['kind'], $d['date'], $now) === null ? '' : (Expiry::severity($d['kind'], $d['date'], $now) === 'error' ? ' badge-error' : ' badge-draft') ?>"><?= e($kinds[$d['kind']]) ?></span></td>
	<td><a href="<?= e($module->url('detail', ['id' => $d['site']['public_id']])) ?>"><?= e($name($d['site'])) ?></a></td>
</tr>
<?php endforeach ?>
</tbody></table></div>
<?php endif ?>
<p class="small-text"><?= e(t('Reminders go by the alert e-mail and wait in the bell: certificates 14 and 7 days before, domain registrations 30 and 7 days before. The certificate date comes from the uptime check; enter a domain registration on the site\'s page.')) ?></p>
