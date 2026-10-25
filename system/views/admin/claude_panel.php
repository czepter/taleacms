<?php
/**
 * The state of the Claude connection at the top of the AI settings (#154, Core\ClaudeConnection): whether Claude is
 * connected, when it last called, how requests reach it, when the first connection ends – and what to do next.
 *
 * @var Talea\Core\App $app
 */
use Talea\Core\ClaudeConnection;

$claude = ClaudeConnection::status($app->db(), $app->settings(), true);
$routine = Talea\Core\AskClaude::routine($app->db());
$when = static fn (?string $moment): string => $moment !== null ? format_date($moment, true) : '–';
$link = fn (string $query, string $text): string => ' <a href="' . e($app->url('admin.php?' . $query)) . '">' . e($text) . '</a>';
$steps = [];
if ($claude['state'] === 'off') {
    $steps[] = e(t('Switch the Claude connection on in Features.')) . $link('module=extensions', t('Features'));
} elseif ($claude['state'] === 'none') {
    $steps[] = e(t('Connect Claude as described below – the connector in the Claude app, or a personal token from My account.'));
} else {
    if ($claude['pickup'] === 'stalled') {
        $steps[] = e(t('The scheduled run that picks up requests has stopped – check the routine in the Claude app.')) . $link('module=schedules', t('Scheduled runs'));
    } elseif ($claude['pickup'] === 'manual') {
        $steps[] = e(t('Requests are picked up only when someone asks Claude. A scheduled run picks them up by itself.')) . $link('module=schedules', t('Schedule a run'));
    }
    if ($claude['state'] === 'idle') {
        $steps[] = e(t('Claude has not called the site for %d days. The connection stays valid; ask Claude something about the site to check it.', ClaudeConnection::ACTIVE_DAYS));
    }
    if ($claude['expiring']) {
        $steps[] = e(t('A connection ends %s – connect Claude again before then (a personal token: create a new one in My account).', $when($claude['expires'])));
    }
}
?>
<section class="notice claude-status" aria-labelledby="claude-status-heading">
	<h2 id="claude-status-heading"><span class="status status-<?= match (true) { $claude['state'] === 'off' || $claude['state'] === 'none' => 'off', $claude['pickup'] === 'stalled' => 'hidden', $claude['state'] === 'active' => 'live', default => 'draft' } ?>"><?= e(match ($claude['state']) {
        'active' => t('Claude is connected'),
        'idle' => t('Claude is connected, but idle'),
        'off' => t('The Claude connection is switched off.'),
        default => t('Claude is not connected'),
    }) ?></span></h2>
	<dl class="enquiry">
		<dt><?= e(t('Last call')) ?></dt>
		<dd><?= e($claude['last_call'] !== null ? $when($claude['last_call']) : t('never')) ?></dd>
		<dt><?= e(t('Requests')) ?></dt>
		<dd><?= e(match ($claude['pickup']) {
            'automatic' => $routine !== null && $routine['next_due'] !== null ? t('picked up by a scheduled run – next %s', $when($routine['next_due'])) : t('picked up by a scheduled run'),
            'stalled' => t('the scheduled run that picks them up has stopped (last missed or failed %s)', $when($claude['stalled_since'])),
            'manual' => t('picked up only when someone asks Claude'),
            default => t('nothing picks them up – they wait'),
        }) ?><?= $claude['waiting'] > 0 ? ' · ' . e(t('%d new waiting', $claude['waiting'])) : '' ?></dd>
		<dt><?= e(t('Connection ends')) ?></dt>
		<dd><?= e($claude['expires'] !== null ? $when($claude['expires']) : (ClaudeConnection::reachable($claude) ? t('never') : '–')) ?></dd>
	</dl>
<?php if ($steps !== []): ?>
	<ul>
<?php foreach ($steps as $step): ?>
		<li><?= $step ?></li>
<?php endforeach ?>
	</ul>
<?php endif ?>
</section>
