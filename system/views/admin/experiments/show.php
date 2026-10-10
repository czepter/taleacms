<?php
/**
 * One experiment: set-up, result, guardrails and the actions.
 *
 * @var Talea\Core\App $app
 * @var Talea\Admin\Modules\Experiments $module
 * @var string $csrf
 * @var array<string, mixed> $x
 * @var ?array<string, mixed> $component
 * @var ?array<string, mixed> $variantPage
 * @var string $goalPage
 * @var bool $canPublish
 */
use Talea\Builder\Experiments;

$v = $x['verdict'];
$a = $v['analysis'];
$percent = fn (float $n): string => format_count($n * 100, 1) . ' %';
$signed = fn (float $n): string => ($n >= 0 ? '+' : '–') . format_count(abs($n) * 100, 0) . ' %';
$form = fn (string $action, string $label, array $extra = [], string $class = 'btn', string $confirm = ''): string => '<form class="inline" method="post" action="' . e($module->url($action)) . '"' . ($confirm !== '' ? ' data-confirm="' . e($confirm) . '"' : '') . '>' . $csrf
    . '<input type="hidden" name="experiment_id" value="' . e($x['public_id']) . '">' . implode('', array_map(fn (string $k, string $val): string => '<input type="hidden" name="' . e($k) . '" value="' . e($val) . '">', array_keys($extra), $extra))
    . '<button class="' . $class . '" type="submit">' . e($label) . '</button></form>';
$message = match ($v['state']) {
    'winner' => $v['winner'] === 'b'
        ? t('B beats A with a probability of %s; the expected uplift is %s (likely between %s and %s). The result can be trusted.', $percent($a['probability_b']), $signed($a['uplift']), $signed($a['uplift_low']), $signed($a['uplift_high']))
        : t('The original (A) is better with a probability of %s. Keep it.', $percent(1 - $a['probability_b'])),
    'inconclusive' => t('Enough data, but no clear difference: B beats A with a probability of %s. Let it run longer or end the test and keep the original.', $percent($a['probability_b'])),
    default => $x['status'] === 'draft' ? t('Not started yet.') : t('Needs more data: the result is not trustworthy yet.'),
};
?>
<p class="help"><?= e(t(Experiments::KINDS[$x['kind']] ?? '')) ?> · <a href="<?= e($app->url('admin.php?module=pages&action=edit&id=' . $x['page_public_id'])) ?>"><?= e($x['page_title']) ?></a>
 · <?= e(t(Experiments::GOALS[$x['goal_type']][0] ?? '')) ?><?= $x['goal_type'] === 'click' ? ' (' . e($x['goal_target']) . ')' : '' ?><?= $goalPage !== '' ? ' (' . e($goalPage) . ')' : '' ?></p>

<p>
<?php if ($component !== null): ?><a class="btn" href="<?= e($app->url('admin.php?module=components&action=builder&id=' . $component['public_id'])) ?>"><?= e(t('Edit version B in the builder')) ?></a><?php endif ?>
<?php if ($variantPage !== null): ?><a class="btn" href="<?= e($app->url('admin.php?module=pages&action=builder&id=' . $variantPage['public_id'])) ?>"><?= e(t('Edit version B: %s', $variantPage['title'])) ?></a><?php endif ?>
<?php if (in_array($x['status'], ['draft', 'stopped'], true)): ?><?= $form('start', $x['status'] === 'stopped' ? t('Restart (clears the counts)') : t('Start the experiment')) ?><?php endif ?>
<?php if ($x['status'] === 'running'): ?><?= $form('stop', t('Stop'), [], 'navigation') ?><?php endif ?>
</p>

<h2><?= e(t('Result')) ?></h2>
<div class="tab-wrap">
<table class="listing">
<thead><tr><th scope="col"><?= e(t('Version')) ?></th><th scope="col" class="number"><?= e(t('Views')) ?></th><th scope="col" class="number"><?= e(t('Conversions')) ?></th><th scope="col" class="number"><?= e(t('Conversion rate')) ?></th><th scope="col" class="number"><?= e(t('Probability of being better')) ?></th></tr></thead>
<tbody>
<tr><td><strong>A</strong> – <?= e(t('original')) ?></td><td class="number"><?= (int) $x['counts']['a']['views'] ?></td><td class="number"><?= (int) $x['counts']['a']['goals'] ?></td><td class="number"><?= e($percent($a['rate_a'])) ?></td><td class="number"><?= e($percent(1 - $a['probability_b'])) ?></td></tr>
<tr><td><strong>B</strong></td><td class="number"><?= (int) $x['counts']['b']['views'] ?></td><td class="number"><?= (int) $x['counts']['b']['goals'] ?></td><td class="number"><?= e($percent($a['rate_b'])) ?></td><td class="number"><?= e($percent($a['probability_b'])) ?></td></tr>
</tbody>
</table>
</div>
<p><strong><?= e($message) ?></strong></p>
<p class="help"><?= e(t('Running for %d days.', $v['days'])) ?> <?= e(t('The counting is cookie-free: nothing identifies a visitor, bots and signed-in users are left out.')) ?></p>

<h2><?= e(t('Guardrails')) ?></h2>
<ul>
<?php foreach ($v['checks'] as $c): ?>
<li><?= $c['met'] ? '✓' : '○' ?> <?= e($c['text']) ?></li>
<?php endforeach ?>
</ul>
<p class="help"><?= e(t('These numbers are fixed. A person can promote a version at any time; automatic promotion waits until all of them are met.')) ?></p>

<?php if (in_array($x['status'], ['running', 'stopped'], true)): ?>
<h2><?= e(t('Apply the result')) ?></h2>
<form class="form" method="post" action="<?= e($module->url('promote')) ?>">
<?= $csrf ?>
<input type="hidden" name="experiment_id" value="<?= e($x['public_id']) ?>">
<div class="row"><label for="reason"><?= e(t('Reason (for the change log)')) ?></label><div><input class="textfield wide" id="reason" name="reason" maxlength="200"></div></div>
<p class="buttons">
<?php if ($v['winner'] === 'a' || $v['state'] !== 'winner'): ?>
<button class="btn" type="submit" name="winner" value="a"><?= e(t('Keep the original (A) and end the test')) ?></button>
<?php endif ?>
<button class="btn" type="submit" name="winner" value="b"><?= e($v['state'] === 'winner' && $v['winner'] === 'b' ? t('Promote winner (B)') : t('Use B anyway')) ?></button>
</p>
<p class="help"><?= e($canPublish ? t('B replaces the original and is published. The previous version stays in the page’s versions and you can undo the promotion here.') : t('B replaces the original in the page’s draft; someone who may publish has to publish it.')) ?></p>
</form>
<?php endif ?>

<?php if ($x['status'] === 'promoted'): ?>
<p><?= e($x['winner'] === 'b' ? ($x['promoted_as'] === 'published' ? t('Version B replaced the original and was published.') : t('Version B replaced the original in the page draft.')) : t('The original was kept.')) ?></p>
<?php if ($x['winner'] === 'b'): ?><p><?= $form('undo', t('Undo the promotion'), [], 'navigation', t('Put the page back as it was before the promotion?')) ?></p><?php endif ?>
<?php endif ?>

<?php if (in_array($x['status'], ['draft', 'running', 'stopped'], true)): ?>
<form class="form" method="post" action="<?= e($module->url('auto')) ?>">
<?= $csrf ?>
<input type="hidden" name="experiment_id" value="<?= e($x['public_id']) ?>">
<div class="row"><label><input type="checkbox" name="auto_promote" value="1"<?= $x['auto_promote'] ? ' checked' : '' ?>> <?= e(t('Promote the winner automatically when all guardrails are met')) ?></label></div>
<p class="buttons"><input class="btn" type="submit" value="<?= e(t('Save')) ?>"></p>
</form>
<?php endif ?>

<p><?= $form('delete', t('Delete the experiment'), [], 'navigation danger', t('Delete the experiment with its counts?')) ?> <a class="navigation" href="<?= e($module->url()) ?>"><?= e(t('Back')) ?></a></p>
