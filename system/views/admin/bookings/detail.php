<?php
/**
 * One booking (3.0): the appointment, the customer, done / did not come / cancel, anonymise.
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\Bookings $module
 * @var string $csrf
 * @var array<string, mixed> $b
 * @var DateTimeImmutable $deadline until when the customer may cancel by the link
 */
use Kaleta\Core\Booking;

?>
<p class="navigace-radek"><a class="navigace" href="<?= e($module->url()) ?>">← <?= e(t('All bookings')) ?></a></p>
<div class="formular">
<dl class="poptavka">
	<dt><?= e(t('When')) ?></dt><dd><strong><?= e(Booking::when((string) $b['starts_at'], (string) $b['ends_at'])) ?></strong></dd>
	<dt><?= e(t('Service')) ?></dt><dd><?= e((string) ($b['service'] ?? '')) ?></dd>
	<dt><?= e(t('Person')) ?></dt><dd><?= e((string) ($b['staff'] ?? '')) ?></dd>
	<dt><?= e(t('Status')) ?></dt><dd><?= e(t(Booking::STATUSES[$b['status']] ?? $b['status'])) ?><?= $b['cancelled_at'] !== null ? ' · ' . e(format_date((string) $b['cancelled_at'], true)) . ' (' . e(t(match ((string) $b['cancelled_by']) { 'customer' => 'by the customer', 'claude' => 'by Claude', default => 'in the administration' })) . ')' : '' ?></dd>
<?php if ($b['anonymised_at'] !== null): ?>
	<dt><?= e(t('Anonymised')) ?></dt><dd><?= e(format_date((string) $b['anonymised_at'], true)) ?> · <?= e(t('the row stays for statistics without the person')) ?></dd>
<?php else: ?>
	<dt><?= e(t('Name')) ?></dt><dd><?= e((string) $b['name']) ?></dd>
	<dt><?= e(t('E-mail')) ?></dt><dd><?= (string) $b['email'] !== '' ? '<a href="mailto:' . e((string) $b['email']) . '">' . e((string) $b['email']) . '</a>' : '<span class="napoveda">—</span>' ?></dd>
	<dt><?= e(t('Phone')) ?></dt><dd><?= (string) $b['phone'] !== '' ? '<a href="tel:' . e(preg_replace('/[^+\d]/', '', (string) $b['phone']) ?? '') . '">' . e((string) $b['phone']) . '</a>' : '<span class="napoveda">—</span>' ?></dd>
	<dt><?= e(t('Note')) ?></dt><dd><?= (string) $b['note'] !== '' ? nl2br(e((string) $b['note'])) : '<span class="napoveda">—</span>' ?></dd>
<?php endif ?>
	<dt><?= e(t('Booked')) ?></dt><dd><?= e(format_date((string) $b['created_at'], true)) ?><?= (string) $b['source'] !== '' ? ' · ' . ($b['source'] === 'admin' ? e(t('in the administration')) : '<a href="' . e((string) $b['source']) . '" target="_blank" rel="noopener">' . e((string) $b['source']) . '</a>') : '' ?></dd>
<?php if ($b['status'] === 'confirmed'): ?>
	<dt><?= e(t('Reminder')) ?></dt><dd><?= $b['reminded_at'] !== null ? e(t('sent %s', format_date((string) $b['reminded_at'], true))) : e(t('not yet')) ?> · <?= e(t('the customer may cancel online until %s', format_date($deadline, true))) ?></dd>
<?php endif ?>
</dl>
<?php if ($b['status'] === 'confirmed'): ?>
<form class="vradku" method="post" action="<?= e($module->url('status')) ?>"><?= $csrf ?><input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
	<button class="tl" type="submit" name="stav" value="done"><?= e(t('Done')) ?></button>
	<button class="navigace" type="submit" name="stav" value="no_show"><?= e(t('Did not come')) ?></button>
	<button class="navigace nebezpecne" type="submit" name="stav" value="cancelled" data-potvrdit="<?= e(t('Cancel the appointment? The customer will get an e-mail.')) ?>"><?= e(t('Cancel the appointment')) ?></button>
</form>
<?php endif ?>
<?php if ($b['anonymised_at'] === null): ?>
<form class="vradku" method="post" action="<?= e($module->url('anonymise')) ?>" data-potvrdit="<?= e(t('Blank the name, e-mail, phone and note of this booking? The row stays for statistics.')) ?>"><?= $csrf ?><input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
	<button class="navigace" type="submit"><?= e(t('Anonymise')) ?></button> <span class="napoveda"><?= e(t('Everything about the person is erased for all bookings of one e-mail address in Enquiries → Personal data request.')) ?></span>
</form>
<?php endif ?>
</div>
