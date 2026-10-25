<?php
/** The "Newsletter" screen (Modules\NewsletterSettings): the connection to a mailing service. */
?>
<p class="navigation-row"><a class="navigation" href="<?= e($app->url('admin.php?module=subscribers')) ?>"><?= e(t('Subscribers and export')) ?></a></p>
<fieldset>
<legend><?= e(t('Connection to a mailing service')) ?></legend>
<p class="help"><?= e(t('After a sign-up is confirmed (double opt-in), the site adds the address to the list in your service and removes it after unsubscribing. Sending, deliverability and unsubscribing from e-mails stay with the service. The transfer runs in the background – visitors do not wait.')) ?></p>
<div class="row">
	<label for="newsletter_service"><?= e(t('Service')) ?></label>
	<div><select id="newsletter_service" name="newsletter_service">
		<option value=""><?= e(t('none – you export subscribers to CSV')) ?></option>
<?php foreach (Talea\Core\Newsletter::SERVICES as $key => [$name]): ?>
		<option value="<?= e($key) ?>"<?= $values['newsletter_service'] === $key ? ' selected' : '' ?>><?= e(t($name)) ?></option>
<?php endforeach ?>
	</select></div>
</div>
<div class="row">
	<label for="newsletter_key"><?= e(t('API key')) ?></label>
	<div><input class="textfield wide" type="password" id="newsletter_key" name="newsletter_key" value="" autocomplete="off" placeholder="<?= $values['newsletter_key'] !== '' ? e(t('saved key ending in %s – enter a new one only to change it', $values['newsletter_key'])) : '' ?>">
<?php if ($values['newsletter_key'] !== ''): ?>
	<label><input type="checkbox" name="newsletter_key_delete" value="1"> <?= e(t('Remove saved key')) ?></label>
<?php endif ?>
	<span class="help"><?= e(t('Create the key in your service account (API, integrations). For SmartEmailing, enter the user name and the key separated by a colon. The key is stored only on your site and is never shown over MCP.')) ?></span></div>
</div>
<div class="row">
	<label for="newsletter_list"><?= e(t('List')) ?></label>
	<div><input class="textfield" id="newsletter_list" name="newsletter_list" value="<?= e($values['newsletter_list']) ?>" maxlength="64" spellcheck="false">
	<span class="help"><?= e(t('The ID of the list (Brevo, Ecomail, SmartEmailing), group (MailerLite) or audience (Mailchimp) – you find it in the list settings in the service.')) ?></span></div>
</div>
<div class="row">
	<label for="newsletter_webhook"><?= e(t('Webhook URL')) ?></label>
	<div><input class="textfield wide" type="url" id="newsletter_webhook" name="newsletter_webhook" value="<?= e($values['newsletter_webhook']) ?>" placeholder="https://hook.eu1.make.com/…">
	<span class="help"><?= e(t('Only for “Another service via a webhook”: the site sends JSON with the event subscribed or unsubscribed and the e-mail.')) ?></span></div>
</div>
</fieldset>
