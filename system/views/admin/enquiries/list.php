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
 * @var string $kind the triage kind shown ('' = all but spam, '-' = not sorted yet; spam has its own tab)
 * @var int $spam enquiries marked as spam
 * @var int $inTrash enquiries in the trash
 * @var string $search
 * @var array<int, string> $users
 */
use Talea\Admin\Modules\Enquiries;

$pageCount = (int) ceil($total / $perPage);
$trash = $filter === 'trash';
$spamTab = $filter === 'spam';
$preview = function (string $data): string {
    $items = json_decode($data, true) ?: [];
    $text = implode(' · ', array_filter(array_map(fn (array $d): string => (string) $d[1], $items), fn (string $v): bool => $v !== '' && mb_strlen($v) > 1));

    return mb_strimwidth($text, 0, 140, '…');
};
echo $app->view->render('admin/list/head', ['count' => (string) $total,
    // retention and sorting live in Settings → Enquiries (administrators)
    'links' => $app->auth()->isAdmin() ? [[$module->url('personal'), t('Personal data request')], [$app->url('admin.php?module=settings&tab=enquiries'), t('Settings')]] : [],
    'secondary' => $total > 0 ? '<a class="navigation" href="' . e($module->url('csv')) . '">' . e(t('Export (CSV)')) . '</a>' : '']);
$kinds = '<select name="category" aria-label="' . e(t('Kind')) . '" data-submit-on-change>';
$kindNames = Talea\Core\Triage::names($app->settings());
foreach (array_diff_key(['' => t('All kinds'), '-' => t('Not sorted')] + $kindNames, ['spam' => 1]) as $key => $name) {
    $kinds .= '<option value="' . e($key) . '"' . ($kind === $key ? ' selected' : '') . '>' . e($name) . '</option>';
}
$kinds .= '</select>';
echo $app->view->render('admin/list/toolbar', ['tabsLabel' => t('Enquiry status'),
    'tabs' => [...array_map(fn (string $key, string $name): array => ['label' => t($name), 'url' => $module->url('', array_filter(['status' => $key, 'category' => $kind])), 'active' => $filter === $key],
        ['', 'open', 'mine', 'resolved'], ['All', 'To do', 'Mine', 'Resolved']),
        ['label' => $kindNames['spam'] ?? t('Spam'), 'count' => $spam, 'url' => $module->url('', ['status' => 'spam']), 'active' => $spamTab],
        ['label' => t('Trash'), 'count' => $inTrash, 'url' => $module->url('', ['status' => 'trash']), 'active' => $trash]],
    'action' => $app->url('admin.php'), 'hidden' => ['module' => 'enquiries'] + array_filter(['status' => $filter]), 'filters' => $trash || $spamTab ? '' : $kinds,
    'search' => ['name' => 'search', 'value' => $search, 'placeholder' => t('Name, e-mail or text'), 'label' => t('Search enquiries')]]);
?>
<?php if ($enquiries === [] && $trash && $search === ''): ?>
<?= $app->view->render('admin/empty', ['icon' => 'enquiries', 'heading' => t('The trash is empty.'), 'text' => t('Deleted enquiries stay here for 30 days, then they are deleted permanently with their attachments.'), 'action' => [$module->url(), t('Back to enquiries')]]) ?>
<?php elseif ($enquiries === [] && ($search !== '' || $filter !== '')): ?>
<?= $app->view->render('admin/empty', ['icon' => 'enquiries', 'heading' => t('No enquiry matches the filter.'), 'text' => t('Try another word or status.'), 'action' => [$module->url(), t('Clear filter')]]) ?>
<?php elseif ($enquiries === []): ?>
<?= $app->view->render('admin/empty', ['icon' => 'enquiries', 'heading' => t('No enquiries yet.'), 'text' => t('Add a Form element in the page builder (or the ready-made Enquiry form section) – submitted messages will appear here and arrive by email.'), 'action' => [$app->url('admin.php?module=pages'), t('Open pages')]]) ?>
<?php elseif ($trash): ?>
<p class="small-text"><?= e(t('A restored enquiry comes back as it was; after 30 days in the trash it is deleted permanently with its attachments.')) ?></p>
<div class="tab-wrap">
<table class="listing">
<thead><tr><th scope="col" class="cell-select"><input type="checkbox" data-select-all="bulk" form="bulk" aria-label="<?= e(t('Select all')) ?>"></th><th scope="col"><?= e(t('From')) ?></th><th scope="col"><?= e(t('Message')) ?></th><th scope="col"><?= e(t('In trash since')) ?></th><th scope="col"><span class="visually-hidden"><?= e(t('Actions')) ?></span></th></tr></thead>
<tbody>
<?php foreach ($enquiries as $p):
    $who = (string) ($p['email'] !== '' ? $p['email'] : $p['form']);
    $one = ['selected[]' => $p['public_id']];
?>
<tr>
	<td class="cell-select"><input type="checkbox" name="selected[]" value="<?= e($p['public_id']) ?>" form="bulk" aria-label="<?= e(t('Select %s', $who)) ?>"></td>
	<td><div class="cell-text"><span class="cell-title"><?= bdi($who) ?></span><span class="cell-sub"><?= bdi($p['form']) ?> · <?= e(format_date($p['created_at'], true)) ?></span></div></td>
	<td class="cell-main"><span class="cell-sub cell-preview"><?= bdi($preview((string) $p['data'])) ?></span></td>
	<td class="cell-weak"><?= e(format_date((string) $p['deleted_at'], true)) ?></td>
	<td class="cell-actions"><?= $app->view->render('admin/list/actions', ['id' => $p['public_id'], 'name' => $who, 'edit' => null, 'csrf' => $csrf,
        'quick' => ['action' => $module->url('restore'), 'fields' => $one, 'label' => t('Restore'), 'icon' => 'restore'],
        'menu' => [['post' => $module->url('delete_permanently'), 'fields' => $one, 'label' => t('Delete permanently'), 'confirm' => t('Delete the enquiry and its attachments permanently? This cannot be undone.'), 'danger' => true]]]) ?></td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<?= $app->view->render('admin/list/selection', ['form' => 'bulk', 'action' => $module->url('restore'), 'csrf' => $csrf, 'actions' => [
    ['label' => t('Restore'), 'kind' => 'primary'],
    ['label' => t('Delete permanently'), 'kind' => 'danger', 'formaction' => $module->url('delete_permanently'), 'confirm' => t('Delete the selected enquiries and their attachments permanently? This cannot be undone.')],
]]) ?>
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
	<td class="cell-main"><div class="cell-row"><?= $p['category'] !== '' ? '<span class="tag">' . e($kindNames[$p['category']] ?? $p['category']) . '</span>' : '' ?><?= (int) $p['priority'] === 3 ? '<span class="tag tag-warning">' . e(t('urgent')) . '</span>' : '' ?><a class="cell-sub cell-preview" href="<?= e($detail) ?>"><?= bdi($preview((string) $p['data'])) ?></a></div></td>
	<td><?= $app->view->render('admin/list/status', ['kind' => [0 => 'hidden', 1 => 'draft', 2 => 'live'][$status] ?? 'draft', 'label' => t(Enquiries::STATUSES[$status] ?? '')]) ?></td>
	<td class="cell-weak"><?= e(format_ago($p['created_at'])) ?></td>
	<td class="cell-actions"><?= $app->view->render('admin/list/actions', ['id' => $p['public_id'], 'name' => $who, 'edit' => null, 'csrf' => $csrf,
        'quick' => $status !== 2 ? ['action' => $module->url('bulk'), 'fields' => $one + ['bulk' => 'vyridit'], 'label' => t('Mark as resolved'), 'icon' => 'check'] : null,
        'menu' => [['url' => $detail, 'label' => t('Open'), 'icon' => 'eye'], '-',
            ['post' => $module->url('bulk'), 'fields' => $one + ['bulk' => 'delete'], 'label' => t('Move to trash'), 'confirm' => t('Move the enquiry to the trash? You can restore it for 30 days.'), 'danger' => true]]]) ?></td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<?= $app->view->render('admin/list/selection', ['form' => 'bulk', 'action' => $module->url('bulk'), 'csrf' => $csrf, 'actions' => [
    ['value' => 'vyridit', 'label' => t('Mark as resolved'), 'kind' => 'primary'],
    $spamTab ? ['value' => 'not_spam', 'label' => t('Not spam')] : ['value' => 'spam', 'label' => t('Mark as spam')],
    ['value' => 'delete', 'label' => t('Move to trash'), 'kind' => 'danger', 'confirm' => t('Move the selected enquiries to the trash?')],
]]) ?>
<?php endif ?>
<?php if ($pageCount > 1): ?>
<p class="pagination">
<?php for ($s = 1; $s <= $pageCount; $s++): ?>
	<?= $s === $pageNumber ? '<strong>[' . $s . ']</strong>' : '<a href="' . e($module->url('', array_filter(['status' => $filter]) + ['page' => $s])) . '">' . $s . '</a>' ?>
<?php endfor ?>
</p>
<?php endif ?>
