<?php
/**
 * One personal link (Core\FormLinks, Admin\Modules\Forms): whom it is for, its status, what it fills in; while nobody used it the
 * fields (one more draft), the name and the days can change, or it is revoked. Every draft – ours and the person's – is listed with its answers.
 *
 * @var Talea\Core\App $app
 * @var Talea\Admin\Modules\Forms $module
 * @var string $csrf
 * @var array<string, mixed> $link
 * @var array<string, mixed> $form
 * @var list<array<string, mixed>> $fields
 * @var string $status open | used | expired | revoked
 * @var list<array{at: string, by: string, values: array<string, string|list<string>>}> $drafts our suggestions and what the person saved, oldest first
 * @var bool $canSave the form lets the person save drafts
 */
use Talea\Core\FormLinks;

$editable = $status === 'open' || $status === 'expired';
$labels = array_column($fields, 'label', 'key');
$answer = static fn (string|array $v): string => is_array($v) ? implode(', ', $v) : $v;
echo $app->view->render('admin/list/head', ['links' => [[$module->url('detail', ['id' => (string) $form['public_id']]), (string) $form['name']]]]);
?>
<p class="small-text"><?= $app->view->render('admin/list/status', ['kind' => ['open' => 'live', 'used' => 'draft', 'expired' => 'off', 'revoked' => 'off'][$status], 'label' => t(FormLinks::STATUSES[$status])]) ?>
	· <?= e(t('Created %s', format_date((string) $link['created_at'], true))) ?>
	· <?= e(t('Works until %s', format_date((string) $link['expires_at'], true))) ?>
<?php if ($link['used_at'] !== null): ?>
	· <?= e(t('Answered %s', format_date((string) $link['used_at'], true))) ?>
<?php endif ?>
<?php if ((string) $link['email'] !== ''): ?>
	· <?= bdi((string) $link['email']) ?>
<?php endif ?>
</p>
<?php if ($editable): ?>
<form class="form" method="post" action="<?= e($module->url('link_save')) ?>"><?= $csrf ?><input type="hidden" name="link" value="<?= e((string) $link['public_id']) ?>">
	<div class="row"><label for="link-label"><?= e(t('Who is the link for?')) ?></label><div><input class="textfield wide" id="link-label" type="text" name="label" maxlength="190" value="<?= e((string) $link['label']) ?>" required></div></div>
	<div class="row"><label for="extend-days"><?= e(t('Works longer by')) ?></label><div><select class="textfield" id="extend-days" name="extend_days"><option value="0">–</option>
<?php foreach ([7, 14, 30, 90] as $days): ?>
		<option value="<?= $days ?>"><?= e(t('%d days', $days)) ?></option>
<?php endforeach ?>
	</select><span class="help"><?= e(t('Counted from its end, or from today when it ran out.')) ?></span></div></div>
	<h3><?= e(t('Fill in the fields')) ?></h3>
	<p class="help"><?= e(t('The fields show the latest draft. What you save here becomes a new draft the person sees; the earlier ones stay in the history.')) ?></p>
<?= $app->view->render('admin/forms/prefill', ['fields' => $fields, 'values' => FormLinks::draft($link)]) ?>
	<p class="buttons"><button class="btn" type="submit"><?= e(t('Save')) ?></button></p>
</form>
<form method="post" action="<?= e($module->url('link_revoke')) ?>" data-confirm="<?= e(t('Revoke this link? It stops working at once.')) ?>"><?= $csrf ?><input type="hidden" name="link" value="<?= e((string) $link['public_id']) ?>">
	<p class="buttons"><button class="navigation" type="submit"><?= e(t('Revoke link')) ?></button></p>
</form>
<?php else: $filled = FormLinks::draft($link); ?>
<section class="panel-card">
<h2><?= e(t('Filled in')) ?></h2>
<?php if ($filled === []): ?>
<p class="help"><?= e(t('The link filled in nothing.')) ?></p>
<?php else: ?>
<dl>
<?php foreach ($filled as $key => $value): ?>
	<dt><?= bdi((string) ($labels[$key] ?? $key)) ?></dt><dd><?= nl2br(e($answer($value))) ?></dd>
<?php endforeach ?>
</dl>
<?php endif ?>
</section>
<?php endif ?>
<?php if ($drafts !== []): ?>
<h2 id="link-drafts"><?= e(t('Drafts (%d)', count($drafts))) ?></h2>
<?php if (!$canSave): ?>
<p class="small-text"><?= e(t('The person cannot save drafts of this form; switch it on in the form (“The person can save a draft”).')) ?></p>
<?php endif ?>
<div class="tab-wrap">
<table class="listing" aria-labelledby="link-drafts">
<thead><tr><th scope="col"><?= e(t('Saved')) ?></th><th scope="col"><?= e(t('By')) ?></th><th scope="col"><?= e(t('Answers')) ?></th></tr></thead>
<tbody>
<?php foreach (array_reverse($drafts, true) as $n => $draft): ?>
<tr>
	<td><?= e(format_date((string) $draft['at'], true)) ?><?= $n === count($drafts) - 1 ? ' <span class="tag">' . e(t('latest')) . '</span>' : '' ?></td>
	<td><?= e(($draft['by'] ?? '') === 'person' ? t('saved by the person') : t('our suggestion')) ?></td>
	<td><?php foreach ($draft['values'] as $key => $value): ?><strong><?= bdi((string) ($labels[$key] ?? $key)) ?>:</strong> <?= nl2br(e($answer($value))) ?><br><?php endforeach ?></td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<?php endif ?>
