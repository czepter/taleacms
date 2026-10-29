<?php
/**
 * One booking (3.0): the appointment and the answer to a request on the left, the customer on the right.
 *
 * @var Talea\Core\App $app
 * @var Talea\Admin\Modules\Bookings $module
 * @var string $csrf
 * @var array<string, mixed> $b
 * @var DateTimeImmutable $deadline until when the customer may cancel by the link
 * @var list<array{id: int, starts_at: string, ends_at: string}> $proposals times already proposed to the customer (pending)
 * @var list<string> $free free times of this person to propose from, "YYYY-MM-DD HH:MM" (pending)
 */
use Talea\Core\Booking;

$pending = $b['status'] === 'pending';
$id = '<input type="hidden" name="id" value="' . e($b['public_id']) . '">';
$kinds = ['confirmed' => 'live', 'pending' => 'hidden', 'done' => 'draft', 'declined' => 'off', 'cancelled' => 'off', 'no_show' => 'error'];
$notes = $b['cancelled_at'] !== null ? [['text' => format_date((string) $b['cancelled_at'], true) . ' (' . t(match ((string) $b['cancelled_by']) { 'customer' => 'by the customer', 'claude' => 'by Claude', default => 'in the administration' }) . ')', 'kind' => 'neutral']] : [];
$days = [];
foreach ($free as $slot) {
    $days[substr($slot, 0, 10)][] = $slot;
}
?>
<p class="navigation-row"><a class="navigation" href="<?= e($module->url()) ?>">← <?= e(t('All bookings')) ?></a></p>
<div class="booking-detail">
<div class="booking-main">
<section class="booking-card">
	<p class="booking-status"><?= $app->view->render('admin/list/status', ['kind' => $kinds[$b['status']] ?? 'draft', 'label' => t(Booking::STATUSES[$b['status']] ?? $b['status']), 'notes' => $notes]) ?></p>
	<h2 class="booking-when-title"><?= e($pending ? t('Requested appointment') : t('Appointment')) ?></h2>
	<p class="booking-date"><?= e(format_date_long(substr((string) $b['starts_at'], 0, 10))) ?></p>
	<p class="booking-time"><?= e(substr((string) $b['starts_at'], 11, 5) . '–' . substr((string) $b['ends_at'], 11, 5)) ?></p>
	<dl class="booking-facts">
		<dt><?= e(t('Service')) ?></dt><dd><?= e((string) ($b['service'] ?? '')) ?></dd>
		<dt><?= e(t('Person')) ?></dt><dd><?= e((string) ($b['staff'] ?? '')) ?></dd>
<?php if ($b['status'] === 'confirmed'): ?>
		<dt><?= e(t('Reminder')) ?></dt><dd><?= $b['reminded_at'] !== null ? e(t('sent %s', format_date((string) $b['reminded_at'], true))) : e(t('not yet')) ?> · <?= e(t('the customer may cancel online until %s', format_date($deadline, true))) ?></dd>
<?php endif ?>
	</dl>
<?php if ($pending && $b['hold_until'] !== null): ?>
	<div class="notice notice-warning"><?= e(strtotime((string) $b['hold_until']) > time() ? t('The time is held until %s.', format_date((string) $b['hold_until'], true)) : t('The hold ran out on %s – the time is free for others, but you can still answer.', format_date((string) $b['hold_until'], true))) ?></div>
<?php endif ?>
</section>
<?php if ($pending): ?>
<section class="booking-card booking-answer">
	<h2><?= e(t('Your answer')) ?></h2>
	<fieldset class="answer-options">
		<legend class="visually-hidden"><?= e(t('Your answer')) ?></legend>
		<label class="answer-tile"><input class="visually-hidden" type="radio" name="answer" id="answer-accept" checked><span class="answer-title"><?= e(t('Accept')) ?></span><span class="answer-effect"><?= e(t('The appointment is booked.')) ?></span></label>
		<label class="answer-tile"><input class="visually-hidden" type="radio" name="answer" id="answer-propose"><span class="answer-title"><?= e(t('Propose other times')) ?></span><span class="answer-effect"><?= e(t('Up to three free times to choose from.')) ?></span></label>
		<label class="answer-tile"><input class="visually-hidden" type="radio" name="answer" id="answer-decline"><span class="answer-title"><?= e(t('Decline')) ?></span><span class="answer-effect"><?= e(t('The request is closed.')) ?></span></label>
	</fieldset>
	<form class="answer-section answer-accept" method="post" action="<?= e($module->url('confirm')) ?>" data-no-page-save><?= $csrf ?><?= $id ?>
		<button class="btn" type="submit"><?= e(t('Confirm the appointment')) ?></button>
		<p class="help"><?= e(t('The customer gets the confirmation with the calendar and cancel links.')) ?></p>
	</form>
<?php if ((string) $b['email'] !== '' && $free !== []): ?>
	<form class="answer-section answer-propose" method="post" action="<?= e($module->url('propose')) ?>" data-no-page-save><?= $csrf ?><?= $id ?>
<?php if ($proposals !== []): ?>
		<p class="booking-proposed"><strong><?= e(t('Proposed to the customer')) ?>:</strong> <?= e(implode(' · ', array_map(fn (array $p): string => Booking::when($p['starts_at'], $p['ends_at']), $proposals))) ?></p>
<?php endif ?>
		<div id="propose-slots" class="answer-days">
<?php $n = 0; foreach ($days as $day => $slots): $n++ ?>
			<div class="answer-day" role="group" aria-labelledby="propose-day-<?= $n ?>">
				<h3 id="propose-day-<?= $n ?>"><?= e(format_date_long($day)) ?></h3>
				<div class="answer-chips">
<?php foreach ($slots as $slot): ?>
					<label class="answer-chip"><input class="visually-hidden" type="checkbox" name="slots[]" value="<?= e($slot) ?>"><span><?= e(substr($slot, 11, 5)) ?></span></label>
<?php endforeach ?>
				</div>
			</div>
<?php endforeach ?>
		</div>
		<p class="help"><?= e(t('Pick up to three. The customer gets them by e-mail and confirms one with a click; the requested time stays held until then.')) ?></p>
		<div class="answer-field"><label for="propose-message"><?= e(t('Personal message (optional)')) ?></label><textarea class="textfield wide" id="propose-message" name="message" rows="3" maxlength="1000"></textarea></div>
		<button class="btn" type="submit"><?= e(t('Send the proposal')) ?></button>
	</form>
<?php else: ?>
	<div class="answer-section answer-propose">
<?php if ($proposals !== []): ?>
		<p class="booking-proposed"><strong><?= e(t('Proposed to the customer')) ?>:</strong> <?= e(implode(' · ', array_map(fn (array $p): string => Booking::when($p['starts_at'], $p['ends_at']), $proposals))) ?></p>
<?php endif ?>
		<p class="help"><?= e((string) $b['email'] === '' ? t('The customer left no e-mail address – call them, or decline the request.') : t('There are no free times in the next weeks to propose.')) ?></p>
	</div>
<?php endif ?>
	<form class="answer-section answer-decline" method="post" action="<?= e($module->url('decline')) ?>" data-no-page-save data-confirm="<?= e(t('Decline the request? The customer will get an e-mail.')) ?>"><?= $csrf ?><?= $id ?>
		<p class="help"><?= e(t('Only the time does not suit?')) ?> <label class="answer-link" for="answer-propose"><?= e(t('Propose other times')) ?></label></p>
		<div class="answer-field"><label for="decline-message"><?= e(t('Personal message (optional)')) ?></label><textarea class="textfield wide" id="decline-message" name="message" rows="3" maxlength="1000"></textarea></div>
		<button class="btn btn-danger" type="submit"><?= e(t('Decline the request')) ?></button>
		<p class="help"><?= e(t('The customer gets a refusal by e-mail; the time is free again.')) ?></p>
	</form>
</section>
<?php endif ?>
<?php if ($b['status'] === 'confirmed'): ?>
<section class="booking-card">
	<form class="navigation-row" method="post" action="<?= e($module->url('status')) ?>" data-no-page-save><?= $csrf ?><?= $id ?>
		<button class="btn" type="submit" name="status" value="done"><?= e(t('Done')) ?></button>
		<button class="navigation" type="submit" name="status" value="no_show"><?= e(t('Did not come')) ?></button>
	</form>
	<form class="navigation-row actions-bottom" method="post" action="<?= e($module->url('status')) ?>" data-no-page-save><?= $csrf ?><?= $id ?>
		<button class="navigation danger" type="submit" name="status" value="cancelled" data-confirm="<?= e(t('Cancel the appointment? The customer will get an e-mail.')) ?>"><?= e(t('Cancel the appointment')) ?></button>
	</form>
</section>
<?php endif ?>
</div>
<aside class="booking-side">
<section class="booking-card">
	<h2><?= e(t('Contact')) ?></h2>
<?php if ($b['anonymised_at'] !== null): ?>
	<p class="help"><?= e(t('Anonymised')) ?>: <?= e(format_date((string) $b['anonymised_at'], true)) ?> · <?= e(t('the row stays for statistics without the person')) ?></p>
<?php else: ?>
	<p class="booking-name"><strong><?= e((string) $b['name']) ?></strong></p>
	<p><?= (string) $b['email'] !== '' ? '<a href="mailto:' . e((string) $b['email']) . '">' . e((string) $b['email']) . '</a>' : '<span class="help">' . e(t('Email')) . ': —</span>' ?></p>
	<p><?= (string) $b['phone'] !== '' ? '<a href="tel:' . e(preg_replace('/[^+\d]/', '', (string) $b['phone']) ?? '') . '">' . e((string) $b['phone']) . '</a>' : '<span class="help">' . e(t('Phone')) . ': —</span>' ?></p>
<?php if ((string) $b['note'] !== ''): ?>
	<blockquote class="booking-note"><?= nl2br(e((string) $b['note'])) ?></blockquote>
<?php endif ?>
<?php endif ?>
	<p class="help booking-meta"><?= e(t('Booked')) ?> <?= e(format_date((string) $b['created_at'], true)) ?><?= (string) $b['source'] !== '' ? ' · ' . ($b['source'] === 'admin' ? e(t('in the administration')) : '<a href="' . e((string) $b['source']) . '" target="_blank" rel="noopener">' . e((string) $b['source']) . '</a>') : '' ?></p>
<?php if ($b['anonymised_at'] === null): ?>
	<form class="booking-anonymise" method="post" action="<?= e($module->url('anonymise')) ?>" data-no-page-save data-confirm="<?= e(t('Blank the name, e-mail, phone and note of this booking? The row stays for statistics.')) ?>"><?= $csrf ?><?= $id ?>
		<button class="quiet-link" type="submit"><?= e(t('Anonymise')) ?></button>
		<p class="help"><?= e(t('Blanks the name, e-mail, phone and note of this booking; the row stays for statistics.')) ?></p>
	</form>
<?php endif ?>
</section>
</aside>
</div>
