<?php
/**
 * A/B tests with their status and result.
 *
 * @var Talea\Core\App $app
 * @var Talea\Admin\Modules\Experiments $module
 * @var string $csrf
 * @var list<array<string, mixed>> $experiments
 */
use Talea\Builder\Experiments;

$status = ['draft' => ['badge-draft', 'not started'], 'running' => ['badge-published', 'running'], 'stopped' => ['badge', 'stopped'], 'promoted' => ['badge-published', 'finished']];
?>
<p class="navigation-row"><a class="btn" href="<?= e($module->url('new')) ?>"><?= e(t('New experiment')) ?></a></p>
<?php if ($experiments === []): ?>
<?= $app->view->render('admin/empty', ['icon' => 'experiments', 'heading' => t('No experiments yet.'), 'text' => t('Try two versions of a headline, a section or a whole page and see which one brings more enquiries. Visitors get a random version, nothing is stored in their browser, and the counting works without cookies.'), 'action' => [$module->url('new'), t('Create an experiment')]]) ?>
<?php else: ?>
<div class="tab-wrap">
<table class="listing">
<thead><tr><th scope="col"><?= e(t('Experiment')) ?></th><th scope="col"><?= e(t('Goal')) ?></th><th scope="col"><?= e(t('Status')) ?></th><th scope="col"><?= e(t('Result')) ?></th></tr></thead>
<tbody>
<?php foreach ($experiments as $x): ?>
<?php
    $v = $x['verdict'];
    $result = match ($v['state']) {
        'winner' => $v['winner'] === 'b' ? t('B is better: %d%% probability', (int) round($v['analysis']['probability_b'] * 100)) : t('The original holds'),
        'inconclusive' => t('No clear difference yet'),
        default => $x['status'] === 'draft' ? '–' : t('Collecting data'),
    };
?>
<tr>
	<td><a href="<?= e($module->url('show', ['id' => $x['public_id']])) ?>"><strong><?= e($x['name']) ?></strong></a><br><span class="help"><?= e(t(Experiments::KINDS[$x['kind']] ?? '')) ?> · <?= e($x['page_title']) ?></span></td>
	<td><?= e(t(Experiments::GOALS[$x['goal_type']][0] ?? '')) ?></td>
	<td><span class="badge <?= e($status[$x['status']][0] ?? 'badge') ?>"><?= e(t($status[$x['status']][1] ?? $x['status'])) ?></span><?= $x['status'] === 'promoted' ? ' <span class="help">' . e($x['winner'] === 'b' ? t('B applied') : t('A kept')) . '</span>' : '' ?></td>
	<td><?= e($result) ?><br><span class="help"><?= e(t('%d / %d views', $x['counts']['a']['views'], $x['counts']['b']['views'])) ?></span></td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<?php endif ?>
