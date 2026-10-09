<?php
/**
 * Claude sessions (2.17): the changes one Claude connection made in a row, each undoable as a whole.
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\ChangeLog $module
 * @var string $csrf
 * @var list<array<string, mixed>> $sessions
 * @var array<string, mixed>|null $result the last undo: restored, removed, conflicts, untracked
 */
?>
<p class="navigation-row"><a class="navigation" href="<?= e($module->url()) ?>">← <?= e(t('Change log')) ?></a></p>
<p><?= e(t('Changes one Claude connection makes in a row (with less than %d minutes between them) form a session. Undo puts back everything the session changed – pages, builds and drafts, news, collection items, menus, settings, the look – as it was before. Sessions are kept %d days.', Kaleta\Core\AgentJournal::SESSION_GAP, Kaleta\Core\AgentJournal::JOURNAL_DAYS)) ?></p>
<?php if ($result !== null && ($result['conflicts'] !== [] || $result['untracked'] !== [])): ?>
<div class="notice error" role="alert">
<?php if ($result['conflicts'] !== []): ?><p><?= e(t('%d rows were changed after the session (by a person or another session) and were left as they are. Undo again with “Also overwrite later changes” to put them back too.', count($result['conflicts']))) ?></p>
<ul><?php foreach (array_slice($result['conflicts'], 0, 20) as $c): ?><li><code><?= e($c['table']) ?> <?= e((string) json_encode($c['key'], JSON_UNESCAPED_UNICODE)) ?></code></li><?php endforeach ?></ul><?php endif ?>
<?php if ($result['untracked'] !== []): ?><p><?= e(t('These changes could not be followed row by row and stay as they are – check them by hand:')) ?></p>
<ul><?php foreach ($result['untracked'] as $u): ?><li><?= e($u) ?></li><?php endforeach ?></ul><?php endif ?>
</div>
<?php endif ?>
<?php if ($sessions === []): ?>
<p><?= e(t('No Claude session yet.')) ?></p>
<?php else: ?>
<table class="listing">
<thead><tr><th scope="col"><?= e(t('Connection')) ?></th><th scope="col"><?= e(t('When')) ?></th><th scope="col"><?= e(t('Changes')) ?></th><th scope="col"><?= e(t('Tools')) ?></th><th scope="col"></th></tr></thead>
<tbody>
<?php foreach ($sessions as $s): ?>
<tr>
	<td><?= e((string) $s['connection']) ?></td>
	<td><?= e(format_date((string) $s['started_at'], true)) ?> – <?= e(substr((string) $s['last_at'], 11, 5)) ?></td>
	<td><?= (int) $s['rows_changed'] ?><?= (int) $s['rows_untracked'] > 0 ? ' <span class="badge" title="' . e(t('Changes that undo cannot follow')) . '">+' . (int) $s['rows_untracked'] . '</span>' : '' ?></td>
	<td class="small-text"><?= e((string) ($s['tools'] ?? '')) ?></td>
	<td class="actions">
<?php if ($s['undone_at'] !== null): ?>
		<span class="small-text"><?= e(t('Undone %s by %s', format_date((string) $s['undone_at'], true), (string) $s['undone_by'])) ?></span>
<?php elseif ((int) $s['rows_changed'] > 0): ?>
		<form class="inline" method="post" action="<?= e($module->url('undo')) ?>" data-confirm="<?= e(t('Undo everything this session changed?')) ?>"><?= $csrf ?><input type="hidden" name="id" value="<?= (int) $s['id'] ?>">
		<label class="small-text"><input type="checkbox" name="force" value="1"> <?= e(t('Also overwrite later changes')) ?></label>
		<button class="navigation danger" type="submit"><?= e(t('Undo the session')) ?></button></form>
<?php endif ?>
	</td>
</tr>
<?php endforeach ?>
</tbody>
</table>
<?php endif ?>
