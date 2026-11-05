<?php
/**
 * Your page templates (Builder\PageTemplates): pages saved as templates for New page. Rename or delete them; they are copies, so the
 * pages they came from and the pages started from them stay as they are.
 *
 * @var Talea\Admin\Modules\Pages $module
 * @var Talea\Core\App $app
 * @var string $csrf
 * @var list<array{id: string, name: string, sections: int, updated_at: string}> $templates
 */
?>
<p class="navigation-row"><a class="navigation" href="<?= e($module->url()) ?>"><?= e(t('Back to pages')) ?></a> <a class="navigation" href="<?= e($module->url('new')) ?>"><?= e(t('New page')) ?></a></p>
<?php if ($templates === []): ?>
<?= $app->view->render('admin/empty', ['icon' => 'pages', 'heading' => t('No templates yet.'), 'text' => t('Save any page as a template from its menu in Pages. New page then offers it as a start.')]) ?>
<?php else: ?>
<p class="help"><?= e(t('A template is a copy of a page made when it was saved. New page offers it as a start; the page it came from and the pages started from it stay as they are.')) ?></p>
<div class="tab-wrap"><table class="listing">
<thead><tr><th scope="col"><?= e(t('Name')) ?></th><th scope="col" class="number"><?= e(t('Sections')) ?></th><th scope="col"><?= e(t('Changed')) ?></th><th scope="col"><span class="visually-hidden"><?= e(t('Actions')) ?></span></th></tr></thead>
<tbody>
<?php foreach ($templates as $tpl): ?>
<tr>
	<td class="cell-main"><form class="inline" method="post" action="<?= e($module->url('template_rename')) ?>"><?= $csrf ?><input type="hidden" name="id" value="<?= e($tpl['id']) ?>"><label class="visually-hidden" for="tpl-<?= e($tpl['id']) ?>"><?= e(t('Name')) ?></label><input class="textfield" id="tpl-<?= e($tpl['id']) ?>" name="name" value="<?= e($tpl['name']) ?>" maxlength="100" required> <button class="navigation" type="submit"><?= e(t('Rename')) ?></button></form></td>
	<td class="number"><?= (int) $tpl['sections'] ?></td>
	<td class="cell-weak"><?= e(format_date($tpl['updated_at'])) ?></td>
	<td class="cell-actions"><a class="navigation" href="<?= e($module->url('new', ['template' => 'mine:' . $tpl['id']])) ?>"><?= e(t('New page from it')) ?></a>
		<form class="inline" method="post" action="<?= e($module->url('template_delete')) ?>"><?= $csrf ?><input type="hidden" name="id" value="<?= e($tpl['id']) ?>"><button class="navigation danger" type="submit" data-confirm="<?= e(t('Delete the template “%s”? Pages started from it stay as they are.', $tpl['name'])) ?>"><?= e(t('Delete')) ?></button></form></td>
</tr>
<?php endforeach ?>
</tbody>
</table></div>
<?php endif ?>
