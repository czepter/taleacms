<?php
/** The "Features" tab. */
use Talea\Core\Extensions;
?>
<p class="notice"><?= e(t('Features are optional parts of Talea. All of them are part of the system and maintained by the Talea team – nothing is downloaded or installed. A switched-off feature disappears from the menu and the site; its data stays and returns when you switch it on again.')) ?></p>
<?php
// where an enabled extension is configured – each lives elsewhere in the admin, so the card leads straight to that place
$adminUrl = fn (string $query): string => $app->url('admin.php?' . $query);
$extensionSettings = [
    'news' => [[$adminUrl('module=news'), 'News'], [$adminUrl('module=categories'), 'Categories'], [$adminUrl('module=tags'), 'Tags']],
    'enquiries' => [[$adminUrl('module=enquiries'), 'Enquiries'], [$adminUrl('module=settings&tab=enquiries'), 'Retention'], [$adminUrl('module=settings&tab=webhooks'), 'Webhook to CRM']],
    'newsletter_signup' => [[$adminUrl('module=subscribers'), 'Subscribers and export'], [$adminUrl('module=newsletter_settings'), 'Connection to a mailing service']],
    'bookings' => [[$adminUrl('module=bookings'), 'Bookings'], [$adminUrl('module=bookings&action=services'), 'Services'], [$adminUrl('module=bookings&action=staff'), 'People']],
    'stats' => [[$adminUrl('module=stats'), 'Statistics'], [$adminUrl('module=settings&tab=analytics'), 'Analytics']],
    'redirects' => [[$adminUrl('module=redirects'), 'Redirects']],
    'languages' => [[$adminUrl('module=settings&tab=general#additional_languages'), 'Choose languages']],
    'assistant' => [[$adminUrl('module=settings&tab=ai#assistant'), 'Provider, key and model']],
    'claude' => [[$adminUrl('module=claude'), 'Claude connection'], [$adminUrl('module=settings&tab=ai'), 'AI settings']],
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
