<?php
/**
 * The "Firewall" tab (2.8, Core\Firewall). For the variables and the $field function see list.php.
 *
 * @var array{blocks: list<array<string, mixed>>, log: list<array<string, mixed>>, ip: string, country: string, invalid: list<string>} $firewall
 */
$reasons = ['list' => t('blocked address'), 'country' => t('blocked country'), 'rate' => t('too many requests'), 'probe' => t('probing for other systems'), 'temporary' => t('blocked for a while')];
?>
<p class="notice"><?= e(t('The firewall guards the public site and the Claude connection – never the administration, so you cannot lock yourself out. Addresses of your local network are never blocked.')) ?></p>
<fieldset>
<legend><?= e(t('Firewall')) ?></legend>
<?php
$field('firewall_enabled', 'Firewall on', 'flag', '');
?>
<div class="row"><label for="firewall_proxy"><?= e(t('The site runs behind')) ?></label><div><select id="firewall_proxy" name="firewall_proxy">
	<option value=""<?= $values['firewall_proxy'] === '' ? ' selected' : '' ?>><?= e(t('nothing – visitors connect directly')) ?></option>
	<option value="cloudflare"<?= $values['firewall_proxy'] === 'cloudflare' ? ' selected' : '' ?>>Cloudflare</option>
</select><span class="help"><?= e(t('Your address as the site sees it: %s.', $firewall['ip'])) ?> <?= e($firewall['country'] !== '' ? t('Country: %s.', $firewall['country']) : t('The country of visitors is not known here – blocking countries works only behind Cloudflare or when the hosting sends the country.')) ?></span></div></div>
<?php
$field('firewall_probes', 'Block probing', 'flag', 'An address that asks for /wp-login.php, /.env and similar addresses of other systems 5 times in an hour is blocked for 24 hours.');
$field('firewall_rate', 'Requests per minute from one address', 'number', '0 = no limit. 120 is plenty for people; it stops aggressive scrapers.', 'min="0" max="10000"');
$field('firewall_ips', 'Blocked addresses and networks', 'lines', 'One per line, e.g. 203.0.113.7 or 198.51.100.0/24; a comment after #.');
?>
<?php if ($firewall['invalid'] !== []): ?><p class="notice notice-warning"><?= e(t('These lines are not addresses and are ignored: %s', implode(', ', $firewall['invalid']))) ?></p><?php endif ?>
<?php
$field('firewall_countries', 'Blocked countries', 'text', 'Two-letter codes separated by commas, e.g. RU, CN. Only when the country is known (see above).', 'maxlength="400"');
?>
</fieldset>
<fieldset>
<legend><?= e(t('Blocked for a while')) ?></legend>
<?php if ($firewall['blocks'] === []): ?>
<p><?= e(t('No address is blocked right now.')) ?></p>
<?php else: ?>
<div class="tab-wrap"><table class="listing"><thead><tr><th scope="col"><?= e(t('Address')) ?></th><th scope="col"><?= e(t('Why')) ?></th><th scope="col"><?= e(t('Until')) ?></th><th scope="col"></th></tr></thead><tbody>
<?php foreach ($firewall['blocks'] as $b): ?>
	<tr><td><?= e($b['ip']) ?></td><td><?= e($reasons[$b['reason']] ?? $b['reason']) ?></td><td><?= e(format_date(new DateTimeImmutable((string) $b['until']), true)) ?></td>
		<td><button class="navigation" type="submit" formaction="<?= e($module->url('firewall_unblock')) ?>" name="ip" value="<?= e($b['ip']) ?>"><?= e(t('Unblock')) ?></button></td></tr>
<?php endforeach ?>
</tbody></table></div>
<?php endif ?>
</fieldset>
<fieldset>
<legend><?= e(t('Refused requests')) ?></legend>
<?php if ($firewall['log'] === []): ?>
<p><?= e(t('Nothing refused yet.')) ?></p>
<?php else: ?>
<div class="tab-wrap"><table class="listing"><thead><tr><th scope="col"><?= e(t('When')) ?></th><th scope="col"><?= e(t('Address')) ?></th><th scope="col"><?= e(t('Why')) ?></th><th scope="col"><?= e(t('Page')) ?></th></tr></thead><tbody>
<?php foreach ($firewall['log'] as $l): ?>
	<tr><td><?= e(format_date(new DateTimeImmutable((string) $l['created_at']), true)) ?></td><td><?= e($l['ip']) ?></td><td><?= e($reasons[$l['reason']] ?? $l['reason']) ?></td><td><?= e($l['path']) ?></td></tr>
<?php endforeach ?>
</tbody></table></div>
<p class="small-text"><?= e(t('The last 50; the log keeps 30 days.')) ?></p>
<?php endif ?>
</fieldset>
