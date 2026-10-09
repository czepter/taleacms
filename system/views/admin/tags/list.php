<?php
/**
 * @var Kaleta\Admin\Modules\Tags $module
 * @var Kaleta\Core\App $app
 * @var string $csrf
 * @var list<array<string, mixed>> $tags
 * @var array<string, mixed>|null $edit
 */
?>
<p class="small-text"><?= e(t('Tags are created automatically as you write news. When you add a description to a tag, its page becomes a topic – an introduction to a field or project with all its news in one place.')) ?></p>
<?php if ($edit !== null): ?>
<form class="form" method="post" action="<?= e($module->url('save')) ?>" id="uprav">
<?= $csrf ?><input type="hidden" name="tag_id" value="<?= (int) $edit['tag_id'] ?>">
<fieldset>
<legend><?= e(t('Edit tag')) ?></legend>
<div class="row"><label for="nazev"><?= e(t('Name')) ?></label><input class="textfield wide" type="text" id="nazev" name="name" value="<?= e($edit['name']) ?>" maxlength="80" required></div>
<div class="row"><label for="popis"><?= e(t('Topic introduction')) ?></label><div><textarea class="textbox" id="popis" name="description" rows="5" data-editor="small"><?= e((string) $edit['description']) ?></textarea><span class="help"><?= e(t('Optional. Shown above the news list and as the description for search engines.')) ?></span></div></div>
<div class="row"><label for="obrazek"><?= e(t('Topic image')) ?></label><input class="textfield wide" type="text" id="obrazek" name="image" value="<?= e($edit['image']) ?>" maxlength="255" data-image></div>
<details class="advanced">
<summary><?= e(t('Merge with another tag')) ?></summary>
<div class="row"><label for="sloucit_do"><?= e(t('Merge into')) ?></label><div><select id="sloucit_do" name="merge_into">
	<option value="0"><?= e(t('– do not merge –')) ?></option>
<?php foreach ($tags as $s): if ((int) $s['tag_id'] !== (int) $edit['tag_id']): ?>
	<option value="<?= (int) $s['tag_id'] ?>"><?= e($s['name']) ?> (<?= (int) $s['count'] ?>)</option>
<?php endif; endforeach ?>
</select><span class="help"><?= e(t('The news items get the selected tag, this one is removed and its address redirects. Useful for typos and duplicate spellings.')) ?></span></div></div>
</details>
</fieldset>
<p class="buttons"><input class="btn" type="submit" value="<?= e(t('Save')) ?>"> <a class="navigation" href="<?= e($module->url()) ?>"><?= e(t('Cancel')) ?></a></p>
</form>
<?php endif ?>
<?php if ($tags === []): ?>
<?= $app->view->render('admin/empty', ['icon' => 'tags', 'heading' => t('No tags yet.'), 'text' => t('Add them in the news editor in the Tags field. Here you can then merge them and turn them into topic pages.')]) ?>
<?php else: ?>
<div class="tab-wrap"><table class="listing">
<thead><tr><th scope="col"><?= e(t('Tag')) ?></th><th scope="col"><?= e(t('News items')) ?></th><th scope="col"><?= e(t('Topic')) ?></th><th scope="col"><?= e(t('Actions')) ?></th></tr></thead>
<tbody>
<?php foreach ($tags as $s): ?>
<tr>
	<td><a href="<?= e($app->url('news/tag/' . $s['slug'])) ?>" target="_blank" rel="noopener">#<?= e($s['name']) ?></a></td>
	<td class="number"><?= (int) $s['count'] ?></td>
	<td><?= trim((string) $s['description']) !== '' ? '<span class="badge badge-published">' . e(t('has an intro')) . '</span>' : '' ?></td>
	<td class="actions"><a href="<?= e($module->url('', ['edit' => $s['tag_id']])) ?>#uprav"><?= e(t('Edit')) ?></a>
		<form class="inline" method="post" action="<?= e($module->url('delete')) ?>" data-confirm="<?= e(t('Delete the tag? The news items stay, they just lose this tag.')) ?>"><?= $csrf ?><input type="hidden" name="tag_id" value="<?= (int) $s['tag_id'] ?>"><button class="navigation danger" type="submit"><?= e(t('Delete')) ?></button></form></td>
</tr>
<?php endforeach ?>
</tbody></table></div>
<?php endif ?>
