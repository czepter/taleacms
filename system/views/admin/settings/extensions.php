<?php
/** The "Features" tab. */
use Kaleta\Core\Extensions;
?>
<p class="notice"><?= e(t('Features are optional parts of Kaleta. All of them are part of the system and maintained by the Kaleta team – nothing is downloaded or installed. A switched-off feature disappears from the menu and the site; its data stays and returns when you switch it on again.')) ?></p>
<?php
// where an enabled extension is configured – each lives elsewhere in the admin, so the card leads straight to that place
$adminUrl = fn (string $query): string => $app->url('admin.php?' . $query);
$extensionSettings = [
    'news' => [[$adminUrl('module=news'), 'News'], [$adminUrl('module=categories'), 'Categories'], [$adminUrl('module=tags'), 'Tags']],
    'enquiries' => [[$adminUrl('module=enquiries'), 'Enquiries and retention'], [$adminUrl('module=settings&tab=webhooks'), 'Webhook to CRM']],
    'newsletter_signup' => [[$adminUrl('module=subscribers'), 'Subscribers and export'], ['#newsletter', 'Connection to a mailing service']],
    'bookings' => [[$adminUrl('module=bookings'), 'Bookings'], [$adminUrl('module=bookings&action=services'), 'Services'], [$adminUrl('module=bookings&action=staff'), 'People']],
    'stats' => [[$adminUrl('module=stats'), 'Statistics'], [$adminUrl('module=settings&tab=analytics'), 'Analytics']],
    'redirects' => [[$adminUrl('module=redirects'), 'Redirects']],
    'languages' => [[$adminUrl('module=settings&tab=general#additional_languages'), 'Choose languages']],
    'assistant' => [['#assistant', 'Provider, key and model']],
    'claude' => [['#claude', 'How to connect Claude']],
];
?>
<div class="extensions-list">
<?php foreach (Extensions::CATALOG as $key => [$name, $description]): $isEnabled = in_array($key, $enabledExtensions, true); ?>
	<div class="extensions-card">
		<input type="checkbox" id="rozsireni-<?= e($key) ?>" name="extensions[]" value="<?= e($key) ?>"<?= $isEnabled ? ' checked' : '' ?>>
		<span><label for="rozsireni-<?= e($key) ?>"><strong><?= e(t($name)) ?></strong><br><?= e(t($description)) ?></label>
<?php if ($isEnabled && isset($extensionSettings[$key])): ?>
			<span class="extensions-links"><?php foreach ($extensionSettings[$key] as $i => [$url, $text]): ?><?= $i > 0 ? ' · ' : '' ?><a href="<?= e($url) ?>"><?= e(t($text)) ?></a><?php endforeach ?></span>
<?php endif ?>
		</span>
	</div>
<?php endforeach ?>
</div>
<p class="help"><?= e(t('Links for a feature appear once it is switched on and saved.')) ?></p>
<p class="help"><?= e(t('Always-on core: Pages, Collections, Media, Site appearance, Site parts, Menu, Components, Pop-ups, Users and roles, Import and export, Change log and Settings.')) ?></p>
<details class="advanced" id="assistant"<?= in_array('assistant', $enabledExtensions, true) ? ' open' : '' ?>>
<summary><?= e(t('Writing assistant – provider, your key and model')) ?></summary>
<input type="hidden" name="ai_provider_previous" value="<?= e($values['ai_provider']) ?>">
<div class="row">
	<label for="ai_provider"><?= e(t('Provider')) ?></label>
	<div><select id="ai_provider" name="ai_provider">
<?php foreach (Kaleta\Core\Assistant::PROVIDERS as $key => [$name, , $console]): ?>
		<option value="<?= e($key) ?>"<?= $values['ai_provider'] === $key ? ' selected' : '' ?>><?= e(t($name)) ?></option>
<?php endforeach ?>
	</select>
	<span class="help"><?= e(t('Create a key with the provider:')) ?>
<?php foreach (Kaleta\Core\Assistant::PROVIDERS as [$name, , $console]): ?>
		<a href="<?= e($console) ?>" target="_blank" rel="noopener"><?= e(t($name)) ?></a>
<?php endforeach ?>
		· <?= e(t('You pay only for actual use; one suggestion costs a fraction of a cent. The key is stored only on your site.')) ?></span></div>
</div>
<div class="row">
	<label for="ai_key"><?= e(t('API key')) ?></label>
	<div><input class="textfield wide" type="password" id="ai_key" name="ai_key" value="" autocomplete="off" placeholder="<?= $values['ai_key'] !== '' ? e(t('saved key ending in %s – enter a new one only to change it', $values['ai_key'])) : '' ?>">
<?php if ($values['ai_key'] !== ''): ?>
	<label><input type="checkbox" name="ai_key_delete" value="1"> <?= e(t('Remove saved key')) ?></label>
<?php endif ?>
	</div>
</div>
<div class="row">
	<label for="ai_model"><?= e(t('Model')) ?></label>
	<div><input class="textfield" id="ai_model" name="ai_model" value="<?= e($values['ai_model']) ?>" list="ai_modely" maxlength="80" spellcheck="false">
	<datalist id="ai_models">
<?php foreach (Kaleta\Core\Assistant::MODELS as $key => $name): ?>
		<option value="<?= e($key) ?>"><?= e(t($name)) ?></option>
<?php endforeach ?>
	</datalist>
	<span class="help"><?= e(t('For Claude, choose from the list (Sonnet is recommended). For other providers, enter the exact model name from their documentation – their model line-up changes often.')) ?></span></div>
</div>
<p class="help"><?= e(t('The assistant only suggests – a person decides on every change. When used, the text is sent to the chosen provider; nothing is sent anywhere without clicking an assistant button.')) ?></p>
</details>
<details class="advanced" id="newsletter"<?= in_array('newsletter_signup', $enabledExtensions, true) && $values['newsletter_service'] !== '' ? ' open' : '' ?>>
<summary><?= e(t('Newsletter – connection to a mailing service')) ?></summary>
<p class="help"><?= e(t('After a sign-up is confirmed (double opt-in), the site adds the address to the list in your service and removes it after unsubscribing. Sending, deliverability and unsubscribing from e-mails stay with the service. The transfer runs in the background – visitors do not wait.')) ?></p>
<div class="row">
	<label for="newsletter_service"><?= e(t('Service')) ?></label>
	<div><select id="newsletter_service" name="newsletter_service">
		<option value=""><?= e(t('none – you export subscribers to CSV')) ?></option>
<?php foreach (Kaleta\Core\Newsletter::SERVICES as $key => [$name]): ?>
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
	<label for="newsletter_list"><?= e(t('Leaf')) ?></label>
	<div><input class="textfield" id="newsletter_list" name="newsletter_list" value="<?= e($values['newsletter_list']) ?>" maxlength="64" spellcheck="false">
	<span class="help"><?= e(t('The ID of the list (Brevo, Ecomail, SmartEmailing), group (MailerLite) or audience (Mailchimp) – you find it in the list settings in the service.')) ?></span></div>
</div>
<div class="row">
	<label for="newsletter_webhook"><?= e(t('Webhook URL')) ?></label>
	<div><input class="textfield wide" type="url" id="newsletter_webhook" name="newsletter_webhook" value="<?= e($values['newsletter_webhook']) ?>" placeholder="https://hook.eu1.make.com/…">
	<span class="help"><?= e(t('Only for “Another service via a webhook”: the site sends JSON with the event subscribed or unsubscribed and the e-mail.')) ?></span></div>
</div>
</details>
<?php if (in_array('claude', $enabledExtensions, true)): ?>
<p class="notice"><?= e(t('How to connect Claude, its instructions, its guardrails and every connection are in')) ?> <a href="<?= e($app->url('admin.php?module=claude_settings')) ?>"><?= e(t('Claude settings')) ?></a>.</p>
<?php endif ?>
