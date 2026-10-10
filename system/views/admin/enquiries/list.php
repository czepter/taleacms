<?php
/**
 * Enquiries from the site's forms.
 *
 * @var Talea\Core\App $app
 * @var Talea\Admin\Modules\Enquiries $module
 * @var string $csrf
 * @var list<array<string, mixed>> $enquiries
 * @var int $total
 * @var string $filter
 * @var int $pageNumber
 * @var int $perPage
 * @var int $months
 * @var string $expiry delete | anonymise – what happens after the retention period (2.14)
 * @var int $applicationMonths retention of applications to job openings (2.11)
 * @var array{0: string, 1: int}|null $suggestion [country, months] usually kept there
 * @var string $kind the triage kind shown ('' = all but spam, '-' = not sorted yet)
 * @var int $spam enquiries marked as spam
 * @var string $search
 * @var array<int, string> $users
 */
use Talea\Admin\Modules\Enquiries;

$pageCount = (int) ceil($total / $perPage);
$preview = function (string $data): string {
    $items = json_decode($data, true) ?: [];
    $text = implode(' · ', array_filter(array_map(fn (array $d): string => (string) $d[1], $items), fn (string $v): bool => $v !== '' && mb_strlen($v) > 1));

    return mb_strimwidth($text, 0, 140, '…');
};
?>
<nav class="tabs" aria-label="<?= e(t('Enquiry status')) ?>">
<?php foreach (['' => 'All', 'open' => 'To do', 'mine' => 'Mine', 'resolved' => 'Resolved'] as $key => $name): ?>
	<a href="<?= e($module->url('', array_filter(['status' => $key]))) ?>"<?= $filter === $key ? ' class="active" aria-current="true"' : '' ?>><?= e(t($name)) ?></a>
<?php endforeach ?>
</nav>
<nav class="tabs" aria-label="<?= e(t('Kind')) ?>">
<?php foreach (['' => t('All kinds'), '-' => t('Not sorted')] + array_map('t', Talea\Core\Triage::CATEGORIES) as $key => $name): ?>
	<a href="<?= e($module->url('', array_filter(['status' => $filter, 'category' => $key]))) ?>"<?= $kind === $key ? ' class="active" aria-current="true"' : '' ?>><?= e($name) ?><?= $key === 'spam' && $spam > 0 ? ' (' . $spam . ')' : '' ?></a>
<?php endforeach ?>
</nav>
<form method="get" action="<?= e($app->url('admin.php')) ?>" class="center small-text">
	<input type="hidden" name="module" value="enquiries"><input type="hidden" name="status" value="<?= e($filter) ?>">
	<label><?= e(t('Search (name, e-mail, text):')) ?> <input class="textfield" type="search" name="search" value="<?= e($search) ?>" size="24"></label>
	<input class="btn" type="submit" value="<?= e(t('Filter')) ?>">
</form>
<br>
<?php if ($enquiries === [] && ($search !== '' || $filter !== '')): ?>
<?= $app->view->render('admin/empty', ['icon' => 'enquiries', 'heading' => t('No enquiry matches the filter.'), 'text' => t('Try another word or status.'), 'action' => [$module->url(), t('Clear filter')]]) ?>
<?php elseif ($enquiries === []): ?>
<?= $app->view->render('admin/empty', ['icon' => 'enquiries', 'heading' => t('No enquiries yet.'), 'text' => t('Add a Form element in the page builder (or the ready-made Enquiry form section) – submitted messages will appear here and arrive by email.'), 'action' => [$app->url('admin.php?module=pages'), t('Open pages')]]) ?>
<?php else: ?>
<form method="post" action="<?= e($module->url('bulk')) ?>">
<?= $csrf ?>
<div class="tab-wrap">
<table class="listing">
<thead><tr><th scope="col"><?= e(t('Date')) ?></th><th scope="col"><?= e(t('Form')) ?></th><th scope="col"><?= e(t('Content')) ?></th><th scope="col"><?= e(t('Status')) ?></th><th scope="col" class="center"><?= e(t('Select')) ?></th></tr></thead>
<tbody>
<?php foreach ($enquiries as $p): ?>
<tr<?= (int) $p['status'] === 2 ? ' class="unpublished"' : '' ?>>
	<td><a href="<?= e($module->url('detail', ['id' => $p['public_id']])) ?>"><?= (int) $p['status'] === 0 ? '<strong>' . e(format_date($p['created_at'], true)) . '</strong>' : e(format_date($p['created_at'], true)) ?></a></td>
	<td><?= e($p['form']) ?><?= ($p['topic'] ?? '') !== '' ? '<br><small>' . e(t('Topic')) . ': <a href="' . e($p['page']) . '" target="_blank" rel="noopener">' . e($p['topic']) . '</a></small>' : '' ?><?= $p['email'] !== '' ? '<br><small>' . e($p['email']) . '</small>' : '' ?><?= $p['category'] !== '' ? '<br><span class="badge">' . e(t(Talea\Core\Triage::CATEGORIES[$p['category']] ?? $p['category'])) . '</span>' : '' ?><?= (int) $p['priority'] === 3 ? ' <span class="badge badge-draft">' . e(t('urgent')) . '</span>' : '' ?></td>
	<td><a href="<?= e($module->url('detail', ['id' => $p['public_id']])) ?>"><?= e($preview((string) $p['data'])) ?></a></td>
	<td><span class="badge<?= (int) $p['status'] === 0 ? ' badge-draft' : ((int) $p['status'] === 2 ? ' badge-published' : '') ?>"><?= e(t(Enquiries::STATUSES[(int) $p['status']])) ?></span><?= $p['assigned_to'] && isset($users[(int) $p['assigned_to']]) ? '<br><small>' . e($users[(int) $p['assigned_to']]) . '</small>' : '' ?></td>
	<td class="center"><input type="checkbox" name="selected[]" value="<?= e($p['public_id']) ?>" aria-label="<?= e(t('Select')) ?>: <?= e(format_date($p['created_at'], true)) ?>"></td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<p class="media-bulk"><?= e(t('With selected:')) ?>
	<button class="btn" type="submit" name="bulk" value="vyridit"><?= e(t('Mark as resolved')) ?></button>
	<button class="navigation danger" type="submit" name="bulk" value="delete" data-confirm="<?= e(t('Delete the selected enquiries including attachments? This cannot be undone.')) ?>"><?= e(t('Delete')) ?></button></p>
</form>
<?php if ($pageCount > 1): ?>
<p class="pagination">
<?php for ($s = 1; $s <= $pageCount; $s++): ?>
	<?= $s === $pageNumber ? '<strong>[' . $s . ']</strong>' : '<a href="' . e($module->url('', array_filter(['status' => $filter]) + ['page' => $s])) . '">' . $s . '</a>' ?>
<?php endfor ?>
</p>
<?php endif ?>
<p class="navigation-row"><a class="navigation" href="<?= e($module->url('csv')) ?>"><?= e(t('Download all enquiries (CSV)')) ?></a></p>
<?php endif ?>
<?php if ($app->auth()->isAdmin()): ?>
<form class="form" method="post" action="<?= e($module->url('settings')) ?>" data-confirm="<?= e(t('Enquiries older than the given number of months will be permanently deleted right away – including attachments. Save anyway?')) ?>">
<?= $csrf ?>
<div class="row"><label for="months"><?= e(t('Delete enquiries older than')) ?></label><div><input class="textfield" type="number" id="months" name="months" value="<?= $months ?>" min="0" max="120" size="4"> <?= e(t('months')) ?>
<span class="help"><?= e(t('Enquiries contain personal data – they should not be kept longer than necessary. 0 = keep forever.')) ?></span></div></div>
<div class="row"><span class="caption"><?= e(t('After that period')) ?></span><div class="options">
<?php foreach (['delete' => 'delete them including attachments', 'anonymise' => 'anonymise them – the row stays for statistics (date, form, page, topic, kind) without the name, e-mail, phone, message and attachments'] as $key => $labelText): ?>
	<label><input type="radio" name="after_expiry" value="<?= $key ?>"<?= $expiry === $key ? ' checked' : '' ?>> <?= e(t($labelText)) ?></label>
<?php endforeach ?>
</div></div>
<div class="row"><label for="months-applicants"><?= e(t('Delete job applications after')) ?></label><div><input class="textfield" type="number" id="months-applicants" name="applicant_months" value="<?= $applicationMonths ?>" min="0" max="120" size="4"> <?= e(t('months')) ?> <input class="btn" type="submit" value="<?= e(t('Save')) ?>">
<span class="help"><?= e(t('Applications sent from the pages of a Job openings collection carry CVs and are usually kept only for a limited time after the selection. 0 = like other enquiries.')) ?><?= $suggestion !== null ? ' ' . e(t('Usual practice in %s: %d months – check with your lawyer.', $suggestion[0], $suggestion[1])) : '' ?></span></div></div>
<div class="row"><span></span><div><label><input type="checkbox" name="triage_assistant" value="1"<?= $app->settings()->bool('triage_assistant') ? ' checked' : '' ?>> <?= e(t('Sort new enquiries with the writing assistant')) ?></label>
<span class="help"><?= e(t('The assistant suggests the kind, the priority and a reply; the text of each enquiry is then sent to the AI provider chosen in Features – mention it in your privacy policy. Claude can sort enquiries over its connection without this.')) ?></span></div></div>
</form>
<p><a class="navigation" href="<?= e($module->url('personal')) ?>"><?= e(t('Personal data request')) ?></a> – <?= e(t('find, export or erase everything about one e-mail address')) ?></p>
<?php endif ?>
