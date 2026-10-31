<?php
/**
 * Cancel a course: who is told, the other courses to offer (up to 3) and the holder's message.
 *
 * @var Talea\Core\App $app
 * @var Talea\Admin\Modules\Collections $module
 * @var string $csrf
 * @var array<string, mixed> $k
 * @var array<string, mixed> $item
 * @var array{confirmed: int, waiting: int, waitlisted: int, mails: int} $affected  registrations that are cancelled, and how many of them have an address
 * @var list<array{id: string, name: string, info: string}> $offers  the other courses that are open for registration
 */
$back = ['id' => $k['public_id'], 'item' => $item['public_id']];
$total = $affected['confirmed'] + $affected['waiting'] + $affected['waitlisted'];
echo $app->view->render('admin/list/head', ['count' => null, 'links' => [[$module->url('registrations', $back), t('Back to the registrations')]]]);
?>
<div class="notice notice-warning" role="status"><p><?= e(t('%d registrations are cancelled: %d confirmed, %d requests, %d on the waiting list. %d of them get an e-mail. The course is hidden from the site. This cannot be undone.', $total, $affected['confirmed'], $affected['waiting'], $affected['waitlisted'], $affected['mails'])) ?></p></div>
<form method="post" action="<?= e($module->url('cancel_course')) ?>">
	<?= $csrf ?>
	<input type="hidden" name="id" value="<?= e($back['id']) ?>"><input type="hidden" name="item" value="<?= e($back['item']) ?>">
	<fieldset class="website-field">
		<legend class="website-caption"><?= e(t('Offer another course')) ?></legend>
		<span class="help"><?= e(t('Choose up to 3. The e-mail gets a personal link for each one: one click takes a seat there with the same number of people, or a place on its waiting list. Leave all unticked to offer nothing.')) ?></span>
<?php if ($offers === []): ?>
		<p class="small-text"><?= e(t('No other course is open for registration.')) ?></p>
<?php endif ?>
<?php foreach ($offers as $o): ?>
		<label><input type="checkbox" name="offer[]" value="<?= e($o['id']) ?>"> <strong><?= e($o['name']) ?></strong> <span class="cell-weak"><?= e($o['info']) ?></span></label>
<?php endforeach ?>
	</fieldset>
	<p class="help"><?= e(t('Visitors of the address of this course go to the first course you offer, or to the course list.')) ?></p>
	<div class="website-field"><label for="cancel-message"><?= e(t('Message')) ?></label><textarea class="textbox" id="cancel-message" name="message" rows="4" maxlength="2000" placeholder="<?= e(t('[Why the course does not take place]')) ?>"></textarea><span class="help"><?= e(t('Goes into the e-mail above the course links. Optional.')) ?></span></div>
	<p><button class="danger" type="submit" data-confirm="<?= e(t('Cancel this course? Everyone registered is told by e-mail and the course is hidden. This cannot be undone.')) ?>"><?= e($affected['mails'] > 0 ? t('Cancel the course and send %d e-mails', $affected['mails']) : t('Cancel the course')) ?></button></p>
</form>
