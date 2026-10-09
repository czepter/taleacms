<?php
/**
 * Whistleblowing channel (2.14): the cases with their deadlines – numbers, dates and statuses only; the content opens only
 * for the chosen readers. Below it the setup, for administrators.
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\Whistleblowing $module
 * @var string $csrf
 * @var list<array<string, mixed>> $cases with 'overdue' (acknowledgement, feedback) and 'acknowledge_by'
 * @var bool $isReader the signed-in user may open the cases
 * @var bool $isAdmin
 * @var bool $on
 * @var list<int> $readerIds
 * @var list<array<string, mixed>> $users active users (administrators only see them)
 * @var string $intro
 * @var int $retention months after closing
 * @var string $publicUrl
 */
use Kaleta\Core\Whistleblowing;

$waiting = count(array_filter($cases, fn (array $c): bool => $c['status'] !== 'closed'));
?>
<p class="notice"><?= e(t('An internal reporting channel for the EU Whistleblower Directive (2019/1937) and the national laws built on it. Reports are stored encrypted and only the readers chosen below can open them – other administrators see case numbers and dates. Acknowledge a report within %d days and give feedback within %d months.', Whistleblowing::ACKNOWLEDGE_DAYS, Whistleblowing::FEEDBACK_MONTHS)) ?></p>
<p class="small-text"><?= e(t('This is a tool, not legal advice: your company must appoint the person responsible for reports and check the rules of its country (who must have a channel, what the deadlines and the records are).')) ?></p>
<?php if (!$on): ?>
<p class="notice error"><?= e(t('The channel is switched off – the public address answers with a 404 error.')) ?></p>
<?php elseif ($readerIds === []): ?>
<p class="notice error"><?= e(t('No reader is chosen – reports arrive, but nobody can open them.')) ?></p>
<?php endif ?>
<?php if ($cases === []): ?>
<?= $app->view->render('admin/empty', ['icon' => 'comments', 'heading' => t('No reports yet.'), 'text' => $on ? t('The form is at %s – put a link to it in the footer menu (Menu) or on a page.', $publicUrl) : t('Switch the channel on below and put a link to the form in the footer menu.')]) ?>
<?php else: ?>
<p class="small-text"><?= e(t('%d open, %d in total.', $waiting, count($cases))) ?></p>
<div class="tab-wrap"><table class="listing">
<thead><tr><th scope="col"><?= e(t('Case')) ?></th><th scope="col"><?= e(t('Received')) ?></th><th scope="col"><?= e(t('Status')) ?></th><th scope="col"><?= e(t('Acknowledge by')) ?></th><th scope="col"><?= e(t('Feedback by')) ?></th></tr></thead>
<tbody>
<?php foreach ($cases as $c): ?>
<tr>
	<td><?= $isReader ? '<a href="' . e($module->url('detail', ['id' => (int) $c['id']])) . '"><strong>' . e((string) $c['number']) . '</strong></a>' : '<strong>' . e((string) $c['number']) . '</strong>' ?><?= !empty($c['flood']) ? ' <span class="badge">' . e(t('received during a flood')) . '</span>' : '' ?></td>
	<td><?= e(format_date((string) $c['created_at'], true)) ?></td>
	<td><span class="badge<?= $c['status'] === 'closed' ? ' badge-published' : ($c['status'] === 'received' ? ' badge-draft' : '') ?>"><?= e(t(Whistleblowing::STATUSES[$c['status']] ?? (string) $c['status'])) ?></span></td>
	<td><?= $c['acknowledged_at'] !== null ? '<span class="small-text">' . e(t('acknowledged %s', format_date((string) $c['acknowledged_at']))) . '</span>' : ($c['overdue']['acknowledgement'] ? '<span class="badge badge-error">' . e(t('overdue')) . ' ' . e(format_date((string) $c['acknowledge_by'])) . '</span>' : e(format_date((string) $c['acknowledge_by']))) ?></td>
	<td><?= $c['status'] === 'closed' ? '<span class="small-text">' . e(t('closed %s', format_date((string) $c['closed_at']))) . '</span>' : ($c['overdue']['feedback'] ? '<span class="badge badge-error">' . e(t('overdue')) . ' ' . e(format_date((string) $c['feedback_due'])) . '</span>' : e(format_date((string) $c['feedback_due']))) ?></td>
</tr>
<?php endforeach ?>
</tbody></table></div>
<?php if (!$isReader): ?><p class="small-text"><?= e(t('You are not among the readers, so the cases do not open for you.')) ?></p><?php endif ?>
<?php endif ?>
<?php if ($isAdmin): ?>
<h2><?= e(t('Setup')) ?></h2>
<form class="form" method="post" action="<?= e($module->url('settings')) ?>">
<?= $csrf ?>
<div class="row"><span></span><div><label><input type="checkbox" name="enabled" value="1"<?= $on ? ' checked' : '' ?>> <?= e(t('The channel is on')) ?></label>
	<span class="help"><?= e(t('The public form: %s. Add a link to it to the footer menu or a page – the site does not add it by itself.', $publicUrl)) ?></span></div></div>
<div class="row"><label for="wb-intro"><?= e(t('Introduction')) ?></label><div><textarea class="textbox low" id="wb-intro" name="intro" rows="4" maxlength="5000"><?= e($intro) ?></textarea>
	<span class="help"><?= e(t('Shown above the form: who handles reports in your company, what belongs here, where the whistleblowing policy is.')) ?></span></div></div>
<fieldset class="row"><legend><?= e(t('Who may read reports')) ?></legend><div>
<?php foreach ($users as $u): ?>
	<label class="block"><input type="checkbox" name="readers[]" value="<?= (int) $u['user_id'] ?>"<?= in_array((int) $u['user_id'], $readerIds, true) ? ' checked' : '' ?>> <?= e((string) ($u['name'] !== '' ? $u['name'] : $u['username'])) ?><?= $u['email'] !== '' ? ' <span class="small-text">' . e((string) $u['email']) . '</span>' : ' <span class="small-text">' . e(t('no e-mail – will not be notified')) . '</span>' ?></label>
<?php endforeach ?>
	<span class="help"><?= e(t('Only these people open the reports – other administrators see case numbers and dates. A new report is announced to them by e-mail with the case number only.')) ?></span></div></fieldset>
<div class="row"><label for="wb-retention"><?= e(t('Delete closed cases after')) ?></label><div><input class="textfield" type="number" id="wb-retention" name="retention" value="<?= (int) $retention ?>" min="1" max="120" size="4"> <?= e(t('months')) ?>
	<span class="help"><?= e(t('Closed cases are deleted with their attachments and messages; open cases are kept. Check the retention period your national law requires.')) ?></span></div></div>
<p class="buttons"><input class="btn" type="submit" value="<?= e(t('Save')) ?>"></p>
</form>
<p class="small-text"><?= e(t('Claude and other connected tools never see the reports: no MCP tool reads or lists them, and the site export leaves them out.')) ?></p>
<?php endif ?>
