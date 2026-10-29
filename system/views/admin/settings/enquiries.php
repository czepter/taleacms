<?php
/**
 * The "Enquiries" tab: the kinds enquiries are sorted into, how long enquiries and job applications are kept (2.11, 2.14)
 * and whether the writing assistant sorts new ones (2.12). The kinds are rows of kind_key[], kind_name[] and kind_description[];
 * the arrows and the bin are submit buttons (kind_move, kind_delete) that save the tab, so ordering works without a script. For the variables and the $field function see list.php.
 *
 * @var array{0: string, 1: int}|null $retentionSuggestion [country, months] job applications are usually kept there
 * @var array<string, array{name: string, description: string, default: string, fixed: bool}> $kinds the kinds enquiries are sorted into (Core\Triage::kinds)
 * @var array<string, int> $kindCounts enquiries per kind
 */
$expiry = ($values['enquiries_expiry'] ?? '') === 'anonymise' ? 'anonymise' : 'delete';
$icon = require __DIR__ . '/../icons.php';
$fixedNotes = ['job' => 'Set by a rule for applications from job openings.', 'spam' => 'Spam stays out of the inbox.'];
$rows = array_values(array_map(fn (string $key, array $kind): array => ['key' => $key] + $kind, array_keys($kinds), $kinds));
?>
<fieldset id="kinds">
<legend><?= e(t('Kinds')) ?></legend>
<p class="help kinds-intro"><?= e(t('You sort incoming enquiries into these kinds. The writing assistant and Claude sort by the description, so write how to recognise each kind.')) ?></p>
<div class="kinds">
	<div class="kinds-row kinds-head" aria-hidden="true"><span></span><span><?= e(t('Name')) ?></span><span><?= e(t('How to recognise it')) ?></span><span class="number"><?= e(t('Enquiries')) ?></span><span></span></div>
<?php foreach ([...$rows, ['key' => '', 'name' => '', 'description' => '', 'default' => '', 'fixed' => false]] as $i => $kind): $new = $kind['key'] === ''; ?>
	<div class="kinds-row<?= $new ? ' kinds-new' : '' ?>">
		<span class="kinds-move"><?php if (!$new): ?>
			<button class="menu-tool" type="submit" name="kind_move" value="<?= $i ?>:-1" aria-label="<?= e(t('Move %s up', $kind['name'])) ?>"<?= $i === 0 ? ' disabled' : '' ?>><?= $icon('up') ?></button>
			<button class="menu-tool" type="submit" name="kind_move" value="<?= $i ?>:1" aria-label="<?= e(t('Move %s down', $kind['name'])) ?>"<?= $i === count($rows) - 1 ? ' disabled' : '' ?>><?= $icon('down') ?></button>
		<?php endif ?></span>
		<span><input type="hidden" name="kind_key[]" value="<?= e($kind['key']) ?>"><input class="textfield wide" type="text" name="kind_name[]" value="<?= e($kind['name']) ?>" maxlength="60" aria-label="<?= e($new ? t('New kind') : t('Name')) ?>"<?= $new ? ' placeholder="' . e(t('New kind')) . '"' : '' ?><?= $kind['fixed'] ? ' readonly' : '' ?>>
			<?php if ($kind['fixed']): ?><span class="help"><?= e(t($fixedNotes[$kind['key']] ?? '')) ?></span><?php endif ?></span>
		<input class="textfield wide" type="text" name="kind_description[]" value="<?= e($kind['description']) ?>" maxlength="300" aria-label="<?= e(t('How to recognise it')) ?>"<?= $kind['default'] !== '' ? ' placeholder="' . e(t($kind['default'])) . '"' : '' ?>>
		<span class="number"><?= $new ? '' : (int) ($kindCounts[$kind['key']] ?? 0) ?></span>
		<span><?php if (!$new && !$kind['fixed']): ?><button class="menu-tool danger" type="submit" name="kind_delete" value="<?= $i ?>" aria-label="<?= e(t('Delete %s', $kind['name'])) ?>" data-confirm="<?= e(t('Delete the kind %s? Its enquiries count as not sorted again.', $kind['name'])) ?>"><?= $icon('trash') ?></button><?php endif ?></span>
	</div>
<?php endforeach ?>
</div>
<p class="help"><?= e(t('To add a kind, fill in the empty row and save. Deleting a kind leaves its enquiries not sorted.')) ?></p>
</fieldset>
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
<div class="row"><span></span><div><label><input type="checkbox" name="spam_assistant" value="1"<?= ($values['spam_assistant'] ?? '') === '1' ? ' checked' : '' ?>> <?= e(t('Check new enquiries for spam with AI')) ?></label>
<span class="help"><?= e(t('Before anyone is notified, the assistant reads each new enquiry. Spam is kept under the Spam filter in Enquiries and sends no e-mail, webhook or delivery; the sender sees the usual thank-you. If the assistant cannot be reached, the enquiry goes through as usual. The text of each enquiry is sent to the AI provider – mention it in your privacy policy.')) ?></span></div></div>
</fieldset>
