<?php
/**
 * One tag on its own page: the name, the topic introduction and merging into another tag.
 *
 * @var Talea\Admin\Modules\Tags $module
 * @var Talea\Core\App $app
 * @var string $csrf
 * @var list<array<string, mixed>> $tags
 * @var array<string, mixed> $edit
 */
?>
<p class="navigation-row"><a class="navigation" href="<?= e($module->url()) ?>">← <?= e(t('Tags and topics')) ?></a></p>
<form class="form" method="post" action="<?= e($module->url('save')) ?>">
<?= $csrf ?><input type="hidden" name="tag_id" value="<?= e($edit['public_id']) ?>">
<fieldset>
<legend><?= e(t('Tag')) ?></legend>
<div class="row"><label for="name"><?= e(t('Name')) ?></label><input class="textfield wide" type="text" id="name" name="name" value="<?= e($edit['name']) ?>" maxlength="80" required></div>
<div class="row"><label for="description"><?= e(t('Topic introduction')) ?></label><div><textarea class="textbox" id="description" name="description" rows="5" data-editor="small"><?= e((string) $edit['description']) ?></textarea><span class="help"><?= e(t('Optional. Shown above the news list and as the description for search engines.')) ?></span></div></div>
<div class="row"><label for="image"><?= e(t('Topic image')) ?></label><input class="textfield wide" type="text" id="image" name="image" value="<?= e($edit['image']) ?>" maxlength="255" data-image></div>
<details class="advanced">
<summary><?= e(t('Merge with another tag')) ?></summary>
<div class="row"><label for="merge_into"><?= e(t('Merge into')) ?></label><div><select id="merge_into" name="merge_into">
	<option value="0"><?= e(t('Do not merge')) ?></option>
<?php foreach ($tags as $s): if ((int) $s['tag_id'] !== (int) $edit['tag_id']): ?>
	<option value="<?= e($s['public_id']) ?>"><?= e($s['name']) ?> (<?= (int) $s['count'] ?>)</option>
<?php endif; endforeach ?>
</select><span class="help"><?= e(t('The news items move to the selected tag. Talea then deletes this tag and redirects its address. Use it for typos and duplicate spellings.')) ?></span></div></div>
</details>
</fieldset>
<p class="buttons"><input class="btn" type="submit" value="<?= e(t('Save')) ?>"> <a class="navigation" href="<?= e($module->url()) ?>"><?= e(t('Cancel')) ?></a>
<button class="navigation danger buttons-end" type="submit" form="tag-delete" data-confirm="<?= e(t('Delete the tag? The news items stay, they just lose this tag.')) ?>"><?= e(t('Delete')) ?></button></p>
</form>
<form id="tag-delete" method="post" action="<?= e($module->url('delete')) ?>"><?= $csrf ?><input type="hidden" name="tag_id" value="<?= e($edit['public_id']) ?>"></form>
