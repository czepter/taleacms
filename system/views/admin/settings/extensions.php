<?php
/** The "Rozšíření" (Extensions) tab. */
use Kaleta\Core\Extensions;
?>
<p class="hlaska"><?= e(t('Extensions are optional parts of Kaleta. All of them are part of the system and maintained by the Kaleta team – nothing is downloaded or installed. A disabled extension disappears from the menu and the site; its data stays and returns when you enable it again.')) ?></p>
<?php
// where an enabled extension is configured – each lives elsewhere in the admin, so the card leads straight to that place
$adminUrl = fn (string $query): string => $app->url('admin.php?' . $query);
$extensionSettings = [
    'novinky' => [[$adminUrl('module=news'), 'Novinky'], [$adminUrl('module=categories'), 'Categories'], [$adminUrl('module=tags'), 'Tags']],
    'poptavky' => [[$adminUrl('module=enquiries'), 'Enquiries and retention'], [$adminUrl('module=settings&tab=webhooks'), 'Webhook to CRM']],
    'newsletter' => [[$adminUrl('module=subscribers'), 'Subscribers and export'], ['#newsletter', 'Connection to a mailing service']],
    'statistika' => [[$adminUrl('module=stats'), 'Statistics'], [$adminUrl('module=settings&tab=analytics'), 'Analytics']],
    'presmerovani' => [[$adminUrl('module=redirects'), 'Redirects']],
    'jazyky' => [[$adminUrl('module=settings&tab=general#additional_languages'), 'Choose languages']],
    'asistent' => [['#asistent', 'Provider, key and model']],
    'claude' => [['#claude', 'How to connect Claude']],
];
?>
<div class="rozsireni-seznam">
<?php foreach (Extensions::CATALOG as $key => [$name, $description]): $isEnabled = in_array($key, $enabledExtensions, true); ?>
	<div class="rozsireni-karta">
		<input type="checkbox" id="rozsireni-<?= e($key) ?>" name="rozsireni[]" value="<?= e($key) ?>"<?= $isEnabled ? ' checked' : '' ?>>
		<span><label for="rozsireni-<?= e($key) ?>"><strong><?= e(t($name)) ?></strong><br><?= e(t($description)) ?></label>
<?php if ($isEnabled && isset($extensionSettings[$key])): ?>
			<span class="rozsireni-odkazy"><?php foreach ($extensionSettings[$key] as $i => [$url, $text]): ?><?= $i > 0 ? ' · ' : '' ?><a href="<?= e($url) ?>"><?= e(t($text)) ?></a><?php endforeach ?></span>
<?php endif ?>
		</span>
	</div>
<?php endforeach ?>
</div>
<p class="napoveda"><?= e(t('Links for an extension appear once it is switched on and saved.')) ?></p>
<p class="napoveda"><?= e(t('Always-on core: Pages, Collections, Media, Site appearance, Site parts, Menu, Components, Pop-ups, Users and roles, Import and export, Change log and Settings.')) ?></p>
<details class="pokrocile" id="asistent"<?= in_array('asistent', $enabledExtensions, true) ? ' open' : '' ?>>
<summary><?= e(t('AI assistant – provider, key and model')) ?></summary>
<input type="hidden" name="ai_poskytovatel_puvodni" value="<?= e($values['ai_provider']) ?>">
<div class="radek">
	<label for="ai_provider"><?= e(t('Provider')) ?></label>
	<div><select id="ai_provider" name="ai_provider">
<?php foreach (Kaleta\Core\Assistant::PROVIDERS as $key => [$name, , $console]): ?>
		<option value="<?= e($key) ?>"<?= $values['ai_provider'] === $key ? ' selected' : '' ?>><?= e(t($name)) ?></option>
<?php endforeach ?>
	</select>
	<span class="napoveda"><?= e(t('Create a key with the provider:')) ?>
<?php foreach (Kaleta\Core\Assistant::PROVIDERS as [$name, , $console]): ?>
		<a href="<?= e($console) ?>" target="_blank" rel="noopener"><?= e(t($name)) ?></a>
<?php endforeach ?>
		· <?= e(t('You pay only for actual use; one suggestion costs a fraction of a cent. The key is stored only on your site.')) ?></span></div>
</div>
<div class="radek">
	<label for="ai_key"><?= e(t('API key')) ?></label>
	<div><input class="textpole siroke" type="password" id="ai_key" name="ai_key" value="" autocomplete="off" placeholder="<?= $values['ai_key'] !== '' ? e(t('saved key ending in %s – enter a new one only to change it', $values['ai_key'])) : '' ?>">
<?php if ($values['ai_key'] !== ''): ?>
	<label><input type="checkbox" name="ai_key_smazat" value="1"> <?= e(t('Remove saved key')) ?></label>
<?php endif ?>
	</div>
</div>
<div class="radek">
	<label for="ai_model"><?= e(t('Model')) ?></label>
	<div><input class="textpole" id="ai_model" name="ai_model" value="<?= e($values['ai_model']) ?>" list="ai_modely" maxlength="80" spellcheck="false">
	<datalist id="ai_modely">
<?php foreach (Kaleta\Core\Assistant::MODELS as $key => $name): ?>
		<option value="<?= e($key) ?>"><?= e(t($name)) ?></option>
<?php endforeach ?>
	</datalist>
	<span class="napoveda"><?= e(t('For Claude, choose from the list (Sonnet is recommended). For other providers, enter the exact model name from their documentation – their model line-up changes often.')) ?></span></div>
</div>
<p class="napoveda"><?= e(t('The assistant only suggests – a person decides on every change. When used, the text is sent to the chosen provider; nothing is sent anywhere without clicking an assistant button.')) ?></p>
</details>
<details class="pokrocile" id="newsletter"<?= in_array('newsletter', $enabledExtensions, true) && $values['newsletter_service'] !== '' ? ' open' : '' ?>>
<summary><?= e(t('Newsletter – connection to a mailing service')) ?></summary>
<p class="napoveda"><?= e(t('After a sign-up is confirmed (double opt-in), the site adds the address to the list in your service and removes it after unsubscribing. Sending, deliverability and unsubscribing from e-mails stay with the service. The transfer runs in the background – visitors do not wait.')) ?></p>
<div class="radek">
	<label for="newsletter_service"><?= e(t('Service')) ?></label>
	<div><select id="newsletter_service" name="newsletter_service">
		<option value=""><?= e(t('none – you export subscribers to CSV')) ?></option>
<?php foreach (Kaleta\Core\Newsletter::SERVICES as $key => [$name]): ?>
		<option value="<?= e($key) ?>"<?= $values['newsletter_service'] === $key ? ' selected' : '' ?>><?= e(t($name)) ?></option>
<?php endforeach ?>
	</select></div>
</div>
<div class="radek">
	<label for="newsletter_key"><?= e(t('API key')) ?></label>
	<div><input class="textpole siroke" type="password" id="newsletter_key" name="newsletter_key" value="" autocomplete="off" placeholder="<?= $values['newsletter_key'] !== '' ? e(t('saved key ending in %s – enter a new one only to change it', $values['newsletter_key'])) : '' ?>">
<?php if ($values['newsletter_key'] !== ''): ?>
	<label><input type="checkbox" name="newsletter_key_smazat" value="1"> <?= e(t('Remove saved key')) ?></label>
<?php endif ?>
	<span class="napoveda"><?= e(t('Create the key in your service account (API, integrations). For SmartEmailing, enter the user name and the key separated by a colon. The key is stored only on your site and is never shown over MCP.')) ?></span></div>
</div>
<div class="radek">
	<label for="newsletter_list"><?= e(t('Seznam')) ?></label>
	<div><input class="textpole" id="newsletter_list" name="newsletter_list" value="<?= e($values['newsletter_list']) ?>" maxlength="64" spellcheck="false">
	<span class="napoveda"><?= e(t('The ID of the list (Brevo, Ecomail, SmartEmailing), group (MailerLite) or audience (Mailchimp) – you find it in the list settings in the service.')) ?></span></div>
</div>
<div class="radek">
	<label for="newsletter_webhook"><?= e(t('Webhook URL')) ?></label>
	<div><input class="textpole siroke" type="url" id="newsletter_webhook" name="newsletter_webhook" value="<?= e($values['newsletter_webhook']) ?>" placeholder="https://hook.eu1.make.com/…">
	<span class="napoveda"><?= e(t('Only for “Another service via a webhook”: the site sends JSON with the event novy_odberatel or odhlaseni_odberu and the e-mail.')) ?></span></div>
</div>
</details>
<?php if (in_array('claude', $enabledExtensions, true)): $mcpUrl = $app->request->origin() . $app->url('mcp'); ?>
<details class="pokrocile" id="claude" open>
<summary><?= e(t('Claude connection – how to connect')) ?></summary>
<ol class="navod">
	<li><?= e(t('In the Claude app open Settings → Connectors → Add custom connector.')) ?></li>
	<li><?= e(t('Enter this address:')) ?> <code class="totp-klic" style="font-size:13px"><?= e($mcpUrl) ?></code></li>
	<li><?= e(t('Claude sends you here to sign in. You choose what it may do: everything your account may, only save drafts, or only read.')) ?></li>
</ol>
<p class="napoveda"><?= e(t('In Claude Code, just run:')) ?> <code>claude mcp add --transport http kaleta <?= e($mcpUrl) ?></code>. <?= e(t('Connected applications and personal tokens for other tools are in')) ?> <a href="<?= e($app->url('admin.php?action=account#claude')) ?>"><?= e(t('My account')) ?></a>.
<?= e(t('The connector needs the site on HTTPS. In a subfolder, the Claude app finds the sign-in on its own; for a tool that does not, use a personal token.')) ?></p>
<div class="radek">
	<label for="claude_instructions"><?= e(t('Instructions for Claude')) ?></label>
	<div><textarea class="textpole siroke" id="claude_instructions" name="claude_instructions" rows="6" maxlength="5000" placeholder="<?= e(t('e.g. We address customers informally. Say “renovation”, never “reconstruction”. Keep headings short. Always offer a free visit.')) ?>"><?= e($values['claude_instructions']) ?></textarea>
	<span class="napoveda"><?= e(t('Brand voice, words to use or avoid, house rules. Every Claude connection gets them when it connects, and they are its resource kaleta://instructions.')) ?></span></div>
</div>
<h3><?= e(t('Guardrails for Claude')) ?></h3>
<p class="napoveda"><?= e(t('They hold for every Claude connection on top of its access. Claude is told why when it hits one, so it can tell you.')) ?></p>
<div class="radek">
	<label for="claude_change_limit"><?= e(t('Changes per connection and hour')) ?></label>
	<div><input class="textpole" type="number" id="claude_change_limit" name="claude_change_limit" min="0" max="10000" value="<?= e($values['claude_change_limit']) ?>">
	<span class="napoveda"><?= e(t('0 = no limit. A long run of changes stops here until the next hour.')) ?></span></div>
</div>
<div class="radek">
	<span class="popisek"><?= e(t('Deleting')) ?></span>
	<div class="volby"><label><input type="hidden" name="claude_destructive" value="0"><input type="checkbox" name="claude_destructive" value="1"<?= $values['claude_destructive'] === '1' ? ' checked' : '' ?>> <?= e(t('Claude may delete, trash and discard drafts')) ?></label>
	<span class="napoveda"><?= e(t('Off: those tools are refused for every connection; you do them in the admin.')) ?></span></div>
</div>
<div class="radek">
	<label for="claude_protected_pages"><?= e(t('Protected pages')) ?></label>
	<div><input class="textpole" type="text" id="claude_protected_pages" name="claude_protected_pages" value="<?= e($values['claude_protected_pages']) ?>" placeholder="12, 15" pattern="[0-9 ,;]*">
	<span class="napoveda"><?= e(t('Page numbers (shown in the page editor’s address) that Claude must not change – neither their settings nor their build.')) ?></span></div>
</div>
</details>
<?php endif ?>
