<?php
/**
 * One short link on its own page: a new one (target prefilled from an edit screen) or an existing one.
 *
 * @var Talea\Core\App $app
 * @var Talea\Admin\Modules\ShortLinks $module
 * @var string $csrf
 * @var ?array<string, mixed> $edit  the short link (null = a new one)
 * @var array<string, string> $typed  what a refused save sent, shown as typed
 * @var string $preset  "page:<public id>" from the entry link of an edit screen
 * @var array<string, list<array{0: string, 1: string}>> $options  picker groups => [value, label]
 */
$u = $edit;
$field = fn (string $key): string => (string) ($typed[$key] ?? ($u[$key] ?? ''));
$target = $typed['target'] ?? ($u !== null ? ($u['target_type'] === 'url' ? 'url' : $u['target_type'] . ':' . $app->db()->publicId(['page' => 'pages', 'news' => 'news', 'item' => 'collection_items'][$u['target_type']], (int) $u['target_id'])) : ($preset !== '' ? $preset : ''));
$kind = $typed['kind'] ?? 'random';
$more = $field('utm_term') !== '' || $field('utm_content') !== '';
?>
<p class="navigation-row"><a class="navigation" href="<?= e($module->url()) ?>">← <?= e(t('Short links')) ?></a></p>
<form class="form" method="post" action="<?= e($module->url('save')) ?>">
<?= $csrf ?><input type="hidden" name="short_link_id" value="<?= e($u['public_id'] ?? '') ?>">
<fieldset><legend><?= e(t($u !== null ? 'Edit short link' : 'New short link')) ?></legend>
<?php if ($u !== null): ?>
<div class="row"><span class="caption"><?= e(t('Short address')) ?></span><div><code>/<?= e($u['path']) ?></code><span class="help"><?= e(t('The short address cannot change, it may be printed already. Delete the short link and create a new one to use another address.')) ?></span></div></div>
<?php else: ?>
<div class="row"><span class="caption"><?= e(t('Short address')) ?></span><div>
	<label><input type="radio" name="kind" value="random"<?= $kind !== 'name' ? ' checked' : '' ?>> <?= e(t('A random code, such as /s/k7f2qm')) ?></label><br>
	<label><input type="radio" name="kind" value="name"<?= $kind === 'name' ? ' checked' : '' ?>> <?= e(t('A name of my own')) ?></label>
	<label class="visually-hidden" for="name"><?= e(t('Name')) ?></label> /<input class="textfield" type="text" id="name" name="name" value="<?= e($field('name')) ?>" maxlength="60" placeholder="<?= e(t('flyer')) ?>">
	<span class="help"><?= e(t('Lower-case letters, digits and hyphens. A typed capital letter is accepted when the link is followed.')) ?></span></div></div>
<?php endif ?>
<div class="row"><label for="target"><?= e(t('Leads to')) ?></label><div><select id="target" name="target">
	<option value="url"<?= $target === 'url' ? ' selected' : '' ?>><?= e(t('Another address (below)')) ?></option>
<?php foreach ($options as $group => $items): ?>
	<optgroup label="<?= e($group) ?>">
<?php foreach ($items as [$value, $label]): ?>
		<option value="<?= e($value) ?>"<?= $target === $value ? ' selected' : '' ?>><?= e($label) ?></option>
<?php endforeach ?>
	</optgroup>
<?php endforeach ?>
</select></div></div>
<div class="row"><label for="target_url"><?= e(t('Address')) ?></label><div><input class="textfield wide" type="text" id="target_url" name="target_url" value="<?= e($field('target_url')) ?>" maxlength="2000" placeholder="<?= e(t('/offer or https://…')) ?>"><span class="help"><?= e(t('Only used when "Another address" is chosen above.')) ?></span></div></div>
<div class="row"><label for="label"><?= e(t('Label')) ?></label><div><input class="textfield wide" type="text" id="label" name="label" value="<?= e($field('label')) ?>" maxlength="120"><span class="help"><?= e(t('For you only, to recognise the link in the list.')) ?></span></div></div>
</fieldset>
<fieldset><legend><?= e(t('Campaign parameters')) ?></legend>
<p class="help"><?= e(t('Added to the address when the short link is followed, so analytics can tell where a visit came from. They never appear on the paper.')) ?></p>
<?php foreach (['utm_source' => 'Source', 'utm_medium' => 'Medium', 'utm_campaign' => 'Campaign'] as $key => $label): ?>
<div class="row"><label for="<?= $key ?>"><?= e(t($label)) ?></label><div><input class="textfield" type="text" id="<?= $key ?>" name="<?= $key ?>" value="<?= e($field($key)) ?>" maxlength="80"></div></div>
<?php endforeach ?>
<details class="advanced"<?= $more ? ' open' : '' ?>>
<summary><?= e(t('More parameters')) ?></summary>
<?php foreach (['utm_term' => 'Term', 'utm_content' => 'Content'] as $key => $label): ?>
<div class="row"><label for="<?= $key ?>"><?= e(t($label)) ?></label><div><input class="textfield" type="text" id="<?= $key ?>" name="<?= $key ?>" value="<?= e($field($key)) ?>" maxlength="80"></div></div>
<?php endforeach ?>
</details>
</fieldset>
<p class="buttons"><input class="btn" type="submit" value="<?= e(t('Save')) ?>"> <a class="navigation" href="<?= e($module->url()) ?>"><?= e(t('Cancel')) ?></a>
<?php if ($u !== null): ?><a class="navigation" href="<?= e($module->url('qr', ['id' => $u['public_id']])) ?>"><?= e(t('QR code (SVG)')) ?></a>
<button class="navigation danger buttons-end" type="submit" form="short-link-delete" data-confirm="<?= e(t('Delete the short link? Printed copies of it will then end with a 404 error.')) ?>"><?= e(t('Delete')) ?></button><?php endif ?></p>
</form>
<?php if ($u !== null): ?>
<form id="short-link-delete" method="post" action="<?= e($module->url('delete')) ?>"><?= $csrf ?><input type="hidden" name="short_link_id" value="<?= e($u['public_id']) ?>"></form>
<?php endif ?>
