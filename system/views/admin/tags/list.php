<?php
/**
 * @var Talea\Admin\Modules\Tags $module
 * @var Talea\Core\App $app
 * @var string $csrf
 * @var list<array<string, mixed>> $tags
 */
echo $app->view->render('admin/list/head', ['count' => (string) count($tags)]);
?>
<p class="small-text"><?= e(t('Tags are created automatically as you write news. When you add a description to a tag, its page becomes a topic – an introduction to a field or project with all its news in one place.')) ?></p>
<?php if ($tags === []): ?>
<?= $app->view->render('admin/empty', ['icon' => 'tags', 'heading' => t('No tags yet.'), 'text' => t('Add them in the news editor in the Tags field. Here you can then merge them and turn them into topic pages.')]) ?>
<?php else: ?>
<div class="tab-wrap"><table class="listing">
<thead><tr><th scope="col"><?= e(t('Tag')) ?></th><th scope="col"><?= e(t('News items')) ?></th><th scope="col"><span class="visually-hidden"><?= e(t('Actions')) ?></span></th></tr></thead>
<tbody>
<?php foreach ($tags as $s): $editUrl = $module->url('', ['edit' => $s['public_id']]); ?>
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
