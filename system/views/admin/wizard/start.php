<?php
/**
 * The first-run wizard (#31): a few questions about the business. Nothing is sent anywhere until "Plan the site" is clicked.
 *
 * @var Talea\Admin\Modules\Wizard $module
 * @var string $csrf
 * @var bool $ready the owner has an AI key
 * @var string $provider the provider's name
 * @var array<string, array<string, mixed>> $blueprints
 * @var array<string, string> $languages code => name
 * @var array<string, string> $values
 */
use Talea\Core\Blueprint;
use Talea\Core\SiteWizard;

$groups = [];
foreach ($blueprints as $key => $m) {
    $groups[$m['group'] ?? 'other'][$key] = $m;
}
?>
<p class="notice"><?= e(t('Answer a few questions and the assistant prepares a first version of your site: a look, the pages with their text and what your kind of business needs. Everything arrives as a draft – nothing is published until you do it.')) ?></p>
<?php if ($ready): ?>
<p class="notice"><?= e(t('Your answers are sent to %s when you click "Plan the site" – not before. Prices, names and opening hours that you do not give stay open for you to fill in.', $provider)) ?></p>
<?php else: ?>
<p class="notice notice-warning"><?= e(t('No AI key is set, so no text can be written. The wizard still applies the blueprint and a look; add a key under Features (Writing assistant) and run it again to get the pages.')) ?> <a href="<?= e($app->url('admin.php?module=extensions')) ?>"><?= e(t('Features')) ?></a></p>
<?php endif ?>
<form class="form" method="post" action="<?= e($module->url('plan')) ?>"><?= $csrf ?>
<h2><?= e(t('Your business')) ?></h2>
<div class="row"><label for="w-name"><?= e(t('Name')) ?></label><div><input class="textfield wide" id="w-name" name="name" maxlength="100" required value="<?= e($values['name']) ?>"></div></div>
<div class="row"><label for="w-blueprint"><?= e(t('Kind of business')) ?></label><div><select id="w-blueprint" name="blueprint">
	<option value=""><?= e($ready ? t('Let the assistant choose') : t('Other')) ?></option>
<?php foreach ($groups as $group => $items): ?>
	<optgroup label="<?= e(t(Blueprint::GROUPS[$group] ?? Blueprint::GROUPS['other'])) ?>">
<?php foreach ($items as $key => $m): ?>
		<option value="<?= e($key) ?>"><?= e(Blueprint::text($m['name'])) ?></option>
<?php endforeach ?>
	</optgroup>
<?php endforeach ?>
</select></div></div>
<div class="row"><label for="w-type"><?= e(t('In your own words')) ?></label><div><input class="textfield wide" id="w-type" name="type" maxlength="100" placeholder="<?= e(t('E.g.: family bakery in Leipzig')) ?>"><span class="help"><?= e(t('Used when no kind of business above fits.')) ?></span></div></div>
<h2><?= e(t('What you offer')) ?></h2>
<div class="row"><label for="w-services"><?= e(t('Services or products')) ?></label><div><textarea class="textfield wide" id="w-services" name="services" rows="4" maxlength="600" placeholder="<?= e(t('E.g.: kitchens, wardrobes and stairs made to measure; free first consultation.')) ?>"></textarea></div></div>
<div class="row"><label for="w-tone"><?= e(t('Tone of the texts')) ?></label><div><select id="w-tone" name="tone">
<?php foreach (array_keys(SiteWizard::TONES) as $tone): ?>
	<option value="<?= e($tone) ?>"><?= e(t(ucfirst($tone))) ?></option>
<?php endforeach ?>
</select></div></div>
<?php if (count($languages) > 1): ?>
<div class="row"><label for="w-language"><?= e(t('Language of the texts')) ?></label><div><select id="w-language" name="language">
<?php foreach ($languages as $code => $name): ?>
	<option value="<?= e($code) ?>"<?= $code === $values['language'] ? ' selected' : '' ?>><?= e($name) ?></option>
<?php endforeach ?>
</select></div></div>
<?php endif ?>
<h2><?= e(t('Contact details')) ?></h2>
<p class="help"><?= e(t('Optional. What you enter is saved as your company details and used on the site; what you leave empty stays open.')) ?></p>
<div class="row"><label for="w-email"><?= e(t('Email')) ?></label><div><input class="textfield" id="w-email" name="company_email" type="email" maxlength="150" value="<?= e($values['company_email']) ?>"></div></div>
<div class="row"><label for="w-phone"><?= e(t('Phone')) ?></label><div><input class="textfield" id="w-phone" name="company_phone" maxlength="50" value="<?= e($values['company_phone']) ?>"></div></div>
<div class="row"><label for="w-street"><?= e(t('Street')) ?></label><div><input class="textfield" id="w-street" name="company_street" maxlength="150" value="<?= e($values['company_street']) ?>"></div></div>
<div class="row"><label for="w-postcode"><?= e(t('Postcode')) ?></label><div><input class="textfield" id="w-postcode" name="company_postcode" maxlength="20" value="<?= e($values['company_postcode']) ?>"></div></div>
<div class="row"><label for="w-city"><?= e(t('City')) ?></label><div><input class="textfield" id="w-city" name="company_city" maxlength="100" value="<?= e($values['company_city']) ?>"></div></div>
<p><button class="btn" type="submit"><?= e($ready ? t('Plan the site') : t('Continue')) ?></button></p>
</form>
