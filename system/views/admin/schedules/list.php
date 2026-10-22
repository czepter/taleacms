<?php
/**
 * Scheduled runs (2.17): the schedules with the next due time and the last run, and the "Set up in Claude" panel – the
 * routine prompt to copy and the advice to connect with a drafts-only token.
 *
 * @var Talea\Core\App $app
 * @var Talea\Admin\Modules\Schedules $module
 * @var string $csrf
 * @var list<array<string, mixed>> $schedules with last_status, last_finished, last_summary
 * @var string $prompt the routine prompt with the site's MCP address
 * @var bool $claudeOn the Claude connection extension is on
 * @var int $draftTokens how many drafts-only tokens or connections exist
 */
use Talea\Core\AgentSchedules;

$last = fn (?string $status): array => $status === null ? ['kind' => 'draft', 'label' => t('no run yet')]
    : ['kind' => match ($status) { 'ok' => 'live', 'running' => 'planned', 'failed', 'missed' => 'error', default => 'draft' }, 'label' => t(AgentSchedules::STATUSES[$status] ?? $status)];
$when = fn (array $s): string => match ((string) $s['cadence']) {
    'weekly' => t('every %s at %s', t(AgentSchedules::WEEKDAYS[(int) $s['day']] ?? ''), (string) $s['time']),
    'monthly' => t('every month on day %d at %s', (int) $s['day'], (string) $s['time']),
    default => t('every day at %s', (string) $s['time']),
};
?>
<p class="notice"><?= e(t('The site cannot run Claude by itself. It keeps the schedules below, tells a routine in Claude what is due, records each run and notices runs nobody picked up. The run itself happens in Claude – a scheduled task in the Claude app or a Claude Code routine – connected to this site with drafts-only access, so a run never publishes, deletes or sends anything.')) ?></p>
<?php if (!$claudeOn): ?>
<p class="notice error"><?= e(t('The Claude connection is switched off (Features) – the schedules are kept, but no routine can pick the runs up until it is on.')) ?></p>
<?php endif ?>
<?= $app->view->render('admin/list/head', ['count' => (string) count($schedules), 'primary' => [$module->url('edit'), t('New schedule')]]) ?>
<?php if ($schedules === []): ?>
<?= $app->view->render('admin/empty', ['icon' => 'log', 'heading' => t('No schedules yet.'), 'text' => t('Typical ones: a site review every Monday, a report on the first of the month, a triage of new enquiries every morning, the open requests from staff every day.'), 'action' => [$module->url('edit'), t('New schedule')]]) ?>
<?php else: ?>
<div class="tab-wrap"><table class="listing">
<thead><tr><th scope="col"><?= e(t('On')) ?></th><th scope="col"><?= e(t('Schedule')) ?></th><th scope="col"><?= e(t('When')) ?></th><th scope="col"><?= e(t('Next due')) ?></th><th scope="col"><?= e(t('Last run')) ?></th><th scope="col"><span class="visually-hidden"><?= e(t('Actions')) ?></span></th></tr></thead>
<tbody>
<?php foreach ($schedules as $s): $active = (int) $s['active'] === 1; $edit = $module->url('edit', ['id' => (int) $s['id']]); ?>
<tr<?= $active ? '' : ' class="inactive"' ?>>
	<td><?= $app->view->render('admin/list/switch', ['action' => $module->url('toggle'), 'fields' => ['id' => (string) $s['id']], 'on' => $active, 'label' => t('%s on', (string) $s['name']), 'csrf' => $csrf]) ?></td>
	<td class="cell-main"><div class="cell-text"><a class="cell-title" href="<?= e($edit) ?>"><?= e((string) $s['name']) ?></a><span class="cell-sub"><?= e(t(AgentSchedules::TASKS[(string) $s['task']][0] ?? (string) $s['task'])) ?></span></div></td>
	<td class="cell-weak"><?= e($when($s)) ?></td>
	<td class="cell-weak"><?= $active && $s['next_due'] !== null ? e(format_date((string) $s['next_due'], true)) : '—' ?></td>
	<td><?= $app->view->render('admin/list/status', $last($s['last_status'] !== null ? (string) $s['last_status'] : null) + ['notes' => array_values(array_filter([
        $s['last_finished'] !== null ? ['text' => format_ago((string) $s['last_finished'])] : null,
    ]))]) ?><?= $s['last_summary'] !== null && $s['last_summary'] !== '' ? '<span class="cell-sub" title="' . e((string) $s['last_summary']) . '">' . e(mb_strimwidth((string) $s['last_summary'], 0, 80, '…')) . '</span>' : '' ?></td>
	<td class="cell-actions"><?= $app->view->render('admin/list/actions', ['id' => 'schedule-' . $s['id'], 'name' => (string) $s['name'], 'edit' => $edit, 'csrf' => $csrf, 'menu' => [
        ['url' => $module->url('history', ['id' => (int) $s['id']]), 'label' => t('History'), 'icon' => 'restore'],
    ]]) ?></td>
</tr>
<?php endforeach ?>
</tbody></table></div>
<?php endif ?>
<h2 id="claude"><?= e(t('Set up in Claude')) ?></h2>
<div class="form">
<ol>
	<li><?= e(t('Connect Claude to this site with a drafts-only token or connection: My account → Claude connection, access “drafts only”. A drafts-only connection can read the site and save drafts, and the two tools the routine needs (get_due_agent_runs, report_agent_run) – it cannot publish, delete or send.')) ?> <a href="<?= e($app->url('admin.php?action=account#claude')) ?>"><?= e(t('My account')) ?></a><?php if ($draftTokens === 0): ?> <span class="badge"><?= e(t('no drafts-only token yet')) ?></span><?php endif ?></li>
	<li><?= e(t('In Claude, create a scheduled task (the Claude app) or a routine (Claude Code) that runs a little after the earliest time in your schedules – for example every day at 07:15 – with this prompt:')) ?>
		<pre class="code" id="routine-prompt"><?= e($prompt) ?></pre>
		<p><button class="navigation" type="button" data-copy="#routine-prompt"><?= e(t('Copy')) ?></button></p></li>
	<li><?= e(t('The routine asks the site what is due, does each run as drafts and reports it; the summary and the links to the drafts appear in the history here. You review and publish. A run nobody picks up within 6 hours is marked missed and reported in the events and the alert e-mails.')) ?></li>
</ol>
<p class="small-text"><?= e(t('Claude never runs on the site: the site holds no Claude sign-in and creates no tokens here – the routine lives in your Claude account and connects with the token you give it.')) ?></p>
</div>
