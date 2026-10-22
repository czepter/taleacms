<?php
/**
 * @var Talea\Admin\Modules\Tags $module
 * @var Talea\Core\App $app
 * @var string $csrf
 * @var list<array<string, mixed>> $tags
 * @var array<string, mixed>|null $edit
 */
echo $app->view->render('admin/list/head', ['count' => (string) count($tags)]);
?>
<p class="small-text"><?= e(t('Tags are created automatically as you write news. When you add a description to a tag, its page becomes a topic – an introduction to a field or project with all its news in one place.')) ?></p>
<?php if ($edit !== null): ?>
<form class="form" method="post" action="<?= e($module->url('save')) ?>" id="edit">
<?= $csrf ?><input type="hidden" name="tag_id" value="<?= e($edit['public_id']) ?>">
<fieldset>
<legend><?= e(t('Edit tag')) ?></legend>
<div class="row"><label for="name"><?= e(t('Name')) ?></label><input class="textfield wide" type="text" id="name" name="name" value="<?= e($edit['name']) ?>" maxlength="80" required></div>
<div class="row"><label for="description"><?= e(t('Topic introduction')) ?></label><div><textarea class="textbox" id="description" name="description" rows="5" data-editor="small"><?= e((string) $edit['description']) ?></textarea><span class="help"><?= e(t('Optional. Shown above the news list and as the description for search engines.')) ?></span></div></div>
<div class="row"><label for="image"><?= e(t('Topic image')) ?></label><input class="textfield wide" type="text" id="image" name="image" value="<?= e($edit['image']) ?>" maxlength="255" data-image></div>
<details class="advanced">
<summary><?= e(t('Merge with another tag')) ?></summary>
<div class="row"><label for="merge_into"><?= e(t('Merge into')) ?></label><div><select id="merge_into" name="merge_into">
	<option value="0"><?= e(t('– do not merge –')) ?></option>
<?php foreach ($tags as $s): if ((int) $s['tag_id'] !== (int) $edit['tag_id']): ?>
	<option value="<?= e($s['public_id']) ?>"><?= e($s['name']) ?> (<?= (int) $s['count'] ?>)</option>
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
<thead><tr><th scope="col"><?= e(t('Tag')) ?></th><th scope="col"><?= e(t('News items')) ?></th><th scope="col"><span class="visually-hidden"><?= e(t('Actions')) ?></span></th></tr></thead>
<tbody>
<?php foreach ($tags as $s): $editUrl = $module->url('', ['edit' => $s['public_id']]) . '#edit'; ?>
<tr>
	<td class="cell-main"><div class="cell-text"><a class="cell-title" href="<?= e($editUrl) ?>">#<?= e($s['name']) ?></a><span class="cell-sub"><?= e(trim((string) $s['description']) !== '' ? t('Topic page with an introduction') : t('Tag without an introduction')) ?></span></div></td>
	<td class="number"><?= (int) $s['count'] ?></td>
	<td class="cell-actions"><?= $app->view->render('admin/list/actions', ['id' => $s['public_id'], 'name' => '#' . $s['name'], 'edit' => $editUrl, 'csrf' => $csrf, 'menu' => [
        ['url' => $app->url('news/tag/' . $s['slug']), 'label' => t('View on the site'), 'external' => true], '-',
        ['post' => $module->url('delete'), 'fields' => ['tag_id' => $s['public_id']], 'label' => t('Delete'), 'confirm' => t('Delete the tag? The news items stay, they just lose this tag.'), 'danger' => true],
    ]]) ?></td>
</tr>
<?php endforeach ?>
</tbody></table></div>
<?php endif ?>
