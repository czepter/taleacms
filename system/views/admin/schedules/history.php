<?php
/**
 * The runs of one schedule (2.17): when each was due, who did it, the status, the summary and the links to the drafts –
 * and the instructions the routine gets.
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\Schedules $module
 * @var string $csrf
 * @var array<string, mixed> $s the schedule
 * @var list<array<string, mixed>> $runs newest first, with links_list
 * @var string $instructions
 */
use Kaleta\Core\AgentSchedules;

$tag = fn (string $status): string => '<span class="badge' . match ($status) { 'ok' => ' badge-published', 'running' => ' badge-draft', 'failed', 'missed' => ' badge-error', default => '' } . '">' . e(t(AgentSchedules::STATUSES[$status] ?? $status)) . '</span>';
?>
<p class="navigation-row"><a class="navigation" href="<?= e($module->url()) ?>">← <?= e(t('All schedules')) ?></a> <a class="navigation" href="<?= e($module->url('edit', ['id' => (int) $s['id']])) ?>"><?= e(t('Edit the schedule')) ?></a></p>
<?php if ($runs === []): ?>
<p><?= e(t('No run yet. The first one is handed out at %s – when the routine in Claude asks.', (int) $s['active'] === 1 && $s['next_due'] !== null ? format_date((string) $s['next_due'], true) : '—')) ?></p>
<?php else: ?>
<div class="tab-wrap"><table class="listing">
<thead><tr><th scope="col"><?= e(t('Due')) ?></th><th scope="col"><?= e(t('Status')) ?></th><th scope="col"><?= e(t('Summary')) ?></th><th scope="col"><?= e(t('Connection')) ?></th></tr></thead>
<tbody>
<?php foreach ($runs as $r): ?>
<tr>
	<td><?= e(format_date((string) $r['due_at'], true)) ?><?= $r['finished_at'] !== null ? '<br><span class="small-text">' . e(t('finished %s', format_date((string) $r['finished_at'], true))) . '</span>' : ($r['started_at'] !== null ? '<br><span class="small-text">' . e(t('handed out %s', format_date((string) $r['started_at'], true))) . '</span>' : '') ?></td>
	<td><?= $tag((string) $r['status']) ?></td>
	<td><?= $r['summary'] !== null && $r['summary'] !== '' ? nl2br(e((string) $r['summary'])) : ($r['status'] === 'missed' ? '<span class="small-text">' . e(t('Nobody picked the run up within 6 hours – is the routine in Claude still running?')) . '</span>' : '') ?>
<?php if ($r['links_list'] !== []): ?><br><span class="small-text"><?= e(t('Drafts:')) ?></span> <?php foreach ($r['links_list'] as $i => $l): ?><?= $i > 0 ? ' · ' : '' ?><?= $l['url'] !== '' ? '<a href="' . e($l['url']) . '" target="_blank" rel="noopener">' . e($l['label'] !== '' ? $l['label'] : $l['url']) . '</a>' : e($l['label']) ?><?php endforeach ?><?php endif ?></td>
	<td><?= e((string) $r['connection']) ?></td>
</tr>
<?php endforeach ?>
</tbody></table></div>
<?php endif ?>
<h2><?= e(t('What the routine gets')) ?></h2>
<p class="small-text"><?= e(t('The instructions handed out with each run of this schedule (in English for Claude):')) ?></p>
<pre class="code"><?= e($instructions) ?></pre>
