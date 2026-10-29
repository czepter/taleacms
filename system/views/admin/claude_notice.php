<?php
/**
 * Whether a request written now reaches Claude (#154, Core\ClaudeConnection) – in the Ask AI box and above a new request:
 *  - not connected: the request waits until someone connects Claude (an administrator gets the link);
 *  - stalled: the scheduled run that picks requests up has stopped;
 *  - manual: requests are picked up only when someone asks Claude (an administrator gets the link to schedule a run);
 *  - automatic: when the scheduled run works through them next.
 *
 * @var Talea\Core\App $app
 * @var array{state: string, pickup: ?string, stalled_since: ?string} $claude
 * @var array{cadence: string, day: int, time: string, next_due: ?string}|null $routine the scheduled run (AskClaude::routine)
 * @var bool $admin the person may connect Claude and schedule runs
 */
use Talea\Core\AgentSchedules;

$link = fn (string $url, string $text): string => $admin ? ' <a href="' . e($app->url($url)) . '">' . e($text) . '</a>' : '';
?>
<?php if (!Talea\Core\ClaudeConnection::reachable($claude)): ?>
	<p class="notice ask-claude-disconnected"><?= e($admin ? t('The AI is not connected to this site yet, so requests wait until it is.') : t('The AI is not connected to this site yet – requests wait until an administrator connects it.')) ?><?= $link('admin.php?action=account&tab=claude', t('Connect AI')) ?></p>
<?php elseif ($claude['pickup'] === 'stalled'): ?>
	<p class="notice ask-claude-when"><?= e(t('The scheduled run that picks up requests has stopped (last missed or failed %s) – requests wait until it runs again or someone asks Claude.', $claude['stalled_since'] !== null ? format_date($claude['stalled_since'], true) : '–')) ?><?= $link('admin.php?module=schedules', t('Scheduled runs')) ?></p>
<?php elseif ($claude['pickup'] === 'manual' || $routine === null): ?>
	<p class="small-text ask-claude-when"><?= e(t('Requests are picked up only when someone asks Claude – no scheduled run works through them.')) ?><?= $link('admin.php?module=schedules', t('Schedule a run')) ?></p>
<?php else: ?>
	<p class="small-text ask-claude-when"><?= e(t('A scheduled run works through the requests %s.', match ($routine['cadence']) {
        'weekly' => t('every %s at %s', t(AgentSchedules::WEEKDAYS[$routine['day']] ?? 'Monday'), $routine['time']),
        'monthly' => t('on day %d of every month at %s', $routine['day'], $routine['time']),
        default => t('every day at %s', $routine['time']),
    })) ?><?php if ($routine['next_due'] !== null): ?> <?= e(t('Next: %s.', format_date($routine['next_due'], true))) ?><?php endif ?></p>
<?php endif ?>
