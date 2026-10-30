<?php
/**
 * @var Talea\Admin\Modules\Owners $module
 * @var string $csrf
 * @var string $site  public id of the site to move
 * @var string $to  public id of the owner that gets it
 * @var list<array{owner: string, owner_name: string, owner_public_id: string, site: array<string, mixed>}> $sites  every owner's sites that are set up
 * @var list<array<string, mixed>> $owners  the owners that can get a site
 * @var array<string, mixed>|null $report  the dry run (Core\SiteTransfer::report) for the chosen site and owner
 * @var list<array{owner: string, owner_name: string, owner_public_id: string, site: array<string, mixed>}> $states  sites with a move under way or a failed one
 */
$size = fn (int $bytes): string => $bytes >= 1048576 ? round($bytes / 1048576, 1) . ' MB' : max(1, (int) round($bytes / 1024)) . ' KB';
$none = e(t('none'));
?>
<p class="navigation-row"><a class="navigation" href="<?= e($module->url()) ?>"><?= e(t('Back to owners')) ?></a></p>
<?php if ($states !== []): ?>
<h2><?= e(t('Moves under way and failed ones')) ?></h2>
<div class="tab-wrap"><table class="listing">
<tbody>
<?php foreach ($states as $f): $s = $f['site']; ?>
<tr>
	<td class="cell-main"><span class="cell-title"><?= bdi(($s['name'] !== '' ? $s['name'] : $s['slug']) . ' (' . $f['owner_name'] . ')') ?></span></td>
	<td class="cell-weak"><?= $s['transfer_to'] !== null ? e(t('Being moved. The old site answers 503 until it is done.')) : bdi(t('The last move failed: %s', (string) $s['transfer_error'])) ?></td>
</tr>
<?php endforeach ?>
</tbody>
</table></div>
<?php endif ?>
<form class="form" method="get" action="<?= e($module->url('transfer')) ?>">
<input type="hidden" name="module" value="owners">
<input type="hidden" name="action" value="transfer">
<div class="row">
	<label for="site"><?= e(t('Site to move')) ?></label>
	<select id="site" name="site" required>
		<option value=""></option>
<?php foreach ($sites as $f): $s = $f['site']; ?>
		<option value="<?= e($s['public_id']) ?>"<?= $site === $s['public_id'] ? ' selected' : '' ?>><?= bdi(($s['name'] !== '' ? $s['name'] : $s['slug']) . ' (' . $f['owner_name'] . ')') ?></option>
<?php endforeach ?>
	</select>
</div>
<div class="row">
	<label for="to"><?= e(t('Owner that gets the site')) ?></label>
	<select id="to" name="to" required>
		<option value=""></option>
<?php foreach ($owners as $o): ?>
		<option value="<?= e($o['public_id']) ?>"<?= $to === $o['public_id'] ? ' selected' : '' ?>><?= bdi($o['name'] !== '' ? $o['name'] : $o['prefix']) ?></option>
<?php endforeach ?>
	</select>
</div>
<p class="small-text"><?= e(t('The site moves with its content, visitors’ data, settings, secrets, host names and media files. You see what moves first; nothing happens until you confirm.')) ?></p>
<p class="buttons"><input class="btn" type="submit" value="<?= e(t('Show what moves')) ?>"></p>
</form>
<?php if ($report !== null): $c = $report['counts']; ?>
<h2><?= bdi($report['site']['name']) ?>: <?= bdi($report['site']['from']) ?> → <?= bdi($report['site']['to']) ?></h2>
<?php foreach ($report['blockers'] as $text): ?>
<p class="notice notice-error" role="alert"><?= e(t($text)) ?></p>
<?php endforeach ?>
<?php foreach ($report['warnings'] as $text): ?>
<p class="notice notice-pending"><?= e(t($text)) ?></p>
<?php endforeach ?>
<div class="tab-wrap"><table class="listing">
<tbody>
<tr><th scope="row"><?= e(t('Content')) ?></th><td><?= e(t('%d pages, %d news items, %d collection items', $c['pages'], $c['news'], $c['collection_items'])) ?></td></tr>
<tr><th scope="row"><?= e(t('Visitors’ data')) ?></th><td><?= e(t('%d enquiries, %d members, %d bookings, %d subscribers', $c['enquiries'], $c['members'], $c['bookings'], $c['subscribers'])) ?></td></tr>
<tr><th scope="row"><?= e(t('Media files')) ?></th><td><?= e(t('%d files, %s (copied; the old files stay)', $report['media']['files'], $size($report['media']['bytes']))) ?></td></tr>
<tr><th scope="row"><?= e(t('Host names')) ?></th><td><?= $report['domains'] === [] ? $none : bdi(implode(', ', array_map(fn (array $d): string => $d['host'] . ($d['verified'] ? '' : ' (' . t('not verified') . ')'), $report['domains']))) ?></td></tr>
<tr><th scope="row"><?= e(t('Secrets that move along')) ?></th><td><?= $report['secrets'] === [] ? $none : bdi(implode(', ', $report['secrets'])) ?><?= $report['connections'] > 0 ? ' · ' . e(t('%d connected services', $report['connections'])) : '' ?></td></tr>
<tr><th scope="row"><?= e(t('Shared components and facts that become its own')) ?></th><td><?= e(t('%d components, %d facts', $report['shared']['components'], $report['shared']['facts'])) ?></td></tr>
<tr><th scope="row"><?= e(t('People found in the new owner by e-mail')) ?></th><td><?= $report['people']['mapped'] === [] ? $none : bdi(implode(', ', array_map(fn (array $p): string => $p['name'] . ' <' . $p['email'] . '>', $report['people']['mapped']))) ?></td></tr>
<tr><th scope="row"><?= e(t('People not found (cleared from authors and revisions)')) ?></th><td><?= $report['people']['unmapped'] === [] ? $none : bdi(implode(', ', array_map(fn (array $p): string => $p['name'] . ($p['email'] !== '' ? ' <' . $p['email'] . '>' : ''), $report['people']['unmapped']))) ?></td></tr>
<tr><th scope="row"><?= e(t('People who lose access')) ?></th><td><?= $report['lose_access'] === [] ? $none : bdi(implode(', ', $report['lose_access'])) ?></td></tr>
<tr><th scope="row"><?= e(t('Claude sessions whose undo ends')) ?></th><td><?= (int) $report['claude_sessions'] ?></td></tr>
</tbody>
</table></div>
<p class="small-text"><?= e(t('The old site goes to the trash for 30 days and is restored without its host names. The signing keys are made new, so open preview links end.')) ?></p>
<?php if ($report['blockers'] === []): ?>
<form class="form" method="post" action="<?= e($module->url('transfer')) ?>" data-confirm="<?= e(t('Move this site now? The old site stops answering at once.')) ?>">
<?= $csrf ?>
<input type="hidden" name="site" value="<?= e($site) ?>">
<input type="hidden" name="to" value="<?= e($to) ?>">
<input type="hidden" name="hash" value="<?= e($report['hash']) ?>">
<p class="buttons"><input class="btn" type="submit" value="<?= e(t('Move the site')) ?>"></p>
</form>
<?php endif ?>
<?php endif ?>
