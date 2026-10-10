<?php
/**
 * The claims inventory (2.10): sentences that state a year, a number, a percentage or an amount as plain text. Each one is
 * a candidate for a fact – once it is a fact, the next change finds every place that states it.
 *
 * @var Talea\Admin\Modules\Facts $module
 * @var list<array<string, mixed>> $claims
 */
?>
<p><a href="<?= e($module->url('')) ?>">← <?= e(t('All facts')) ?></a></p>
<p class="notice"><?= e(t('Sentences with numbers, years and amounts written as plain text. When one of them changes – a new year, another project – you have to find every place yourself. Make it a fact and write {{fact.key}} instead.')) ?></p>
<?php if ($claims === []): ?>
<p><?= e(t('No such sentences – the numbers on the site are facts already.')) ?></p>
<?php else: ?>
<div class="tab-wrap"><table class="listing">
<thead><tr><th scope="col"><?= e(t('Where')) ?></th><th scope="col"><?= e(t('Sentence')) ?></th></tr></thead>
<tbody>
<?php foreach ($claims as $c): ?>
<tr><td><a href="<?= e($app->url($c['edit'])) ?>"><?= e($c['where']) ?></a></td><td><?= e($c['sentence']) ?></td></tr>
<?php endforeach ?>
</tbody></table></div>
<?php endif ?>
