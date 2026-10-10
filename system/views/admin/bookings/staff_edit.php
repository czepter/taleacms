<?php
/**
 * One person who takes bookings (3.0): the services, the weekly hours and the days off.
 *
 * @var Talea\Core\App $app
 * @var Talea\Admin\Modules\Bookings $module
 * @var string $csrf
 * @var array<string, mixed> $m the person ([] = a new one)
 * @var list<array<string, mixed>> $services
 * @var array<int, string> $hours weekday => ranges as text
 * @var list<array<string, mixed>> $offs
 * @var array<string, list<array{0: string, 1: string}>> $siteWeek the site's opening hours
 * @var array<int, string> $users
 */
use Talea\Core\Booking;
use Talea\Core\Hours;

$isNew = $m === [];
?>
<p class="navigation-row"><a class="navigation" href="<?= e($module->url('staff')) ?>">← <?= e(t('People')) ?></a></p>
<form class="form" method="post" action="<?= e($module->url('staff_save')) ?>">
<?= $csrf ?><input type="hidden" name="id" value="<?= e((string) ($m['public_id'] ?? '')) ?>">
<fieldset><legend><?= e($isNew ? t('New person') : t('Person')) ?></legend>
<div class="row"><label for="name"><?= e(t('Name')) ?></label><div><input class="textfield wide" id="name" name="name" required maxlength="150" value="<?= e((string) ($m['name'] ?? '')) ?>"></div></div>
<div class="row"><label for="email"><?= e(t('E-mail for notifications')) ?></label><div><input class="textfield wide" type="email" id="email" name="email" maxlength="190" value="<?= e((string) ($m['email'] ?? '')) ?>"><span class="help"><?= e(t('New and cancelled bookings arrive here; empty = the site e-mail from Settings.')) ?></span></div></div>
<div class="row"><label for="user_id"><?= e(t('Account')) ?></label><div><select id="user_id" name="user_id"><option value="0">—</option>
<?php foreach ($users as $user_id => $displayName): ?><option value="<?= e((string) $user_id) ?>"<?= $userPublicId === (string) $user_id ? ' selected' : '' ?>><?= e($displayName) ?></option><?php endforeach ?>
</select><span class="help"><?= e(t('Optional: the administration user this person is.')) ?></span></div></div>
<div class="row"><span><?= e(t('Services')) ?></span><div>
<?php if ($services === []): ?><span class="help"><?= e(t('Add services first.')) ?></span><?php endif ?>
<?php foreach ($services as $s): ?><label class="inline"><input type="checkbox" name="services[]" value="<?= e($s['public_id']) ?>"<?= in_array($s['id'], $m['services'] ?? [], true) ? ' checked' : '' ?>> <?= e($s['name']) ?></label> <?php endforeach ?>
</div></div>
<div class="row"><label for="sort_order"><?= e(t('Order')) ?></label><div><input class="textfield short" type="number" id="sort_order" name="sort_order" value="<?= (int) ($m['sort_order'] ?? 0) ?>"></div></div>
<div class="row"><span></span><div><label><input type="checkbox" name="active" value="1"<?= ($m['active'] ?? true) ? ' checked' : '' ?>> <?= e(t('Takes bookings')) ?></label></div></div>
</fieldset>
<fieldset><legend><?= e(t('Weekly hours')) ?></legend>
<p class="help"><?= e(t('Ranges like 9:00-12:00, 13:00-17:00; an empty day = no bookings that day. All empty = the opening hours of the site (Business details) with their exceptions.')) ?></p>
<?php foreach (Booking::WEEKDAYS as $d => $dayName): ?>
<div class="row"><label for="hours_<?= $d ?>"><?= e(t($dayName)) ?></label><div><input class="textfield" id="hours_<?= $d ?>" name="hours_<?= $d ?>" maxlength="100" value="<?= e($hours[$d] ?? '') ?>" placeholder="<?= e(Hours::rangesText($siteWeek[$dayName] ?? [])) ?>"></div></div>
<?php endforeach ?>
</fieldset>
<p class="buttons"><input class="btn" type="submit" value="<?= e(t('Save')) ?>"></p>
</form>
<?php if (!$isNew): ?>
<h2><?= e(t('Days off')) ?></h2>
<?php if ($offs !== []): ?>
<ul>
<?php foreach ($offs as $o): ?>
<li><?= e(format_date($o['from'], true)) ?> – <?= e(format_date($o['to'], true)) ?><?= $o['staff_id'] === null ? ' (' . e(t('everyone')) . ')' : '' ?><?= $o['note'] !== '' ? ': ' . e($o['note']) : '' ?>
<?php if ($o['staff_id'] !== null): ?><form class="inline" method="post" action="<?= e($module->url('off_delete')) ?>"><?= $csrf ?><input type="hidden" name="id" value="<?= (int) $o['id'] ?>"><input type="hidden" name="staff_id" value="<?= e($m['public_id']) ?>"><button class="navigation" type="submit"><?= e(t('Remove')) ?></button></form><?php endif ?></li>
<?php endforeach ?>
</ul>
<?php endif ?>
<form class="form" method="post" action="<?= e($module->url('off_save')) ?>">
<?= $csrf ?><input type="hidden" name="staff_id" value="<?= e($m['public_id']) ?>">
<div class="row"><label for="off_from"><?= e(t('Off from')) ?></label><div><input class="textfield" type="date" id="off_from" name="off_from" required> <label><?= e(t('to')) ?> <input class="textfield" type="date" name="off_to"></label> <label><?= e(t('Note')) ?> <input class="textfield" name="note" maxlength="150" placeholder="<?= e(t('e.g. holiday')) ?>"></label> <input class="btn" type="submit" value="<?= e(t('Add')) ?>"></div></div>
</form>
<?php endif ?>
