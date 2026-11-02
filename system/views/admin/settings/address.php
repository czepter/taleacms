<?php /** The "Address and visibility" tab: who can see the site. For the variables and the $field function see list.php. */ ?>
<fieldset>
<legend><?= e(t('Status')) ?></legend>
<div class="cards-options cards-options-text">
<?php foreach (['0' => ['Live', 'Visitors see the site.'], '1' => ['Paused', 'Visitors see the notice below; signed-in users still see the site.']] as $value => [$label, $help]): ?>
	<label class="card-option"><input type="radio" name="maintenance" value="<?= $value ?>"<?= ($values['maintenance'] === '1' ? '1' : '0') === (string) $value ? ' checked' : '' ?>><strong><?= e(t($label)) ?></strong><span><?= e(t($help)) ?></span></label>
<?php endforeach ?>
</div>
<?php $field('maintenance_text', 'Notice text', 'text', '', 'maxlength="300"'); ?>
</fieldset>
<fieldset>
<legend><?= e(t('Search engines')) ?></legend>
<?php $field('indexing', 'Allow the site in search engines', 'flag', 'Turn off only for a site under construction.'); ?>
</fieldset>
<fieldset>
<legend><?= e(t('Redirect')) ?></legend>
<?php $field('redirect_to_site_url', 'Redirect other addresses to the site address', 'flag', 'Visitors who reach the site under another address (with or without www, an old domain) are sent to the same page on the site address. The administration, the Claude connection and preview links are not redirected.'); ?>
</fieldset>
