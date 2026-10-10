<?php
/**
 * One booking (3.0): the appointment, the customer, done / did not come / cancel, anonymise.
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\Bookings $module
 * @var string $csrf
 * @var array<string, mixed> $b
 * @var DateTimeImmutable $deadline until when the customer may cancel by the link
 * @var list<array{id: int, starts_at: string, ends_at: string}> $proposals times already proposed to the customer (pending)
 * @var list<string> $free free times of this person to propose from, "YYYY-MM-DD HH:MM" (pending)
 */
use Kaleta\Core\Booking;

?>
<p class="navigation-row"><a class="navigation" href="<?= e($module->url()) ?>">← <?= e(t('All bookings')) ?></a></p>
<div class="form">
<dl class="enquiry">
	<dt><?= e(t('When')) ?></dt><dd><strong><?= e(Booking::when((string) $b['starts_at'], (string) $b['ends_at'])) ?></strong></dd>
	<dt><?= e(t('Service')) ?></dt><dd><?= e((string) ($b['service'] ?? '')) ?></dd>
	<dt><?= e(t('Person')) ?></dt><dd><?= e((string) ($b['staff'] ?? '')) ?></dd>
	<dt><?= e(t('Status')) ?></dt><dd><?= e(t(Booking::STATUSES[$b['status']] ?? $b['status'])) ?><?= $b['cancelled_at'] !== null ? ' · ' . e(format_date((string) $b['cancelled_at'], true)) . ' (' . e(t(match ((string) $b['cancelled_by']) { 'customer' => 'by the customer', 'claude' => 'by Claude', default => 'in the administration' })) . ')' : '' ?></dd>
<?php if ($b['anonymised_at'] !== null): ?>
	<dt><?= e(t('Anonymised')) ?></dt><dd><?= e(format_date((string) $b['anonymised_at'], true)) ?> · <?= e(t('the row stays for statistics without the person')) ?></dd>
<?php else: ?>
	<dt><?= e(t('Name')) ?></dt><dd><?= e((string) $b['name']) ?></dd>
	<dt><?= e(t('Email')) ?></dt><dd><?= (string) $b['email'] !== '' ? '<a href="mailto:' . e((string) $b['email']) . '">' . e((string) $b['email']) . '</a>' : '<span class="help">—</span>' ?></dd>
	<dt><?= e(t('Phone')) ?></dt><dd><?= (string) $b['phone'] !== '' ? '<a href="tel:' . e(preg_replace('/[^+\d]/', '', (string) $b['phone']) ?? '') . '">' . e((string) $b['phone']) . '</a>' : '<span class="help">—</span>' ?></dd>
	<dt><?= e(t('Note')) ?></dt><dd><?= (string) $b['note'] !== '' ? nl2br(e((string) $b['note'])) : '<span class="help">—</span>' ?></dd>
<?php endif ?>
	<dt><?= e(t('Booked')) ?></dt><dd><?= e(format_date((string) $b['created_at'], true)) ?><?= (string) $b['source'] !== '' ? ' · ' . ($b['source'] === 'admin' ? e(t('in the administration')) : '<a href="' . e((string) $b['source']) . '" target="_blank" rel="noopener">' . e((string) $b['source']) . '</a>') : '' ?></dd>
<?php if ($b['status'] === 'confirmed'): ?>
	<dt><?= e(t('Reminder')) ?></dt><dd><?= $b['reminded_at'] !== null ? e(t('sent %s', format_date((string) $b['reminded_at'], true))) : e(t('not yet')) ?> · <?= e(t('the customer may cancel online until %s', format_date($deadline, true))) ?></dd>
<?php endif ?>
</dl>
<?php if ($b['status'] === 'pending'): ?>
<p class="help"><?= $b['hold_until'] !== null ? e(strtotime((string) $b['hold_until']) > time() ? t('The time is held until %s.', format_date((string) $b['hold_until'], true)) : t('The hold ran out on %s – the time is free for others, but you can still answer.', format_date((string) $b['hold_until'], true))) : '' ?></p>
<?php if ($proposals !== []): ?>
<p><strong><?= e(t('Proposed to the customer')) ?>:</strong> <?= e(implode(' · ', array_map(fn (array $p): string => Booking::when($p['starts_at'], $p['ends_at']), $proposals))) ?></p>
<?php endif ?>
<form class="inline" method="post" action="<?= e($module->url('confirm')) ?>"><?= $csrf ?><input type="hidden" name="id" value="<?= e($b['public_id']) ?>">
	<button class="btn" type="submit"><?= e(t('Accept')) ?></button> <span class="help"><?= e(t('The customer gets the confirmation with the calendar and cancel links.')) ?></span>
</form>
<form class="form" method="post" action="<?= e($module->url('decline')) ?>" data-confirm="<?= e(t('Decline the request? The customer will get an e-mail.')) ?>"><?= $csrf ?><input type="hidden" name="id" value="<?= e($b['public_id']) ?>">
	<div class="row"><label for="decline-message"><?= e(t('Personal message (optional)')) ?></label><div><textarea class="textfield wide" id="decline-message" name="message" rows="3" maxlength="1000"></textarea></div></div>
	<p class="buttons"><button class="navigation danger" type="submit"><?= e(t('Decline')) ?></button></p>
</form>
<?php if ((string) $b['email'] !== '' && $free !== []): ?>
<form class="form" method="post" action="<?= e($module->url('propose')) ?>"><?= $csrf ?><input type="hidden" name="id" value="<?= e($b['public_id']) ?>">
	<h2><?= e(t('Propose other times')) ?></h2>
	<div class="row"><label for="propose-slots"><?= e(t('Free times (pick 1 to 3)')) ?></label><div><select class="textfield" id="propose-slots" name="slots[]" multiple size="8">
<?php foreach ($free as $slot): ?>		<option value="<?= e($slot) ?>"><?= e(format_date($slot, true)) ?></option>
<?php endforeach ?>	</select></div></div>
	<div class="row"><label for="propose-message"><?= e(t('Personal message (optional)')) ?></label><div><textarea class="textfield wide" id="propose-message" name="message" rows="3" maxlength="1000"></textarea></div></div>
	<p class="buttons"><button class="btn" type="submit"><?= e(t('Send the proposal')) ?></button></p>
</form>
<?php endif ?>
<?php endif ?>
<?php if ($b['status'] === 'confirmed'): ?>
<form class="inline" method="post" action="<?= e($module->url('status')) ?>"><?= $csrf ?><input type="hidden" name="id" value="<?= e($b['public_id']) ?>">
	<button class="btn" type="submit" name="status" value="done"><?= e(t('Done')) ?></button>
	<button class="navigation" type="submit" name="status" value="no_show"><?= e(t('Did not come')) ?></button>
	<button class="navigation danger" type="submit" name="status" value="cancelled" data-confirm="<?= e(t('Cancel the appointment? The customer will get an e-mail.')) ?>"><?= e(t('Cancel the appointment')) ?></button>
</form>
<?php endif ?>
<?php if ($b['anonymised_at'] === null): ?>
<form class="inline" method="post" action="<?= e($module->url('anonymise')) ?>" data-confirm="<?= e(t('Blank the name, e-mail, phone and note of this booking? The row stays for statistics.')) ?>"><?= $csrf ?><input type="hidden" name="id" value="<?= e($b['public_id']) ?>">
	<button class="navigation" type="submit"><?= e(t('Anonymise')) ?></button> <span class="help"><?= e(t('Everything about the person is erased for all bookings of one e-mail address in Enquiries → Personal data request.')) ?></span>
</form>
<?php endif ?>
</div>
