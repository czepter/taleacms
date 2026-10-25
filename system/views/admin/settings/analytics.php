<?php
/**
 * The "Analytics" tab (Talea\Admin\AnalyticsServices): the overview of every service, the page of one service, and the
 * "Connect a service" wizard – choose (?service=connect), connect (the service's page with &connect=1), done (&connected=1).
 * Plain links and the settings form; no script.
 *
 * @var string $service  '' = the overview, 'connect' = choosing in the wizard, else a page or a single service
 * @var bool $connecting  the service's page as the wizard's second step
 * @var bool $connected  the wizard's last step: the service has just been saved
 */
use Talea\Admin\AnalyticsServices as Services;

$address = fn (string $query = ''): string => $app->url('admin.php?module=settings&tab=analytics' . $query);
$runs = fn (string $key): string => t(Services::RUNS[Services::SERVICES[$key][2]]);
$steps = function (int $at): void {
    echo '<ol class="transfer-steps">';
    foreach ([1 => 'Choose a service', 2 => 'Connect', 3 => 'Done'] as $number => $name) {
        echo '<li' . ($number === $at ? ' class="active" aria-current="step"' : ($number < $at ? ' class="finished"' : '')) . '><span>' . $number . '</span> ' . e(t($name)) . '</li>';
    }
    echo '</ol>';
};
$doubleCount = $values['gtm_id'] !== '' && $values['ga4_id'] !== '';
?>
<?php if ($service === ''): ?>
<div class="analytics-head">
	<p><?= e(t('Every service has its own page. Here you see what is connected and when it runs: right away, or only after the visitor agrees in the cookie bar.')) ?></p>
	<a class="btn" href="<?= e($address('&service=connect')) ?>"><?= e(t('Connect a service')) ?></a>
</div>
<?php if ($doubleCount): ?>
<p class="notice notice-warning"><?= e(t('Google Analytics is set here and probably also in your Tag Manager container – visits would be counted twice. Keep it in one place.')) ?></p>
<?php endif ?>
<?php foreach (Services::GROUPS as $group => [$heading, $note]): ?>
<fieldset class="analytics-group">
<legend><?= e(t($heading)) ?></legend>
<p class="help"><?= e(t($note)) ?></p>
<div class="tab-wrap"><table class="listing">
<thead><tr><th scope="col"><?= e(t('Service')) ?></th><th scope="col"><?= e(t('Status')) ?></th><th scope="col"><?= e(t('Runs')) ?></th><td></td></tr></thead>
<tbody>
<?php if ($group === 'count'): $statsOn = in_array('stats', $enabledExtensions, true); // 3.2: a feature with one switch, under Features ?>
<tr>
	<td class="cell-main"><span class="cell-title"><?= e(t('Built-in statistics')) ?></span></td>
	<td><span class="status <?= $statsOn ? 'status-live' : 'status-off' ?>"><?= e(t($statsOn ? 'On' : 'Off')) ?></span></td>
	<td class="analytics-runs"><span class="tag"><?= e(t(Services::RUNS['none'])) ?></span></td>
	<td class="cell-actions"><a class="navigation" href="<?= e($app->url('admin.php?module=extensions')) ?>"><?= e(t('Features')) ?></a></td>
</tr>
<?php endif ?>
<?php foreach (Services::SERVICES as $key => [$name, $inGroup, , $fields, $page]): if ($inGroup !== $group) { continue; } $on = Services::connected($key, $values); ?>
<tr>
	<td class="cell-main"><a class="cell-title" href="<?= e($address('&service=' . $page)) ?>"><?= e(t($name)) ?></a></td>
	<td><span class="status <?= $on ? 'status-live' : 'status-off' ?>"><?= e(t($on ? 'Connected' : 'Not connected')) ?></span>
<?php if ($on && $group !== 'code'): ?>
		<span class="status-note status-note-neutral"><bdi><?= e($values[array_key_first($fields)]) ?></bdi></span>
<?php endif ?>
	</td>
	<td class="analytics-runs"><span class="tag"><?= e($runs($key)) ?></span></td>
	<td class="cell-actions"><a class="navigation" href="<?= e($address('&service=' . ($on || $group === 'code' ? $page : $key . '&connect=1'))) ?>"><?= e(t($on || $group === 'code' ? 'Edit' : 'Connect')) ?></a></td>
</tr>
<?php endforeach ?>
</tbody>
</table></div>
</fieldset>
<?php endforeach ?>

<?php elseif ($service === 'connect'): ?>
<?php $steps(1) ?>
<p><?= e(t('Choose a service. Then all it needs is its ID – Talea takes care of the script, the cookie bar and the privacy policy.')) ?></p>
<?php foreach (Services::GROUPS as $group => [$heading, $note]): if ($group === 'code') { continue; } ?>
<fieldset class="analytics-group">
<legend><?= e(t($heading)) ?></legend>
<p class="help"><?= e(t($note)) ?></p>
<div class="onboarding-choices">
<?php foreach (Services::SERVICES as $key => [$name, $inGroup]): if ($inGroup !== $group) { continue; } ?>
	<a class="onboarding-choice" href="<?= e($address('&service=' . $key . '&connect=1')) ?>"><strong><?= e(t($name)) ?></strong>
		<small><?= e($runs($key)) ?></small>
<?php if (Services::connected($key, $values)): ?>
		<span class="status status-live"><?= e(t('Connected')) ?></span>
<?php endif ?>
	</a>
<?php endforeach ?>
</div>
</fieldset>
<?php endforeach ?>
<p class="navigation-row"><a class="navigation" href="<?= e($address()) ?>"><?= e(t('Cancel')) ?></a></p>

<?php elseif ($connected): $key = Services::services($service)[0]; $name = t(Services::SERVICES[$key][0]); $consent = Services::SERVICES[$key][2]; ?>
<?php $steps(3) ?>
<p class="notice notice-ok"><?= e(t('%s is connected.', $name)) ?></p>
<h2 class="analytics-title"><?= e(t('What changed')) ?></h2>
<ul class="analytics-changes">
<?php if ($consent === 'none'): ?>
	<li><?= e(t('%s now loads on every page. It sets no cookies, so the cookie bar stays as it is.', $name)) ?></li>
<?php elseif ($app->settings()->get('cookies_mode') === 'none'): ?>
	<li><?= e(t('%s now loads on every page right away: the site has no cookie bar. Visitors in the EU must agree first – switch the bar on under Settings → Privacy and cookies.', $name)) ?></li>
<?php else: ?>
	<li><?= e(t('%s now loads on every page once the visitor agrees in the cookie bar; the bar and the cookie table name it.', $name)) ?></li>
<?php endif ?>
	<li><?= e(t('The privacy policy template and the record of processing name %s.', $name)) ?></li>
	<li><?= e(t('%s has its own page under Settings → Analytics.', $name)) ?></li>
</ul>
<p class="navigation-row">
	<a class="btn" href="<?= e($address()) ?>"><?= e(t('Back to the overview')) ?></a>
	<a class="navigation" href="<?= e($address('&service=connect')) ?>"><?= e(t('Connect another service')) ?></a>
</p>

<?php else: $shown = Services::services($service); $page = Services::page($service);
    $fields = array_merge(...array_map(fn (string $key): array => array_keys(Services::SERVICES[$key][3]), $shown));
    $show = fn (string $key): bool => in_array($key, $fields, true);
    $on = array_filter($shown, fn (string $key): bool => Services::connected($key, $values)) !== [];
    $hosted = in_array($page, ['plausible', 'umami'], true); ?>
<?php if ($connecting): $steps(2); endif ?>
<p class="analytics-path"><a href="<?= e($address()) ?>"><?= e(t('Analytics')) ?></a> › <?= e(t(count($shown) === 1 ? Services::SERVICES[$shown[0]][0] : Services::PAGES[$page])) ?></p>
<p class="analytics-state"><span class="status <?= $on ? 'status-live' : 'status-off' ?>"><?= e(t($on ? 'Connected' : 'Not connected')) ?></span> <span class="tag"><?= e($runs($shown[0])) ?></span></p>
<input type="hidden" name="service" value="<?= e($service) ?>">
<?php if ($connecting): ?>
<input type="hidden" name="connect" value="1">
<?php endif ?>
<?php if ($page === 'pixels'): ?>
<p class="help"><?= e(t('Only the ID is needed. The pixels run only after the visitor allows marketing in the cookie bar (the Privacy and cookies tab) and appear in the cookie table.')) ?></p>
<?php endif ?>
<?php if ($doubleCount && in_array($page, ['google-analytics', 'tag-manager'], true)): ?>
<p class="notice notice-warning"><?= e(t('Google Analytics is set here and probably also in your Tag Manager container – visits would be counted twice. Keep it in one place.')) ?></p>
<?php endif ?>
<?php if ($hosted): $own = $values[$page . '_url'] !== ''; ?>
<div class="row">
	<span class="caption"><?= e(t('Where does it run?')) ?></span>
	<div class="options">
		<label><input type="radio" name="hosting" value="cloud"<?= $own ? '' : ' checked' ?>> <?= e($page === 'plausible' ? 'plausible.io' : 'Umami Cloud') ?></label>
		<label><input type="radio" name="hosting" value="own"<?= $own ? ' checked' : '' ?>> <?= e(t('Own installation')) ?></label>
	</div>
</div>
<?php endif ?>
<?php
if ($show('ga4_id')) $field('ga4_id', 'Google Analytics', 'text', 'The measurement ID in the form G-XXXXXXXXXX is enough. With a cookie bar on, it runs only after the visitor\'s consent (the Privacy and cookies tab).', 'placeholder="G-" maxlength="24"');
if ($show('gtm_id')) $field('gtm_id', 'Google Tag Manager', 'text', 'The container ID in the form GTM-XXXXXXX. Consent mode is built in: with the built-in cookie bar the container starts after the visitor allows analytics or marketing.', 'placeholder="GTM-" maxlength="16"');
if ($show('matomo_url')) $field('matomo_url', 'Matomo – address', 'url', 'The address of your installation, e.g. https://statistiky.example.cz/', 'placeholder="https://"');
if ($show('matomo_id')) $field('matomo_id', 'Matomo – site ID', 'number', 'In Matomo under Administration → Websites → Manage.', 'min="0"');
if ($show('plausible_domain')) $field('plausible_domain', 'Plausible – domain', 'text', 'The domain as the site is entered in Plausible.', 'placeholder="example.cz" maxlength="100"');
?>
<?php if ($show('plausible_url')): ?><div class="analytics-own"><?php $field('plausible_url', 'Plausible – address', 'url', 'Only for your own installation, e.g. https://plausible.example.cz/ – empty means plausible.io.', 'placeholder="https://"') ?></div><?php endif ?>
<?php if ($show('umami_id')) $field('umami_id', 'Umami – website ID', 'text', 'In Umami under Settings → Websites → Edit.', 'placeholder="00000000-0000-0000-0000-000000000000" maxlength="36" spellcheck="false"'); ?>
<?php if ($show('umami_url')): ?><div class="analytics-own"><?php $field('umami_url', 'Umami – address', 'url', 'Only for your own installation, e.g. https://umami.example.cz/ – empty means Umami Cloud.', 'placeholder="https://"') ?></div><?php endif ?>
<?php
if ($show('meta_pixel_id')) $field('meta_pixel_id', 'Meta Pixel', 'text', 'In Meta Events Manager under Data sources.', 'inputmode="numeric" maxlength="20"');
if ($show('google_ads_id')) $field('google_ads_id', 'Google Ads', 'text', 'In Google Ads under Tools → Conversions; it starts with AW-.', 'placeholder="AW-" maxlength="18"');
if ($show('linkedin_partner_id')) $field('linkedin_partner_id', 'LinkedIn Insight Tag', 'text', 'In Campaign Manager under Analyze → Insight Tag.', 'inputmode="numeric" maxlength="12"');
if ($show('tiktok_pixel_id')) $field('tiktok_pixel_id', 'TikTok Pixel', 'text', 'In TikTok Ads Manager under Assets → Events.', 'maxlength="25" spellcheck="false"');
if ($show('microsoft_uet_id')) $field('microsoft_uet_id', 'Microsoft Advertising (UET)', 'text', 'In Microsoft Advertising under Tools → UET tag.', 'inputmode="numeric" maxlength="12"');
if ($show('pinterest_tag_id')) $field('pinterest_tag_id', 'Pinterest Tag', 'text', 'In Pinterest Ads under Conversions.', 'inputmode="numeric" maxlength="16"');
if ($show('head_code')) $field('head_code', 'Custom code in the head', 'code', 'Inserted on every page regardless of consent – only for codes that do not store cookies.', 'spellcheck="false"');
?>
<?php if ($page === 'tag-manager'): ?>
<p class="help"><?= e(t('Talea sends these events to the data layer for your tags: generate_lead (a form was sent), sign_up (newsletter), popup_conversion, click_phone, click_email and file_download.')) ?>
	<a href="<?= e($siteUrl . 'image/gtm-talea.json') ?>" download><?= e(t('Download a container template with these triggers')) ?></a></p>
<?php endif ?>
<p class="buttons">
	<button class="btn" type="submit"><?= e(t($connecting ? 'Connect' : 'Save settings')) ?></button>
	<a class="navigation" href="<?= e($address($connecting ? '&service=connect' : '')) ?>"><?= e(t($connecting ? 'Back' : 'Cancel')) ?></a>
<?php if ($on && !$connecting && $page !== 'pixels'): ?>
	<button class="navigation danger buttons-end" type="submit" name="disconnect" value="1" data-confirm="<?= e(t('Disconnect %s? The site stops loading it.', t(Services::PAGES[$page]))) ?>"><?= e(t('Disconnect')) ?></button>
<?php endif ?>
</p>
<?php endif ?>
