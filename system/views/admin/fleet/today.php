<?php
/**
 * Today on the fleet console: what needs doing, in groups by check (Fleet\Today::groups) – with the form to accept a known state.
 *
 * @var Talea\Admin\Modules\Fleet $module
 * @var list<array{key: string, label: string, incident: bool, open: bool, rows: list<array{site: array<string, mixed>, item: array<string, mixed>}>}> $groups
 * @var array<string, string> $reasonLabels
 */
$until = date('Y-m-d', strtotime('+' . Talea\Fleet\Today::ACCEPT_DAYS . ' days'));
$tomorrow = date('Y-m-d', strtotime('+1 day'));
$last = date('Y-m-d', strtotime('+' . Talea\Fleet\Today::ACCEPT_MAX_DAYS . ' days'));
?>
<?php foreach ($groups as $g): ?>
<details class="panel-collapsed fleet-group"<?= $g['open'] ? ' open' : '' ?>>
	<summary><h2><?= e(t($g['label'])) ?></h2> <span class="badge<?= $g['incident'] ? ' badge-error' : ' badge-draft' ?>"><?= e(t('%d sites', count(array_unique(array_map(fn (array $r): int => (int) $r['site']['id'], $g['rows']))))) ?></span></summary>
	<div class="tab-wrap"><table class="listing"><tbody>
<?php foreach ($g['rows'] as $row): $site = $row['site']; $item = $row['item']; $label = $reasonLabels[$item['key']] ?? ($item['key'] === 'health' ? $reasonLabels[$item['reason']] : $item['title']); ?>
	<tr>
		<td class="center"><span class="badge badge-<?= $item['severity'] === 'error' ? 'error' : 'draft' ?>"><?= $item['severity'] === 'error' ? '✕' : '!' ?></span></td>
		<td><a href="<?= e($module->url('detail', ['id' => $site['public_id']])) ?>"><strong><?= e((string) $site['name'] !== '' ? (string) $site['name'] : (string) $site['url']) ?></strong></a><br>
			<a class="small-text" href="<?= e((string) $site['url'] . '/admin.php') ?>" target="_blank" rel="noopener"><?= e(t('Administration of the site')) ?></a></td>
		<td><strong><?= e($label) ?></strong><?php if ($item['detail'] !== ''): ?><br><span class="small-text"><?= e($item['key'] === 'down' && $item['detail'] === 'no answer' ? t('no answer') : $item['detail']) ?></span><?php endif ?></td>
		<td>
<?php if ($item['accepted'] === null): ?>
			<details class="advanced"><summary><?= e(t('Accept this state')) ?></summary>
				<form class="form" method="post" action="<?= e($module->url('accept')) ?>">
					<?= $csrf ?>
					<input type="hidden" name="id" value="<?= e($site['public_id']) ?>"><input type="hidden" name="check" value="<?= e($item['key']) ?>">
					<label><?= e(t('Why it is fine for now')) ?> <input class="textfield" name="note" maxlength="<?= Talea\Fleet\Today::NOTE_MAX ?>" required></label>
					<label><?= e(t('Until')) ?> <input class="textfield" type="date" name="until" value="<?= e($until) ?>" min="<?= e($tomorrow) ?>" max="<?= e($last) ?>" required></label>
					<button class="btn" type="submit"><?= e(t('Accept this state')) ?></button>
				</form></details>
<?php else: ?>
			<span class="small-text"><?= e((string) $item['accepted']['note']) ?><br><?= e(t('until %s', format_date((string) $item['accepted']['until']))) ?> · <?= e(t('by %s', (string) ($item['accepted']['by'] ?? ''))) ?></span>
			<form class="form" method="post" action="<?= e($module->url('withdraw')) ?>">
				<?= $csrf ?>
				<input type="hidden" name="id" value="<?= e($site['public_id']) ?>"><input type="hidden" name="check" value="<?= e($item['key']) ?>">
				<button class="navigation" type="submit"><?= e(t('Withdraw')) ?></button>
			</form>
<?php endif ?>
		</td>
	</tr>
<?php endforeach ?>
	</tbody></table></div>
</details>
<?php endforeach ?>
