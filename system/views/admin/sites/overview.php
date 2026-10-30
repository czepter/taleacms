<?php
/**
 * Sites → Overview: the figures of every set-up site next to each other (Core\SiteFigures).
 *
 * @var Talea\Admin\Modules\Sites $module
 * @var Talea\Core\App $app
 * @var array{sites: list<array<string, mixed>>, totals: array<int, array<string, int|float|null>>} $figures
 * @var list<int> $periods
 */
echo $app->view->render('admin/list/head', ['count' => (string) count($figures['sites'])]);
echo $app->view->render('admin/list/toolbar', ['tabsLabel' => t('Sites'), 'tabs' => [['label' => t('Sites'), 'url' => $module->url(), 'active' => false], ['label' => t('Overview'), 'url' => $module->url('overview'), 'active' => true]]]);
$columns = ['visits' => 'Visitors', 'views' => 'Page views', 'enquiries' => 'Enquiries', 'bookings' => 'Bookings', 'signups' => 'Sign-ups', 'conversion' => 'Conversion'];
$cell = fn (array $row, string $key): string => $key === 'conversion' ? ($row[$key] === null ? '–' : e(format_number((float) $row[$key], 1)) . ' %') : e(format_number((int) $row[$key], 0));
?>
<p class="small-text"><?= e(t('The figures of each site come from its own statistics, enquiries, bookings and newsletter sign-ups. The conversion is the share of visitors who sent an enquiry or signed up for the newsletter.')) ?></p>
<?php foreach ($periods as $days): ?>
<h2><?= e(t('Last %d days', $days)) ?></h2>
<div class="tab-wrap"><table class="listing" data-overview-days="<?= (int) $days ?>">
<thead><tr><th scope="col"><?= e(t('Site')) ?></th><?php foreach ($columns as $label): ?><th scope="col" class="number"><?= e(t($label)) ?></th><?php endforeach ?></tr></thead>
<tbody>
<?php foreach ($figures['sites'] as $s): ?>
<tr>
	<td class="cell-main"><a class="cell-title" href="<?= e($module->url('edit', ['id' => $s['public_id']])) ?>"><?= bdi($s['name']) ?></a></td>
<?php foreach ($columns as $key => $label): ?>
	<td class="number"><?= $cell($s['periods'][$days], $key) ?></td>
<?php endforeach ?>
</tr>
<?php endforeach ?>
</tbody>
<tfoot><tr><th scope="row"><?= e(t('Total')) ?></th><?php foreach ($columns as $key => $label): ?><td class="number"><?= $cell($figures['totals'][$days], $key) ?></td><?php endforeach ?></tr></tfoot>
</table></div>
<?php endforeach ?>
