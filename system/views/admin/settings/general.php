<?php /** The "Základní" (General) tab. For the variables and the $field function see vypis.php. */ ?>
<fieldset>
<legend><?= e(t('Website')) ?></legend>
<?php
$field('site_name', 'Site name', 'text', '', 'maxlength="150" required');
$field('site_url', 'Site address', 'url', 'For example https://www.example.com, without a trailing slash. Links in e-mails, RSS, the sitemap and notifications are built from it. Change it after moving to another domain.', 'required placeholder="https://"');
$field('site_description', 'Site description', 'radky', 'One or two sentences – a motto, a description for search engines and RSS.');
$field('site_email', 'Site email', 'email', 'System notifications are sent to it.');
?>
<div class="radek">
	<label for="require_2fa"><?= e(t('Two-factor sign-in')) ?></label>
	<div><select id="require_2fa" name="require_2fa">
<?php foreach (['' => 'dobrovolné', 'spravci' => 'required for administrators', 'vsichni' => 'required for all users'] as $k => $n): ?>
		<option value="<?= e($k) ?>"<?= ($values['require_2fa'] ?? '') === $k ? ' selected' : '' ?>><?= e(t($n)) ?></option>
<?php endforeach ?>
	</select><span class="napoveda"><?= e(t('Anyone who must have it and has not turned it on yet can only reach My account after signing in until they set it up.')) ?></span></div>
</div>
<?php $autoSuspend = explode(',', $values['auto_suspend'] ?? ''); ?>
<div class="radek">
	<span class="popisek"><?= e(t('Suspend automatically')) ?></span>
	<div class="volby">
		<label><input type="checkbox" name="auto_suspend[]" value="<?= e(Kaleta\Core\SecurityHygiene::SUSPEND_ACCOUNTS) ?>"<?= in_array(Kaleta\Core\SecurityHygiene::SUSPEND_ACCOUNTS, $autoSuspend, true) ? ' checked' : '' ?>> <?= e(t('block accounts nobody has used for %d days (never the last administrator)', Kaleta\Core\SecurityHygiene::ACCOUNT_DAYS)) ?></label><br>
		<label><input type="checkbox" name="auto_suspend[]" value="<?= e(Kaleta\Core\SecurityHygiene::SUSPEND_CONNECTIONS) ?>"<?= in_array(Kaleta\Core\SecurityHygiene::SUSPEND_CONNECTIONS, $autoSuspend, true) ? ' checked' : '' ?>> <?= e(t('revoke Claude connections nobody has used for %d days', Kaleta\Core\SecurityHygiene::CONNECTION_DAYS)) ?></label>
		<span class="napoveda"><?= e(t('Checked once a day. While this is off, System status and the site audit only report unused accounts and connections. A blocked account shows the reason in Users and an administrator can reactivate it; a revoked connection has to be connected again.')) ?></span>
	</div>
</div>
<?php
?>
<?php if (($additionalLanguages = Kaleta\Core\Language::additional($app->settings())) !== []): ?>
<details class="pokrocile"<?= array_filter($additionalLanguages, fn (string $j): bool => ($values['nazev_webu_' . $j] ?? '') . ($values['popis_webu_' . $j] ?? '') !== '') !== [] ? ' open' : '' ?>>
<summary><?= e(t('Name and description in other language versions')) ?></summary>
<p class="napoveda"><?= e(t('An empty field means the same text as in the default language.')) ?></p>
<?php foreach ($additionalLanguages as $j): ?>
<div class="radek"><label for="nazev_webu_<?= e($j) ?>"><?= e(t('Site name')) ?> (<?= e(strtoupper($j)) ?>)</label><div><input class="textpole siroke" type="text" id="nazev_webu_<?= e($j) ?>" name="nazev_webu_<?= e($j) ?>" value="<?= e($values['nazev_webu_' . $j] ?? '') ?>" maxlength="150" lang="<?= e($j) ?>"></div></div>
<div class="radek"><label for="popis_webu_<?= e($j) ?>"><?= e(t('Site description')) ?> (<?= e(strtoupper($j)) ?>)</label><div><textarea class="textbox radkovy" id="popis_webu_<?= e($j) ?>" name="popis_webu_<?= e($j) ?>" rows="2" cols="60" lang="<?= e($j) ?>"><?= e($values['popis_webu_' . $j] ?? '') ?></textarea></div></div>
<?php endforeach ?>
</details>
<?php endif ?>
<div class="radek">
	<label for="time_zone"><?= e(t('Time zone')) ?></label>
	<div><select id="time_zone" name="time_zone">
<?php foreach (DateTimeZone::listIdentifiers() as $timeZone): ?>
		<option value="<?= e($timeZone) ?>"<?= $values['time_zone'] === $timeZone ? ' selected' : '' ?>><?= e(str_replace('_', ' ', $timeZone)) ?></option>
<?php endforeach ?>
	</select>
	<span class="napoveda"><?= e(t('Scheduled news is published and dates are shown according to it. It is now %s.', format_date(new DateTimeImmutable(), true))) ?></span></div>
</div>
<div class="radek">
	<label for="site_language"><?= e(t('Jazyk webu')) ?></label>
	<div><select id="site_language" name="site_language">
<?php foreach (Kaleta\Core\Language::AVAILABLE as $code => [$languageName]): ?>
		<option value="<?= e($code) ?>"<?= $values['site_language'] === $code ? ' selected' : '' ?>><?= e($languageName) ?></option>
<?php endforeach ?>
	</select>
	<span class="napoveda"><?= e(t('The site texts (Search, News, Read more…) are in this language, and the site declares it to search engines.')) ?></span></div>
</div>
<?php if (Kaleta\Core\Extensions::isEnabled($app->settings(), 'jazyky')): ?>
<div class="radek" id="additional_languages">
	<span class="popisek"><?= e(t('Other language versions')) ?></span>
	<div class="volby">
		<div class="volby-jazyky">
<?php foreach (Kaleta\Core\Language::AVAILABLE as $code => [$languageName]): if ($code === $values['site_language']) { continue; } ?>
		<label><input type="checkbox" name="additional_languages[]" value="<?= e($code) ?>"<?= in_array($code, explode(',', $values['additional_languages']), true) ? ' checked' : '' ?>> <?= e($languageName) ?> <small>(/<?= e($code) ?>/)</small></label>
<?php endforeach ?>
		</div>
		<span class="napoveda"><?= e(t('Each version has its own pages, categories and news. A news item\'s language is set by its category. Link translations in the editor.')) ?></span>
<?php $translated = array_map(fn (string $code): string => Kaleta\Core\Language::AVAILABLE[$code][0], array_values(array_filter(array_keys(Kaleta\Core\Language::AVAILABLE), fn (string $code): bool => $code === 'cs' || is_file(KALETA_SYSTEM . '/jazyky/' . $code . '.php')))); ?>
		<span class="napoveda"><?= e(t('Texts for visitors (Search, Read more…) are translated into: %s. Other languages show them in English, with dates in their own format. You write the content of pages and news in the language of the version.', implode(', ', $translated))) ?></span>
	</div>
</div>
<?php else: ?>
<?php foreach (array_filter(explode(',', $values['additional_languages'])) as $code): ?><input type="hidden" name="additional_languages[]" value="<?= e($code) ?>"><?php endforeach ?>
<?php endif ?>
</fieldset>
<fieldset>
<legend><?= e(t('Home page and news')) ?></legend>
<div class="radek">
	<label for="home_page"><?= e(t('Site home page')) ?></label>
	<div><select id="home_page" name="home_page">
		<option value="0"><?= e(t('– news list –')) ?></option>
<?php foreach ($pages as $pageId => $pageTitle): ?>
		<option value="<?= (int) $pageId ?>"<?= (int) $values['home_page'] === (int) $pageId ? ' selected' : '' ?>><?= e($pageTitle) ?></option>
<?php endforeach ?>
	</select>
	<span class="napoveda"><?= e(t('The page shown at the site address. News is always at %s.', substr($app->url('novinky'), strlen($app->request->basePath())))) ?></span></div>
</div>
<?php $field('news_per_page', 'News items per page', 'cislo', '', 'min="1" max="100"'); ?>
</fieldset>
<fieldset>
<legend><?= e(t('Built and looked after by')) ?></legend>
<p class="napoveda"><?= e(t('The agency or freelancer who looks after the site. The sign-in screen and the foot of the admin show whom to ask for help.')) ?></p>
<?php
$field('agency_name', 'Name');
$field('agency_url', 'Website', 'url');
$field('agency_email', 'E-mail for help', 'email');
$field('agency_phone', 'Phone for help');
$field('agency_logo', 'Logo', 'text', 'A path from Media, e.g. media/2026/10/agency.svg – click the file in Media to open it, its address starts with media/.');
?>
</fieldset>
<details class="pokrocile"<?= $values['maintenance'] === '1' ? ' open' : '' ?>>
<summary><?= e(t('Maintenance mode')) ?><?= $values['maintenance'] === '1' ? ' – ' . e(t('ON')) : '' ?></summary>
<?php
$field('maintenance', 'The site is temporarily unavailable', 'ano', 'Visitors see only the notice below. Signed-in users see the site as usual.');
$field('maintenance_text', 'Notice text', 'text', '', 'maxlength="300"');
?>
</details>
<details class="pokrocile">
<summary><?= e(t('Sociální sítě')) ?></summary>
<?php foreach (Kaleta\Admin\Modules\Settings::SOCIAL_NETWORKS as $key => $name) { $field($key, $name, 'url', '', 'placeholder="https://"'); } ?>
<p class="napoveda"><?= e(t('Filled-in profiles are shown in the site footer and passed to search engines.')) ?></p>
</details>
<details class="pokrocile">
<summary><?= e(t('More options')) ?></summary>
<?php
$field('footer_text', 'Text v patičce', 'text', 'For example the registered company name and company ID.', 'maxlength="300"');
$field('share_buttons', 'Share links below the news item', 'ano', 'Facebook, X, LinkedIn, WhatsApp, e-mail and copy link – no third-party scripts.');
$field('article_outline', 'News table of contents from subheadings', 'ano', 'News items with at least three subheadings get a clickable outline above the text.');
$field('related_news_auto', 'Related news', 'ano', 'Similar news by tags and category is offered below a news item.');
?>
<?php
$field('link_check', 'Look for broken links', 'ano', 'In the background, one news item every five minutes. The result is in News → Broken links.');
$field('page_cache', 'Page cache', 'ano', 'Finished pages are served to visitors from memory – the site is faster and copes with traffic peaks. Leave it on.');
?>
</details>
