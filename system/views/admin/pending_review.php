<?php
/**
 * "Waiting for you" on the dashboard (3.2, Core\PendingReview): one row per kind of draft or proposal that waits for a
 * person – the count, the link to where it is reviewed, and a few examples. Rendered only when something waits.
 *
 * @var Kaleta\Core\App $app
 * @var list<array{kind: string, label: string, count: int, url: string, examples: list<string>}> $pending
 */
?>
<section class="awaiting-you" aria-labelledby="awaiting-you-heading">
	<h2 id="awaiting-you-heading"><?= e(t('Waiting for you')) ?></h2>
	<ul>
<?php foreach ($pending as $p): ?>
		<li data-kind="<?= e($p['kind']) ?>"><a href="<?= e($app->url($p['url'])) ?>"><strong><?= format_count($p['count']) ?></strong> <?= e(t($p['label'])) ?></a>
<?php if ($p['examples'] !== []): ?>
			<span class="small-text"><?= e(implode(' · ', $p['examples'])) ?><?= $p['count'] > count($p['examples']) && $p['kind'] !== 'draft_comments' ? ' …' : '' ?></span>
<?php endif ?>
		</li>
<?php endforeach ?>
	</ul>
</section>
