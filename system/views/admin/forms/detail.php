<?php
/**
 * The answers of one form: its enquiries as a table whose columns are the form's fields (Admin\Modules\Forms).
 *
 * @var Talea\Core\App $app
 * @var Talea\Admin\Modules\Forms $module
 * @var array<string, mixed> $form          query (the address parameters that name it), form_id (0 = not a record), name, fields, where, state, edit
 * @var list<array<string, mixed>> $enquiries
 * @var array{total: int, new: int, open: int, recent: int} $totals
 * @var int $shownTotal
 * @var int $days
 * @var list<array{step: int, title: string, reached: int}> $steps
 * @var ?string $drop
 * @var int $sent

 * @var string $csrf
 * @var bool $linkForm        the form opens only through a personal link (Core\FormLinks)
 * @var list<array<string, mixed>> $linkFields the fields of that form (what a link can fill in)
 * @var list<array<string, mixed>> $links      its links, newest first
 * @var bool $multiStep
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
    'secondary' => $form['edit'] !== '' ? '<a class="navigation" href="' . e($form['edit']) . '">' . e($form['form_id'] > 0 ? t('Edit form') : t('Edit in the builder')) . '</a>' : '']);
?>
<p class="small-text"><?= $app->view->render('admin/list/status', ['kind' => ['live' => 'live', 'changes' => 'live', 'draft' => 'draft', 'unplaced' => 'hidden', 'written' => 'draft', 'removed' => 'off'][$form['state']], 'label' => t(Forms::STATES[$form['state']])]) ?><?= $form['where'] !== '' ? ' · ' . e(t('Placed on')) . ': ' . bdi($form['where']) : '' ?></p>
<div class="tiles">
	<div class="tiles-item"><strong><?= $totals['total'] ?></strong><span><?= e(t('Answers')) ?></span></div>
	<div class="tiles-item"><strong><?= $totals['new'] ?></strong><span><?= e(t('New, not read yet')) ?></span></div>
	<div class="tiles-item"><strong><?= $totals['open'] ?></strong><span><?= e(t('To do')) ?></span></div>
	<div class="tiles-item"><strong><?= $totals['recent'] ?></strong><span><?= e(t('Last 30 days')) ?></span></div>
</div>
<?php if ($linkForm): $statusKinds = ['open' => 'live', 'used' => 'draft', 'expired' => 'off', 'revoked' => 'off']; ?>
<section aria-labelledby="form-link">
	<h2 id="form-link"><?= e(t('Personal links')) ?></h2>
	<details class="panel-collapsed"<?= $links === [] ? ' open' : '' ?>>
	<summary><h2><?= e(t('Create a link')) ?></h2></summary>
	<form class="form" method="post" action="<?= e($module->url('link')) ?>"><?= $csrf ?><input type="hidden" name="id" value="<?= e($form['query']['id'] ?? '') ?>">
		<div class="row"><label for="link-label"><?= e(t('Who is the link for?')) ?></label><div><input class="textfield wide" id="link-label" type="text" name="label" maxlength="190" required></div></div>
		<div class="row"><label for="link-email"><?= e(t('E-mail (optional)')) ?></label><div><input class="textfield wide" id="link-email" type="email" name="email" maxlength="190">
			<label><input type="checkbox" name="send" value="1"> <?= e(t('Send the link to this address now, with the form’s link e-mail')) ?></label></div></div>
		<h3><?= e(t('Fill in the fields')) ?></h3>
		<p class="help"><?= e(t('What you fill in here the person sees in the form and can change. It belongs to this link only – the form stays the same for everyone.')) ?></p>
<?= $app->view->render('admin/forms/prefill', ['fields' => $linkFields, 'values' => []]) ?>
		<p class="buttons"><button class="btn" type="submit"><?= e(t('Create link')) ?></button></p>
	</form>
	<p class="small-text"><?= e(t('The link works once and for the days the form says.')) ?></p>
	</details>
<?php if ($links !== []): ?>
	<div class="tab-wrap">
	<table class="listing">
	<thead><tr><th scope="col"><?= e(t('For')) ?></th><th scope="col"><?= e(t('Status')) ?></th><th scope="col"><?= e(t('Filled in')) ?></th><th scope="col"><?= e(t('Answered')) ?></th><th scope="col"><?= e(t('Works until')) ?></th></tr></thead>
	<tbody>
<?php foreach ($links as $l): $state = Talea\Core\FormLinks::status($l); $filled = Talea\Core\FormLinks::draft($l); $saves = count(array_filter(Talea\Core\FormLinks::drafts($l), fn (array $d): bool => ($d['by'] ?? '') === 'person'));
    $labels = array_column($linkFields, 'label', 'key'); ?>
	<tr>
		<td><a class="cell-title" href="<?= e($module->url('link_edit', ['link' => (string) $l['public_id']])) ?>"><?= bdi((string) $l['label']) ?></a></td>
		<td><?= $app->view->render('admin/list/status', ['kind' => $statusKinds[$state], 'label' => t(Talea\Core\FormLinks::STATUSES[$state])]) ?><?= $saves > 0 && $state === 'open' ? ' <span class="tag">' . e(t('%d drafts saved', $saves)) . '</span>' : '' ?></td>
		<td class="cell-weak"><?= $filled === [] ? '–' : bdi(mb_strimwidth(implode(' · ', array_map(fn (string $key, string|array $v): string => ($labels[$key] ?? $key) . ': ' . (is_array($v) ? implode(', ', $v) : $v), array_keys($filled), $filled)), 0, 120, '…')) ?></td>
		<td><?= $l['used_at'] !== null ? e(format_date((string) $l['used_at'], true)) : '–' ?></td>
		<td class="cell-weak"><?= e(format_date((string) $l['expires_at'])) ?></td>
	</tr>
<?php endforeach ?>
	</tbody>
	</table>
	</div>
<?php endif ?>
</section>
<?php endif ?>
<?php if ($multiStep): $base = max(1, $steps[0]['reached'] ?? 0); ?>
<section aria-labelledby="form-steps">
	<h2 id="form-steps"><?= e(t('Where visitors stop')) ?></h2>
	<form method="get" action="<?= e($app->url('admin.php')) ?>" class="toolbar">
		<input type="hidden" name="module" value="forms"><input type="hidden" name="action" value="detail"><?php foreach ($form['query'] as $name => $value): ?><input type="hidden" name="<?= e($name) ?>" value="<?= e($value) ?>"><?php endforeach ?>
		<label><?= e(t('Period')) ?> <select name="days" data-submit-on-change>
<?php foreach ([7 => t('Last 7 days'), 30 => t('Last 30 days'), 365 => t('Last 365 days')] as $value => $label): ?>
			<option value="<?= $value ?>"<?= $days === $value ? ' selected' : '' ?>><?= e($label) ?></option>
<?php endforeach ?>
		</select></label>
		<noscript><button type="submit"><?= e(t('Show')) ?></button></noscript>
	</form>
<?php if (($steps[0]['reached'] ?? 0) === 0): ?>
	<p class="small-text"><?= e(t('No visitor reached this form in this period.')) ?></p>
<?php endif ?>
	<ol class="effect">
<?php foreach ($steps as $s): $share = (int) round(100 * $s['reached'] / $base); ?>
		<li><?= bdi($s['title']) ?> <meter min="0" max="100" value="<?= $share ?>" aria-label="<?= e($s['title']) ?>"><?= $share ?> %</meter> <?= $share ?> % · <?= $s['reached'] ?></li>
<?php endforeach ?>
	</ol>
	<p class="small-text"><?= e(t('%d enquiries sent in this period.', $sent)) ?><?= $drop !== null ? ' ' . e($drop) : '' ?></p>
</section>
<?php endif ?>
<?= $app->view->render('admin/list/toolbar', ['tabsLabel' => t('Enquiry status'),
    'tabs' => array_map(fn (string $key, string $name): array => ['label' => t($name), 'url' => $module->url('detail', $form['query'] + array_filter(['status' => $key])), 'active' => $filter === $key],
        ['', 'open', 'resolved'], ['All', 'To do', 'Resolved']),
    'action' => $app->url('admin.php'), 'hidden' => ['module' => 'forms', 'action' => 'detail'] + $form['query'] + array_filter(['status' => $filter]),
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
	<?= $s === $pageNumber ? '<strong>[' . $s . ']</strong>' : '<a href="' . e($module->url('detail', $form['query'] + array_filter(['status' => $filter, 'search' => $search]) + ['page' => $s])) . '">' . $s . '</a>' ?>
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
<p class="help"><?= e($form['form_id'] > 0 ? t('The fields and recipients are the published form’s. Change them in the form.') : t('The fields and recipients come from the published page. Change them in the builder.')) ?></p>
</section>
<?php endif ?>
