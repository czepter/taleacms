<?php
/**
 * Broken links across the site: news items, page builds and collection items (Core\Links).
 *
 * @var Talea\Core\App $app
 * @var Talea\Admin\Modules\News $module
 * @var string $csrf
 * @var list<array<string, mixed>> $links
 * @var int $checked
 * @var int $total
 * @var bool $isEnabled
 */
echo $app->view->render('admin/list/head', ['count' => (string) count($links), 'links' => [[$module->url(), t('Back to the news list')]]]);
?>
<?php if (!$isEnabled): ?>
<p class="notice"><?= e(t('Link checking is off (Settings → General → More options).')) ?></p>
<?php endif ?>
<p class="small-text"><?= e(t('In the background, the system goes through published news, pages and collection items – one every five minutes, each once a month – and checks whether their links still work. Checked: %s of %s.', $checked, $total)) ?></p>
<?php if ($links === []): ?>
<p><?= e(t('No broken links found.')) ?></p>
<?php else: ?>
<div class="tab-wrap"><table class="listing">
<thead><tr><th scope="col"><?= e(t('Link')) ?></th><th scope="col"><?= e(t('Problem')) ?></th><th scope="col"><?= e(t('Found')) ?></th><th scope="col"><span class="visually-hidden"><?= e(t('Actions')) ?></span></th></tr></thead>
<tbody>
<?php $kinds = ['news' => 'News item', 'page' => 'Page', 'item' => 'Item']; foreach ($links as $o): ?>
<tr>
	<td class="cell-main"><div class="cell-text"><a class="cell-title" href="<?= e($o['url']) ?>" target="_blank" rel="noopener noreferrer" title="<?= e($o['url']) ?>"><?= e($o['url']) ?></a><span class="cell-sub"><?= e(t($kinds[$o['kind']] ?? 'Page')) ?>: <?= e($o['title']) ?><?= $o['element'] !== '' ? ' · ' . e(t('element %s', $o['element'])) : '' ?></span></div></td>
	<td><?= $app->view->render('admin/list/status', ['kind' => 'error', 'label' => $o['status'] === 0 ? t('server does not respond') : ($o['status'] === 404 ? t('page does not exist (404)') : t('error %s', $o['status']))]) ?></td>
	<td class="cell-weak"><?= e(format_ago($o['found'])) ?></td>
	<td class="cell-actions"><?= $app->view->render('admin/list/actions', ['id' => 'link-' . md5($o['kind'] . $o['id'] . $o['url']), 'name' => $o['title'], 'edit' => $o['edit'], 'csrf' => $csrf,
        'quick' => ['action' => $module->url('links'), 'fields' => ['kind' => $o['kind'], 'id' => (string) $o['id']], 'label' => t('Check again'), 'icon' => 'restore'], 'menu' => []]) ?></td>
</tr>
<?php endforeach ?>
</tbody></table></div>
<?php endif ?>
