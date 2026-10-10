<?php
/**
 * Name and properties of a component.
 *
 * @var Talea\Core\App $app
 * @var Talea\Admin\Modules\Components $module
 * @var string $csrf
 * @var array<string, mixed> $k
 */
use Talea\Builder\Components;

$properties = array_merge($k['properties'], array_fill(0, 3, ['key' => '', 'label' => '', 'type' => 'text', 'default' => '']));
?>
<form class="form" method="post" action="<?= e($module->url('save')) ?>">
<?= $csrf ?>
<input type="hidden" name="component_id" value="<?= e($k['public_id']) ?>">
<div class="row"><label for="name"><?= e(t('Component name')) ?></label><div><input class="textfield wide" id="name" name="name" value="<?= e($k['name']) ?>" maxlength="100" required placeholder="<?= e(t('e.g. Service card')) ?>"></div></div>
<fieldset>
<legend><?= e(t('Properties')) ?></legend>
<p class="help"><?= e(t('What can differ between uses of the component – heading, text, image, link. In the builder you insert them into the component with a {{key}} tag, then fill in a value for each use (empty = default).')) ?></p>
<div class="tab-wrap">
<table class="listing">
<thead><tr><th scope="col"><?= e(t('Label')) ?></th><th scope="col"><?= e(t('Type')) ?></th><th scope="col"><?= e(t('Default value')) ?></th><th scope="col"><?= e(t('Tag')) ?></th></tr></thead>
<tbody>
<?php foreach ($properties as $i => $v): ?>
<tr>
	<td><input class="textfield" name="properties[<?= $i ?>][label]" value="<?= e($v['label']) ?>" maxlength="80" aria-label="<?= e(t('Label')) ?>"><input type="hidden" name="properties[<?= $i ?>][key]" value="<?= e($v['key']) ?>"></td>
	<td><select name="properties[<?= $i ?>][type]" aria-label="<?= e(t('Type')) ?>">
<?php foreach (Components::TYPES as $type => $name): ?>
		<option value="<?= e($type) ?>"<?= $v['type'] === $type ? ' selected' : '' ?>><?= e(t($name)) ?></option>
<?php endforeach ?>
	</select></td>
	<td><input class="textfield" name="properties[<?= $i ?>][default]" value="<?= e($v['default']) ?>" maxlength="500" aria-label="<?= e(t('Default value')) ?>"></td>
	<td><?= $v['key'] !== '' ? '<code>{{' . e($v['key']) . '}}</code>' : '<span class="help">' . e(t('created from the label')) . '</span>' ?></td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
</fieldset>
<p class="buttons"><input class="btn" type="submit" value="<?= e(t('Save component')) ?>"> <a class="navigation" href="<?= e($module->url()) ?>"><?= e(t('Back')) ?></a></p>
</form>
