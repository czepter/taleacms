<?php
/**
 * The forms of the site with their answers (Admin\Modules\Forms).
 *
 * @var Talea\Core\App $app
 * @var Talea\Admin\Modules\Forms $module
 * @var array<string, array<string, mixed>> $forms the forms the tab and the search let through
 * @var array<string, int> $counts per tab ('' = all, new, live, draft, removed)
 * @var string $shown
 * @var string $search
 */
use Talea\Admin\Modules\Forms;

$icon = require __DIR__ . '/../icons.php';
echo $app->view->render('admin/list/head', ['count' => (string) $counts[''], 'links' => [[$app->url('admin.php?module=enquiries'), t('Inbox')]]]);
echo $app->view->render('admin/list/toolbar', ['tabsLabel' => t('Forms'),
    'tabs' => array_map(fn (string $key, string $name): array => ['label' => t($name), 'count' => $counts[$key], 'url' => $module->url('', array_filter(['status' => $key])), 'active' => $shown === $key],
        ['', 'new', 'draft', 'removed'], ['All', 'With new answers', Forms::STATES['draft'], Forms::STATES['removed']]),
    'action' => $app->url('admin.php'), 'hidden' => ['module' => 'forms'] + array_filter(['status' => $shown]),
    'search' => ['name' => 'search', 'value' => $search, 'placeholder' => t('Form or page'), 'label' => t('Search forms')]]);
?>
<?php if ($forms === [] && ($search !== '' || $shown !== '')): ?>
<?= $app->view->render('admin/empty', ['icon' => 'b-contact', 'heading' => t('No form matches the filter.'), 'text' => t('Try another word or status.'), 'action' => [$module->url(), t('Clear filter')]]) ?>
<?php elseif ($forms === []): ?>
<?= $app->view->render('admin/empty', ['icon' => 'b-contact', 'heading' => t('No forms yet.'), 'text' => t('Add a Form element in the page builder (or the ready-made Enquiry form section) – it will appear here with its answers.'), 'action' => [$app->url('admin.php?module=pages'), t('Open pages')]]) ?>
<?php else: ?>
<div class="tab-wrap">
<table class="listing">
<thead><tr><th scope="col"><?= e(t('Form')) ?></th><th scope="col"><?= e(t('Where')) ?></th><th scope="col"><?= e(t('Answers')) ?></th><th scope="col"><?= e(t('Last answer')) ?></th><th scope="col"><?= e(t('Status')) ?></th><th scope="col"><span class="visually-hidden"><?= e(t('Actions')) ?></span></th></tr></thead>
<tbody>
<?php foreach ($forms as $f):
    // a removed form whose page is gone for good has no address left: its answers are still in the inbox, found by its name
    $answers = str_ends_with($f['public'], ':') ? $app->url('admin.php?module=enquiries&search=' . rawurlencode($f['name'])) : $module->detailUrl($f);
    $name = $f['name'] !== '' ? $f['name'] : t('Form');
    $fields = count($f['fields']);
?>
<tr<?= $f['state'] === 'draft' ? ' class="unpublished"' : '' ?>>
	<td class="cell-main"><div class="cell-row"><?php if ($f['new'] > 0): ?><span class="unread-dot"><span class="visually-hidden"><?= e(t('New answers')) ?></span></span><?php endif ?><div class="cell-text"><a class="cell-title" href="<?= e($answers) ?>"><?= bdi($name) ?></a><?php if ($fields > 0): ?><span class="cell-sub"><?= e(t('%d fields', $fields)) ?></span><?php endif ?></div></div></td>
	<td><?php if ($f['where'] !== ''): ?><div class="cell-text"><span><?= bdi($f['where']) ?></span><span class="cell-sub"><?= e($f['kind']) ?></span></div><?php else: ?><span class="cell-weak">–</span><?php endif ?></td>
	<td class="cell-time"><?= $f['total'] > 0 ? '<strong>' . $f['total'] . '</strong>' : '<span class="cell-weak">0</span>' ?><?= $f['new'] > 0 ? ' <span class="tag tag-warning">' . e(t('%s new', (string) $f['new'])) . '</span>' : '' ?></td>
	<td class="cell-weak"><?= $f['last'] !== null ? e(format_ago($f['last'])) : '–' ?></td>
	<td><?= $app->view->render('admin/list/status', ['kind' => ['live' => 'live', 'draft' => 'draft', 'removed' => 'off'][$f['state']], 'label' => t(Forms::STATES[$f['state']]),
        'notes' => $f['state'] === 'removed' ? [['text' => t('The answers are kept'), 'kind' => 'neutral']] : []]) ?></td>
	<td class="cell-actions"><div class="row-actions">
		<a class="icon-btn" href="<?= e($answers) ?>" aria-label="<?= e(t('Answers: %s', $name)) ?>" title="<?= e(t('Answers')) ?>"><?= $icon('enquiries') ?></a>
<?php if ($f['edit'] !== ''): ?>
		<a class="icon-btn" href="<?= e($f['edit']) ?>" aria-label="<?= e(t('Edit in the builder') . ': ' . $name) ?>" title="<?= e(t('Edit in the builder')) ?>"><?= $icon('edit') ?></a>
<?php endif ?>
	</div></td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<p class="help"><?= e(t('Forms are read from the published pages, site parts, pop-ups and item templates. Fields and recipients are changed in the builder.')) ?></p>
<?php endif ?>
