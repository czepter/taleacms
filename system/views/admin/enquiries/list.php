<?php
/**
 * Enquiries from the site's forms.
 *
 * @var Talea\Core\App $app
 * @var Talea\Admin\Modules\Enquiries $module
 * @var string $csrf
 * @var list<array<string, mixed>> $enquiries
 * @var int $total
 * @var string $filter
 * @var int $pageNumber
 * @var int $perPage
 * @var int $months
 * @var string $expiry delete | anonymise – what happens after the retention period (2.14)
 * @var int $applicationMonths retention of applications to job openings (2.11)
 * @var array{0: string, 1: int}|null $suggestion [country, months] usually kept there
 * @var string $kind the triage kind shown ('' = all but spam, '-' = not sorted yet)
 * @var int $spam enquiries marked as spam
 * @var string $search
 * @var array<int, string> $users
 */
use Talea\Admin\Modules\Enquiries;

$pageCount = (int) ceil($total / $perPage);
$preview = function (string $data): string {
    $items = json_decode($data, true) ?: [];
    $text = implode(' · ', array_filter(array_map(fn (array $d): string => (string) $d[1], $items), fn (string $v): bool => $v !== '' && mb_strlen($v) > 1));

    return mb_strimwidth($text, 0, 140, '…');
};
echo $app->view->render('admin/list/head', ['count' => (string) $total,
    'links' => $app->auth()->isAdmin() ? [[$module->url('personal'), t('Personal data request')]] : [],
    'secondary' => $total > 0 ? '<a class="navigation" href="' . e($module->url('csv')) . '">' . e(t('Export (CSV)')) . '</a>' : '']);
$kinds = '<select name="category" aria-label="' . e(t('Kind')) . '" data-submit-on-change>';
foreach (['' => t('All kinds'), '-' => t('Not sorted')] + array_map('t', Talea\Core\Triage::CATEGORIES) as $key => $name) {
    $kinds .= '<option value="' . e($key) . '"' . ($kind === $key ? ' selected' : '') . '>' . e($name) . ($key === 'spam' && $spam > 0 ? ' (' . $spam . ')' : '') . '</option>';
}
$kinds .= '</select>';
echo $app->view->render('admin/list/toolbar', ['tabsLabel' => t('Enquiry status'),
    'tabs' => array_map(fn (string $key, string $name): array => ['label' => t($name), 'url' => $module->url('', array_filter(['status' => $key, 'category' => $kind])), 'active' => $filter === $key],
        ['', 'open', 'mine', 'resolved'], ['All', 'To do', 'Mine', 'Resolved']),
    'action' => $app->url('admin.php'), 'hidden' => ['module' => 'enquiries'] + array_filter(['status' => $filter]), 'filters' => $kinds,
    'search' => ['name' => 'search', 'value' => $search, 'placeholder' => t('Name, e-mail or text'), 'label' => t('Search enquiries')]]);
?>
<?php if ($enquiries === [] && ($search !== '' || $filter !== '')): ?>
<?= $app->view->render('admin/empty', ['icon' => 'enquiries', 'heading' => t('No enquiry matches the filter.'), 'text' => t('Try another word or status.'), 'action' => [$module->url(), t('Clear filter')]]) ?>
<?php elseif ($enquiries === []): ?>
<?= $app->view->render('admin/empty', ['icon' => 'enquiries', 'heading' => t('No enquiries yet.'), 'text' => t('Add a Form element in the page builder (or the ready-made Enquiry form section) – submitted messages will appear here and arrive by email.'), 'action' => [$app->url('admin.php?module=pages'), t('Open pages')]]) ?>
<?php else: ?>
<div class="tab-wrap">
<table class="listing">
<thead><tr><th scope="col" class="cell-select"><input type="checkbox" data-select-all="bulk" form="bulk" aria-label="<?= e(t('Select all')) ?>"></th><th scope="col"><?= e(t('From')) ?></th><th scope="col"><?= e(t('Message')) ?></th><th scope="col"><?= e(t('Status')) ?></th><th scope="col"><?= e(t('Received')) ?></th><th scope="col"><span class="visually-hidden"><?= e(t('Actions')) ?></span></th></tr></thead>
<tbody>
<?php foreach ($enquiries as $p):
    $detail = $module->url('detail', ['id' => $p['public_id']]);
    $status = (int) $p['status'];
    $who = (string) ($p['email'] !== '' ? $p['email'] : $p['form']);
    // the form, the topic with a link to the page it came from (2.12) and who handles it
    // a page from an import (MCP migration) is stored as given: only a path on this site becomes a link
    $page = (string) ($p['page'] ?? '');
    $topic = ($p['topic'] ?? '') === '' ? '' : e(t('Topic')) . ': ' . (preg_match('#^/(?!/)[^\s\\\\]*$#', $page) ? '<a href="' . e($page) . '" target="_blank" rel="noopener">' . bdi($p['topic']) . '</a>' : bdi($p['topic']));
    $sub = array_filter([bdi($p['form']), $topic,
        $p['assigned_to'] && isset($users[(int) $p['assigned_to']]) ? '→ ' . bdi($users[(int) $p['assigned_to']]) : '']);
    $one = ['selected[]' => $p['public_id']];
?>
<tr<?= $status === 0 ? ' class="unread"' : '' ?>>
	<td class="cell-select"><input type="checkbox" name="selected[]" value="<?= e($p['public_id']) ?>" form="bulk" aria-label="<?= e(t('Select %s', $who)) ?>"></td>
	<td><div class="cell-row"><?php if ($status === 0): ?><span class="unread-dot"><span class="visually-hidden"><?= e(t('New')) ?></span></span><?php endif ?><div class="cell-text"><a class="cell-title" href="<?= e($detail) ?>"><?= bdi($who) ?></a><span class="cell-sub"><?= implode(' · ', $sub) ?></span></div></div></td>
	<td class="cell-main"><div class="cell-row"><?= $p['category'] !== '' ? '<span class="tag">' . e(t(Talea\Core\Triage::CATEGORIES[$p['category']] ?? $p['category'])) . '</span>' : '' ?><?= (int) $p['priority'] === 3 ? '<span class="tag tag-warning">' . e(t('urgent')) . '</span>' : '' ?><a class="cell-sub cell-preview" href="<?= e($detail) ?>"><?= bdi($preview((string) $p['data'])) ?></a></div></td>
	<td><?= $app->view->render('admin/list/status', ['kind' => [0 => 'hidden', 1 => 'draft', 2 => 'live'][$status] ?? 'draft', 'label' => t(Enquiries::STATUSES[$status] ?? '')]) ?></td>
	<td class="cell-weak"><?= e(format_ago($p['created_at'])) ?></td>
	<td class="cell-actions"><?= $app->view->render('admin/list/actions', ['id' => $p['public_id'], 'name' => $who, 'edit' => null, 'csrf' => $csrf,
        'quick' => $status !== 2 ? ['action' => $module->url('bulk'), 'fields' => $one + ['bulk' => 'vyridit'], 'label' => t('Mark as resolved'), 'icon' => 'check'] : null,
        'menu' => [['url' => $detail, 'label' => t('Open'), 'icon' => 'eye'], '-',
            ['post' => $module->url('bulk'), 'fields' => $one + ['bulk' => 'delete'], 'label' => t('Delete'), 'confirm' => t('Delete the enquiry including attachments? This cannot be undone.'), 'danger' => true]]]) ?></td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<?= $app->view->render('admin/list/selection', ['form' => 'bulk', 'action' => $module->url('bulk'), 'csrf' => $csrf, 'actions' => [
    ['value' => 'vyridit', 'label' => t('Mark as resolved'), 'kind' => 'primary'],
    ['value' => 'delete', 'label' => t('Delete'), 'kind' => 'danger', 'confirm' => t('Delete the selected enquiries including attachments? This cannot be undone.')],
]]) ?>
<?php if ($pageCount > 1): ?>
<p class="pagination">
<?php for ($s = 1; $s <= $pageCount; $s++): ?>
	<?= $s === $pageNumber ? '<strong>[' . $s . ']</strong>' : '<a href="' . e($module->url('', array_filter(['status' => $filter]) + ['page' => $s])) . '">' . $s . '</a>' ?>
<?php endfor ?>
</p>
<?php endif ?>
<?php endif ?>
<?php if ($app->auth()->isAdmin()): ?>
<form class="form" method="post" action="<?= e($module->url('settings')) ?>" data-confirm="<?= e(t('Enquiries older than the given number of months will be permanently deleted right away – including attachments. Save anyway?')) ?>">
<?= $csrf ?>
<div class="row"><label for="months"><?= e(t('Delete enquiries older than')) ?></label><div><input class="textfield" type="number" id="months" name="months" value="<?= $months ?>" min="0" max="120" size="4"> <?= e(t('months')) ?>
<span class="help"><?= e(t('Enquiries contain personal data – they should not be kept longer than necessary. 0 = keep forever.')) ?></span></div></div>
<div class="row"><span class="caption"><?= e(t('After that period')) ?></span><div class="options">
<?php foreach (['delete' => 'delete them including attachments', 'anonymise' => 'anonymise them – the row stays for statistics (date, form, page, topic, kind) without the name, e-mail, phone, message and attachments'] as $key => $labelText): ?>
	<label><input type="radio" name="after_expiry" value="<?= $key ?>"<?= $expiry === $key ? ' checked' : '' ?>> <?= e(t($labelText)) ?></label>
<?php endforeach ?>
</div></div>
<div class="row"><label for="months-applicants"><?= e(t('Delete job applications after')) ?></label><div><input class="textfield" type="number" id="months-applicants" name="applicant_months" value="<?= $applicationMonths ?>" min="0" max="120" size="4"> <?= e(t('months')) ?> <input class="btn" type="submit" value="<?= e(t('Save')) ?>">
<span class="help"><?= e(t('Applications sent from the pages of a Job openings collection carry CVs and are usually kept only for a limited time after the selection. 0 = like other enquiries.')) ?><?= $suggestion !== null ? ' ' . e(t('Usual practice in %s: %d months – check with your lawyer.', $suggestion[0], $suggestion[1])) : '' ?></span></div></div>
<div class="row"><span></span><div><label><input type="checkbox" name="triage_assistant" value="1"<?= $app->settings()->bool('triage_assistant') ? ' checked' : '' ?>> <?= e(t('Sort new enquiries with the writing assistant')) ?></label>
<span class="help"><?= e(t('The assistant suggests the kind, the priority and a reply; the text of each enquiry is then sent to the AI provider chosen in Features – mention it in your privacy policy. Claude can sort enquiries over its connection without this.')) ?></span></div></div>
</form>
<?php endif ?>
