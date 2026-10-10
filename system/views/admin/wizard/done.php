<?php
/**
 * What the wizard made, and what the owner still has to confirm (#31).
 *
 * @var Talea\Admin\Modules\Wizard $module
 * @var array{session: int, blueprint: string, look: string, pages: list<array{id: string, title: string, url: string, placeholders: int, unknown_facts: list<string>}>, text: bool, saved: list<string>} $result
 * @var string $undo
 */
?>
<p class="notice"><?= e(t('Done. Everything is a draft: nothing on your public site has changed yet.')) ?></p>
<ul>
<?php if ($result['blueprint'] !== ''): ?>
	<li><?= e(t('Blueprint applied: %s. Answer its questions under Blueprints.', $result['blueprint'])) ?> <a href="<?= e($app->url('admin.php?module=blueprints')) ?>"><?= e(t('Blueprints')) ?></a></li>
<?php endif ?>
<?php if ($result['look'] !== ''): ?>
	<li><?= e(t('Look in the draft: %s. Preview the whole site, then publish it.', $result['look'])) ?> <a href="<?= e($app->url('admin.php?module=appearance&action=looks')) ?>"><?= e(t('Looks')) ?></a></li>
<?php endif ?>
<?php if (!$result['text']): ?>
	<li><?= e(t('No text was written. Add an AI key under Features (Writing assistant) and run the wizard again to get the pages.')) ?></li>
<?php endif ?>
</ul>
<?php if ($result['pages'] !== []): ?>
<h2><?= e(t('Draft pages')) ?></h2>
<div class="tab-wrap"><table class="listing">
<thead><tr><th scope="col"><?= e(t('Page')) ?></th><th scope="col"><?= e(t('To confirm')) ?></th><th scope="col"><?= e(t('Actions')) ?></th></tr></thead>
<tbody>
<?php foreach ($result['pages'] as $page): ?>
<tr><td><strong><?= e($page['title']) ?></strong></td>
	<td><?php if ($page['placeholders'] > 0): ?><?= e(t('%d placeholders in square brackets to fill in', $page['placeholders'])) ?><?php endif ?>
	<?php if ($page['unknown_facts'] !== []): ?> <?= e(t('Facts without a value: %s', implode(', ', $page['unknown_facts']))) ?><?php endif ?>
	<?php if ($page['placeholders'] === 0 && $page['unknown_facts'] === []): ?>–<?php endif ?></td>
	<td class="actions"><a href="<?= e($page['url']) ?>"><?= e(t('Open in the builder')) ?></a></td></tr>
<?php endforeach ?>
</tbody></table></div>
<p class="help"><?= e(t('Check every page: the assistant writes no prices, names or opening hours it was not given. Publish a page in the builder when it is right.')) ?></p>
<?php endif ?>
<p class="help"><?= e(t('Not what you wanted? The whole run can be taken back in one step.')) ?> <a href="<?= e($undo) ?>"><?= e(t('Claude sessions')) ?></a></p>
<p><a class="btn" href="<?= e($app->url('admin.php?module=pages')) ?>"><?= e(t('Pages')) ?></a></p>
