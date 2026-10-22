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
 * @var array<string, mixed>|null $ask the Ask Claude box (Kernel::askClaude), null without the Claude connection
 */
use Talea\Core\Requests;

$kinds = ['done' => 'live', 'new' => 'hidden', 'declined' => 'off'];
echo $app->view->render('admin/list/head', ['count' => t('%d open', $open), 'primary' => [$module->url('new'), t('New request')]]);
?>
<?php if ($ask !== null): ?>
<?= $app->view->render('admin/ask_claude', ['app' => $app, 'ask' => $ask]) ?>
<?php else: ?>
<p class="notice"><?= e(t('Write what should change on the site – "change the opening hours on Monday", "add this PDF to the price list" – and Claude does it as drafts the next time it works on the site. Its notes and the links to the drafts appear in the request; a person reviews and publishes them.')) ?></p>
<?php endif ?>
<?php if (!$claudeOn): ?>
<p class="notice error"><?= e(t('The Claude connection is switched off (Features) – requests are saved, but nobody reads them until it is on.')) ?></p>
<?php endif ?>
<?= $app->view->render('admin/list/toolbar', ['tabsLabel' => t('Request status'), 'tabs' => array_map(fn (string $key, string $name): array => ['label' => $name, 'url' => $module->url('', array_filter(['status' => $key])), 'active' => $status === $key],
    array_keys(['' => t('All')] + array_map('t', Requests::STATUSES)), ['' => t('All')] + array_map('t', Requests::STATUSES))]) ?>
<?php if ($requests === [] && $status !== ''): ?>
<?= $app->view->render('admin/empty', ['icon' => 'comments', 'heading' => t('No request has this status.'), 'text' => '', 'action' => [$module->url(), t('Clear filter')]]) ?>
<?php elseif ($requests === []): ?>
<?= $app->view->render('admin/empty', ['icon' => 'comments', 'heading' => t('No requests yet.'), 'text' => t('Write the first one: what should change, on which page, with the files Claude needs.'), 'action' => [$module->url('new'), t('New request')]]) ?>
<?php else: ?>
<div class="tab-wrap"><table class="listing">
<thead><tr><th scope="col"><?= e(t('Request')) ?></th><th scope="col"><?= e(t('Author')) ?></th><th scope="col"><?= e(t('Status')) ?></th><th scope="col"><?= e(t('Written')) ?></th><th scope="col"><?= e(t('Last change')) ?></th><th scope="col"><span class="visually-hidden"><?= e(t('Actions')) ?></span></th></tr></thead>
<tbody>
<?php foreach ($requests as $r): $detail = $module->url('detail', ['id' => $r['public_id']]); ?>
<tr>
	<td class="cell-main"><div class="cell-text"><span class="cell-row"><a class="cell-title" href="<?= e($detail) ?>"><?= e((string) $r['title']) ?></a><?= $r['attachments'] !== [] ? ' <span class="tag">' . e(t('%d attachments', count($r['attachments']))) . '</span>' : '' ?></span><span class="cell-sub"><?= e(mb_strimwidth((string) $r['text'], 0, 120, '…')) ?></span></div></td>
	<td><?= e((string) $r['author']) ?></td>
	<td><?= $app->view->render('admin/list/status', ['kind' => $kinds[(string) $r['status']] ?? 'planned', 'label' => t(Requests::STATUSES[$r['status']] ?? (string) $r['status'])]) ?></td>
	<td class="cell-weak"><?= e(format_ago((string) $r['created_at'])) ?></td>
	<td class="cell-weak"><?= e(format_ago((string) $r['updated_at'])) ?></td>
	<td class="cell-actions"><?= $app->view->render('admin/list/actions', ['id' => $r['public_id'], 'name' => (string) $r['title'], 'edit' => $detail, 'menu' => [], 'csrf' => $csrf]) ?></td>
</tr>
<?php endforeach ?>
</tbody></table></div>
<?php endif ?>
