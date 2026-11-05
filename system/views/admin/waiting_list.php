<?php
/**
 * The list under the bell (layout.php), fetched by admin.js when the bell opens (GET /admin/waiting, Kernel::waitingList):
 * what waits for the signed-in person, each kind with its number and up to two examples.
 *
 * @var Talea\Core\App $app
 * @var list<array{label: string, url: string, count: int, examples: list<string>}> $items
 */
?>
<?php if ($items === []): ?>
<p class="small-text"><?= e(t('Nothing waits for you right now.')) ?></p>
<?php else: ?>
<ul>
<?php foreach ($items as $item): $examples = array_slice($item['examples'], 0, 2); ?>
	<li><a href="<?= e($item['url']) ?>"><span><?= e(t($item['label'])) ?><?php if ($examples !== []): ?><small><?= e(implode(' · ', $examples)) ?><?= count($item['examples']) > count($examples) ? ' …' : '' ?></small><?php endif ?></span><span class="menu-count"><?= $item['count'] > 99 ? '99+' : $item['count'] ?></span></a></li>
<?php endforeach ?>
</ul>
<?php endif ?>
