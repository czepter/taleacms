<?php /** The "Pošta" (Mail) tab: from where and how the site sends e-mails. For the variables and the $field function see vypis.php. */ ?>
<p class="hlaska"><?= e(t('The site sends password reset links and system notifications, and enquiries from forms. With your own SMTP server, messages go out from a verified mailbox and do not end up in spam.')) ?></p>
<fieldset>
<legend><?= e(t('Sending method')) ?></legend>
<div class="karty-volby">
	<label class="karta-volba"><input type="radio" name="mail_mode" value="mail"<?= $values['mail_mode'] !== 'smtp' ? ' checked' : '' ?> data-prepni="smtp:0"><strong><?= e(t('Hosting server')) ?></strong><span><?= e(t('The mail() function. Nothing to set up, but with some hosts messages end up in spam.')) ?></span></label>
	<label class="karta-volba"><input type="radio" name="mail_mode" value="smtp"<?= $values['mail_mode'] === 'smtp' ? ' checked' : '' ?> data-prepni="smtp:1"><strong><?= e(t('Custom SMTP server')) ?></strong><span><?= e(t('A mailbox at your host, Google Workspace, Seznam, or a service such as Brevo, Mailgun, Amazon SES… Recommended for newsletters.')) ?></span></label>
</div>
</fieldset>
<fieldset data-sekce="smtp"<?= $values['mail_mode'] === 'smtp' ? '' : ' hidden' ?>>
<legend><?= e(t('SMTP server')) ?></legend>
<?php
$field('smtp_host', 'Server address', 'text', 'For example smtp.gmail.com, smtp.seznam.cz, smtp-relay.brevo.com or smtp.vasedomena.cz.', 'maxlength="120" placeholder="smtp.example.com" autocomplete="off"');
?>
<div class="radek">
	<label for="smtp_encryption"><?= e(t('Zabezpečení')) ?></label>
	<div><select id="smtp_encryption" name="smtp_encryption">
		<option value="tls"<?= $values['smtp_encryption'] === 'tls' ? ' selected' : '' ?>><?= e(t('STARTTLS – port 587 (most common)')) ?></option>
		<option value="ssl"<?= $values['smtp_encryption'] === 'ssl' ? ' selected' : '' ?>><?= e(t('SSL/TLS – port 465')) ?></option>
		<option value="zadne"<?= $values['smtp_encryption'] === 'zadne' ? ' selected' : '' ?>><?= e(t('none – only for a server on your own network')) ?></option>
	</select></div>
</div>
<?php
$field('smtp_port', 'Port', 'cislo', '', 'min="1" max="65535"');
$field('smtp_user', 'Přihlašovací jméno', 'text', 'Usually the full e-mail address of the mailbox.', 'maxlength="190" autocomplete="off"');
?>
<div class="radek">
	<label for="smtp_password"><?= e(t('Password')) ?></label>
	<div><input class="textpole siroke" type="password" id="smtp_password" name="smtp_password" value="" autocomplete="new-password" placeholder="<?= $values['smtp_password'] !== '' ? e(t('password is saved – enter a new one only to change it')) : '' ?>">
	<span class="napoveda"><?= e(t('For Gmail and Seznam use an “app password”, not your account password. The password is stored only on your site and is never displayed back.')) ?></span>
<?php if ($values['smtp_password'] !== ''): ?>
	<label><input type="checkbox" name="smtp_password_smazat" value="1"> <?= e(t('Remove saved password')) ?></label>
<?php endif ?>
	</div>
</div>
<?php
$field('newsletter_hourly_limit', 'Newsletters: e-mails per hour', 'cislo', 'Newsletters go out in batches while cron runs. Keep to the sending limit of your SMTP service – free plans often allow only a few hundred e-mails a day.', 'min="10" max="100000"');
?>
</fieldset>
<details class="pokrocile"<?= $values['mail_from'] !== '' || $values['mail_reply_to'] !== '' ? ' open' : '' ?>>
<summary><?= e(t('Sender and replies')) ?></summary>
<?php
$field('mail_from', 'Sender address', 'email', 'Empty = the site e-mail. With SMTP it must be an address your mailbox is allowed to send from.');
$field('mail_reply_to', 'Send replies to', 'email', 'Optional – when readers\' replies should go somewhere other than the sender.');
?>
</details>
<p><button class="navigace" type="submit" formaction="<?= e($module->url('test_mail')) ?>"><?= e(t('Send a test e-mail to the site address')) ?></button> <span class="smltxt"><?= e(t('Save the settings first – the test uses the saved values.')) ?></span></p>
<fieldset>
<legend><?= e(t('Monthly report')) ?></legend>
<p class="napoveda"><?= e(t('In the first days of each month the site e-mails a short report about the previous month: traffic, enquiries and sign-ups, updates, backups, changes made by people and by Claude, the problems right now and what needs your decision. Counts and page addresses only – never names, e-mail addresses or the contents of enquiries, so the report can be forwarded. With the agency details from the General tab it carries the agency’s branding.')) ?></p>
<?php
$field('report_monthly', 'Send a monthly report by e-mail', 'ano', '');
$field('report_recipients', 'Recipients', 'radky', 'One address per line or separated by commas, at most 10. Empty = the site e-mail (Settings → General).', 'maxlength="2000"');
?>
<p><a class="navigace" href="<?= e($module->url('report_preview')) ?>" target="_blank" rel="noopener"><?= e(t('Preview last month')) ?></a> <button class="navigace" type="submit" formaction="<?= e($module->url('report_send')) ?>"><?= e(t('Send last month’s report now')) ?></button> <span class="smltxt"><?= e(t('Save the settings first – sending uses the saved recipients.')) ?></span></p>
</fieldset>
<?php if (!empty($mail)): ?>
<h2><?= e(t('Recent messages')) ?></h2>
<div class="tab-obal"><table class="vypis">
<thead><tr><th scope="col"><?= e(t('Time')) ?></th><th scope="col"><?= e(t('Komu')) ?></th><th scope="col"><?= e(t('Subject')) ?></th><th scope="col"><?= e(t('Status')) ?></th></tr></thead>
<tbody>
<?php foreach ($mail as $z): ?>
<tr>
	<td class="cislo"><?= e(format_date($z['created_at'], true)) ?></td>
	<td><?= e($z['recipient']) ?></td>
	<td><?= e($z['subject']) ?></td>
	<td><?php if ($z['sent_at'] !== null): ?><span class="stitek stitek-vydano"><?= e(t('sent')) ?></span><?= (int) $z['attempts'] > 1 ? ' ' . e(t('on attempt %s', (int) $z['attempts'])) : '' ?>
<?php elseif ($z['next_attempt_at'] !== null): ?><span class="stitek stitek-koncept"><?= e(t('waiting for the next attempt')) ?></span> <?= e(format_date($z['next_attempt_at'], true)) ?><br><small><?= e($z['error']) ?></small>
<?php else: ?><span class="stitek stitek-koncept"><?= e(t('not sent')) ?></span><br><small><?= e($z['error']) ?></small><?php endif ?></td>
</tr>
<?php endforeach ?>
</tbody></table></div>
<p class="smltxt"><?= e(t('A message that fails to send is retried after 5 minutes, 30 minutes, 2 and 12 hours. Records are deleted after 30 days; message content is not kept.')) ?></p>
<?php endif ?>
