<?php
/**
 * Review by the person an item is about (Core\FormLinks, Admin\Modules\Collections::review): "Send for confirmation" makes a
 * personal link that shows the item's values in a form of this collection; an answer waits as a hidden draft and is compared
 * here with the item, field by field – Apply puts it into the item (the previous version stays in the item history), Discard
 * leaves the item as it is. A draft answer points to the item it answers.
 *
 * @var Talea\Core\App $app
 * @var Talea\Admin\Modules\Collections $module
 * @var string $csrf
 * @var array<string, mixed> $k
 * @var array<string, mixed> $p
 * @var array{forms: array<string, string>, links: list<array<string, mixed>>, answerTo: ?array<string, mixed>, canLink: bool} $review
 */
use Talea\Core\FormLinks;

if ($review['forms'] === [] && $review['links'] === [] && $review['answerTo'] === null) {
    return;
}
$kinds = ['open' => 'live', 'used' => 'draft', 'expired' => 'off', 'revoked' => 'off'];
$answers = ['waiting' => t('Answer waiting'), 'applied' => t('Applied'), 'discarded' => t('Discarded')];
?>
<details class="panel-collapsed item-review" open>
<summary><h2><?= e(t('Confirmation by the customer')) ?></h2></summary>
<?php if ($review['answerTo'] !== null && $review['answerTo']['original'] !== null): ?>
<p class="help"><?= e(t('This hidden draft is an answer to a confirmation link.')) ?>
	<a href="<?= e($module->url('item', ['id' => $k['public_id'], 'item' => (string) $review['answerTo']['original']['public_id']])) ?>"><?= e(t('Compare it with “%s”', (string) $review['answerTo']['original']['name'])) ?></a></p>
<?php endif ?>
<?php foreach ($review['links'] as $l): ?>
<section class="review-link">
	<p><strong><?= bdi((string) $l['label']) ?></strong>
		<?= $app->view->render('admin/list/status', ['kind' => $kinds[$l['status']], 'label' => t(FormLinks::STATUSES[$l['status']])]) ?>
		<?= isset($answers[$l['state']]) ? '<span class="tag">' . e($answers[$l['state']]) . '</span>' : '' ?>
		<span class="help"><?= e($l['used_at'] !== null ? t('Answered %s', format_date((string) $l['used_at'], true)) : t('Works until %s', format_date((string) $l['expires_at']))) ?></span>
<?php if ($l['status'] === 'open' && $review['canLink']): ?>
		<a class="navigation" href="<?= e($app->url('admin.php?module=forms&action=link_edit&link=' . rawurlencode((string) $l['public_id']))) ?>"><?= e(t('Edit link')) ?></a>
<?php endif ?>
	</p>
<?php if ($l['compare'] !== null): ?>
	<div class="tab-wrap">
	<table class="listing review-compare">
	<thead><tr><th scope="col"><?= e(t('Field')) ?></th><th scope="col"><?= e(t('Now')) ?></th><th scope="col"><?= e(t('Their answer')) ?></th></tr></thead>
	<tbody>
<?php foreach ($l['compare']['rows'] as $row): ?>
	<tr<?= $row['changed'] ? ' class="unread"' : '' ?>><th scope="row"><?= bdi($row['label']) ?><?= $row['changed'] ? ' <span class="tag">' . e(t('changed')) . '</span>' : '' ?><?= $row['kept'] ? ' <span class="tag" title="' . e(t('The form did not show this value and the answer left it empty, so the item keeps it.')) . '">' . e(t('stays')) . '</span>' : '' ?></th>
		<td><?= nl2br(e($row['before'])) ?></td><td><?= nl2br(e($row['after'])) ?></td></tr>
<?php endforeach ?>
	</tbody>
	</table>
	</div>
	<form class="buttons" method="post" action="<?= e($module->url('review_answer')) ?>"><?= $csrf ?><input type="hidden" name="link" value="<?= e((string) $l['public_id']) ?>">
		<button class="btn" type="submit" name="decision" value="apply" data-confirm="<?= e(t('Apply the answer to the item? The current version stays in the item history.')) ?>"><?= e(t('Apply')) ?></button>
		<button class="navigation" type="submit" name="decision" value="discard"><?= e(t('Discard')) ?></button>
	</form>
<?php endif ?>
</section>
<?php endforeach ?>
<?php if ($review['canLink'] && $review['forms'] !== []): ?>
<form class="form" method="post" action="<?= e($module->url('review_link')) ?>"><?= $csrf ?>
	<input type="hidden" name="collection_id" value="<?= e((string) $k['public_id']) ?>"><input type="hidden" name="item_id" value="<?= e((string) $p['public_id']) ?>">
	<h3><?= e(t('Send for confirmation')) ?></h3>
	<p class="help"><?= e(t('The person gets a link to a form that shows this item’s text. They change it or leave it; their answer waits here next to the item until you apply it.')) ?></p>
	<div class="row"><label for="review-form"><?= e(t('Form')) ?></label><div><select class="textfield" id="review-form" name="form">
<?php foreach ($review['forms'] as $id => $name): ?>
		<option value="<?= e($id) ?>"><?= e($name) ?></option>
<?php endforeach ?>
	</select></div></div>
	<div class="row"><label for="review-label"><?= e(t('Who is the link for?')) ?></label><div><input class="textfield wide" id="review-label" type="text" name="label" maxlength="190" placeholder="<?= e((string) $p['name']) ?>"></div></div>
	<div class="row"><label for="review-email"><?= e(t('E-mail (optional)')) ?></label><div><input class="textfield wide" id="review-email" type="email" name="email" maxlength="190">
		<label><input type="checkbox" name="send" value="1"> <?= e(t('Send the link to this address now, with the form’s link e-mail')) ?></label></div></div>
	<p class="buttons"><button class="btn" type="submit"><?= e(t('Create confirmation link')) ?></button></p>
</form>
<?php elseif ($review['canLink'] && $review['links'] === []): ?>
<p class="help"><?= e(t('No form for personal links adds to this collection yet. Create one in Enquiries → Forms (“Add an item to a collection”, only through a personal link).')) ?></p>
<?php endif ?>
</details>
