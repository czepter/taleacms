<?php
/**
 * The registrations of one course: seats, then the registrations by state with the actions that apply to each.
 *
 * @var Talea\Core\App $app
 * @var Talea\Admin\Modules\Collections $module
 * @var string $csrf
 * @var array<string, mixed> $k
 * @var array<string, mixed> $item
 * @var array{mode: string, waiting_list: bool, max_seats: int, capacity: int} $course
 * @var array{taken: int, requested: int, waitlisted: int} $counts
 * @var array<string, list<array<string, mixed>>> $rows  by state (Core\Registrations::list)
 * @var array<int, string> $names  enquiry id => the participant's name ('' = none given)
 * @var int $free  free seats (PHP_INT_MAX without a capacity)
 * @var list<array<string, mixed>> $confirmedShown
 * @var int $confirmedCount
 * @var bool $all  every confirmed registration is shown
 * @var int $noEmail  registrations of this course without an e-mail address
 */
$icon = require __DIR__ . '/../icons.php';
$back = ['id' => $k['public_id'], 'item' => $item['public_id']];
echo $app->view->render('admin/list/head', ['count' => null, 'links' => [[$module->url('item', $back), t('Edit course')]]]);
$capacity = (int) $course['capacity'];
$plural = fn (int $n): string => t($n === 1 ? '%d seat' : '%d seats', $n);

/** One person: the name (or the address), the address below. */
$who = function (array $r) use ($names): string {
    $name = $names[(int) $r['enquiry_id']] ?? '';
    $email = (string) $r['email'];

    return '<div class="cell-text"><span class="cell-title">' . bdi($name !== '' ? $name : ($email !== '' ? $email : t('Enquiry'))) . '</span>'
        . ($name !== '' && $email !== '' ? '<span class="cell-sub">' . bdi($email) . '</span>' : '') . '</div>';
};
/** A button that posts one decision; $message adds an optional text for the visitor in a <details>. */
$decide = function (array $r, string $do, string $label, string $confirm = '', bool $message = false, bool $disabled = false, string $class = 'navigation') use ($app, $module, $csrf, $back): string {
    $fields = $csrf . '<input type="hidden" name="id" value="' . e($back['id']) . '"><input type="hidden" name="item" value="' . e($back['item']) . '"><input type="hidden" name="registration" value="' . e($r['public_id']) . '">';
    $details = $message ? '<details class="registration-message"><summary>' . e(t('Add a message')) . '</summary><textarea name="message" rows="3" maxlength="2000" aria-label="' . e(t('Message to the visitor')) . '"></textarea></details>' : '';

    return '<form class="inline" method="post" action="' . e($module->url('registration')) . '">' . $fields . $details . '<button type="submit" class="' . e($class) . '" name="do" value="' . e($do) . '"'
        . ($confirm !== '' ? ' data-confirm="' . e($confirm) . '"' : '') . ($disabled ? ' disabled aria-describedby="noseat"' : '') . '>' . e($label) . '</button></form>';
};
$time = fn (array $r): string => e(format_date((string) $r['created_at'], true));
$head = '<thead><tr><th scope="col">' . e(t('Participant')) . '</th><th scope="col">' . e(t('Seats')) . '</th><th scope="col">' . e(t('Status')) . '</th><th scope="col">' . e(t('Registered')) . '</th><th scope="col"><span class="visually-hidden">' . e(t('Actions')) . '</span></th></tr></thead>';
$status = fn (string $state): string => $app->view->render('admin/list/status', ['kind' => Talea\Core\Registrations::stateBadge($state)[0], 'label' => Talea\Core\Registrations::stateBadge($state)[1]]);
?>
<p class="item-meta">
	<a class="item-back" href="<?= e($module->url('items', ['id' => $k['public_id']])) ?>"><?= $icon('prev') ?><?= e($k['name']) ?></a>
	<span class="status <?= $item['visible'] ? 'status-live' : 'status-hidden' ?>"><?= e($item['visible'] ? t('Published') : t('hidden')) ?></span>
</p>
<?php if ($noEmail > 0): ?>
<div class="notice notice-warning" role="status"><p><?= e(t('%d of these registrations have no e-mail address, so the visitor cannot be told about what you decide. Add an e-mail field to the registration form.', $noEmail)) ?></p></div>
<?php endif ?>
<div class="tiles">
	<div class="tiles-item"><strong><?= e($capacity > 0 ? t('%d of %d', $counts['taken'], $capacity) : (string) $counts['taken']) ?></strong><span><?= e(t('Seats taken')) ?></span></div>
	<div class="tiles-item"><strong><?= (int) $counts['requested'] ?></strong><span><?= e(t('Seats held by requests')) ?></span></div>
	<div class="tiles-item"><strong><?= (int) $counts['waitlisted'] ?></strong><span><?= e(t('On the waiting list')) ?></span></div>
	<div class="tiles-item"><strong><?= e($course['mode'] === 'confirm' ? t('Confirm first') : t('Instant')) ?></strong><span><?= e(t('Registration · up to %d seats each', $course['max_seats'])) ?></span></div>
</div>
<?php if ($rows['waiting'] !== []): ?>
<div class="notice notice-actions"><p><?= e(t('%d requests are waiting for you. They hold %d seats until you confirm or decline them.', count($rows['waiting']), $counts['requested'])) ?></p></div>

<section>
	<h2><?= e(t('Waiting for confirmation')) ?></h2>
	<div class="tab-wrap"><table class="listing">
		<?= $head ?>
		<tbody>
<?php foreach ($rows['waiting'] as $r): ?>
		<tr><td class="cell-main"><?= $who($r) ?></td><td class="cell-weak"><?= e($plural((int) $r['seats'])) ?></td><td><?= $status('waiting') ?></td><td class="cell-weak cell-time"><?= $time($r) ?></td>
			<td class="cell-actions"><div class="row-actions"><?= $decide($r, 'confirm', t('Confirm'), '', false, false, 'btn') ?><?= $decide($r, 'decline', t('Decline'), t('Decline this request? The visitor gets an e-mail.'), true, false, 'navigation danger') ?></div></td></tr>
<?php endforeach ?>
		</tbody>
	</table></div>
</section>
<?php endif ?>

<section>
	<h2><?= e(t('Confirmed')) ?> <span class="cell-weak"><?= e($plural(array_sum(array_map(fn (array $r): int => (int) $r['seats'], $rows['confirmed'])))) ?></span></h2>
<?php if ($rows['confirmed'] === []): ?>
	<p class="small-text"><?= e(t('Nobody is registered yet.')) ?></p>
<?php else: ?>
	<div class="tab-wrap"><table class="listing">
		<?= $head ?>
		<tbody>
<?php foreach ($confirmedShown as $r): ?>
		<tr><td class="cell-main"><?= $who($r) ?></td><td class="cell-weak"><?= e($plural((int) $r['seats'])) ?></td><td><?= $status('confirmed') ?></td><td class="cell-weak cell-time"><?= $time($r) ?></td>
			<td class="cell-actions"><div class="row-actions"><?= $decide($r, 'cancel', t('Cancel'), t('Cancel this registration? The seats go to the waiting list.'), true, false, 'navigation danger') ?></div></td></tr>
<?php endforeach ?>
		</tbody>
	</table></div>
<?php if (!$all && $confirmedCount > count($confirmedShown)): ?>
	<a href="<?= e($module->url('registrations', $back + ['all' => 1])) ?>"><?= e(t('Show all %d confirmed registrations', $confirmedCount)) ?></a>
<?php endif ?>
<?php endif ?>
</section>

<?php if ($rows['waitlisted'] !== [] || $course['waiting_list']): ?>
<section>
	<h2><?= e(t('Waiting list')) ?></h2>
<?php if ($rows['waitlisted'] === []): ?>
	<p class="small-text"><?= e($course['waiting_list'] ? t('Nobody is on the waiting list.') : t('The waiting list is off.')) ?></p>
<?php else: ?>
	<p class="small-text"><?= e(t('In the order they registered. When a seat frees up, the first one that fits moves up.')) ?></p>
	<div class="tab-wrap"><table class="listing">
		<thead><tr><th scope="col">#</th><th scope="col"><?= e(t('Participant')) ?></th><th scope="col"><?= e(t('Seats')) ?></th><th scope="col"><?= e(t('Joined the list')) ?></th><th scope="col"><span class="visually-hidden"><?= e(t('Actions')) ?></span></th></tr></thead>
		<tbody>
<?php $anyBlocked = false; foreach ($rows['waitlisted'] as $n => $r): $blocked = (int) $r['seats'] > $free; $anyBlocked = $anyBlocked || $blocked; ?>
		<tr><td class="cell-weak"><?= $n + 1 ?></td><td class="cell-main"><?= $who($r) ?></td><td class="cell-weak"><?= e($plural((int) $r['seats'])) ?></td><td class="cell-weak cell-time"><?= $time($r) ?></td>
			<td class="cell-actions"><div class="row-actions"><?= $decide($r, 'promote', t('Move up'), '', false, $blocked) ?><?= $decide($r, 'cancel', t('Remove'), t('Remove from the waiting list? The visitor gets an e-mail.'), true) ?></div></td></tr>
<?php endforeach ?>
		</tbody>
	</table></div>
<?php if ($anyBlocked): ?>
	<p id="noseat" class="small-text"><?= e(t('No free seat. Cancel or decline a registration, or raise the capacity, to move someone up.')) ?></p>
<?php endif ?>
<?php endif ?>
</section>
<?php endif ?>

<?php if ($rows['declined'] !== [] || $rows['cancelled'] !== []): ?>
<details class="tab-wrap">
	<summary><?= e(t('Declined and cancelled')) ?> <span class="cell-weak"><?= count($rows['declined']) + count($rows['cancelled']) ?></span></summary>
	<p class="small-text"><?= e(t('These hold no seat. They stay here until the enquiry is deleted.')) ?></p>
	<table class="listing">
		<?= $head ?>
		<tbody>
<?php foreach (array_merge($rows['declined'], $rows['cancelled']) as $r): ?>
		<tr><td class="cell-main"><?= $who($r) ?></td><td class="cell-weak"><?= e($plural((int) $r['seats'])) ?></td><td><?= $status((string) $r['seat_state']) ?></td><td class="cell-weak cell-time"><?= $time($r) ?></td><td></td></tr>
<?php endforeach ?>
		</tbody>
	</table>
</details>
<?php endif ?>
