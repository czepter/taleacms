<?php /** The "Analytics" tab. */ ?>
<fieldset>
<legend><?= e(t('Traffic')) ?></legend>
<?php /* 3.2: the built-in statistics are a feature – one switch, under Features; this tab only shows the state */ ?>
<div class="row">
	<span class="caption"><?= e(t('Built-in statistics')) ?></span>
	<div><?= e(in_array('stats', $enabledExtensions, true) ? t('On – visits, most-read news and traffic sources under Statistics. No cookies and no consent needed.') : t('Off – nothing is measured.')) ?>
	<span class="help"><?= sprintf(e(t('Switched on and off as a feature under %s.')), '<a href="' . e($app->url('admin.php?module=extensions')) . '">' . e(t('Features')) . '</a>') ?></span></div>
</div>
<?php
$field('ga4_id', 'Google Analytics', 'text', 'The measurement ID in the form G-XXXXXXXXXX is enough. With a cookie bar on, it runs only after the visitor\'s consent (the Privacy and cookies tab).', 'placeholder="G-" maxlength="24"');
$field('gtm_id', 'Google Tag Manager', 'text', 'The container ID in the form GTM-XXXXXXX. Consent mode is built in: with the built-in cookie bar the container starts after the visitor allows analytics or marketing.', 'placeholder="GTM-" maxlength="16"');
?>
<?php if ($values['gtm_id'] !== '' && $values['ga4_id'] !== ''): ?>
<p class="notice notice-warning"><?= e(t('Google Analytics is set here and probably also in your Tag Manager container – visits would be counted twice. Keep it in one place.')) ?></p>
<?php endif ?>
<p class="help"><?= e(t('Talea sends these events to the data layer for your tags: generate_lead (a form was sent), sign_up (newsletter), popup_conversion, click_phone, click_email and file_download.')) ?>
	<a href="<?= e($siteUrl . 'image/gtm-talea.json') ?>" download><?= e(t('Download a container template with these triggers')) ?></a></p>
</fieldset>
<details class="advanced"<?= $values['matomo_url'] . $values['plausible_domain'] . $values['umami_id'] . $values['head_code'] !== '' ? ' open' : '' ?>>
<summary><?= e(t('Other tools (Matomo, Plausible, Umami, custom code)')) ?></summary>
<?php
$field('matomo_url', 'Matomo – address', 'url', 'The address of your installation, e.g. https://statistiky.example.cz/', 'placeholder="https://"');
$field('matomo_id', 'Matomo – site ID', 'number', '', 'min="0"');
$field('plausible_domain', 'Plausible – domain', 'text', 'Uses no cookies, loads without consent.', 'placeholder="example.cz" maxlength="100"');
$field('plausible_url', 'Plausible – address', 'url', 'Only for your own installation, e.g. https://plausible.example.cz/ – empty means plausible.io.', 'placeholder="https://"');
$field('umami_id', 'Umami – website ID', 'text', 'Uses no cookies, loads without consent.', 'placeholder="00000000-0000-0000-0000-000000000000" maxlength="36" spellcheck="false"');
$field('umami_url', 'Umami – address', 'url', 'Only for your own installation, e.g. https://umami.example.cz/ – empty means Umami Cloud.', 'placeholder="https://"');
$field('head_code', 'Custom code in the head', 'code', 'Inserted on every page regardless of consent – only for codes that do not store cookies.', 'spellcheck="false"');
?>
</details>
<details class="advanced"<?= implode('', array_map(fn (string $key): string => $values[$key], array_keys(Talea\Front\Seo::PIXELS))) !== '' ? ' open' : '' ?>>
<summary><?= e(t('Advertising pixels (remarketing)')) ?></summary>
<p class="help"><?= e(t('Only the ID is needed. The pixels run only after the visitor allows marketing in the cookie bar (the Privacy and cookies tab) and appear in the cookie table.')) ?></p>
<?php
$field('meta_pixel_id', 'Meta Pixel', 'text', '', 'inputmode="numeric" maxlength="20"');
$field('google_ads_id', 'Google Ads', 'text', '', 'placeholder="AW-" maxlength="18"');
$field('linkedin_partner_id', 'LinkedIn Insight Tag', 'text', '', 'inputmode="numeric" maxlength="12"');
$field('tiktok_pixel_id', 'TikTok Pixel', 'text', '', 'maxlength="25" spellcheck="false"');
$field('microsoft_uet_id', 'Microsoft Advertising (UET)', 'text', '', 'inputmode="numeric" maxlength="12"');
$field('pinterest_tag_id', 'Pinterest Tag', 'text', '', 'inputmode="numeric" maxlength="16"');
?>
</details>
