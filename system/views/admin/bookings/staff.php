<?php
/**
 * The people who take bookings (3.0) and the days off of everyone.
 *
 * @var Talea\Core\App $app
 * @var Talea\Admin\Modules\Bookings $module
 * @var string $csrf
 * @var list<array<string, mixed>> $staff
 * @var list<array<string, mixed>> $services
 * @var list<array<string, mixed>> $offs days off still to come (everyone's and personal)
 */
$names = array_column($services, 'name', 'id');
$staffNames = array_column($staff, 'name', 'id');
?>
<p class="navigation-row"><a class="navigation" href="<?= e($module->url()) ?>">← <?= e(t('All bookings')) ?></a> <a class="navigation" href="<?= e($module->url('services')) ?>"><?= e(t('Services')) ?></a></p>
<p><a class="btn" href="<?= e($module->url('staff_edit')) ?>"><?= e(t('New person')) ?></a></p>
<?php if ($staff === []): ?>
<p><?= e(t('Nobody takes bookings yet. Add the people (or just one entry for the whole business) and tick the services each offers.')) ?></p>
<?php else: ?>
<div class="tab-wrap"><table class="listing">
<thead><tr><th scope="col"><?= e(t('Person')) ?></th><th scope="col"><?= e(t('Services')) ?></th><th scope="col"><?= e(t('Status')) ?></th><th scope="col"><span class="visually-hidden"><?= e(t('Actions')) ?></span></th></tr></thead>
<tbody>
<?php foreach ($staff as $m): $editUrl = $module->url('staff_edit', ['id' => $m['public_id']]); ?>
<tr>
	<td class="cell-main"><div class="cell-row"><span class="cell-icon avatar" aria-hidden="true"><?= e(mb_strtoupper(mb_substr((string) $m['name'], 0, 2))) ?></span><div class="cell-text"><a class="cell-title" href="<?= e($editUrl) ?>"><?= e($m['name']) ?></a><span class="cell-sub"><?= $m['email'] !== '' ? e($m['email']) : e(t('the site e-mail')) ?></span></div></div></td>
	<td><?= $m['services'] === [] ? '<span class="status-note-warning">' . e(t('none yet')) . '</span>' : e(implode(', ', array_map(fn (int $id): string => (string) ($names[$id] ?? '–'), $m['services']))) ?></td>
	<td><?= $app->view->render('admin/list/status', $m['active'] ? ['kind' => 'live', 'label' => t('bookable')] : ['kind' => 'off', 'label' => t('switched off')]) ?></td>
	<td class="cell-actions"><?= $app->view->render('admin/list/actions', ['id' => $m['public_id'], 'name' => (string) $m['name'], 'edit' => $editUrl, 'csrf' => $csrf, 'menu' => [
        ['post' => $module->url('staff_delete'), 'fields' => ['id' => $m['public_id']], 'label' => t('Remove'), 'confirm' => t('Remove the person?'), 'danger' => true],
    ]]) ?></td>
</tr>
<?php endforeach ?>
</tbody></table></div>
<?php endif ?>
<h2><?= e(t('Days off')) ?></h2>
<p class="small-text"><?= e(t('Holidays and closed days of the whole business. A closed day in the opening hours exceptions (Business details) counts as a day off for everyone too; a person has their own days off on their form.')) ?></p>
<?php if ($offs !== []): ?>
<ul>
<?php foreach ($offs as $o): ?>
<li><?= e(format_date($o['from'], true)) ?> – <?= e(format_date($o['to'], true)) ?>: <?= e($o['staff_id'] === null ? t('everyone') : (string) ($staffNames[$o['staff_id']] ?? '–')) ?><?= $o['note'] !== '' ? ' (' . e($o['note']) . ')' : '' ?>
<form class="inline" method="post" action="<?= e($module->url('off_delete')) ?>"><?= $csrf ?><input type="hidden" name="id" value="<?= (int) $o['id'] ?>"><button class="navigation" type="submit"><?= e(t('Remove')) ?></button></form></li>
<?php endforeach ?>
</ul>
<?php endif ?>
<form class="form" method="post" action="<?= e($module->url('off_save')) ?>">
<?= $csrf ?><input type="hidden" name="staff_id" value="0">
<div class="row"><label for="off_from"><?= e(t('Everyone off from')) ?></label><div><input class="textfield" type="date" id="off_from" name="off_from" required> <label><?= e(t('to')) ?> <input class="textfield" type="date" name="off_to"></label> <label><?= e(t('Note')) ?> <input class="textfield" name="note" maxlength="150"></label> <input class="btn" type="submit" value="<?= e(t('Add')) ?>"></div></div>
</form>
