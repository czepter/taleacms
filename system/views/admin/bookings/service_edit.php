<?php
/**
 * One bookable service on its own page: what it is, the appointment and the times it can be booked.
 *
 * @var Talea\Core\App $app
 * @var Talea\Admin\Modules\Bookings $module
 * @var string $csrf
 * @var array<string, mixed> $s the service ([] = a new one)
 * @var list<array<string, mixed>> $staff
 * @var array<int, array<int, string>> $hours person id => weekday => the service's own times as text
 * @var bool $ownHours the service has times of its own
 */
use Talea\Core\Booking;

$isNew = $s === [];
?>
<p class="navigation-row"><a class="navigation" href="<?= e($module->url('services')) ?>">← <?= e(t('Services')) ?></a></p>
<form class="form" method="post" action="<?= e($module->url('service_save')) ?>">
<?= $csrf ?><input type="hidden" name="id" value="<?= e((string) ($s['public_id'] ?? '')) ?>">
<fieldset><legend><?= e(t('What is booked')) ?></legend>
<div class="row"><label for="name"><?= e(t('Name')) ?></label><div><input class="textfield wide" id="name" name="name" required maxlength="150" value="<?= e((string) ($s['name'] ?? '')) ?>" placeholder="<?= e(t('e.g. Haircut')) ?>"></div></div>
<div class="row"><label for="description"><?= e(t('Description')) ?></label><div><input class="textfield wide" id="description" name="description" maxlength="500" value="<?= e((string) ($s['description'] ?? '')) ?>"></div></div>
<div class="row"><label for="price_text"><?= e(t('Price')) ?></label><div><input class="textfield" id="price_text" name="price_text" maxlength="60" value="<?= e((string) ($s['price_text'] ?? '')) ?>" placeholder="<?= e(t('e.g. from 450 CZK')) ?>"><span class="help"><?= e(t('Shown as typed. Talea takes no payments, the customer pays on the spot.')) ?></span></div></div>
</fieldset>
<fieldset><legend><?= e(t('Appointment')) ?></legend>
<div class="row"><label for="duration_min"><?= e(t('Duration')) ?></label><div><input class="textfield short" type="number" id="duration_min" name="duration_min" min="5" max="480" step="5" required value="<?= (int) ($s['duration_min'] ?? 30) ?>"> <?= e(t('minutes')) ?>
<span class="help"><?= e(t('A 30-minute service can start every 30 minutes. Services longer than an hour can start every 15 minutes.')) ?></span></div></div>
<div class="row"><label for="buffer_min"><?= e(t('Buffer')) ?></label><div><input class="textfield short" type="number" id="buffer_min" name="buffer_min" min="0" max="240" step="5" value="<?= (int) ($s['buffer_min'] ?? 0) ?>"> <?= e(t('minutes kept free after the appointment')) ?></div></div>
<div class="row"><span class="caption"><?= e(t('Offered by')) ?></span><div class="options">
<?php if ($staff === []): ?><span class="help"><?= e(t('Add people first.')) ?></span><?php endif ?>
<?php foreach ($staff as $m): ?><label class="inline"><input type="checkbox" name="staff[]" value="<?= e($m['public_id']) ?>"<?= in_array($m['id'], $s['staff'] ?? [], true) ? ' checked' : '' ?>> <?= e($m['name']) ?></label> <?php endforeach ?>
</div></div>
<div class="row"><span></span><div><label><input type="checkbox" name="requires_confirmation" value="1"<?= !empty($s['requires_confirmation']) ? ' checked' : '' ?>> <?= e(t('Requires confirmation')) ?></label>
<span class="help"><?= e(t('The visitor sends a request instead of a booking. Talea holds the time until you accept, decline or propose other times, and tells the customer by e-mail.')) ?></span></div></div>
<div class="row"><span></span><div><label><input type="checkbox" name="active" value="1"<?= ($s['active'] ?? true) ? ' checked' : '' ?>> <?= e(t('Offered to visitors')) ?></label></div></div>
<div class="row"><label for="sort_order"><?= e(t('Order')) ?></label><div><input class="textfield short" type="number" id="sort_order" name="sort_order" value="<?= (int) ($s['sort_order'] ?? 0) ?>"></div></div>
</fieldset>
<fieldset class="service-hours"><legend><?= e(t('Bookable times')) ?></legend>
<div class="options-stacked">
<label><input type="radio" name="hours_mode" value="person"<?= $ownHours ? '' : ' checked' ?>> <?= e(t('When the person works')) ?><span class="help"><?= e(t('The weekly hours and days off of each person apply.')) ?></span></label>
<label><input type="radio" name="hours_mode" value="own"<?= $ownHours ? ' checked' : '' ?>> <?= e(t('Only at certain times')) ?><span class="help"><?= e(t('These times replace the weekly hours for this service.')) ?></span></label>
</div>
<div class="service-hours-own">
<?php if ($staff === []): ?><p class="help"><?= e(t('Add people first.')) ?></p><?php else: ?>
<div class="tab-wrap"><table class="listing">
<thead><tr><th scope="col"><?= e(t('Day')) ?></th><?php foreach ($staff as $m): ?><th scope="col"><?= e($m['name']) ?></th><?php endforeach ?></tr></thead>
<tbody>
<?php foreach (Booking::WEEKDAYS as $d => $dayName): ?>
<tr><th scope="row"><?= e(t($dayName)) ?></th><?php foreach ($staff as $m): $field = 'hours_' . $m['public_id'] . '_' . $d ?><td><input class="textfield" name="<?= e($field) ?>" maxlength="100" value="<?= e($hours[$m['id']][$d] ?? '') ?>" aria-label="<?= e(t($dayName) . ', ' . $m['name']) ?>"></td><?php endforeach ?></tr>
<?php endforeach ?>
</tbody></table></div>
<p class="help"><?= e(t('Ranges like 9:00-12:00, 13:00-17:00. A day left empty cannot be booked. All services of a person share one calendar, so bookings never overlap.')) ?><?= count($staff) > 1 ? ' ' . e(t('A person whose column stays empty keeps the weekly hours.')) : '' ?></p>
<?php endif ?>
</div>
</fieldset>
<p class="buttons"><input class="btn" type="submit" value="<?= e(t('Save')) ?>"> <a class="navigation" href="<?= e($module->url('services')) ?>"><?= e(t('Cancel')) ?></a>
<?php if (!$isNew): ?><button class="navigation danger service-delete" type="submit" form="service-delete" data-confirm="<?= e(t('Delete the service?')) ?>"><?= e(t('Delete')) ?></button><?php endif ?></p>
</form>
<?php if (!$isNew): ?>
<form id="service-delete" method="post" action="<?= e($module->url('service_delete')) ?>"><?= $csrf ?><input type="hidden" name="id" value="<?= e((string) $s['public_id']) ?>"></form>
<?php endif ?>
