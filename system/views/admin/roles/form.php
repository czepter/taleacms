<?php
/**
 * @var Kaleta\Admin\Modules\Roles $module
 * @var string $csrf
 * @var array<string, mixed> $role
 * @var array<string, string> $errors
 * @var array<string, string> $section  ident => name
 * @var list<string> $selected
 * @var list<array<string, mixed>> $members
 */
$error = fn (string $field): string => isset($errors[$field]) ? '<span class="error-field" role="alert">' . e(t($errors[$field])) . '</span>' : '';
?>
<p class="navigation-row"><a class="navigation" href="<?= e($module->url()) ?>"><?= e(t('Back to roles')) ?></a></p>
<form class="form" method="post" action="<?= e($module->url('save')) ?>">
<?= $csrf ?>
<input type="hidden" name="role_id" value="<?= (int) $role['role_id'] ?>">
<div class="row">
	<label for="name"><?= e(t('Role name')) ?></label>
	<div><input class="textfield" type="text" id="name" name="name" value="<?= e($role['name']) ?>" maxlength="60" required placeholder="<?= e(t('e.g. Salesperson')) ?>"><?= $error('name') ?></div>
</div>
<div class="row">
	<label for="description"><?= e(t('Description')) ?></label>
	<input class="textfield wide" type="text" id="description" name="description" value="<?= e($role['description']) ?>" maxlength="200">
</div>
<fieldset>
<legend><?= e(t('News')) ?></legend>
<div class="cards-options cards-options-text">
<?php foreach (Kaleta\Admin\Modules\Roles::LEVELS as $value => [$name, $description]): ?>
	<label class="card-option"><input type="radio" name="level" value="<?= $value ?>"<?= (int) $role['level'] === $value ? ' checked' : '' ?>><strong><?= e(t($name)) ?></strong><span><?= e(t($description)) ?></span></label>
<?php endforeach ?>
</div>
</fieldset>
<fieldset>
<legend><?= e(t('Access to areas')) ?></legend>
<div class="options">
<?php foreach ($section as $ident => $name): ?>
	<label><input type="checkbox" name="modules[]" value="<?= e($ident) ?>"<?= in_array($ident, $selected, true) ? ' checked' : '' ?>> <?= e(t($name)) ?></label><br>
<?php endforeach ?>
	<?= $error('modules') ?>
	<span class="help"><?= e(t('Website settings, users and appearance stay with the administrator.')) ?></span>
</div>
</fieldset>
<?php if ($members !== []): ?>
<p class="small-text"><?= e(t('Saving also changes the permissions of these users:')) ?> <?= e(implode(', ', array_map(fn (array $u): string => $u['name'] !== '' ? $u['name'] : $u['username'], $members))) ?></p>
<?php endif ?>
<p class="buttons"><input class="btn" type="submit" value="<?= e(t('Save')) ?>"></p>
</form>
