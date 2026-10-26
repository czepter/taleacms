<?php
/**
 * The answers of one form: its enquiries as a table whose columns are the form's fields (Admin\Modules\Forms).
 *
 * @var Talea\Core\App $app
 * @var Talea\Admin\Modules\Forms $module
 * @var array<string, mixed> $form
 * @var list<array<string, mixed>> $enquiries
 * @var array{total: int, new: int, open: int, recent: int} $totals
 * @var int $shownTotal
 * @var string $filter
 * @var string $search
 * @var int $pageNumber
 * @var int $perPage
 */
use Talea\Admin\Modules\Enquiries;
use Talea\Admin\Modules\Forms;
use Talea\Builder\Elements\Form;

$rows = array_map(fn (array $p): array => $p + ['values' => array_column(array_filter(json_decode((string) $p['data'], true) ?: [], 'is_array'), 1, 0)], $enquiries);
// the columns are the form's fields as it is now; a removed form shows the labels its answers carried
$columns = array_slice(array_values(array_unique($form['fields'] !== []
    ? array_map(fn (array $f): string => (string) ($f['label'] ?? ''), array_filter($form['fields'], fn (array $f): bool => ($f['type'] ?? '') !== 'checkbox'))
    : array_merge([], ...array_map(fn (array $r): array => array_map('strval', array_keys($r['values'])), $rows)))), 0, 6);
$pageCount = (int) ceil($shownTotal / $perPage);
echo $app->view->render('admin/list/head', ['count' => (string) $totals['total'], 'links' => [[$module->url(), t('All forms')]],
    'secondary' => $form['edit'] !== '' ? '<a class="navigation" href="' . e($form['edit']) . '">' . e(t('Edit in the builder')) . '</a>' : '']);
?>
<p class="small-text"><?= $app->view->render('admin/list/status', ['kind' => ['live' => 'live', 'draft' => 'draft', 'removed' => 'off'][$form['state']], 'label' => t(Forms::STATES[$form['state']])]) ?><?= $form['where'] !== '' ? ' · ' . e($form['kind']) . ': ' . bdi($form['where']) : '' ?></p>
<div class="tiles">
	<div class="tiles-item"><strong><?= $totals['total'] ?></strong><span><?= e(t('Answers')) ?></span></div>
	<div class="tiles-item"><strong><?= $totals['new'] ?></strong><span><?= e(t('New, not read yet')) ?></span></div>
	<div class="tiles-item"><strong><?= $totals['open'] ?></strong><span><?= e(t('To do')) ?></span></div>
	<div class="tiles-item"><strong><?= $totals['recent'] ?></strong><span><?= e(t('Last 30 days')) ?></span></div>
</div>
<?= $app->view->render('admin/list/toolbar', ['tabsLabel' => t('Enquiry status'),
    'tabs' => array_map(fn (string $key, string $name): array => ['label' => t($name), 'url' => $module->url('detail', ['source' => $form['public'], 'element' => $form['element']] + array_filter(['status' => $key])), 'active' => $filter === $key],
        ['', 'open', 'resolved'], ['All', 'To do', 'Resolved']),
    'action' => $app->url('admin.php'), 'hidden' => ['module' => 'forms', 'action' => 'detail', 'source' => $form['public'], 'element' => $form['element']] + array_filter(['status' => $filter]),
    'search' => ['name' => 'search', 'value' => $search, 'placeholder' => t('Name, e-mail or text'), 'label' => t('Search enquiries')]]) ?>
<?php if ($enquiries === []): ?>
<?= $app->view->render('admin/empty', ['icon' => 'enquiries', 'heading' => $search !== '' || $filter !== '' ? t('No enquiry matches the filter.') : t('No answers yet.'),
    'text' => $search !== '' || $filter !== '' ? t('Try another word or status.') : t('Answers to this form will appear here and arrive by email.')]) ?>
<?php else: ?>
<div class="tab-wrap">
<table class="listing">
<thead><tr><th scope="col"><?= e(t('Received')) ?></th><?php foreach ($columns as $label): ?><th scope="col"><span title="<?= e($label) ?>"><?= e(mb_strimwidth($label, 0, 40, '…')) ?></span></th><?php endforeach ?><th scope="col"><?= e(t('Page')) ?></th><th scope="col"><?= e(t('Status')) ?></th></tr></thead>
<tbody>
<?php foreach ($rows as $p): $status = (int) $p['status']; $detail = $app->url('admin.php?module=enquiries&action=detail&id=' . $p['public_id']); ?>
<tr<?= $status === 0 ? ' class="unread"' : '' ?>>
	<td><div class="cell-row"><?php if ($status === 0): ?><span class="unread-dot"><span class="visually-hidden"><?= e(t('New')) ?></span></span><?php endif ?><a class="cell-title" href="<?= e($detail) ?>"><?= e(format_ago($p['created_at'])) ?></a></div></td>
<?php foreach ($columns as $label): ?>
	<td><?= bdi(mb_strimwidth((string) ($p['values'][$label] ?? ''), 0, 80, '…')) ?></td>
<?php endforeach ?>
	<td class="cell-weak"><?= bdi((string) $p['page']) ?></td>
	<td><?= $app->view->render('admin/list/status', ['kind' => [0 => 'hidden', 1 => 'draft', 2 => 'live'][$status] ?? 'draft', 'label' => t(Enquiries::STATUSES[$status] ?? '')]) ?></td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<?php if ($pageCount > 1): ?>
<p class="pagination">
<?php for ($s = 1; $s <= $pageCount; $s++): ?>
	<?= $s === $pageNumber ? '<strong>[' . $s . ']</strong>' : '<a href="' . e($module->url('detail', ['source' => $form['public'], 'element' => $form['element']] + array_filter(['status' => $filter, 'search' => $search]) + ['page' => $s])) . '">' . $s . '</a>' ?>
<?php endfor ?>
</p>
<?php endif ?>
<?php endif ?>
<?php if ($form['fields'] !== []): ?>
<section class="panel">
<h2><?= e(t('Fields')) ?></h2>
<ul>
<?php foreach ($form['fields'] as $f): ?>
	<li><?= bdi((string) ($f['label'] ?? '')) ?> <span class="tag"><?= e(t(Form::FIELD_TYPES[$f['type'] ?? ''] ?? (string) ($f['type'] ?? ''))) ?><?= !empty($f['required']) ? ' · ' . e(t('Required')) : '' ?></span></li>
<?php endforeach ?>
</ul>
<p class="help"><?= e(t('The fields and recipients come from the published page. Change them in the builder.')) ?></p>
</section>
<?php endif ?>
