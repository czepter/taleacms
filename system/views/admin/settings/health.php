<?php
/** The "System health" tab. */
$icons = ['ok' => '✓', 'warning' => '!', 'error' => '✕'];
$summary = Talea\Core\Health::summary($checks);
$group = '';
?>
<p class="notice notice-<?= ['ok' => 'ok', 'warning' => 'warning', 'error' => 'error'][$summary] ?>"><?= e(t(['ok' => 'Everything is fine.', 'warning' => 'The system is running, but some items deserve attention.', 'error' => 'Errors were found that prevent the site from running properly.'][$summary])) ?></p>
<div class="tab-wrap">
<table class="listing">
<tbody>
<?php foreach ($checks as $k): ?>
<?php if ($k['group'] !== $group): $group = $k['group']; ?>
<tr><th colspan="3" scope="colgroup"><?= e($group) ?></th></tr>
<?php endif ?>
<tr>
	<td class="center"><span class="badge badge-<?= ['ok' => 'published', 'warning' => 'draft', 'error' => 'error'][$k['status']] ?>" title="<?= e(t(['ok' => 'OK', 'warning' => 'warning', 'error' => 'error'][$k['status']])) ?>"><?= $icons[$k['status']] ?></span></td>
	<td><strong><?= e($k['name']) ?></strong></td>
	<td><?= Talea\Admin\MenuPaths::links($app->url('admin.php'), (string) $k['info'], ['settings', 'appearance', 'menu', 'business', 'status', 'claude']) ?><?php if (($k['links'] ?? []) !== []): ?><br><span class="small-text"><?php foreach ($k['links'] as $i => $link): ?><?= $i > 0 ? ', ' : '' ?><a href="<?= e($link['url']) ?>"><?= e($link['text']) ?></a><?php endforeach ?></span><?php endif ?></td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<fieldset>
<legend><?= e(t('Mail')) ?></legend>
<p><button class="navigation" type="submit" formaction="<?= e($module->url('test_mail')) ?>"><?= e(t('Send a test e-mail to the site address')) ?></button></p>
</fieldset>
<fieldset>
<legend><?= e(t('Error log')) ?></legend>
<?php if ($errorLog === []): ?>
<p><?= e(t('No errors – the log is empty.')) ?></p>
<?php else: ?>
<pre class="log-errors"><?php foreach (array_reverse($errorLog) as $row): ?><?= e(mb_strimwidth(str_replace(TALEA_ROOT, '', $row), 0, 400, '…')) . "\n" ?><?php endforeach ?></pre>
<p><button class="navigation danger" type="submit" formaction="<?= e($module->url('delete_log')) ?>" data-confirm="<?= e(t('Clear the error log?')) ?>"><?= e(t('Clear the log')) ?></button> <span class="small-text"><?= e(t('Newest first, the last 40 entries from storage/log/errors.log.')) ?></span></p>
<?php endif ?>
</fieldset>
<fieldset>
<legend><?= e(t('Background jobs (cron)')) ?></legend>
<?php $webCron = !Talea\Core\Config::fromEnv(); // a container runs the jobs itself (docker/entrypoint.sh) ?>
<?php if ($webCron): ?>
<p><?= e(t('Scheduled news, notifications and outgoing mail run on site visits. A low-traffic site makes them more precise by calling this address every 5 minutes from your hosting\'s cron:')) ?></p>
<?php if ($tasksToken !== ''): ?>
<p><code>*/5 * * * * curl -s "<?= e($siteUrl) ?>tasks?token=<?= e($tasksToken) ?>" &gt; /dev/null</code></p>
<?php endif ?>
<?php endif ?>
<div class="tab-wrap"><table class="listing">
<thead><tr><th scope="col"><?= e(t('Job')) ?></th><th scope="col"><?= e(t('Runs')) ?></th><th scope="col"><?= e(t('Last run')) ?></th><th scope="col"><?= e(t('Result')) ?></th></tr></thead>
<tbody>
<?php foreach (Talea\Core\Scheduler::overview($app->db(), $app->settings()) as $j): ?>
	<tr><td><?= e(t($j['label'])) ?></td><td><?= e($j['where'] === 'cron' ? t('only from cron') : t('cron and visits')) ?><?= $j['interval'] > 0 ? ', ' . e(t('every %s', $j['interval'] >= 3600 ? t('%d h', intdiv($j['interval'], 3600)) : t('%d min', intdiv($j['interval'], 60)))) : '' ?></td>
		<td><?= $j['last_run'] !== null ? e(format_date(new DateTimeImmutable((string) $j['last_run']), true)) : '–' ?></td>
		<td><?php if ($j['last_run'] === null): ?><?= e(t('not run yet')) ?><?php elseif ($j['failures'] > 0): ?><span class="badge badge-error"><?= e(t('failed %d×', $j['failures'])) ?></span> <?= e($j['last_error']) ?><?php else: ?><span class="badge badge-published"><?= e(t('ok')) ?></span><?php endif ?></td></tr>
<?php endforeach ?>
</tbody></table></div>
<?php if ($webCron): ?>
<p><button class="navigation" type="submit" name="new_tasks_token" value="1"><?= e(t($tasksToken !== '' ? 'Create a new address (the old one stops working)' : 'Create the cron address')) ?></button></p>
<?php endif ?>
</fieldset>
<fieldset>
<legend><?= e(t('Alerts')) ?></legend>
<p><?= e(t('When something on the site fails or needs attention, the site sends one e-mail with everything that happened since the last one.')) ?></p>
<?php
$field('alerts_enabled', 'Send alert e-mails', 'flag', '');
$field('alerts_email', 'Send them to', 'email', 'Empty = the site e-mail (Settings → General).', 'maxlength="190"');
$alertKinds = explode(',', $values['alerts_kinds'] ?? '');
?>
<div class="row">
	<span class="caption"><?= e(t('Alert me about')) ?></span>
	<div class="options">
<?php foreach (Talea\Core\Alerts::KINDS as $kind => [$label]): ?>
		<label><input type="checkbox" name="alerts_kinds[]" value="<?= e($kind) ?>"<?= in_array($kind, $alertKinds, true) ? ' checked' : '' ?>> <?= e(t($label)) ?></label><br>
<?php endforeach ?>
		<span class="help"><?= e(t('Everything stays listed in System status, whether it sends an e-mail or not.')) ?></span>
	</div>
</div>
<div class="row">
	<label for="alerts_interval"><?= e(t('How often')) ?></label>
	<div><select id="alerts_interval" name="alerts_interval">
<?php foreach (Talea\Core\Alerts::INTERVALS as $minutes => $label): ?>
		<option value="<?= $minutes ?>"<?= ($values['alerts_interval'] ?? '60') === (string) $minutes ? ' selected' : '' ?>><?= e(t($label)) ?></option>
<?php endforeach ?>
	</select><span class="help"><?= e(t('What happens in between waits for the next e-mail; nothing is lost.')) ?></span></div>
</div>
<p><input class="btn" type="submit" value="<?= e(t('Save settings')) ?>"></p>
</fieldset>
<fieldset>
<legend><?= e(t('Monitoring')) ?></legend>
<?php if ($values['health_token'] !== ''): ?>
<p><?= e(t('Status in JSON format for monitoring tools (UptimeRobot, Zabbix…):')) ?><br><code><?= e($siteUrl) ?>status.json?token=<?= e($values['health_token']) ?></code></p>
<?php else: ?>
<p><?= e(t('A monitoring tool can read the status as JSON. Create an access token first.')) ?></p>
<?php endif ?>
<input type="hidden" name="health_token" value="<?= e($values['health_token']) ?>">
<p><button class="navigation" type="submit" name="new_token" value="1"><?= e(t($values['health_token'] !== '' ? 'Create a new token (the old one stops working)' : 'Create token')) ?></button></p>
</fieldset>
