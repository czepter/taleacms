<?php
/** The "Stav systému" (System health) tab. */
$icons = ['ok' => '✓', 'varovani' => '!', 'chyba' => '✕'];
$summary = Kaleta\Core\Health::summary($checks);
$group = '';
?>
<p class="hlaska hlaska-<?= ['ok' => 'ok', 'varovani' => 'varovani', 'chyba' => 'chyba'][$summary] ?>"><?= e(t(['ok' => 'Everything is fine.', 'varovani' => 'The system is running, but some items deserve attention.', 'chyba' => 'Errors were found that prevent the site from running properly.'][$summary])) ?></p>
<div class="tab-obal">
<table class="vypis">
<tbody>
<?php foreach ($checks as $k): ?>
<?php if ($k['skupina'] !== $group): $group = $k['skupina']; ?>
<tr><th colspan="3" scope="colgroup"><?= e($group) ?></th></tr>
<?php endif ?>
<tr>
	<td class="stred"><span class="stitek stitek-<?= ['ok' => 'vydano', 'varovani' => 'koncept', 'chyba' => 'chyba'][$k['stav']] ?>" title="<?= e(t(['ok' => 'v pořádku', 'varovani' => 'varování', 'chyba' => 'chyba'][$k['stav']])) ?>"><?= $icons[$k['stav']] ?></span></td>
	<td><strong><?= e($k['nazev']) ?></strong></td>
	<td><?= Kaleta\Admin\MenuPaths::links($app->url('admin.php'), (string) $k['info'], ['settings', 'appearance', 'menu']) ?><?php if (($k['odkazy'] ?? []) !== []): ?><br><span class="smltxt"><?php foreach ($k['odkazy'] as $i => $link): ?><?= $i > 0 ? ', ' : '' ?><a href="<?= e($link['url']) ?>"><?= e($link['text']) ?></a><?php endforeach ?></span><?php endif ?></td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<fieldset>
<legend><?= e(t('Mail')) ?></legend>
<p><button class="navigace" type="submit" formaction="<?= e($module->url('test_mail')) ?>"><?= e(t('Send a test e-mail to the site address')) ?></button></p>
</fieldset>
<fieldset>
<legend><?= e(t('Error log')) ?></legend>
<?php if ($errorLog === []): ?>
<p><?= e(t('No errors – the log is empty.')) ?></p>
<?php else: ?>
<pre class="log-chyb"><?php foreach (array_reverse($errorLog) as $row): ?><?= e(mb_strimwidth(str_replace(KALETA_ROOT, '', $row), 0, 400, '…')) . "\n" ?><?php endforeach ?></pre>
<p><button class="navigace nebezpecne" type="submit" formaction="<?= e($module->url('delete_log')) ?>" data-potvrdit="<?= e(t('Clear the error log?')) ?>"><?= e(t('Clear the log')) ?></button> <span class="smltxt"><?= e(t('Newest first, the last 40 entries from storage/log/chyby.log.')) ?></span></p>
<?php endif ?>
</fieldset>
<fieldset>
<legend><?= e(t('Background jobs (cron)')) ?></legend>
<p><?= e(t('Scheduled news, notifications and outgoing mail run on site visits. A low-traffic site makes them more precise by calling this address every 5 minutes from your hosting\'s cron:')) ?></p>
<?php if ($tasksToken !== ''): ?>
<p><code>*/5 * * * * curl -s "<?= e($siteUrl) ?>ulohy?token=<?= e($tasksToken) ?>" &gt; /dev/null</code></p>
<?php endif ?>
<p><button class="navigace" type="submit" name="novy_token_ulohy" value="1"><?= e(t($tasksToken !== '' ? 'Create a new address (the old one stops working)' : 'Create the cron address')) ?></button></p>
</fieldset>
<fieldset>
<legend><?= e(t('Monitoring')) ?></legend>
<?php if ($values['health_token'] !== ''): ?>
<p><?= e(t('Status in JSON format for monitoring tools (UptimeRobot, Zabbix…):')) ?><br><code><?= e($siteUrl) ?>stav.json?token=<?= e($values['health_token']) ?></code></p>
<?php else: ?>
<p><?= e(t('A monitoring tool can read the status as JSON. Create an access token first.')) ?></p>
<?php endif ?>
<input type="hidden" name="health_token" value="<?= e($values['health_token']) ?>">
<p><button class="navigace" type="submit" name="novy_token" value="1"><?= e(t($values['health_token'] !== '' ? 'Create a new token (the old one stops working)' : 'Create token')) ?></button></p>
</fieldset>
