<?php
/**
 * The "Enquiries" tab: how long enquiries and job applications are kept (2.11, 2.14) and whether the writing assistant
 * sorts new ones (2.12). For the variables and the $field function see list.php.
 *
 * @var array{0: string, 1: int}|null $retentionSuggestion [country, months] job applications are usually kept there
 */
$expiry = ($values['enquiries_expiry'] ?? '') === 'anonymise' ? 'anonymise' : 'delete';
?>
<fieldset>
<legend><?= e(t('Enquiries')) ?></legend>
<div class="row"><label for="enquiries_months"><?= e(t('Delete enquiries older than')) ?></label><div><input class="textfield" type="number" id="enquiries_months" name="enquiries_months" value="<?= e($values['enquiries_months'] ?? '') ?>" min="0" max="120" size="4"<?= in_array('enquiries_months', $invalidFields, true) ? ' aria-invalid="true"' : '' ?>> <?= e(t('months')) ?>
<span class="help"><?= e(t('Enquiries contain personal data – they should not be kept longer than necessary. 0 = keep forever.')) ?></span></div></div>
<div class="row"><span class="caption"><?= e(t('After that period')) ?></span><div class="options">
<?php foreach (['delete' => 'delete them including attachments', 'anonymise' => 'anonymise them – the row stays for statistics (date, form, page, topic, kind) without the name, e-mail, phone, message and attachments'] as $key => $labelText): ?>
	<label><input type="radio" name="enquiries_expiry" value="<?= $key ?>"<?= $expiry === $key ? ' checked' : '' ?>> <?= e(t($labelText)) ?></label>
<?php endforeach ?>
</div></div>
</fieldset>
<fieldset>
<legend><?= e(t('Job applications')) ?></legend>
<div class="row"><label for="job_applications_months"><?= e(t('Delete job applications after')) ?></label><div><input class="textfield" type="number" id="job_applications_months" name="job_applications_months" value="<?= e($values['job_applications_months'] ?? '') ?>" min="0" max="120" size="4"<?= in_array('job_applications_months', $invalidFields, true) ? ' aria-invalid="true"' : '' ?>> <?= e(t('months')) ?>
<span class="help"><?= e(t('Applications sent from the pages of a Job openings collection carry CVs and are usually kept only for a limited time after the selection. 0 = like other enquiries.')) ?><?= $retentionSuggestion !== null ? ' ' . e(t('Usual practice in %s: %d months – check with your lawyer.', $retentionSuggestion[0], $retentionSuggestion[1])) : '' ?></span></div></div>
</fieldset>
<fieldset>
<legend><?= e(t('Writing assistant')) ?></legend>
<div class="row"><span></span><div><label><input type="checkbox" name="triage_assistant" value="1"<?= ($values['triage_assistant'] ?? '') === '1' ? ' checked' : '' ?>> <?= e(t('Sort new enquiries with the writing assistant')) ?></label>
<span class="help"><?= e(t('The assistant suggests the kind, the priority and a reply; the text of each enquiry is then sent to the AI provider chosen in Features – mention it in your privacy policy. Claude can sort enquiries over its connection without this.')) ?></span></div></div>
</fieldset>
