<?php
/**
 * Enquiry detail.
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\Enquiries $module
 * @var list<array<string, mixed>> $testimonials testimonial requests of this enquiry (2.12)
 * @var string $csrf
 * @var array<string, mixed> $p
 * @var list<array{0:string, 1:string, 2?:string}> $data  [label, value, attachment path]
 * @var array<int, string> $users
 */
use Kaleta\Admin\Modules\Enquiries;

?>
<p class="navigace-radek"><a class="navigace" href="<?= e($module->url()) ?>">← <?= e(t('All enquiries')) ?></a></p>
<div class="formular">
<dl class="poptavka">
	<dt><?= e(t('Received')) ?></dt><dd><?= e(format_date($p['created_at'], true)) ?> · <?= e($p['form']) ?><?php if ($p['page'] !== ''): ?> · <a href="<?= e($p['page']) ?>" target="_blank" rel="noopener"><?= e($p['page']) ?></a><?php endif ?></dd>
<?php if (($p['topic'] ?? '') !== ''): ?>
	<dt><?= e(t('Topic')) ?></dt><dd><?= $p['page'] !== '' ? '<a href="' . e($p['page']) . '" target="_blank" rel="noopener">' . e($p['topic']) . '</a>' : e($p['topic']) ?></dd>
<?php endif ?>
<?php if (($p['landing_page'] ?? '') !== ''): ?>
	<dt><?= e(t('First page of the visit')) ?></dt><dd><?= e($p['landing_page']) ?></dd>
<?php endif ?>
<?php if (($p['referrer'] ?? '') !== ''): ?>
	<dt><?= e(t('Came from')) ?></dt><dd><?= e($p['referrer']) ?></dd>
<?php endif ?>
<?php if (($p['campaign'] ?? '') !== ''): ?>
	<dt><?= e(t('Campaign')) ?></dt><dd><?= e(Kaleta\Front\Forms::campaignText($p['campaign'])) ?></dd>
<?php endif ?>
	<dt><?= e(t('Status')) ?></dt><dd><?= e(t(Enquiries::STATUSES[(int) $p['status']])) ?></dd>
<?php if (($p['anonymised_at'] ?? null) !== null): ?>
	<dt><?= e(t('Anonymised')) ?></dt><dd><?= e(format_date((string) $p['anonymised_at'], true)) ?> · <?= e(t('the row stays for statistics without the person')) ?></dd>
<?php endif ?>
<?php foreach ($data as $i => $d): [$labelText, $value] = $d; ?>
	<dt><?= e($labelText) ?></dt><dd><?= $value === '' ? '<span class="napoveda">—</span>' : (isset($d[2]) ? '<a href="' . e($module->url('attachment', ['id' => (int) $p['enquiry_id'], 'field' => $i])) . '">' . e($value) . '</a>' : nl2br(e($value))) ?></dd>
<?php endforeach ?>
</dl>
<h2><?= e(t('Triage')) ?></h2>
<form method="post" action="<?= e($module->url('triage')) ?>">
	<?= $csrf ?><input type="hidden" name="id" value="<?= (int) $p['enquiry_id'] ?>">
	<div class="radek"><label for="kategorie"><?= e(t('Kind')) ?></label><div><select id="kategorie" name="category"><option value="">—</option>
<?php foreach (Kaleta\Core\Triage::CATEGORIES as $key => $name): ?>
		<option value="<?= e($key) ?>"<?= $p['category'] === $key ? ' selected' : '' ?>><?= e(t($name)) ?></option>
<?php endforeach ?>
	</select>
	<?php if ($p['triaged_by'] !== ''): ?><span class="napoveda"><?= e(t('Sorted by %s', match ($p['triaged_by']) { 'claude' => 'Claude', 'assistant' => t('the writing assistant'), 'rule' => t('a rule'), default => $p['triaged_by'] })) ?></span><?php endif ?></div></div>
	<div class="radek"><label for="priorita"><?= e(t('Priority')) ?></label><div><select id="priorita" name="priority"><option value="0">—</option>
<?php foreach ([3 => 'urgent', 2 => 'normal', 1 => 'can wait'] as $value => $name): ?>
		<option value="<?= $value ?>"<?= (int) $p['priority'] === $value ? ' selected' : '' ?>><?= e(t($name)) ?></option>
<?php endforeach ?>
	</select></div></div>
	<div class="radek"><label for="navrh-odpovedi"><?= e(t('Drafted reply')) ?></label><div><textarea class="textbox nizky" id="navrh-odpovedi" name="suggested_reply" rows="5"><?= e((string) ($p['suggested_reply'] ?? '')) ?></textarea>
		<span class="napoveda"><?= e(t('Never sent by itself – check it, then reply by e-mail.')) ?></span></div></div>
	<p class="tlacitka"><button class="navigace" type="submit"><?= e(t('Save triage')) ?></button><?php if ($p['category'] !== 'spam'): ?> <button class="navigace" type="submit" name="category" value="spam"><?= e(t('Mark as spam')) ?></button><?php endif ?></p>
</form>
<form method="post" action="<?= e($module->url('note')) ?>">
	<?= $csrf ?><input type="hidden" name="enquiry_id" value="<?= (int) $p['enquiry_id'] ?>">
	<div class="radek"><label for="prirazeno"><?= e(t('Handled by')) ?></label><select id="prirazeno" name="assigned_to"><option value="0">—</option>
<?php foreach ($users as $userId => $displayName): ?>
		<option value="<?= (int) $userId ?>"<?= (int) ($p['assigned_to'] ?? 0) === (int) $userId ? ' selected' : '' ?>><?= e($displayName) ?></option>
<?php endforeach ?>
	</select></div>
	<div class="radek"><label for="poznamka"><?= e(t('Internal note')) ?></label><div><textarea class="textbox nizky" id="poznamka" name="note" rows="3"><?= e((string) ($p['note'] ?? '')) ?></textarea>
		<span class="napoveda"><?= e(t('Only administration users see it – e.g. what you offered the customer.')) ?></span></div></div>
	<p class="tlacitka"><button class="navigace" type="submit"><?= e(t('Save note')) ?></button></p>
</form>
<?php if ($p['email'] !== ''): ?>
<h2><?= e(t('Testimonial')) ?></h2>
<?php foreach ($testimonials as $req): ?>
<p class="smltxt"><?= e(format_date((string) $req['created_at'], true)) ?> · <?= $req['used_at'] !== null
    ? e(t('Answered')) . ($req['item_id'] !== null ? ' – <a href="' . e($app->url('admin.php?module=collections&action=item&id=' . (int) $app->db()->value('SELECT collection_id FROM {collection_items} WHERE item_id = ?', [(int) $req['item_id']]) . '&item=' . (int) $req['item_id'])) . '">' . e(t('the draft reference')) . '</a>' : '')
    : e(strtotime((string) $req['expires_at']) < time() ? t('Expired') : t('Waiting for the answer')) ?></p>
<?php endforeach ?>
<form class="vradku" method="post" action="<?= e($module->url('testimonial')) ?>"><?= $csrf ?><input type="hidden" name="id" value="<?= (int) $p['enquiry_id'] ?>">
	<button class="navigace" type="submit" name="poslat" value="1"><?= e(t('Ask for a testimonial by e-mail')) ?></button> <button class="navigace" type="submit" name="poslat" value="0"><?= e(t('Only create the link')) ?></button>
	<span class="napoveda"><?= e(t('The customer gets a personal link for 30 days; what they write arrives as a hidden draft in References, with the consent they gave.')) ?></span></form>
<?php endif ?>
<div class="tlacitka">
<?php if ($p['email'] !== ''): ?>
	<a class="tl" href="mailto:<?= e($p['email']) ?>?subject=<?= e(rawurlencode('Re: ' . $p['form'])) ?><?= ($p['suggested_reply'] ?? '') !== '' ? '&amp;body=' . e(rawurlencode((string) $p['suggested_reply'])) : '' ?>"><?= e(t(($p['suggested_reply'] ?? '') !== '' ? 'Reply by email with the draft' : 'Reply by email')) ?></a>
<?php endif ?>
	<form class="vradku" method="post" action="<?= e($module->url('status')) ?>"><?= $csrf ?><input type="hidden" name="enquiry_id" value="<?= (int) $p['enquiry_id'] ?>"><input type="hidden" name="status" value="<?= (int) $p['status'] === 2 ? 1 : 2 ?>"><button class="tl<?= (int) $p['status'] === 2 ? ' tl-vedlejsi' : '' ?>" type="submit"><?= e(t((int) $p['status'] === 2 ? 'Reopen' : 'Mark as resolved')) ?></button></form>
<?php if (($p['anonymised_at'] ?? null) === null): ?>
	<form class="vradku" method="post" action="<?= e($module->url('anonymise')) ?>" data-potvrdit="<?= e(t('Blank the name, e-mail, phone, message and attachments of this enquiry? The row stays for statistics.')) ?>"><?= $csrf ?><input type="hidden" name="enquiry_id" value="<?= (int) $p['enquiry_id'] ?>"><button class="navigace" type="submit"><?= e(t('Anonymise')) ?></button></form>
<?php endif ?>
	<form class="vradku" method="post" action="<?= e($module->url('delete')) ?>" data-potvrdit="<?= e(t('Delete this enquiry and its attachments for good? This cannot be undone.')) ?>"><?= $csrf ?><input type="hidden" name="enquiry_id" value="<?= (int) $p['enquiry_id'] ?>"><button class="navigace nebezpecne" type="submit"><?= e(t('Delete')) ?></button></form>
</div>
</div>
