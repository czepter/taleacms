<?php
/**
 * Enquiry detail: the inbox column, the open enquiry (the message first, then the sender's details, where it came from and
 * the drafted reply) and its handling. One surface without cards: the columns are told apart by space, not by frames.
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
 * @var array<string, string> $kinds key => name of the site's kinds (Core\Triage::names)
 */
use Talea\Admin\Modules\Enquiries;

$icon = require __DIR__ . '/../icons.php';
// the neighbours in the inbox column: the arrows walk it without going back to the list
$position = array_search($p['public_id'], array_column($inbox, 'public_id'), true);
$newer = $position !== false && $position > 0 ? $inbox[$position - 1]['public_id'] : null;
$older = $position !== false && isset($inbox[$position + 1]) ? $inbox[$position + 1]['public_id'] : null;
$resolved = (int) $p['status'] === 2;
$anonymised = ($p['anonymised_at'] ?? null) !== null;
$sender = $p['email'] !== '' ? $p['email'] : ($p['form'] !== '' ? $p['form'] : t('Enquiry'));
$today = date('Y-m-d');
$when = fn (string $at): string => str_starts_with($at, $today) ? substr($at, 11, 5) : format_date($at);
// the message is the longest text the visitor wrote; the other fields are the sender's details
$message = null;
foreach ($data as $i => $d) {
    if (!isset($d[2]) && mb_strlen((string) $d[1]) >= 40 && ($message === null || mb_strlen((string) $d[1]) > mb_strlen((string) $data[$message][1]))) {
        $message = $i;
    }
}
$pageLink = $p['page'] !== '' ? '<a href="' . e($p['page']) . '" target="_blank" rel="noopener">' . e($p['page']) . '</a>' : '';
?>
<div class="inbox">
<nav class="inbox-list" aria-label="<?= e(t('Latest enquiries')) ?>">
	<p><a href="<?= e($module->url()) ?>"><?= e(t('All enquiries')) ?></a></p>
	<ul>
<?php foreach ($inbox as $row): $first = array_values(array_filter(array_map(fn (array $d): string => (string) $d[1], json_decode((string) $row['data'], true) ?: []), fn (string $v): bool => mb_strlen($v) > 1)); $here = $row['public_id'] === $p['public_id']; ?>
		<li<?= $here ? ' class="active"' : '' ?>><a href="<?= e($module->url('detail', ['id' => $row['public_id']])) ?>"<?= $here ? ' aria-current="page"' : '' ?>>
			<span class="inbox-who"><?php if ((int) $row['status'] === 0): ?><span class="unread-dot"><span class="visually-hidden"><?= e(t('New')) ?></span></span><?php endif ?><strong><?= bdi($row['email'] !== '' ? $row['email'] : ($row['form'] !== '' ? $row['form'] : t('Enquiry'))) ?></strong><time datetime="<?= e(str_replace(' ', 'T', (string) $row['created_at'])) ?>"><?= e($when((string) $row['created_at'])) ?></time></span>
			<span class="inbox-text"><?= bdi(mb_strimwidth(implode(' · ', $first), 0, 90, '…')) ?></span>
<?php if ($row['category'] !== '' || (int) $row['status'] === 2): ?>
			<span class="inbox-tags"><?= $row['category'] !== '' ? '<span class="tag">' . e($kinds[$row['category']] ?? $row['category']) . '</span>' : '' ?><?= (int) $row['status'] === 2 ? '<span class="tag">' . e(t('Resolved')) . '</span>' : '' ?></span>
<?php endif ?>
		</a></li>
<?php endforeach ?>
	</ul>
</nav>
<div class="inbox-open">
<div class="enquiry-bar">
<?php if ($p['email'] !== ''): ?>
	<a class="btn" href="mailto:<?= e($p['email']) ?>?subject=<?= e(rawurlencode('Re: ' . $p['form'])) ?><?= ($p['suggested_reply'] ?? '') !== '' ? '&amp;body=' . e(rawurlencode((string) $p['suggested_reply'])) : '' ?>"><?= $icon('reply') ?><?= e(t(($p['suggested_reply'] ?? '') !== '' ? 'Reply by email with the draft' : 'Reply by email')) ?></a>
<?php endif ?>
	<form class="inline" method="post" action="<?= e($module->url('status')) ?>"><?= $csrf ?><input type="hidden" name="enquiry_id" value="<?= e($p['public_id']) ?>"><input type="hidden" name="status" value="<?= $resolved ? 1 : 2 ?>"><button class="navigation" type="submit"><?= $icon($resolved ? 'restore' : 'check') ?><?= e(t($resolved ? 'Reopen' : 'Mark as resolved')) ?></button></form>
	<span class="enquiry-bar-end">
		<span class="status <?= $p['category'] === 'spam' ? 'status-error' : ($resolved ? 'status-live' : 'status-draft') ?>"><?= e($p['category'] === 'spam' ? ($kinds['spam'] ?? t('Spam')) : t(Enquiries::STATUSES[(int) $p['status']])) ?></span>
<?php if ((string) ($p['seat_state'] ?? '') !== ''): [$stateKind, $stateLabel] = Talea\Core\Registrations::stateBadge((string) $p['seat_state']); ?>
		<span class="status status-<?= e($stateKind) ?>"><?= e($stateLabel) ?></span>
<?php endif ?>
<?php if ($position !== false): ?>
		<span class="enquiry-step">
			<?php if ($newer !== null): ?><a class="icon-btn" href="<?= e($module->url('detail', ['id' => $newer])) ?>" aria-label="<?= e(t('Newer enquiry')) ?>" title="<?= e(t('Newer enquiry')) ?>"><?= $icon('prev') ?></a><?php else: ?><span class="icon-btn" aria-hidden="true"><?= $icon('prev') ?></span><?php endif ?>
			<span class="small-text"><?= e(t('%d of %d', $position + 1, count($inbox))) ?></span>
			<?php if ($older !== null): ?><a class="icon-btn" href="<?= e($module->url('detail', ['id' => $older])) ?>" aria-label="<?= e(t('Older enquiry')) ?>" title="<?= e(t('Older enquiry')) ?>"><?= $icon('next') ?></a><?php else: ?><span class="icon-btn" aria-hidden="true"><?= $icon('next') ?></span><?php endif ?>
		</span>
<?php endif ?>
		<button class="icon-btn" type="button" popovertarget="enquiry-menu" aria-label="<?= e(t('More actions')) ?>" title="<?= e(t('More actions')) ?>"><?= $icon('more') ?></button>
		<div class="row-menu enquiry-menu" id="enquiry-menu" popover>
<?php if ($p['category'] !== 'spam'): ?>
			<form method="post" action="<?= e($module->url('triage')) ?>"><?= $csrf ?><input type="hidden" name="id" value="<?= e($p['public_id']) ?>"><button type="submit" name="category" value="spam"><?= $icon('flag') ?><?= e(t('Mark as spam')) ?></button></form>
			<hr role="separator">
<?php endif ?>
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
			<form method="post" action="<?= e($module->url('delete')) ?>" data-confirm="<?= e(t('Move the enquiry to the trash? You can restore it for 30 days.')) ?>"><?= $csrf ?><input type="hidden" name="enquiry_id" value="<?= e($p['public_id']) ?>"><button class="danger" type="submit"><?= $icon('trash') ?><?= e(t('Move to trash')) ?></button></form>
		</div>
	</span>
</div>
<?php if ($p['category'] === 'spam' && in_array($p['triaged_by'], ['claude', 'assistant', 'rule'], true)): ?>
<div class="notice notice-warning notice-actions enquiry-spam" role="status">
	<p><?= e(t('%s sorted this enquiry as spam.', match ($p['triaged_by']) { 'claude' => 'Claude', 'assistant' => t('The writing assistant'), default => t('A rule') })) ?></p>
	<form class="navigation-row" method="post" action="<?= e($module->url('triage')) ?>"><?= $csrf ?><input type="hidden" name="id" value="<?= e($p['public_id']) ?>"><button class="navigation" type="submit" name="category" value=""><?= e(t('Not spam')) ?></button></form>
</div>
<?php endif ?>
<div class="enquiry-body">
<div class="enquiry-message">
<article class="enquiry-text">
	<header>
		<span class="cell-icon avatar" aria-hidden="true"><?= e(mb_strtoupper(mb_substr($sender, 0, 1))) ?></span>
		<span class="enquiry-from"><strong><?= bdi($sender) ?></strong><span><?= e($p['form']) ?><?php if ($pageLink !== ''): ?> · <?= $pageLink ?><?php endif ?></span></span>
		<time datetime="<?= e(str_replace(' ', 'T', (string) $p['created_at'])) ?>"><?= e(format_date($p['created_at'], true)) ?></time>
	</header>
<?php if ($message !== null): ?>
	<p dir="auto"><?= nl2br(e((string) $data[$message][1])) ?></p>
<?php endif ?>
</article>
<div class="enquiry-facts">
<?php if (count($data) > ($message === null ? 0 : 1)): ?>
<section>
	<h2><?= e(t('Details')) ?></h2>
	<dl class="enquiry">
<?php foreach ($data as $i => $d): if ($i === $message) { continue; } [$labelText, $value] = $d; ?>
		<dt><?= e($labelText) ?></dt><dd><?= $value === '' ? '<span class="help">—</span>' : (isset($d[2]) ? '<a href="' . e($module->url('attachment', ['id' => $p['public_id'], 'field' => $i])) . '">' . e($value) . '</a>' : nl2br(bdi($value))) ?></dd>
<?php endforeach ?>
	</dl>
</section>
<?php endif ?>
<section>
	<h2><?= e(t('Origin')) ?></h2>
	<dl class="enquiry">
		<dt><?= e(t('Received')) ?></dt><dd><?= e(format_date($p['created_at'], true)) ?> · <?= e($p['form']) ?><?php if ($pageLink !== ''): ?> · <?= $pageLink ?><?php endif ?></dd>
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
	</dl>
</section>
</div>
<div class="enquiry-draft">
	<label for="suggested-reply"><?= e(t('Drafted reply')) ?></label>
	<textarea class="textbox low" id="suggested-reply" name="suggested_reply" rows="5" form="enquiry-handling"><?= e((string) ($p['suggested_reply'] ?? '')) ?></textarea>
	<span class="help"><?= e(t('Talea never sends the draft by itself. Read it, then reply by e-mail.')) ?></span>
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
	<div class="enquiry-field"><span class="enquiry-field-head"><label for="category"><?= e(t('Kind')) ?></label><?php if ($app->auth()->isAdmin()): ?><a href="<?= e($app->url('admin.php?module=settings&tab=enquiries#kinds')) ?>"><?= e(t('Edit kinds')) ?></a><?php endif ?></span>
	<select id="category" name="category"><option value=""><?= e(t('Not sorted yet')) ?></option>
<?php foreach ($kinds as $key => $name): ?>
		<option value="<?= e($key) ?>"<?= $p['category'] === $key ? ' selected' : '' ?>><?= e($name) ?></option>
<?php endforeach ?>
<?php if ($p['category'] !== '' && !isset($kinds[$p['category']])): ?>
		<option value="<?= e($p['category']) ?>" selected><?= e($p['category']) ?></option>
<?php endif ?>
	</select>
	<?php if ($p['triaged_by'] !== ''): ?><span class="help"><?= e(t('Sorted by %s', match ($p['triaged_by']) { 'claude' => 'Claude', 'assistant' => t('the writing assistant'), 'rule' => t('a rule'), default => $p['triaged_by'] })) ?></span><?php endif ?></div>
	<fieldset class="enquiry-field"><legend><?= e(t('Priority')) ?></legend><div class="segmented">
<?php foreach (Talea\Core\Triage::PRIORITIES as $value => $name): ?>
		<label><input type="radio" name="priority" value="<?= $value ?>"<?= (int) $p['priority'] === $value ? ' checked' : '' ?>><span><?= e(t($name)) ?></span></label>
<?php endforeach ?>
	</div></fieldset>
	<div class="enquiry-field"><label for="assigned_to"><?= e(t('Handled by')) ?></label><select id="assigned_to" name="assigned_to"><option value="0"><?= e(t('Nobody')) ?></option>
<?php foreach ($users as $userId => $displayName): ?>
		<option value="<?= e((string) $userId) ?>"<?= $assignedPublicId === (string) $userId ? ' selected' : '' ?>><?= e($displayName) ?></option>
<?php endforeach ?>
	</select></div>
	<div class="enquiry-field"><label for="note"><?= e(t('Internal note')) ?></label><textarea class="textbox low" id="note" name="note" rows="4"><?= e((string) ($p['note'] ?? '')) ?></textarea>
		<span class="help"><?= e(t('Only your team sees this note. Write down what you offered, for example.')) ?></span></div>
	<p class="enquiry-save"><button class="navigation" type="submit"><?= e(t('Save')) ?></button></p>
</form>
</div>
</div>
</div>
