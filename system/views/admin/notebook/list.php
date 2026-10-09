<?php
/**
 * Agent notebook (2.15): the notes by topic with a search, pinned first.
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\Notebook $module
 * @var string $csrf
 * @var list<array<string, mixed>> $notes
 * @var string $topic the chosen topic ('' = all)
 * @var string $search
 * @var array<string, int|string> $counts notes per topic
 */
use Kaleta\Core\Notebook;
?>
<p class="notice"><?= e(t('Notes for whoever works on the site next – Claude in a new conversation, or a colleague: decisions, wording rules, photo credits, the history of the site, what the client is sensitive about. Claude reads them with read_notebook before larger changes and writes decisions down with write_notebook – a drafts-only connection too. Nothing here is shown on the site.')) ?></p>
<nav class="tabs" aria-label="<?= e(t('Topic')) ?>">
	<a href="<?= e($module->url('', array_filter(['search' => $search]))) ?>"<?= $topic === '' ? ' class="active" aria-current="true"' : '' ?>><?= e(t('All topics')) ?></a>
<?php foreach (Notebook::TOPICS as $key => $label): ?>
	<a href="<?= e($module->url('', array_filter(['topic' => $key, 'search' => $search]))) ?>"<?= $topic === $key ? ' class="active" aria-current="true"' : '' ?>><?= e(t($label)) ?><?= isset($counts[$key]) ? ' (' . (int) $counts[$key] . ')' : '' ?></a>
<?php endforeach ?>
</nav>
<form method="get" action="<?= e($app->url('admin.php')) ?>" class="center small-text">
	<input type="hidden" name="module" value="notebook"><input type="hidden" name="topic" value="<?= e($topic) ?>">
	<label><?= e(t('Search (title, text):')) ?> <input class="textfield" type="search" name="search" value="<?= e($search) ?>" size="24"></label>
	<input class="btn" type="submit" value="<?= e(t('Filter')) ?>">
</form>
<p><a class="btn" href="<?= e($module->url('edit', array_filter(['topic' => $topic]))) ?>"><?= e(t('New note')) ?></a></p>
<?php if ($notes === [] && ($search !== '' || $topic !== '')): ?>
<?= $app->view->render('admin/empty', ['icon' => 'log', 'heading' => t('No note matches the filter.'), 'text' => t('Try another word or topic.'), 'action' => [$module->url(), t('Clear filter')]]) ?>
<?php elseif ($notes === []): ?>
<?= $app->view->render('admin/empty', ['icon' => 'log', 'heading' => t('No notes yet.'), 'text' => t('Write down what the next person – or Claude – should know: a decision, a wording rule, who took the photos, why a page looks the way it does.'), 'action' => [$module->url('edit'), t('New note')]]) ?>
<?php else: ?>
<div class="tab-wrap">
<table class="listing">
<thead><tr><th scope="col"><?= e(t('Note')) ?></th><th scope="col"><?= e(t('Topic')) ?></th><th scope="col"><?= e(t('Author')) ?></th><th scope="col"><?= e(t('Changed')) ?></th><th scope="col"><?= e(t('Actions')) ?></th></tr></thead>
<tbody>
<?php foreach ($notes as $n): ?>
<tr>
	<td><a href="<?= e($module->url('edit', ['id' => $n['id']])) ?>"><strong><?= e($n['title']) ?></strong></a><?= $n['pinned'] ? ' <span class="badge badge-published">' . e(t('pinned')) . '</span>' : '' ?><br><span class="small-text"><?= e(mb_strimwidth((string) $n['text'], 0, 160, '…')) ?></span></td>
	<td><?= e(t(Notebook::TOPICS[$n['topic']] ?? $n['topic'])) ?></td>
	<td><?= e($n['author']) ?></td>
	<td class="number"><?= e(format_date($n['updated_at'], true)) ?></td>
	<td class="actions">
		<form class="inline" method="post" action="<?= e($module->url('pin')) ?>"><?= $csrf ?><input type="hidden" name="id" value="<?= (int) $n['id'] ?>"><input type="hidden" name="topic" value="<?= e($topic) ?>"><input type="hidden" name="search" value="<?= e($search) ?>"><button class="navigation" type="submit"><?= e(t($n['pinned'] ? 'Unpin' : 'Pin')) ?></button></form> ·
		<form class="inline" method="post" action="<?= e($module->url('delete')) ?>" data-confirm="<?= e(t('Delete the note? It cannot be brought back.')) ?>"><?= $csrf ?><input type="hidden" name="id" value="<?= (int) $n['id'] ?>"><button class="navigation danger" type="submit"><?= e(t('Delete')) ?></button></form>
	</td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<?php if (count($notes) >= Notebook::MAX_NOTES): ?>
<p class="small-text"><?= e(t('Only the first %d notes are shown – narrow the list by a topic or a search.', Notebook::MAX_NOTES)) ?></p>
<?php endif ?>
<?php endif ?>
