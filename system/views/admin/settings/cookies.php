<?php /** The "Privacy and cookies" tab. */ ?>
<fieldset>
<legend><?= e(t('Cookie bar')) ?></legend>
<div class="cards-options cards-options-text">
<?php foreach ([
    'builtin' => ['Built-in banner', 'Recommended. Shown only when there is something to consent to; tracking starts only after consent.'],
    'external' => ['External service', 'Cookiebot, CookieYes, Usercentrics… You paste in their code.'],
    'none' => ['None', 'Tracking codes run immediately. Only if you handle consent another way.'],
] as $key => [$name, $description]): ?>
	<label class="card-option">
		<input type="radio" name="cookies_mode" value="<?= e($key) ?>"<?= $values['cookies_mode'] === $key ? ' checked' : '' ?>>
		<strong><?= e(t($name)) ?></strong>
		<span><?= e(t($description)) ?></span>
	</label>
<?php endforeach ?>
</div>
<?php
$field('cookies_text', 'Banner text', 'lines');
$field('cookies_policy_url', 'Link to the policy', 'text', 'E.g. /privacy-policy – create the page in the Pages section.', 'maxlength="255"');
?>
</fieldset>
<details class="advanced"<?= $values['cookies_mode'] === 'external' || $values['marketing_code'] !== '' ? ' open' : '' ?>>
<summary><?= e(t('Codes and records')) ?></summary>
<?php
$field('cookies_external_code', 'External service code', 'code', 'The script from your provider (for Cookiebot, the line with data-cbid). It loads first.', 'spellcheck="false"');
$field('marketing_code', 'Marketing codes', 'code', 'Meta Pixel, Sklik retargeting, Google Ads… Runs only after marketing consent is given.', 'spellcheck="false"');
$field('lead_attribution', 'Remember where leads came from', 'flag', 'The first page of a visit, its campaign and the site that sent the visitor go with enquiries and newsletter sign-ups (Statistics → Campaigns and pages). Only for visitors who allow marketing in the cookie bar – turning it on adds that choice to the bar.');
$field('cookies_log', 'Log consents', 'flag', 'Time, a random identifier and the chosen categories – no IP address. Evidence in case of an audit.');
$field('cookies_log_months', 'Keep consent records (months)', 'number', 'Older records are deleted automatically. 0 = keep.', 'min="0" max="120"');
?>
</details>
<fieldset>
<legend><?= e(t('Spam check for forms')) ?></legend>
<p class="help"><?= e(t('Forms are protected without one: a signed time, a hidden trap field and a limit per address. Add a CAPTCHA only if spam still gets through. The provider receives the visitor’s address and browser details – name it in your privacy policy.')) ?></p>
<div class="row">
	<label for="captcha_provider"><?= e(t('Extra spam check')) ?></label>
	<div><select id="captcha_provider" name="captcha_provider">
		<option value=""<?= $values['captcha_provider'] === '' ? ' selected' : '' ?>><?= e(t('none – the built-in protection only')) ?></option>
<?php foreach (Kaleta\Core\Captcha::PROVIDERS as $key => [$name]): ?>
		<option value="<?= e($key) ?>"<?= $values['captcha_provider'] === $key ? ' selected' : '' ?>><?= e($name) ?></option>
<?php endforeach ?>
	</select>
	<span class="help"><?= e(t('hCaptcha and Cloudflare Turnstile suit EU sites; reCAPTCHA v3 is invisible and scores every visit.')) ?></span></div>
</div>
<?php $field('captcha_site_key', 'Site key', 'text', 'From the provider’s dashboard, for this site’s domain.', 'maxlength="100" autocomplete="off" spellcheck="false"'); ?>
<div class="row">
	<label for="captcha_secret"><?= e(t('Secret key')) ?></label>
	<div><input class="textfield wide" type="password" id="captcha_secret" name="captcha_secret" value="" autocomplete="new-password" placeholder="<?= $values['captcha_secret'] !== '' ? e(t('the key is saved – enter a new one only to change it')) : '' ?>">
	<span class="help"><?= e(t('Stays on your site; Claude cannot read it.')) ?></span>
<?php if ($values['captcha_secret'] !== ''): ?>
	<label><input type="checkbox" name="captcha_secret_delete" value="1"> <?= e(t('Remove the saved key')) ?></label>
<?php endif ?>
	</div>
</div>
<?php $field('captcha_fail_open', 'Accept forms when the provider is down', 'flag', 'The built-in protection still applies. Off = such forms are refused until the provider answers again.'); ?>
</fieldset>
<?php if ($consents !== []): ?>
<p class="help"><?= e(t('Consents in the last 30 days:')) ?> <?= implode(' · ', array_map(fn (array $r): string => e($r['categories'] === 'none' ? t('necessary only') : $r['categories']) . ' ' . (int) $r['count'] . '×', $consents)) ?></p>
<?php endif ?>
<fieldset>
<legend><?= e(t('Cookies and storage this site uses')) ?></legend>
<p class="help"><?= e(t('What Kaleta itself sets, what the known embeds and tags found in your pages and settings set (YouTube, Google Maps, Analytics, Tag Manager, Matomo, Meta Pixel, CAPTCHA) and what the server answers with. Put {{cookie_table}} into your cookie policy page – the table appears there in the site language.')) ?></p>
<table class="table cookies-table">
<thead><tr><th><?= e(t('Name')) ?></th><th><?= e(t('Provider')) ?></th><th><?= e(t('Purpose')) ?></th><th><?= e(t('Duration')) ?></th><th><?= e(t('Category')) ?></th></tr></thead>
<tbody>
<?php foreach ($cookieTable as $r): ?>
	<tr><td><code><?= e($r['name']) ?></code></td><td><?= e($r['provider']) ?></td><td><?= e($r['purpose']) ?></td><td><?= e($r['duration']) ?></td><td><?= e(t(Kaleta\Core\Privacy::CATEGORIES[$r['category']] ?? $r['category'])) ?></td></tr>
<?php endforeach ?>
</tbody>
</table>
<p class="help"><?= isset($cookieScan['time']) ? e(t('Last scan of the site’s own pages: %s, %d pages, %d cookies set by the server.', format_date(date('Y-m-d H:i:s', (int) $cookieScan['time']), true), (int) ($cookieScan['pages'] ?? 0), count($cookieScan['cookies'] ?? [])))
    . (($cookieScan['error'] ?? '') !== '' ? ' ' . e(t('The server could not reach the site: %s.', (string) $cookieScan['error'])) : '') : e(t('The site’s own pages have not been scanned yet – the background tasks do it daily.')) ?></p>
<p><button class="navigation" type="submit" formaction="<?= e($module->url('cookie_scan')) ?>"><?= e(t('Scan the site now')) ?></button></p>
</fieldset>
<fieldset>
<legend><?= e(t('Documents from the configuration')) ?></legend>
<p class="help"><?= e(t('Templates assembled from what the site is set up to do – review and complete them, they are not legal advice.')) ?></p>
<div class="row"><span class="caption"><?= e(t('Record of processing')) ?></span><div><a class="navigation" href="<?= e($module->url('processing_record')) ?>"><?= e(t('Show the record')) ?></a>
<span class="help"><?= e(t('GDPR Art. 30 style: forms and their fields, enquiries and applications with their retention, newsletter, statistics, connected services, mail, backups, the writing assistant, cookies.')) ?></span></div></div>
<div class="row"><span class="caption"><?= e(t('Accessibility statement')) ?></span><div>
<?php if ($statementPage !== null): ?>
	<a class="navigation" href="<?= e($app->url('admin.php?module=pages&action=builder&id=' . (int) $statementPage['page_id'])) ?>"><?= e($statementPage['title']) ?></a> · <?= e((int) $statementPage['visible'] === 1 ? t('published') : t('hidden draft')) ?> · <?= e(format_date((string) $statementPage['updated_at'], true)) ?>
<?php endif ?>
	<button class="navigation" type="submit" formaction="<?= e($module->url('accessibility_statement')) ?>"><?= e(t($statementPage !== null ? 'Regenerate the draft from the audit' : 'Create the draft from the audit')) ?></button>
<span class="help"><?= e(t('Filled from the site audit: the standard (EN 301 549 / WCAG 2.1 AA), the status by the accessibility findings, the known barriers and the contact. Created as a hidden page – review it, then publish it. Regenerating updates the draft.')) ?></span></div></div>
<?php $field('accessibility_toolbar', 'Accessibility toolbar for visitors', 'flag', 'A small button on every page: larger text, higher contrast, underlined links, reduced motion. Remembered in the visitor’s browser, no cookies.'); ?>
</fieldset>
