<?php
/** The "Zálohy a aktualizace" (Backups and updates) tab. */
?>
<?php if (!\Kaleta\Core\Updater::ENABLED): ?>
<fieldset>
<legend><?= e(t('New versions')) ?></legend>
<p><?= e(t('Installed version:')) ?> <strong><?= e(KALETA_VERSION) ?></strong></p>
<?php $field('update_check', 'Tell me when a new version is available', 'flag', 'Once a day the site reads the project\'s signed release feed (no identifier is sent). It only shows a notice: you update by pulling the new image and restarting. Needs the address of the feed in KALETA_UPDATE_FEED; KALETA_UPDATE_CHECK=0 switches the check off for good.') ?>
</fieldset>
<?php endif ?>
<?php if (\Kaleta\Core\Updater::ENABLED): ?>
<fieldset>
<legend><?= e(t('System update')) ?></legend>
<p><?= e(t('Installed version:')) ?> <strong><?= e($update['current']) ?></strong></p>
<?php if (!$update['configured']): ?>
<p class="notice"><?= e(t('No update source is set yet. Upload a new version via FTP (overwrite all files except config.php, media/ and storage/); the database will be adjusted automatically.')) ?></p>
<?php elseif ($update['error'] !== null): ?>
<p class="notice notice-error"><?= e($update['error']) ?></p>
<?php elseif ($update['available'] !== null): ?>
<div class="notice notice-ok">
	<p><strong><?= e(t(!empty($update['available']['security']) ? 'Security update: version %s' : 'Version %s is available', (string) $update['available']['version'])) ?></strong><?= !empty($update['available']['released']) ? ' (' . e(format_date((string) $update['available']['released'])) . ')' : '' ?></p>
<?php if ($update['available']['changes'] !== []): ?>
	<ul><?php foreach ($update['available']['changes'] as $change): ?><li><?= e($change) ?></li><?php endforeach ?></ul>
<?php endif ?>
	<p><button class="btn" type="submit" name="version" value="<?= e((string) $update['available']['version']) ?>" formaction="<?= e($module->url('update')) ?>" data-confirm="<?= e(t('Update the system? A database backup will be created first. The site will be unavailable for a few seconds.')) ?>"><?= e(t('Update to %s', $update['available']['version'])) ?></button></p>
</div>
<p class="help"><?= e(t('The database is backed up before the update. The package is accepted only with a valid publisher signature. config.php, uploaded media and a custom PHP theme are not overwritten.')) ?></p>
<?php else: ?>
<p><?= e(t('You have the latest version.')) ?><?= $update['checked'] ? ' <small>' . e(t('Checked %s.', format_date((new DateTimeImmutable())->setTimestamp((int) $update['checked']), true))) . '</small>' : '' ?></p>
<?php endif ?>
<?php if ($update['configured']): ?>
<p><button class="navigation" type="submit" formaction="<?= e($module->url('check')) ?>"><?= e(t('Check now')) ?></button></p>
<?php endif ?>
<?php $field('auto_updates', 'Install security updates automatically', 'flag', 'Recommended. Applies only to releases marked as security releases; you install regular versions yourself. The system checks for updates twice a day, backs up the database before installing and e-mails the result to the site e-mail.'); ?>
<?php $field('update_url', 'Custom update source', 'url', 'Leave empty. Enter a different address of the update.json file only if you manage versions yourself.', 'placeholder="https://"'); ?>
</fieldset>
<?php endif ?>

<fieldset>
<legend><?= e(t('Database backups')) ?></legend>
<details class="advanced"<?= $values['remote_backup'] !== 'off' ? ' open' : '' ?>>
<summary><?= e(t('Off-site backup copies')) ?><?= $values['remote_backup'] !== 'off' ? ' – ' . e(t('on')) : '' ?></summary>
<p class="help"><?= e(t('A backup on the same server as the site does not help if you lose the hosting. Each new database backup therefore uploads itself elsewhere, and the media/ folder is copied to the same place – only new and changed files, in the background.')) ?></p>
<div class="row"><label for="remote_backup"><?= e(t('Copy to')) ?></label><select id="remote_backup" name="remote_backup">
	<option value="off"><?= e(t('nowhere')) ?></option>
	<option value="ftp"<?= $values['remote_backup'] === 'ftp' ? ' selected' : '' ?>><?= e(t('to an FTP server with FTPS encryption (another host, home NAS)')) ?></option>
	<option value="s3"<?= $values['remote_backup'] === 's3' ? ' selected' : '' ?>><?= e(t('S3 storage (Amazon S3, Backblaze B2, Wasabi, Cloudflare R2)')) ?></option>
</select></div>
<?php
$field('backup_host', 'Server', 'text', 'FTP: ftp.example.com. S3: the storage endpoint, e.g. s3.eu-central-1.amazonaws.com or s3.eu-central-003.backblazeb2.com.', 'maxlength="150" autocomplete="off"');
$field('backup_user', 'User name / access key', 'text', '', 'maxlength="190" autocomplete="off"');
?>
<div class="row"><label for="backup_password"><?= e(t('Password / secret key')) ?></label><div><input class="textfield wide" type="password" id="backup_password" name="backup_password" value="" autocomplete="new-password" placeholder="<?= $values['backup_password'] !== '' ? e(t('saved – enter a new one only to change it')) : '' ?>">
<?php if ($values['backup_password'] !== ''): ?>
	<label><input type="checkbox" name="backup_password_delete" value="1"> <?= e(t('Remove saved password')) ?></label>
<?php endif ?>
</div></div>
<?php
$field('backup_folder', 'Folder / bucket', 'text', 'FTP: folder for backups (created if missing). S3: bucket name, optionally bucket/folder.', 'maxlength="150"');
$field('backup_region', 'Region (S3 only)', 'text', 'For example eu-central-1. For Cloudflare R2 enter auto.', 'maxlength="40" style="width:180px"');
?>
<?php $field('backup_media', 'Copy the media too', 'flag', 'Uploaded images and files go to the folder media/ of the same target. A file deleted on the site stays in the copy.'); ?>
<?php if ($remoteStatus !== ''): [$when, $how] = explode('|', $remoteStatus, 2) + [1 => '']; ?>
<p class="notice<?= $how === 'ok' ? ' notice-ok' : ' notice-error' ?>"><?= e($how === 'ok' ? t('The last copy was uploaded %s.', $when) : t('The last attempt %s failed: %s', $when, t($how))) ?></p>
<?php endif ?>
<?php if ($mediaStatus !== '' && $values['backup_media'] === '1'): [$when, $how, $waiting] = explode('|', $mediaStatus, 3) + [1 => '', 2 => '0']; ?>
<p class="notice<?= $how === 'ok' ? ((int) $waiting === 0 ? ' notice-ok' : '') : ' notice-error' ?>"><?= e($how !== 'ok' ? t('Media: the last attempt %s failed: %s', $when, t($how)) : ((int) $waiting === 0 ? t('Media: the copy is complete (checked %s).', $when) : t('Media: %d files are still waiting – the copy continues in the background.', (int) $waiting))) ?></p>
<?php endif ?>
<p class="help"><?= e(t('Save the settings, then click “Create backup now” – the copy uploads right away and you will see whether the connection works.')) ?></p>
</details>
<?php $field('auto_backups', 'Automatic backups', 'flag', 'Every day something changed on the site, otherwise once a week – while an administrator works in the administration or cron runs. The last 10 backups are kept.'); ?>
<p><button class="btn" type="submit" formaction="<?= e($module->url('backup')) ?>"><?= e(t('Create backup now')) ?></button></p>
<?php if ($backups !== []): ?>
<div class="tab-wrap">
<table class="listing">
<thead><tr><th scope="col"><?= e(t('File')) ?></th><th scope="col"><?= e(t('Created')) ?></th><th scope="col"><?= e(t('Size')) ?></th><th scope="col"><?= e(t('Actions')) ?></th></tr></thead>
<tbody>
<?php foreach ($backups as $z): ?>
<tr>
	<td><?= e($z['file']) ?></td>
	<td class="number"><?= e(format_date((new DateTimeImmutable())->setTimestamp((int) $z['time']), true)) ?></td>
	<td class="number"><?= format_count($z['size'] / 1024) ?> kB</td>
	<td class="actions"><a href="<?= e($module->url('download_backup', ['file' => $z['file']])) ?>"><?= e(t('Download')) ?></a> · <button class="navigation" type="submit" formaction="<?= e($module->url('restore_backup')) ?>" name="file" value="<?= e($z['file']) ?>" data-confirm="<?= e(t('Restore the database from this backup? Everything added to the site since it was created (pages, news, enquiries, settings) will be lost. The current state is saved to a new backup first.')) ?>"><?= e(t('Restore')) ?></button> · <button class="navigation danger" type="submit" formaction="<?= e($module->url('delete_backup')) ?>" name="file" value="<?= e($z['file']) ?>" data-confirm="<?= e(t('Delete backup?')) ?>"><?= e(t('Delete')) ?></button></td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<?php endif ?>
<p><button class="navigation" type="submit" formaction="<?= e($module->url('media_backup')) ?>"><?= e(t('Download media backup (ZIP)')) ?></button></p>
<p class="help"><?= e(t('The database backup contains pages, news, settings and users; uploaded images are in the media backup. Backups are stored in storage/zalohy/, which is not accessible from the web – download copies off the server too.')) ?></p>
<details class="advanced">
<summary><?= e(t('How to restore the site after losing the hosting')) ?></summary>
<ol>
	<li><?= e(t('Install Kaleta on the new hosting with the same table prefix (ka_ unless you changed it) and any starter site.')) ?></li>
	<li><?= e(t('Upload the latest database backup (kaleta-….sql.gz from your FTP or S3 copy, or a downloaded one) over FTP into storage/zalohy/.')) ?></li>
	<li><?= e(t('Copy the media/ folder from the same place into the root of the site.')) ?></li>
	<li><?= e(t('Here in Backups, click Restore at that backup. Then sign in with the accounts from the backup.')) ?></li>
</ol>
<p class="help"><?= e(t('Moving a site to another Kaleta installation without accounts and secrets is simpler with Export of the whole site and Import from Kaleta (Import and export).')) ?></p>
</details>
</fieldset>
