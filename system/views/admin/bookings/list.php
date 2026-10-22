<?php
/**
 * Online bookings (3.0): by day, with filters; the set-up links and the settings for administrators.
 *
 * @var Talea\Core\App $app
 * @var Talea\Admin\Modules\Bookings $module
 * @var string $csrf
 * @var array<string, list<array<string, mixed>>> $byDay day => bookings
 * @var string $shown upcoming | today | past | all
 * @var array{from: string, to: string, status: string, staff: int, service: int} $filter
 * @var list<array<string, mixed>> $services
 * @var list<array<string, mixed>> $staff
 * @var array{lead: int, horizon: int, cancel: int, reminder: int, hold: int, pendingThanks: string, pendingMail: string, declinedMail: string} $settings
 * @var int $waiting requests waiting for the provider's answer
 * @var list<array<string, mixed>> $requests the next requests to confirm (beside the agenda, #113)
 * @var int $months
 * @var string $expiry
 * @var bool $isAdmin
 */
use Talea\Core\Booking;

$query = array_filter(['view' => $shown === 'upcoming' ? '' : $shown, 'staff' => $filter['staff_public'], 'service' => $filter['service_public']]);
$icon = require __DIR__ . '/../icons.php';
echo $app->view->render('admin/list/head', ['count' => $waiting > 0 ? t('%d waiting', $waiting) : null,
    'links' => $isAdmin ? [[$module->url('services'), t('Services') . ' · ' . count($services)], [$module->url('staff'), t('People') . ' · ' . count($staff)]] : [],
    'primary' => [$module->url('new'), t('New booking')]]);
$filters = '<select name="staff" aria-label="' . e(t('Person')) . '" data-submit-on-change><option value="0">' . e(t('everyone')) . '</option>';
foreach ($staff as $m) {
    $filters .= '<option value="' . e($m['public_id']) . '"' . ($filter['staff'] === $m['id'] ? ' selected' : '') . '>' . e($m['name']) . '</option>';
}
$filters .= '</select><select name="service" aria-label="' . e(t('Service')) . '" data-submit-on-change><option value="0">' . e(t('all services')) . '</option>';
foreach ($services as $sv) {
    $filters .= '<option value="' . e($sv['public_id']) . '"' . ($filter['service'] === $sv['id'] ? ' selected' : '') . '>' . e($sv['name']) . '</option>';
}
$filters .= '</select><select name="status" aria-label="' . e(t('Status')) . '" data-submit-on-change><option value="">' . e($shown === 'upcoming' ? t('confirmed and waiting') : t('any')) . '</option>';
foreach (Booking::STATUSES as $key => $label) {
    $filters .= '<option value="' . e($key) . '"' . ($filter['status'] === $key && $shown !== 'upcoming' ? ' selected' : '') . '>' . e(t($label)) . '</option>';
}
$filters .= '</select>';
echo $app->view->render('admin/list/toolbar', ['tabsLabel' => t('Bookings'),
    'tabs' => array_map(fn (string $key, string $name): array => ['label' => t($name), 'url' => $module->url('', array_filter(['view' => $key === 'upcoming' ? '' : $key]) + array_diff_key($query, ['view' => 1])), 'active' => $shown === $key],
        ['upcoming', 'today', 'past', 'all'], ['Upcoming', 'Today', 'Last 30 days', 'All']),
    'action' => $app->url('admin.php'), 'hidden' => ['module' => 'bookings'] + array_filter(['view' => $shown === 'upcoming' ? '' : $shown]), 'filters' => $filters]);
$kinds = ['confirmed' => 'live', 'pending' => 'hidden', 'done' => 'draft', 'declined' => 'off', 'cancelled' => 'off', 'no_show' => 'error'];
?>
<?php if ($services === [] || $staff === []): ?>
<?= $app->view->render('admin/empty', ['icon' => 'booking', 'heading' => t('Set up the booking first.'), 'text' => t('Add a service (what and how long) and a person who offers it. Then put the Booking element on a page – visitors pick a service, a person, a day and a free time.'), 'action' => $isAdmin ? [$module->url('services', ['new' => 1]), t('Add a service')] : null]) ?>
<?php else: ?>
<div class="bookings-grid">
<?php if ($requests !== []): ?>
<section class="bookings-requests" aria-labelledby="bookings-requests-heading">
	<h2 id="bookings-requests-heading"><?= e(t('Requests to confirm')) ?> <span class="small-text"><?= $waiting ?></span></h2>
<?php foreach ($requests as $b): ?>
	<article>
		<p><strong><?= $b['anonymised_at'] !== null ? e(t('anonymised')) : e((string) $b['name']) ?></strong><br><span class="small-text"><?= e((string) ($b['service'] ?? '')) ?> · <?= e(format_date_long(substr((string) $b['starts_at'], 0, 10))) ?>, <?= e(substr((string) $b['starts_at'], 11, 5)) ?><?= (string) ($b['staff'] ?? '') !== '' ? ' · ' . e((string) $b['staff']) : '' ?></span></p>
		<p class="navigation-row"><form class="inline" method="post" action="<?= e($module->url('confirm')) ?>"><?= $csrf ?><input type="hidden" name="id" value="<?= e($b['public_id']) ?>"><button class="btn" type="submit"><?= e(t('Accept')) ?></button></form>
			<a class="navigation" href="<?= e($module->url('detail', ['id' => $b['public_id']])) ?>"><?= e(t('Decline or propose a time')) ?></a></p>
	</article>
<?php endforeach ?>
<?php if ($waiting > count($requests)): ?>
	<p class="small-text"><a href="<?= e($module->url('', ['status' => 'pending', 'view' => 'all'])) ?>"><?= e(t('All requests (%d)', $waiting)) ?></a></p>
<?php endif ?>
</section>
<?php endif ?>
<section class="bookings-agenda" aria-label="<?= e(t('Bookings by day')) ?>">
<?php if ($byDay === []): ?>
<?= $app->view->render('admin/empty', ['icon' => 'booking', 'heading' => t('No bookings here.'), 'text' => t('Nothing booked for these days and filters.'), 'action' => [$module->url(), t('Clear filter')]]) ?>
<?php else: ?>
<div class="tab-wrap"><table class="listing">
<thead><tr><th scope="col"><?= e(t('Time')) ?></th><th scope="col"><?= e(t('Service')) ?></th><th scope="col"><?= e(t('Person')) ?></th><th scope="col"><?= e(t('Customer')) ?></th><th scope="col"><?= e(t('Status')) ?></th><th scope="col"><span class="visually-hidden"><?= e(t('Actions')) ?></span></th></tr></thead>
<?php foreach ($byDay as $day => $rows): ?>
<tbody>
<tr class="list-group"><th scope="rowgroup" colspan="6"><?= e(format_date_long($day)) ?></th></tr>
<?php foreach ($rows as $b):
    $detail = $module->url('detail', ['id' => $b['public_id']]);
    $closed = !in_array($b['status'], ['confirmed', 'pending'], true);
    $who = $b['anonymised_at'] !== null ? t('anonymised') : (string) $b['name'];
?>
<tr<?= $closed ? ' class="inactive"' : '' ?>>
	<td><a class="cell-title cell-time" href="<?= e($detail) ?>"><?= e(substr((string) $b['starts_at'], 11, 5)) ?>–<?= e(substr((string) $b['ends_at'], 11, 5)) ?></a></td>
	<td class="cell-main"><div class="cell-text"><span class="cell-title"><?= e((string) ($b['service'] ?? '')) ?></span></div></td>
	<td><span class="cell-row"><span class="cell-icon avatar" aria-hidden="true"><?= e(mb_strtoupper(mb_substr((string) ($b['staff'] ?? ''), 0, 2))) ?></span><?= e((string) ($b['staff'] ?? '')) ?></span></td>
	<td><div class="cell-text"><span><?= e($who) ?></span><?= $b['anonymised_at'] === null && (string) $b['phone'] !== '' ? '<span class="cell-sub">' . e((string) $b['phone']) . '</span>' : '' ?></div></td>
	<td><?= $app->view->render('admin/list/status', ['kind' => $kinds[$b['status']] ?? 'draft', 'label' => t(Booking::STATUSES[$b['status']] ?? $b['status'])]) ?></td>
	<td class="cell-actions"><?= $app->view->render('admin/list/actions', ['id' => $b['public_id'], 'name' => $who, 'edit' => null, 'csrf' => $csrf,
        'quick' => $b['status'] === 'pending' ? ['action' => $module->url('confirm'), 'fields' => ['id' => $b['public_id']], 'label' => t('Confirm'), 'icon' => 'check', 'filled' => true] : null,
        'menu' => [['url' => $detail, 'label' => t('Open'), 'icon' => 'eye']]]) ?></td>
</tr>
<?php endforeach ?>
</tbody>
<?php endforeach ?>
</table></div>
<?php endif ?>
</section>
</div>
<?php endif ?>
<?php if ($isAdmin): ?>
<details class="panel-collapsed bookings-settings">
<summary><h2><?= e(t('Booking settings')) ?></h2></summary>
<form class="form" method="post" action="<?= e($module->url('settings')) ?>">
<?= $csrf ?>
<div class="row"><label for="lead"><?= e(t('Earliest booking')) ?></label><div><input class="textfield short" type="number" id="lead" name="lead" min="0" max="720" value="<?= $settings['lead'] ?>"> <?= e(t('hours ahead')) ?></div></div>
<div class="row"><label for="horizon"><?= e(t('Bookable ahead')) ?></label><div><input class="textfield short" type="number" id="horizon" name="horizon" min="1" max="365" value="<?= $settings['horizon'] ?>"> <?= e(t('days')) ?></div></div>
<div class="row"><label for="cancel"><?= e(t('Cancel link works until')) ?></label><div><input class="textfield short" type="number" id="cancel" name="cancel" min="0" max="720" value="<?= $settings['cancel'] ?>"> <?= e(t('hours before the start')) ?></div></div>
<div class="row"><label for="reminder"><?= e(t('Reminder e-mail')) ?></label><div><input class="textfield short" type="number" id="reminder" name="reminder" min="0" max="168" value="<?= $settings['reminder'] ?>"> <?= e(t('hours before the start (0 = none)')) ?></div></div>
<div class="row"><label for="hold"><?= e(t('Hold a request for')) ?></label><div><input class="textfield short" type="number" id="hold" name="hold" min="1" max="720" value="<?= $settings['hold'] ?>"> <?= e(t('hours')) ?>
<span class="help"><?= e(t('Services that need confirmation: the requested time is held this long. Then it is free again and you get a reminder; the customer hears nothing until you answer.')) ?></span></div></div>
<div class="row"><label for="pending_thanks"><?= e(t('Thank-you after a request')) ?></label><div><input class="textfield wide" id="pending_thanks" name="pending_thanks" maxlength="400" value="<?= e($settings['pendingThanks']) ?>" placeholder="<?= e(t('empty = the built-in text')) ?>"></div></div>
<div class="row"><label for="pending_mail"><?= e(t('Acknowledgement e-mail')) ?></label><div><textarea class="textfield wide" id="pending_mail" name="pending_mail" rows="3" maxlength="1000" placeholder="<?= e(t('empty = the built-in text')) ?>"><?= e($settings['pendingMail']) ?></textarea>
<span class="help"><?= e(t('The opening text of the e-mail; the details follow. Write it in your own tone and form of address; {name} is the customer\'s name.')) ?></span></div></div>
<div class="row"><label for="declined_mail"><?= e(t('Decline e-mail')) ?></label><div><textarea class="textfield wide" id="declined_mail" name="declined_mail" rows="3" maxlength="1000" placeholder="<?= e(t('empty = the built-in text')) ?>"><?= e($settings['declinedMail']) ?></textarea>
<span class="help"><?= e(t('The opening text; your personal message from the request follows it.')) ?></span></div></div>
<p class="buttons"><input class="btn" type="submit" value="<?= e(t('Save')) ?>"></p>
</form>
</details>
<p class="small-text"><?= e($months > 0 ? t('Bookings follow the enquiry retention: %d months after the appointment they are %s (Enquiries → settings).', $months, t($expiry === 'anonymise' ? 'anonymised' : 'deleted')) : t('Bookings are kept for good – set a retention in Enquiries so personal data does not stay forever.')) ?> <?= e(t('Claude can check free times and set up services and people; reading bookings is recorded in the change log.')) ?></p>
<?php endif ?>
