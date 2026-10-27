<?php
/**
 * Enquiry detail.
 *
 * @var Talea\Core\App $app
 * @var Talea\Admin\Modules\Enquiries $module
 * @var list<array<string, mixed>> $testimonials testimonial requests of this enquiry (2.12)
 * @var string $csrf
 * @var array<string, mixed> $p
 * @var list<array{0:string, 1:string, 2?:string}> $data  [label, value, attachment path]
 * @var array<string, string> $users  public id => name
 * @var string $assignedPublicId the public id of who handles it ('' = nobody)
 * @var list<array<string, mixed>> $inbox the latest enquiries (not spam) for the column beside this one
 */
use Talea\Admin\Modules\Enquiries;

?>
<div class="inbox">
<nav class="inbox-list" aria-label="<?= e(t('Latest enquiries')) ?>">
	<p><a href="<?= e($module->url()) ?>"><?= e(t('All enquiries')) ?></a></p>
	<ul>
<?php foreach ($inbox as $row): $first = array_values(array_filter(array_map(fn (array $d): string => (string) $d[1], json_decode((string) $row['data'], true) ?: []), fn (string $v): bool => mb_strlen($v) > 1)); ?>
		<li<?= $row['public_id'] === $p['public_id'] ? ' class="active"' : '' ?>><a href="<?= e($module->url('detail', ['id' => $row['public_id']])) ?>"<?= $row['public_id'] === $p['public_id'] ? ' aria-current="page"' : '' ?>>
			<span class="inbox-who"><strong><?= e($row['email'] !== '' ? $row['email'] : ($row['form'] !== '' ? $row['form'] : t('Enquiry'))) ?></strong><span><?= e(format_date($row['created_at'])) ?></span></span>
			<span class="inbox-text"><?= e(mb_strimwidth(implode(' · ', $first), 0, 90, '…')) ?></span>
			<span class="small-text"><?= e($row['form']) ?><?= (int) $row['status'] === 0 ? ' · ' . e(t('new')) : ((int) $row['status'] === 2 ? ' · ' . e(t('Resolved')) : '') ?></span>
		</a></li>
<?php endforeach ?>
	</ul>
</nav>
<?php
$icon = require __DIR__ . '/../icons.php';
// the neighbours in the inbox column: the arrows of the toolbar walk it without going back to the list
$position = array_search($p['public_id'], array_column($inbox, 'public_id'), true);
$newer = $position !== false && $position > 0 ? $inbox[$position - 1]['public_id'] : null;
$older = $position !== false && isset($inbox[$position + 1]) ? $inbox[$position + 1]['public_id'] : null;
$resolved = (int) $p['status'] === 2;
$anonymised = ($p['anonymised_at'] ?? null) !== null;
?>
<div class="inbox-open enquiry-card">
<div class="enquiry-bar">
<?php if ($position !== false): ?>
	<div class="enquiry-step">
		<?php if ($newer !== null): ?><a class="icon-btn" href="<?= e($module->url('detail', ['id' => $newer])) ?>" aria-label="<?= e(t('Newer enquiry')) ?>" title="<?= e(t('Newer enquiry')) ?>"><?= $icon('prev') ?></a><?php else: ?><span class="icon-btn" aria-hidden="true"><?= $icon('prev') ?></span><?php endif ?>
		<?php if ($older !== null): ?><a class="icon-btn" href="<?= e($module->url('detail', ['id' => $older])) ?>" aria-label="<?= e(t('Older enquiry')) ?>" title="<?= e(t('Older enquiry')) ?>"><?= $icon('next') ?></a><?php else: ?><span class="icon-btn" aria-hidden="true"><?= $icon('next') ?></span><?php endif ?>
		<span class="small-text"><?= e(t('%d of %d', $position + 1, count($inbox))) ?></span>
	</div>
<?php endif ?>
	<span class="status <?= $resolved ? 'status-live' : 'status-draft' ?>"><?= e(t(Enquiries::STATUSES[(int) $p['status']])) ?></span>
<?php if ($p['category'] === 'spam'): ?>
	<span class="tag tag-warning"><?= e(t('Spam')) ?></span>
<?php endif ?>
	<div class="enquiry-actions">
<?php if ($p['email'] !== ''): ?>
		<a class="btn" href="mailto:<?= e($p['email']) ?>?subject=<?= e(rawurlencode('Re: ' . $p['form'])) ?><?= ($p['suggested_reply'] ?? '') !== '' ? '&amp;body=' . e(rawurlencode((string) $p['suggested_reply'])) : '' ?>"><?= $icon('reply') ?><?= e(t(($p['suggested_reply'] ?? '') !== '' ? 'Reply by email with the draft' : 'Reply by email')) ?></a>
<?php endif ?>
		<form class="inline" method="post" action="<?= e($module->url('status')) ?>"><?= $csrf ?><input type="hidden" name="enquiry_id" value="<?= e($p['public_id']) ?>"><input type="hidden" name="status" value="<?= $resolved ? 1 : 2 ?>"><button class="navigation" type="submit"><?= $icon($resolved ? 'restore' : 'check') ?><?= e(t($resolved ? 'Reopen' : 'Mark as resolved')) ?></button></form>
<?php if ($p['category'] !== 'spam'): ?>
		<form class="inline" method="post" action="<?= e($module->url('triage')) ?>"><?= $csrf ?><input type="hidden" name="id" value="<?= e($p['public_id']) ?>"><button class="navigation" type="submit" name="category" value="spam"><?= $icon('flag') ?><?= e(t('Mark as spam')) ?></button></form>
<?php endif ?>
		<button class="icon-btn" type="button" popovertarget="enquiry-menu" aria-label="<?= e(t('More actions')) ?>" title="<?= e(t('More actions')) ?>"><?= $icon('more') ?></button>
		<div class="row-menu enquiry-menu" id="enquiry-menu" popover>
<?php if ($p['email'] !== ''): ?>
			<p class="row-menu-note"><?= e(t('Testimonial')) ?></p>
			<form method="post" action="<?= e($module->url('testimonial')) ?>"><?= $csrf ?><input type="hidden" name="id" value="<?= e($p['public_id']) ?>"><input type="hidden" name="send" value="1"><button type="submit"><?= $icon('b-contact') ?><?= e(t('Ask for a testimonial by e-mail')) ?></button></form>
			<form method="post" action="<?= e($module->url('testimonial')) ?>"><?= $csrf ?><input type="hidden" name="id" value="<?= e($p['public_id']) ?>"><input type="hidden" name="send" value="0"><button type="submit"><?= $icon('copy') ?><?= e(t('Only create the link')) ?></button></form>
			<p class="row-menu-note"><?= e(t('The customer gets a personal link for 30 days; what they write arrives as a hidden draft in References, with the consent they gave.')) ?></p>
			<hr role="separator">
<?php endif ?>
<?php if (!$anonymised): ?>
			<form method="post" action="<?= e($module->url('anonymise')) ?>" data-confirm="<?= e(t('Blank the name, e-mail, phone, message and attachments of this enquiry? The row stays for statistics.')) ?>"><?= $csrf ?><input type="hidden" name="enquiry_id" value="<?= e($p['public_id']) ?>"><button type="submit"><?= $icon('eye-off') ?><?= e(t('Anonymise')) ?></button></form>
<?php endif ?>
			<form method="post" action="<?= e($module->url('delete')) ?>" data-confirm="<?= e(t('Delete this enquiry and its attachments for good? This cannot be undone.')) ?>"><?= $csrf ?><input type="hidden" name="enquiry_id" value="<?= e($p['public_id']) ?>"><button class="danger" type="submit"><?= $icon('trash') ?><?= e(t('Delete')) ?></button></form>
		</div>
	</div>
</div>
<div class="enquiry-body">
<div class="enquiry-message">
<dl class="enquiry">
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
	<dt><?= e(t('Campaign')) ?></dt><dd><?= e(Talea\Front\Forms::campaignText($p['campaign'])) ?></dd>
<?php endif ?>
<?php if ($anonymised): ?>
	<dt><?= e(t('Anonymised')) ?></dt><dd><?= e(format_date((string) $p['anonymised_at'], true)) ?> · <?= e(t('the row stays for statistics without the person')) ?></dd>
<?php endif ?>
<?php foreach ($data as $i => $d): [$labelText, $value] = $d; ?>
	<dt><?= e($labelText) ?></dt><dd><?= $value === '' ? '<span class="help">—</span>' : (isset($d[2]) ? '<a href="' . e($module->url('attachment', ['id' => $p['public_id'], 'field' => $i])) . '">' . e($value) . '</a>' : nl2br(e($value))) ?></dd>
<?php endforeach ?>
</dl>
<div class="enquiry-draft">
	<label for="suggested-reply"><?= e(t('Drafted reply')) ?></label>
	<textarea class="textbox low" id="suggested-reply" name="suggested_reply" rows="5" form="enquiry-handling"><?= e((string) ($p['suggested_reply'] ?? '')) ?></textarea>
	<span class="help"><?= e(t('Never sent by itself – check it, then reply by e-mail.')) ?></span>
</div>
<?php if ($testimonials !== []): ?>
<div class="enquiry-testimonials">
	<h2><?= e(t('Testimonial')) ?></h2>
<?php foreach ($testimonials as $req): ?>
	<p class="small-text"><?= e(format_date((string) $req['created_at'], true)) ?> · <?= $req['used_at'] !== null
    ? e(t('Answered')) . ($req['item_id'] !== null ? ' – <a href="' . e($app->url('admin.php?module=collections&action=item&id=' . $app->db()->publicId('collections', (int) $app->db()->value('SELECT collection_id FROM {collection_items} WHERE item_id = ?', [(int) $req['item_id']])) . '&item=' . $app->db()->publicId('collection_items', (int) $req['item_id']))) . '">' . e(t('the draft reference')) . '</a>' : '')
    : e(strtotime((string) $req['expires_at']) < time() ? t('Expired') : t('Waiting for the answer')) ?></p>
<?php endforeach ?>
</div>
<?php endif ?>
</div>
<form class="enquiry-side" id="enquiry-handling" method="post" action="<?= e($module->url('triage')) ?>" data-autosave>
	<?= $csrf ?><input type="hidden" name="id" value="<?= e($p['public_id']) ?>">
	<div class="enquiry-side-head"><h2><?= e(t('Handling')) ?></h2><span class="small-text" data-autosave-state aria-live="polite"></span></div>
	<div class="enquiry-field"><label for="category"><?= e(t('Kind')) ?></label><select id="category" name="category"><option value=""><?= e(t('Not sorted yet')) ?></option>
<?php foreach (Talea\Core\Triage::CATEGORIES as $key => $name): ?>
		<option value="<?= e($key) ?>"<?= $p['category'] === $key ? ' selected' : '' ?>><?= e(t($name)) ?></option>
<?php endforeach ?>
	</select>
	<?php if ($p['triaged_by'] !== ''): ?><span class="help"><?= e(t('Sorted by %s', match ($p['triaged_by']) { 'claude' => 'Claude', 'assistant' => t('the writing assistant'), 'rule' => t('a rule'), default => $p['triaged_by'] })) ?></span><?php endif ?></div>
	<fieldset class="enquiry-field"><legend><?= e(t('Priority')) ?></legend><div class="segmented">
<?php foreach ([1 => 'can wait', 2 => 'normal', 3 => 'urgent'] as $value => $name): ?>
		<label><input type="radio" name="priority" value="<?= $value ?>"<?= (int) $p['priority'] === $value ? ' checked' : '' ?>><span><?= e(t($name)) ?></span></label>
<?php endforeach ?>
	</div></fieldset>
	<div class="enquiry-field"><label for="assigned_to"><?= e(t('Handled by')) ?></label><select id="assigned_to" name="assigned_to"><option value="0"><?= e(t('Nobody')) ?></option>
<?php foreach ($users as $userId => $displayName): ?>
		<option value="<?= e((string) $userId) ?>"<?= $assignedPublicId === (string) $userId ? ' selected' : '' ?>><?= e($displayName) ?></option>
<?php endforeach ?>
	</select></div>
	<div class="enquiry-field"><label for="note"><?= e(t('Internal note')) ?></label><textarea class="textbox low" id="note" name="note" rows="4"><?= e((string) ($p['note'] ?? '')) ?></textarea>
		<span class="help"><?= e(t('Only administration users see it – e.g. what you offered the customer.')) ?></span></div>
	<p class="enquiry-save"><button class="navigation" type="submit"><?= e(t('Save')) ?></button></p>
</form>
</div>
</div>
</div>
