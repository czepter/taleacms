<?php
/**
 * The list under the bell (layout.php), fetched by admin.js when the bell opens (GET /admin/waiting, Kernel::waitingList):
 * what waits for the signed-in person, each kind with its number and up to two examples.
 *
 * @var Talea\Core\App $app
 * @var list<array{label: string, url: string, count: int, examples: list<string>, site?: string, site_name?: string, to?: string}> $items
 */
?>
<?php if ($items === []): ?>
<p class="small-text"><?= e(t('Nothing waits for you right now.')) ?></p>
<?php else: ?>
<ul>
<?php foreach ($items as $item): $examples = array_slice($item['examples'], 0, 2); $count = '<span class="menu-count">' . ($item['count'] > 99 ? '99+' : $item['count']) . '</span>'; ?>
<?php if (isset($item['site'])): // another site: opened there, on the list that waits (the handoff signs the administrator in) ?>
<?php if (!($otherHeading ?? false)): $otherHeading = true; ?>
	<li class="waiting-group"><?= e(t('Other sites')) ?></li>
<?php endif ?>
	<li class="waiting-other"><form method="post" action="<?= e($app->url('admin.php?module=sites&action=handoff')) ?>"><?= $app->session->csrfField() ?><input type="hidden" name="id" value="<?= e($item['site']) ?>"><input type="hidden" name="to" value="<?= e($item['to']) ?>"><button type="submit"><span><?= e(t($item['label'])) ?><small><?= bdi($item['site_name']) ?></small></span><?= $count ?></button></form></li>
<?php else: ?>
	<li><a href="<?= e($item['url']) ?>"><span><?= e(t($item['label'])) ?><?php if ($examples !== []): ?><small><?= e(implode(' · ', $examples)) ?><?= count($item['examples']) > count($examples) ? ' …' : '' ?></small><?php endif ?></span><?= $count ?></a></li>
<?php endif ?>
<?php endforeach ?>
</ul>
<?php endif ?>
