<?php
/**
 * @var Talea\Admin\Modules\Sites $module
 * @var string $csrf
 * @var list<array{file: string, size: int, time: int}> $backups  this owner's backups
 * @var list<array{file: string, size: int, time: int}> $exports  the exports of this owner's sites
 * @var bool $hasZip
 */
?>
<p class="navigation-row"><a class="navigation" href="<?= e($module->url()) ?>"><?= e(t('Back to sites')) ?></a></p>
<h2><?= e(t('Backups')) ?></h2>
<p class="help"><?= e(t('A backup holds everything of your account: all your sites with their pages, news, settings and users. It does not hold uploaded files – export a site for those. A restore puts back every site of your account, not only this one; the current state is saved to a new backup first. The last 10 backups are kept.')) ?></p>
<form method="post" action="<?= e($module->url('backup_create')) ?>"><?= $csrf ?><p><button class="btn" type="submit"><?= e(t('Create backup now')) ?></button></p></form>
<?php if ($backups !== []): ?>
<form method="post" action="<?= e($module->url('backup_restore')) ?>"><?= $csrf ?>
<div class="tab-wrap"><table class="listing">
<thead><tr><th scope="col"><?= e(t('File')) ?></th><th scope="col"><?= e(t('Created')) ?></th><th scope="col"><?= e(t('Size')) ?></th><th scope="col"><?= e(t('Actions')) ?></th></tr></thead>
<tbody>
<?php foreach ($backups as $z): ?>
<tr>
	<td><?= e($z['file']) ?></td>
	<td class="number"><?= e(format_date((new DateTimeImmutable())->setTimestamp((int) $z['time']), true)) ?></td>
	<td class="number"><?= format_count($z['size'] / 1024) ?> kB</td>
	<td class="actions"><a href="<?= e($module->url('backup_download', ['file' => $z['file']])) ?>"><?= e(t('Download')) ?></a> · <button class="navigation" type="submit" formaction="<?= e($module->url('backup_restore')) ?>" name="file" value="<?= e($z['file']) ?>" data-confirm="<?= e(t('Restore all your sites from this backup? Everything added since it was created (pages, news, enquiries, settings) will be lost. The current state is saved to a new backup first.')) ?>"><?= e(t('Restore')) ?></button> · <button class="navigation danger" type="submit" formaction="<?= e($module->url('backup_delete')) ?>" name="file" value="<?= e($z['file']) ?>" data-confirm="<?= e(t('Delete backup?')) ?>"><?= e(t('Delete')) ?></button></td>
</tr>
<?php endforeach ?>
</tbody>
</table></div>
</form>
<?php endif ?>
<h2><?= e(t('Exports')) ?></h2>
<p class="help"><?= e(t('An export of this site in an open format: pages, news, collections, look and the uploaded files. Another Talea imports it as a new site. The last 3 exports are kept.')) ?></p>
<form method="post" action="<?= e($module->url('export_create')) ?>"><?= $csrf ?><p><button class="btn" type="submit"><?= e(t('Export this site')) ?></button></p></form>
<?php if ($exports !== []): ?>
<form method="post" action="<?= e($module->url('export_delete')) ?>"><?= $csrf ?>
<div class="tab-wrap"><table class="listing">
<thead><tr><th scope="col"><?= e(t('File')) ?></th><th scope="col"><?= e(t('Created')) ?></th><th scope="col"><?= e(t('Size')) ?></th><th scope="col"><?= e(t('Actions')) ?></th></tr></thead>
<tbody>
<?php foreach ($exports as $z): ?>
<tr>
	<td><?= e($z['file']) ?></td>
	<td class="number"><?= e(format_date((new DateTimeImmutable())->setTimestamp((int) $z['time']), true)) ?></td>
	<td class="number"><?= e(Talea\Core\Files::size((int) $z['size'])) ?></td>
	<td class="actions"><a href="<?= e($module->url('export_download', ['file' => $z['file']])) ?>"><?= e(t('Download')) ?></a> · <button class="navigation danger" type="submit" name="file" value="<?= e($z['file']) ?>" data-confirm="<?= e(t('Delete the export?')) ?>"><?= e(t('Delete')) ?></button></td>
</tr>
<?php endforeach ?>
</tbody>
</table></div>
</form>
<?php endif ?>
