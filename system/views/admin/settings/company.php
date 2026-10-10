<?php
/** The "Business details" tab: details for the site (the Company details element) and for search engines (schema.org Organization / LocalBusiness). */
use Kaleta\Front\Company;

?>
<p class="notice"><?= e(t('Fill these in once and the site uses them everywhere: in the footer and on the contact page (the Company details element in the builder) and for Google, maps and AI assistants – so they answer correctly when you are open and where to find you.')) ?></p>
<fieldset>
<legend><?= e(t('Company')) ?></legend>
<?php
$field('company_name', 'Registered company name', 'text', 'The exact registered name, e.g. “Novak Joinery Ltd”. Elsewhere the site uses the site name.', 'maxlength="200"');
?>
<div class="row">
	<label for="company_type"><?= e(t('Business type')) ?></label>
	<div><select id="company_type" name="company_type">
<?php foreach (Company::TYPES as $type => $description): ?>
		<option value="<?= e($type) ?>"<?= $values['company_type'] === $type ? ' selected' : '' ?>><?= e(t($description)) ?></option>
<?php endforeach ?>
	</select>
	<span class="help"><?= e(t('Search engines show opening hours, a map and reviews depending on the type. If customers visit you, do not choose “company without premises”.')) ?></span></div>
</div>
<?php
// the legal identifiers stay with administrators (3.2: editors may change the rest of Business details)
if ($app->auth()->isAdmin()) {
    $field('company_id', 'Company ID', 'text', 'Company identification number; in other countries its registration number.', 'maxlength="24"');
    $field('company_vat_id', 'VAT ID', 'text', 'VAT payers only, e.g. CZ12345678.', 'maxlength="14"');
    $field('company_register', 'Commercial register', 'text', 'Register court and entry, e.g. “Amtsgericht München, HRB 12345” or “Companies House, 01234567”. For the imprint.', 'maxlength="200"');
    $field('company_representative', 'Represented by', 'text', 'Who represents the company, e.g. “Managing director: Jane Smith” (Geschäftsführer). For the imprint.', 'maxlength="200"');
}
?>
</fieldset>
<fieldset>
<legend><?= e(t('Address and contact')) ?></legend>
<?php
$field('company_street', 'Street and number', 'text', '', 'maxlength="200" autocomplete="street-address"');
$field('company_postcode', 'Postcode', 'text', '', 'maxlength="10" autocomplete="postal-code"');
$field('company_city', 'City', 'text', '', 'maxlength="120" autocomplete="address-level2"');
$field('company_country', 'Country (code)', 'text', 'Two-letter code: CZ, SK, DE…', 'maxlength="2" size="3"');
$field('company_phone', 'Phone', 'text', 'With country code, e.g. +420 123 456 789.', 'maxlength="30" autocomplete="tel"');
$field('company_email', 'Public email', 'email', 'Contact for visitors and search engines. The site email from the General tab (where enquiries are sent) is not shown on the site.', 'maxlength="190" autocomplete="email"');
?>
</fieldset>
<fieldset>
<legend><?= e(t('Opening hours and map')) ?></legend>
<?php
$field('company_hours', 'Opening hours', 'lines', 'One day or range of days per line: “Mo–Fr 8:00–17:00”, “Sa 9–12”, “Su closed”, lunch break “Tu 8–12, 13–17”. Empty = no opening hours.', 'rows="5" spellcheck="false"');
$field('company_map', 'Map link', 'url', 'Link to the place on Mapy.cz or Google Maps – the Company details element turns it into a “Show on map” link.');
$field('company_gps', 'Coordinates (optional)', 'text', 'Latitude and longitude, e.g. 50.0875, 14.4213 – a more precise location for maps and search engines.', 'maxlength="40"');
?>
</fieldset>
<fieldset>
<legend><?= e(t('Exceptions to the opening hours')) ?></legend>
<p class="small-text"><?= e(t('Holidays, a closed day, shorter hours. The site shows them under the opening hours, knows whether it is open now, and shows a notice bar a few days ahead until the exception ends.')) ?></p>
<?php if ($hoursExceptions !== []): ?>
<div class="tab-wrap"><table class="listing"><tbody>
<?php foreach ($hoursExceptions as $ex): ?>
	<tr><td><?= e(Kaleta\Core\Hours::describe($ex)) ?></td><td class="small-text"><?= $ex['notice_days'] > 0 ? e(t('notice %d days ahead', $ex['notice_days'])) : e(t('no notice')) ?></td>
		<td class="actions"><a class="navigation" href="<?= e($module->url('hours_sign', ['exception' => $ex['id']])) ?>" target="_blank" rel="noopener"><?= e(t('Door sign')) ?></a>
			<button class="navigation danger" type="submit" formaction="<?= e($module->url('hours_delete')) ?>" name="exception" value="<?= (int) $ex['id'] ?>"><?= e(t('Delete')) ?></button></td></tr>
<?php endforeach ?>
</tbody></table></div>
<?php endif ?>
<?= $app->view->render('admin/hours_proposed', ['proposed' => $hoursProposed, 'applyUrl' => $module->url('hours_apply'), 'discardUrl' => $module->url('hours_discard')]) ?>
<div class="row"><label for="exception_from"><?= e(t('From')) ?></label><div><input class="textfield" type="date" id="exception_from" name="exception_from"> <label for="exception_to"><?= e(t('to')) ?></label> <input class="textfield" type="date" id="exception_to" name="exception_to">
<span class="help"><?= e(t('One day: fill in only the first date.')) ?></span></div></div>
<div class="row"><span class="caption"><?= e(t('Closed')) ?></span><div class="options"><label><input type="checkbox" name="exception_closed" value="1" checked> <?= e(t('Closed all day')) ?></label></div></div>
<div class="row"><label for="exception_hours"><?= e(t('Or open')) ?></label><div><input class="textfield" id="exception_hours" name="exception_hours" maxlength="100" placeholder="9:00-12:00">
<span class="help"><?= e(t('When it is open with different hours: uncheck Closed and enter the hours, more ranges with a comma.')) ?></span></div></div>
<div class="row"><label for="exception_note"><?= e(t('Why')) ?></label><div><input class="textfield wide" id="exception_note" name="exception_note" maxlength="150" placeholder="<?= e(t('e.g. Christmas')) ?>"></div></div>
<div class="row"><label for="exception_notice"><?= e(t('Notice bar')) ?></label><div><input class="textfield" type="number" min="0" max="60" id="exception_notice" name="exception_notice" value="7"> <?= e(t('days ahead')) ?>
<span class="help"><?= e(t('0 = no notice bar.')) ?></span></div></div>
<p><button class="navigation" type="submit" formaction="<?= e($module->url('hours_add')) ?>"><?= e(t('Add the exception')) ?></button></p>
</fieldset>
