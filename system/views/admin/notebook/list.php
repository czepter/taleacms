<?php
/**
 * Agent notebook (2.15): the notes by topic with a search, pinned first.
 *
 * @var Talea\Core\App $app
 * @var Talea\Admin\Modules\Notebook $module
 * @var string $csrf
 * @var list<array<string, mixed>> $notes
 * @var string $topic the chosen topic ('' = all)
 * @var string $search
 * @var array<string, int|string> $counts notes per topic
 */
use Talea\Core\Notebook;
?>
<p class="notice"><?= e(t('Notes for whoever works on the site next – Claude in a new conversation, or a colleague: decisions, wording rules, photo credits, the history of the site, what the client is sensitive about. Claude reads them with read_notebook before larger changes and writes decisions down with write_notebook – a drafts-only connection too. Nothing here is shown on the site.')) ?></p>
<?php
$tabs = [['label' => t('All topics'), 'url' => $module->url('', array_filter(['search' => $search])), 'active' => $topic === '']];
foreach (Notebook::TOPICS as $key => $label) {
    $tabs[] = ['label' => t($label), 'url' => $module->url('', array_filter(['topic' => $key, 'search' => $search])), 'active' => $topic === $key] + (isset($counts[$key]) ? ['count' => (int) $counts[$key]] : []);
}
echo $app->view->render('admin/list/head', ['count' => (string) array_sum(array_map('intval', $counts)), 'primary' => [$module->url('edit', array_filter(['topic' => $topic])), t('New note')]]);
echo $app->view->render('admin/list/toolbar', ['tabsLabel' => t('Topic'), 'tabs' => $tabs, 'action' => $app->url('admin.php'), 'hidden' => ['module' => 'notebook'] + array_filter(['topic' => $topic]),
    'search' => ['name' => 'search', 'value' => $search, 'placeholder' => t('Title or text'), 'label' => t('Search notes')]]);
?>
<?php if ($notes === [] && ($search !== '' || $topic !== '')): ?>
<?= $app->view->render('admin/empty', ['icon' => 'log', 'heading' => t('No note matches the filter.'), 'text' => t('Try another word or topic.'), 'action' => [$module->url(), t('Clear filter')]]) ?>
<?php elseif ($notes === []): ?>
<?= $app->view->render('admin/empty', ['icon' => 'log', 'heading' => t('No notes yet.'), 'text' => t('Write down what the next person – or Claude – should know: a decision, a wording rule, who took the photos, why a page looks the way it does.'), 'action' => [$module->url('edit'), t('New note')]]) ?>
<?php else: ?>
<div class="tab-wrap">
<table class="listing">
<thead><tr><th scope="col"><?= e(t('Note')) ?></th><th scope="col"><?= e(t('Topic')) ?></th><th scope="col"><?= e(t('Author')) ?></th><th scope="col"><?= e(t('Changed')) ?></th><th scope="col"><span class="visually-hidden"><?= e(t('Actions')) ?></span></th></tr></thead>
<tbody>
<?php foreach ($notes as $n): $edit = $module->url('edit', ['id' => $n['id']]); $keep = ['id' => (string) $n['id'], 'topic' => $topic, 'search' => $search]; ?>
<tr>
	<td class="cell-main"><div class="cell-text"><span class="cell-row"><a class="cell-title" href="<?= e($edit) ?>"><?= e($n['title']) ?></a><?= $n['pinned'] ? ' <span class="tag">' . e(t('pinned')) . '</span>' : '' ?></span><span class="cell-sub"><?= e(mb_strimwidth((string) $n['text'], 0, 160, '…')) ?></span></div></td>
	<td><?= e(t(Notebook::TOPICS[$n['topic']] ?? $n['topic'])) ?></td>
	<td><?= e($n['author']) ?></td>
	<td class="cell-weak"><?= e(format_ago($n['updated_at'])) ?></td>
	<td class="cell-actions"><?= $app->view->render('admin/list/actions', ['id' => 'note-' . $n['id'], 'name' => $n['title'], 'edit' => $edit, 'csrf' => $csrf, 'menu' => [
        ['post' => $module->url('pin'), 'fields' => $keep, 'label' => t($n['pinned'] ? 'Unpin' : 'Pin'), 'icon' => 'check'], '-',
        ['post' => $module->url('delete'), 'fields' => ['id' => (string) $n['id']], 'label' => t('Delete'), 'confirm' => t('Delete the note? It cannot be brought back.'), 'danger' => true],
    ]]) ?></td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<?php if (count($notes) >= Notebook::MAX_NOTES): ?>
<p class="small-text"><?= e(t('Only the first %d notes are shown – narrow the list by a topic or a search.', Notebook::MAX_NOTES)) ?></p>
<?php endif ?>
<?php endif ?>
