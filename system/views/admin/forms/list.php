<?php
/**
 * The forms of the site with their answers (Admin\Modules\Forms): the records, forms still written inside a draft, and
 * forms that are gone but have answers.
 *
 * @var Talea\Core\App $app
 * @var Talea\Admin\Modules\Forms $module
 * @var string $csrf
 * @var array<string, array<string, mixed>> $forms the forms the tab and the search let through
 * @var array<string, int> $counts per tab ('' = all, new, and each state)
 * @var string $shown
 * @var string $search
 */
use Talea\Admin\Modules\Forms;

$icon = require __DIR__ . '/../icons.php';
echo $app->view->render('admin/list/head', ['count' => (string) $counts[''], 'links' => [[$app->url('admin.php?module=enquiries'), t('Inbox')]], 'primary' => [$module->url('new'), t('New form')]]);
echo $app->view->render('admin/list/toolbar', ['tabsLabel' => t('Forms'),
    'tabs' => array_map(fn (string $key, string $name): array => ['label' => t($name), 'count' => $counts[$key], 'url' => $module->url('', array_filter(['status' => $key])), 'active' => $shown === $key],
        ['', 'new', 'draft', 'unplaced', 'removed'], ['All', 'With new answers', Forms::STATES['draft'], Forms::STATES['unplaced'], Forms::STATES['removed']]),
    'action' => $app->url('admin.php'), 'hidden' => ['module' => 'forms'] + array_filter(['status' => $shown]),
    'search' => ['name' => 'search', 'value' => $search, 'placeholder' => t('Form or page'), 'label' => t('Search forms')]]);
?>
<?php if ($forms === [] && ($search !== '' || $shown !== '')): ?>
<?= $app->view->render('admin/empty', ['icon' => 'b-contact', 'heading' => t('No form matches the filter.'), 'text' => t('Try another word or status.'), 'action' => [$module->url(), t('Clear filter')]]) ?>
<?php elseif ($forms === []): ?>
<?= $app->view->render('admin/empty', ['icon' => 'b-contact', 'heading' => t('No forms yet.'), 'text' => t('Create a form here, then place it on a page with the Form element – its answers appear here.'), 'action' => [$module->url('new'), t('New form')]]) ?>
<?php else: ?>
<div class="tab-wrap">
<table class="listing">
<thead><tr><th scope="col"><?= e(t('Form')) ?></th><th scope="col"><?= e(t('Placed on')) ?></th><th scope="col"><?= e(t('Answers')) ?></th><th scope="col"><?= e(t('Last answer')) ?></th><th scope="col"><?= e(t('Status')) ?></th><th scope="col"><span class="visually-hidden"><?= e(t('Actions')) ?></span></th></tr></thead>
<tbody>
<?php foreach ($forms as $f):
    // a removed form whose page is gone for good has no address left: its answers are still in the inbox, found by its name
    $answers = str_ends_with((string) ($f['query']['source'] ?? ''), ':') ? $app->url('admin.php?module=enquiries&search=' . rawurlencode($f['name'])) : $module->detailUrl($f);
    $name = $f['name'] !== '' ? $f['name'] : t('Form');
    $fields = count($f['fields']);
    $kind = ['live' => 'live', 'changes' => 'live', 'draft' => 'draft', 'unplaced' => 'hidden', 'written' => 'draft', 'removed' => 'off'][$f['state']];
    $notes = match ($f['state']) {
        'removed' => [['text' => t('The answers are kept'), 'kind' => 'neutral']],
        'changes' => [['text' => t('Changes not published'), 'url' => $f['edit']]],
        'written' => [['text' => t('Becomes a form when the page is published'), 'kind' => 'neutral']],
        default => [],
    };
?>
<tr<?= in_array($f['state'], ['draft', 'written'], true) ? ' class="unpublished"' : '' ?>>
	<td class="cell-main"><div class="cell-row"><?php if ($f['new'] > 0): ?><span class="unread-dot"><span class="visually-hidden"><?= e(t('New answers')) ?></span></span><?php endif ?><div class="cell-text"><a class="cell-title" href="<?= e($f['form_id'] > 0 ? $f['edit'] : $answers) ?>"><?= bdi($name) ?></a><?php if ($fields > 0): ?><span class="cell-sub"><?= e(t('%d fields', $fields)) ?></span><?php endif ?></div></div></td>
	<td><?php if ($f['where'] !== ''): ?><div class="cell-text"><span><?= bdi(mb_strimwidth($f['where'], 0, 90, '…')) ?></span><?php if ($f['kind'] !== ''): ?><span class="cell-sub"><?= e($f['kind']) ?></span><?php endif ?></div><?php else: ?><span class="cell-weak">–</span><?php endif ?></td>
	<td class="cell-time"><a href="<?= e($answers) ?>"><?= $f['total'] > 0 ? '<strong>' . $f['total'] . '</strong>' : '0' ?></a><?= $f['new'] > 0 ? ' <span class="cell-weak">' . e(t('%s new', (string) $f['new'])) . '</span>' : '' ?></td>
	<td class="cell-weak"><?= $f['last'] !== null ? e(format_ago($f['last'])) : '–' ?></td>
	<td><?= $app->view->render('admin/list/status', ['kind' => $kind, 'label' => t(Forms::STATES[$f['state']]), 'notes' => $notes]) ?></td>
	<td class="cell-actions"><?php if ($f['form_id'] > 0): ?><?= $app->view->render('admin/list/actions', ['id' => $f['query']['id'], 'name' => $name, 'edit' => $f['edit'], 'csrf' => $csrf, 'menu' => [
        ['url' => $answers, 'label' => t('Answers')],
        ...($f['deletable'] ? ['-', ['post' => $module->url('delete'), 'fields' => ['id' => $f['query']['id']], 'label' => t('Delete'), 'danger' => true, 'confirm' => t('Delete the form “%s”? Its answers stay in Enquiries.', $name)]] : []),
    ]]) ?><?php else: ?><div class="row-actions">
		<a class="icon-btn" href="<?= e($answers) ?>" aria-label="<?= e(t('Answers: %s', $name)) ?>" title="<?= e(t('Answers')) ?>"><?= $icon('enquiries') ?></a>
<?php if ($f['edit'] !== ''): ?>
		<a class="icon-btn" href="<?= e($f['edit']) ?>" aria-label="<?= e(t('Edit in the builder') . ': ' . $name) ?>" title="<?= e(t('Edit in the builder')) ?>"><?= $icon('edit') ?></a>
<?php endif ?>
	</div><?php endif ?></td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<p class="help"><?= e(t('A form is edited here and placed on pages with the Form element; a change shows wherever it is placed. A form placed on a page cannot be deleted – take it off the page first. Its answers stay in Enquiries.')) ?></p>
<?php endif ?>
