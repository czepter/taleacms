<?php
/**
 * A new form: its name and what it starts with; it then opens in the form builder (Admin\Modules\Forms).
 *
 * @var Talea\Core\App $app
 * @var Talea\Admin\Modules\Forms $module
 * @var string $csrf
 * @var list<array<string, mixed>> $collections the collections a visitor could add items to
 * @var array{url: string, text: string}|null $back the page builder it was asked from
 */
use Talea\Admin\Modules\Forms;

?>
<form class="form" method="post" action="<?= e($module->url('create')) ?>">
<?= $csrf ?>
<?php if ($back !== null): ?>
<input type="hidden" name="back" value="<?= e($back['url']) ?>">
<?php endif ?>
<div class="row"><label for="name"><?= e(t('Form name')) ?></label><div><input class="textfield wide" id="name" name="name" maxlength="120" required placeholder="<?= e(t('e.g. Wedding enquiry')) ?>"><span class="help"><?= e(t('Shown in Enquiries and in the e-mails, not to visitors.')) ?></span></div></div>
<div class="row"><span class="caption"><?= e(t('Start from')) ?></span><div class="options">
<?php foreach (Forms::STARTERS as $key => [$name, $description]): ?>
	<label><input type="radio" name="starter" value="<?= e($key) ?>"<?= $key === 'enquiry' ? ' checked' : '' ?>> <strong><?= e(t($name)) ?></strong> <span class="help"><?= e(t($description)) ?></span></label>
<?php endforeach ?>
<?php if ($collections !== []): ?>
	<label><input type="radio" name="starter" value="collection"> <strong><?= e(t('Add an item to a collection')) ?></strong> <span class="help"><?= e(t('A field for everything a visitor can fill in, sent as a hidden draft item you review')) ?></span></label>
</div></div>
<div class="row"><label for="collection"><?= e(t('Collection')) ?></label><div>
	<select class="textfield" id="collection" name="collection">
<?php foreach ($collections as $c): ?>
		<option value="<?= e($c['public_id']) ?>"><?= e($c['name']) ?></option>
<?php endforeach ?>
	</select>
	<label><input type="checkbox" name="by_link" value="1" checked> <?= e(t('Only through a personal link')) ?></label>
	<span class="help"><?= e(t('Used only with "Add an item to a collection". Without the link anyone who finds the form can send a draft.')) ?></span>
</div></div>
<?php else: ?>
</div></div>
<?php endif ?>
<p class="buttons"><input class="btn" type="submit" value="<?= e(t('Create and edit the fields')) ?>"> <a class="navigation" href="<?= e($back['url'] ?? $module->url()) ?>"><?= e(t('Back')) ?></a></p>
</form>
