<?php
/**
 * A manual booking (3.0): a customer who phoned or walked in. The same rules as on the site except the lead time.
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\Bookings $module
 * @var string $csrf
 * @var list<array<string, mixed>> $services
 * @var list<array<string, mixed>> $staff
 * @var array<string, mixed> $old what was typed before an error
 */
?>
<p class="navigation-row"><a class="navigation" href="<?= e($module->url()) ?>">← <?= e(t('All bookings')) ?></a></p>
<form class="form" method="post" action="<?= e($module->url('create')) ?>">
<?= $csrf ?>
<fieldset><legend><?= e(t('Appointment')) ?></legend>
<div class="row"><label for="service"><?= e(t('Service')) ?></label><div><select id="service" name="service" required><option value=""><?= e(t('— choose —')) ?></option>
<?php foreach ($services as $s): ?><option value="<?= (int) $s['id'] ?>"<?= (int) ($old['service'] ?? 0) === $s['id'] ? ' selected' : '' ?>><?= e($s['name']) ?> (<?= e(t('%d min', $s['duration_min'])) ?>)</option><?php endforeach ?>
</select></div></div>
<div class="row"><label for="staff_member"><?= e(t('Person')) ?></label><div><select id="staff_member" name="staff_member"><option value="0"><?= e(t('Anyone available')) ?></option>
<?php foreach ($staff as $m): ?><option value="<?= (int) $m['id'] ?>"<?= (int) ($old['staff_member'] ?? 0) === $m['id'] ? ' selected' : '' ?>><?= e($m['name']) ?></option><?php endforeach ?>
</select></div></div>
<div class="row"><label for="day"><?= e(t('Day and time')) ?></label><div><input class="textfield" type="date" id="day" name="day" required value="<?= e((string) ($old['day'] ?? date('Y-m-d'))) ?>"> <input class="textfield short" type="time" name="time" required step="300" value="<?= e((string) ($old['time'] ?? '')) ?>" aria-label="<?= e(t('Time')) ?>">
<span class="help"><?= e(t('The time must be free for the person within their hours; the lead time for visitors does not apply here.')) ?></span></div></div>
</fieldset>
<fieldset><legend><?= e(t('Customer')) ?></legend>
<div class="row"><label for="name"><?= e(t('Name')) ?></label><div><input class="textfield wide" id="name" name="name" required maxlength="150" value="<?= e((string) ($old['name'] ?? '')) ?>"></div></div>
<div class="row"><label for="email"><?= e(t('Email')) ?></label><div><input class="textfield wide" type="email" id="email" name="email" maxlength="190" value="<?= e((string) ($old['email'] ?? '')) ?>"><span class="help"><?= e(t('With an e-mail the customer gets the confirmation, the cancel link and the reminder.')) ?></span></div></div>
<div class="row"><label for="phone"><?= e(t('Phone')) ?></label><div><input class="textfield" type="tel" id="phone" name="phone" maxlength="30" value="<?= e((string) ($old['phone'] ?? '')) ?>"></div></div>
<div class="row"><label for="note"><?= e(t('Note')) ?></label><div><textarea class="textbox low" id="note" name="note" rows="3" maxlength="1000"><?= e((string) ($old['note'] ?? '')) ?></textarea></div></div>
</fieldset>
<p class="buttons"><input class="btn" type="submit" value="<?= e(t('Save booking')) ?>"></p>
</form>
