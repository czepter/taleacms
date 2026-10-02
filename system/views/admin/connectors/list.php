<?php
/**
 * Connections to outside services (2.13): per service its credentials, settings and connection; the last calls.
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\Connectors $module
 * @var string $csrf
 * @var array<string, array{class: class-string<Kaleta\Connectors\Connector>, row: array<string, mixed>|null, config: array<string, string>}> $services
 * @var array<string, array<string, mixed>> $status
 * @var string $redirectUri
 * @var list<array<string, mixed>> $log
 * @var int $queue deliveries waiting or being retried
 */
?>
<p class="hlaska"><?= e(t('The site talks only to the services listed here, only after you connect them. Sign-ins and keys are stored encrypted and never shown again – not here, not to Claude, not in the site export.')) ?></p>
<?php foreach ($services as $key => ['class' => $class, 'row' => $row, 'config' => $config]): $st = $status[$key]; ?>
<section class="panel">
<h2><?= e($class::NAME) ?> <?php if ($st['connected']): ?><span class="stitek stitek-vydano"><?= e(t('Connected')) ?></span><?php else: ?><span class="stitek"><?= e(t('Not connected')) ?></span><?php endif ?></h2>
<?php if ($st['connected']): ?><p class="smltxt"><?= e($st['account']) ?> · <?= e(t('since %s', format_date((string) $st['since'], true))) ?></p><?php endif ?>
<?php if ($st['error'] !== ''): ?><p class="hlaska chyba"><?= e($st['error']) ?></p><?php endif ?>
<form class="formular" method="post" action="<?= e($module->url('save')) ?>"><?= $csrf ?><input type="hidden" name="service" value="<?= e($key) ?>">
<?php if ($class::AUTH === 'oauth'): ?>
	<p class="napoveda"><?= e(t('Create an OAuth app (a web application) with %s and enter its client ID and secret. The redirect address to enter there:', $class::NAME)) ?> <code><?= e($redirectUri) ?></code><?php if ($class::HELP_URL !== ''): ?> · <a href="<?= e($class::HELP_URL) ?>" target="_blank" rel="noopener"><?= e(t('Where to get it')) ?></a><?php endif ?></p>
	<div class="radek"><label for="c-<?= e($key) ?>-id"><?= e(t('Client ID')) ?></label><div><input class="textpole siroke" id="c-<?= e($key) ?>-id" name="client_id" value="<?= e((string) ($row['client_id'] ?? '')) ?>" maxlength="255" autocomplete="off"></div></div>
	<div class="radek"><label for="c-<?= e($key) ?>-secret"><?= e(t('Client secret')) ?></label><div><input class="textpole siroke" type="password" id="c-<?= e($key) ?>-secret" name="secret" value="" autocomplete="new-password" placeholder="<?= e(($row['secret'] ?? null) !== null ? t('stored – type to replace') : '') ?>"></div></div>
<?php else: ?>
	<?php if ($class::HELP_URL !== ''): ?><p class="napoveda"><a href="<?= e($class::HELP_URL) ?>" target="_blank" rel="noopener"><?= e(t('Where to get the key')) ?></a></p><?php endif ?>
	<?php if ($class::AUTH === 'basic'): ?>
	<div class="radek"><label for="c-<?= e($key) ?>-account"><?= e(t('User')) ?></label><div><input class="textpole siroke" id="c-<?= e($key) ?>-account" name="account" value="<?= e((string) ($row['account'] ?? '')) ?>" maxlength="190" autocomplete="off"></div></div>
	<?php endif ?>
	<div class="radek"><label for="c-<?= e($key) ?>-secret"><?= e(t('API key')) ?></label><div><input class="textpole siroke" type="password" id="c-<?= e($key) ?>-secret" name="secret" value="" autocomplete="new-password" placeholder="<?= e(($row['secret'] ?? null) !== null ? t('stored – type to replace') : '') ?>"></div></div>
<?php endif ?>
<?php foreach ($class::settings() as $name => $setting): [$label, $hint] = $setting; ?>
<?php if (($setting[2] ?? '') === 'check'): ?>
	<div class="radek"><span class="popisek"></span><div class="volby"><label><input type="checkbox" name="config[<?= e($name) ?>]" value="1"<?= ($config[$name] ?? '') === '1' ? ' checked' : '' ?>> <?= e(t($label)) ?></label><?php if ($hint !== ''): ?> <span class="napoveda"><?= e(t($hint)) ?></span><?php endif ?></div></div>
<?php else: ?>
	<div class="radek"><label for="c-<?= e($key . '-' . $name) ?>"><?= e(t($label)) ?></label><div><input class="textpole siroke" id="c-<?= e($key . '-' . $name) ?>" name="config[<?= e($name) ?>]" value="<?= e($config[$name] ?? '') ?>" maxlength="500"><?php if ($hint !== ''): ?> <span class="napoveda"><?= e(t($hint)) ?></span><?php endif ?></div></div>
<?php endif ?>
<?php endforeach ?>
	<p class="tlacitka"><button class="navigace" type="submit"><?= e(t('Uložit')) ?></button></p>
</form>
<div class="tlacitka">
<?php if ($class::AUTH === 'oauth' && !$st['connected'] && $st['has_app']): ?>
	<form class="vradku" method="post" action="<?= e($module->url('connect')) ?>"><?= $csrf ?><input type="hidden" name="service" value="<?= e($key) ?>"><button class="tl" type="submit"><?= e(t('Connect %s', $class::NAME)) ?></button></form>
<?php endif ?>
<?php if ($key === Kaleta\Connectors\Google::KEY && $st['connected']): /* the sheet of enquiries (2.13, Core\EnquirySheet) */ ?>
	<?php if (($config['sheet_id'] ?? '') !== ''): ?><a class="tl" href="<?= e(Kaleta\Core\EnquirySheet::url($config['sheet_id'])) ?>" target="_blank" rel="noopener"><?= e(t('Open the sheet')) ?></a><?php else: ?>
	<form class="vradku" method="post" action="<?= e($module->url('sheet')) ?>"><?= $csrf ?><button class="tl" type="submit"><?= e(t('Create the sheet')) ?></button></form>
	<?php if (($config['enquiries'] ?? '') === '1'): ?><p class="hlaska chyba"><?= e(t('Create the sheet first – without it no enquiry is sent.')) ?></p><?php endif ?>
	<?php endif ?>
<?php endif ?>
<?php if ($st['connected']): ?>
	<form class="vradku" method="post" action="<?= e($module->url('disconnect')) ?>" data-potvrdit="<?= e(t('Disconnect %s? The stored sign-in is deleted; what was already sent stays there.', $class::NAME)) ?>"><?= $csrf ?><input type="hidden" name="service" value="<?= e($key) ?>"><button class="navigace nebezpecne" type="submit"><?= e(t('Disconnect')) ?></button></form>
<?php endif ?>
</div>
</section>
<?php endforeach ?>
<h2><?= e(t('Last calls')) ?></h2>
<?php if ($queue > 0): ?><p class="smltxt"><?= e(t('%d deliveries are waiting or being retried.', $queue)) ?></p><?php endif ?>
<?php if ($log === []): ?>
<p class="smltxt"><?= e(t('No calls yet.')) ?></p>
<?php else: ?>
<div class="tab-obal"><table class="vypis">
<thead><tr><th scope="col"><?= e(t('Date')) ?></th><th scope="col"><?= e(t('Service')) ?></th><th scope="col"><?= e(t('Action')) ?></th><th scope="col"><?= e(t('Result')) ?></th></tr></thead>
<tbody>
<?php foreach ($log as $l): ?>
<tr><td><?= e(format_date((string) $l['created_at'], true)) ?></td><td><?= e((string) $l['service']) ?></td><td><code><?= e((string) $l['action']) ?></code></td>
	<td><?= $l['ok'] ? '<span class="stitek stitek-vydano">' . (int) $l['status'] . '</span>' : '<span class="stitek stitek-koncept">' . (int) $l['status'] . '</span> ' . e((string) $l['error']) ?> <span class="smltxt"><?= (int) $l['ms'] ?> ms</span></td></tr>
<?php endforeach ?>
</tbody></table></div>
<?php endif ?>
