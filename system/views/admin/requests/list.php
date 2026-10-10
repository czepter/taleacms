<?php
/**
 * Requests to Claude (2.15): the team's inbox – open requests first (new, in progress), then done and declined.
 *
 * @var Talea\Core\App $app
 * @var Talea\Admin\Modules\Requests $module
 * @var string $csrf
 * @var list<array<string, mixed>> $requests with 'author' and decoded 'attachments'
 * @var string $status the filter ('' = all)
 * @var int $open new and in progress together
 * @var bool $claudeOn the Claude connection extension is on
 */
use Talea\Core\Requests;

$tag = fn (string $status): string => '<span class="badge' . match ($status) { 'done' => ' badge-published', 'new' => ' badge-draft', 'declined' => ' badge-error', default => '' } . '">' . e(t(Requests::STATUSES[$status] ?? $status)) . '</span>';
?>
<p class="notice"><?= e(t('Write what should change on the site – "change the opening hours on Monday", "add this PDF to the price list" – and Claude does it as drafts the next time it works on the site. Its notes and the links to the drafts appear in the request; a person reviews and publishes them.')) ?></p>
<?php if (!$claudeOn): ?>
<p class="notice error"><?= e(t('The Claude connection is switched off (Features) – requests are saved, but nobody reads them until it is on.')) ?></p>
<?php endif ?>
<p><a class="btn" href="<?= e($module->url('new')) ?>"><?= e(t('New request')) ?></a></p>
<nav class="tabs" aria-label="<?= e(t('Request status')) ?>">
<?php foreach (['' => t('All')] + array_map('t', Requests::STATUSES) as $key => $name): ?>
	<a href="<?= e($module->url('', array_filter(['status' => $key]))) ?>"<?= $status === $key ? ' class="active" aria-current="true"' : '' ?>><?= e($name) ?></a>
<?php endforeach ?>
</nav>
<?php if ($requests === [] && $status !== ''): ?>
<?= $app->view->render('admin/empty', ['icon' => 'comments', 'heading' => t('No request has this status.'), 'text' => '', 'action' => [$module->url(), t('Clear filter')]]) ?>
<?php elseif ($requests === []): ?>
<?= $app->view->render('admin/empty', ['icon' => 'comments', 'heading' => t('No requests yet.'), 'text' => t('Write the first one: what should change, on which page, with the files Claude needs.'), 'action' => [$module->url('new'), t('New request')]]) ?>
<?php else: ?>
<p class="small-text"><?= e(t('%d open, %d shown.', $open, count($requests))) ?></p>
<div class="tab-wrap"><table class="listing">
<thead><tr><th scope="col"><?= e(t('Request')) ?></th><th scope="col"><?= e(t('Author')) ?></th><th scope="col"><?= e(t('Status')) ?></th><th scope="col"><?= e(t('Written')) ?></th><th scope="col"><?= e(t('Last change')) ?></th></tr></thead>
<tbody>
<?php foreach ($requests as $r): ?>
<tr<?= in_array($r['status'], ['done', 'declined'], true) ? ' class="unpublished"' : '' ?>>
	<td><a href="<?= e($module->url('detail', ['id' => $r['public_id']])) ?>"><strong><?= e((string) $r['title']) ?></strong></a><?= $r['attachments'] !== [] ? ' <span class="small-text">(' . e(t('%d attachments', count($r['attachments']))) . ')</span>' : '' ?><br><span class="small-text"><?= e(mb_strimwidth((string) $r['text'], 0, 120, '…')) ?></span></td>
	<td><?= e((string) $r['author']) ?></td>
	<td><?= $tag((string) $r['status']) ?></td>
	<td><?= e(format_date((string) $r['created_at'], true)) ?></td>
	<td><?= e(format_date((string) $r['updated_at'], true)) ?></td>
</tr>
<?php endforeach ?>
</tbody></table></div>
<?php endif ?>
