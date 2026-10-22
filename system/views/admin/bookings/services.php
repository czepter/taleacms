<?php
/**
 * Bookable services (3.0): the list; one service is edited on its own page (service_edit.php).
 *
 * @var Talea\Core\App $app
 * @var Talea\Admin\Modules\Bookings $module
 * @var string $csrf
 * @var list<array<string, mixed>> $services
 * @var list<array<string, mixed>> $staff
 */
$names = array_column($staff, 'name', 'id');
?>
<p class="navigation-row"><a class="navigation" href="<?= e($module->url()) ?>">← <?= e(t('All bookings')) ?></a> <a class="navigation" href="<?= e($module->url('staff')) ?>"><?= e(t('People')) ?></a></p>
<?php if ($services === []): ?>
<?= $app->view->render('admin/empty', ['icon' => 'booking', 'heading' => t('Add a service'), 'text' => t('No services yet. A service is what the visitor books, such as a haircut, a consultation or a tyre change, and how long it takes.'), 'action' => [$module->url('services', ['new' => 1]), t('New service')]]) ?>
<?php else: ?>
<p><a class="btn" href="<?= e($module->url('services', ['new' => 1])) ?>"><?= e(t('New service')) ?></a></p>
<div class="tab-wrap"><table class="listing">
<thead><tr><th scope="col"><?= e(t('Service')) ?></th><th scope="col"><?= e(t('Duration')) ?></th><th scope="col"><?= e(t('Price')) ?></th><th scope="col"><?= e(t('Offered by')) ?></th><th scope="col"><?= e(t('Status')) ?></th><th scope="col"><span class="visually-hidden"><?= e(t('Actions')) ?></span></th></tr></thead>
<tbody>
<?php foreach ($services as $s): $editUrl = $module->url('services', ['id' => $s['public_id']]); ?>
<tr>
	<td class="cell-main"><div class="cell-text"><span class="cell-row"><a class="cell-title" href="<?= e($editUrl) ?>"><?= e($s['name']) ?></a><?= $s['requires_confirmation'] ? ' <span class="tag">' . e(t('requires confirmation')) . '</span>' : '' ?></span><?= $s['description'] !== '' ? '<span class="cell-sub">' . e($s['description']) . '</span>' : '' ?></div></td>
	<td class="cell-weak"><?= e(t('%d min', $s['duration_min'])) ?><?= $s['buffer_min'] > 0 ? ' + ' . e(t('%d min', $s['buffer_min'])) : '' ?></td>
	<td><?= e($s['price_text']) ?></td>
	<td><?= $s['staff'] === [] ? '<span class="status-note-warning">' . e(t('nobody yet')) . '</span>' : e(implode(', ', array_map(fn (int $id): string => (string) ($names[$id] ?? '–'), $s['staff']))) ?></td>
	<td><?= $app->view->render('admin/list/status', $s['active'] ? ['kind' => 'live', 'label' => t('bookable')] : ['kind' => 'off', 'label' => t('switched off')]) ?></td>
	<td class="cell-actions"><?= $app->view->render('admin/list/actions', ['id' => $s['public_id'], 'name' => $s['name'], 'edit' => $editUrl, 'csrf' => $csrf, 'menu' => [
        ['post' => $module->url('service_delete'), 'fields' => ['id' => $s['public_id']], 'label' => t('Delete'), 'confirm' => t('Delete the service?'), 'danger' => true],
    ]]) ?></td>
</tr>
<?php endforeach ?>
</tbody></table></div>
<?php endif ?>
