<?php
/**
 * Settings → Reports (#161): the archive of the monthly reports (Core\ReportArchive) – view and print, download, send by e-mail –
 * and the monthly e-mail (2.9, moved here from Mail).
 *
 * @var array{months?: list<array{id: string, month: string, created_at: string}>, first?: string, previous?: string} $reports
 */
$months = $reports['months'] ?? [];
$monthName = static fn (string $month): string => Talea\Core\ReportPage::monthName($month);
?>
<p class="help"><?= e(t('Once a month ends, the site keeps a report of what happened to it: requests to Claude, changes by people and by Claude, pages and news published, backups, security, the site audit, traffic, enquiries, bookings and the newsletter. It is a summary for you or your client – counts, titles and page addresses only, never personal data, hours or prices. Print it or save it as PDF, download it, or send it by e-mail.')) ?></p>
<h2><?= e(t('Archived reports')) ?></h2>
<?php if ($months === []): ?>
<?= $app->view->render('admin/empty', ['icon' => 'stats', 'heading' => t('No report is stored yet'),
    'text' => t('The background jobs store the report for %s within an hour. Earlier months cannot be made any more: what they would be built from is kept only 30 to 180 days.', $monthName((string) ($reports['previous'] ?? '')))]) ?>
<?php else: ?>
<p class="help"><?= e(t('Reports exist from %s on and are kept for %d months. Earlier months were not stored: what they would be built from is kept only 30 to 180 days.', $monthName((string) ($reports['first'] ?? '')), Talea\Core\ReportArchive::KEEP_MONTHS)) ?></p>
<div class="tab-wrap"><table class="listing">
<thead><tr><th scope="col"><?= e(t('Month')) ?></th><th scope="col" class="number"><?= e(t('Made')) ?></th><th scope="col"><?= e(t('Actions')) ?></th></tr></thead>
<tbody>
<?php foreach ($months as $m): ?>
<tr>
	<td><strong><?= e($monthName($m['month'])) ?></strong></td>
	<td class="number"><?= e(format_date($m['created_at'], true)) ?></td>
	<td class="actions"><a href="<?= e($module->url('report_view', ['month' => $m['month']])) ?>" target="_blank" rel="noopener"><?= e(t('View and print')) ?></a> ·
		<a href="<?= e($module->url('report_download', ['month' => $m['month']])) ?>"><?= e(t('Download')) ?></a> ·
		<button class="navigation" type="submit" name="month" value="<?= e($m['month']) ?>" formaction="<?= e($module->url('report_send')) ?>" data-confirm="<?= e(t('Send the report for %s to the recipients below now?', $monthName($m['month']))) ?>"><?= e(t('Send by e-mail')) ?></button></td>
</tr>
<?php endforeach ?>
</tbody></table></div>
<?php endif ?>
<fieldset>
<legend><?= e(t('Monthly report by e-mail')) ?></legend>
<p class="help"><?= e(t('In the first days of each month the site e-mails a short report about the previous month: traffic, enquiries and sign-ups, updates, backups, changes made by people and by Claude, the problems right now and what needs your decision. Counts and page addresses only – never names, e-mail addresses or the contents of enquiries, so the report can be forwarded. With the agency details from the General tab it carries the agency’s branding.')) ?></p>
<?php
$field('report_monthly', 'Send a monthly report by e-mail', 'flag', '');
$field('report_recipients', 'Recipients', 'lines', 'One address per line or separated by commas, at most 10. Empty = the site e-mail (Settings → General).', 'maxlength="2000"');
?>
<p class="navigation-row"><a class="navigation" href="<?= e($module->url('report_preview')) ?>" target="_blank" rel="noopener"><?= e(t('Preview last month')) ?></a> <button class="navigation" type="submit" formaction="<?= e($module->url('report_send')) ?>"><?= e(t('Send last month’s report now')) ?></button> <span class="small-text"><?= e(t('Save the settings first – sending uses the saved recipients.')) ?></span></p>
</fieldset>
