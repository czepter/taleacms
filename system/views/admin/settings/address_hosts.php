<?php
/**
 * The host names of the current site (several websites only), after the settings form because it holds forms of its own.
 * The actions are those of Modules\Sites; `return` brings the browser back here.
 *
 * @var array{site: array<string, mixed>, host: string, dnsOnly: bool} $address
 */
$site = $address['site'];
$host = $address['host'];
$hidden = '<input type="hidden" name="id" value="' . e((string) $site['public_id']) . '"><input type="hidden" name="return" value="address">';
$action = fn (string $name): string => e($app->url('admin.php?module=sites&action=' . $name));
?>
<h2><?= e(t('Host names')) ?></h2>
<p class="small-text"><?= e(t('The site answers to these host names. Point each one to this installation in the DNS. The primary one is where people are sent when they open the site from here.')) ?></p>
<?php if ($site['domains'] === []): ?>
<p class="small-text"><?= e(t('No host name yet.')) ?></p>
<?php else: ?>
<div class="tab-wrap"><table class="listing">
<thead><tr><th scope="col"><?= e(t('Host name')) ?></th><th scope="col"><?= e(t('Role')) ?></th><th scope="col"><?= e(t('Status')) ?></th><th scope="col"><span class="visually-hidden"><?= e(t('Actions')) ?></span></th></tr></thead>
<tbody>
<?php foreach ($site['domains'] as $d): $field = '<input type="hidden" name="host" value="' . e($d['host']) . '">'; ?>
<tr>
	<td class="cell-main"><div class="cell-text"><span class="cell-title"><?= bdi($d['host']) ?></span><?= $d['host'] === $host ? '<span class="cell-sub">' . e(t('You are using this host name')) . '</span>' : '' ?></div></td>
	<td><?= $d['kind'] === 'preview' ? '<span class="badge">' . e(t('Preview address')) . '</span>' : ($d['is_primary'] ? '<span class="badge badge-published">' . e(t('Primary')) . '</span>' : ($d['redirect_to_primary'] ? '<span class="badge">' . e(t('Redirects to the primary')) . '</span>' : '<span class="badge">' . e(t('Alias')) . '</span>')) ?></td>
	<td><?= $d['verified_at'] !== null ? '<span class="badge badge-published">' . e(t('Verified')) . '</span>' : '<span class="badge">' . e(t('Not verified')) . '</span>' ?></td>
	<td class="cell-actions">
<?php if ($d['kind'] === 'preview'): ?>
		<span class="small-text"><?= e(t('Never indexed by search engines')) ?></span>
<?php else: ?>
<?php if ($d['verified_at'] === null): ?>
		<form class="inline" method="post" action="<?= $action('domain_check') ?>"><?= $csrf . $hidden . $field ?><button class="navigation" type="submit"><?= e(t('Check now')) ?></button></form>
<?php endif ?>
<?php if (!$d['is_primary']): ?>
		<form class="inline" method="post" action="<?= $action('domain_primary') ?>"><?= $csrf . $hidden . $field ?><button class="navigation" type="submit"><?= e(t('Make primary')) ?></button></form>
		<form class="inline" method="post" action="<?= $action('domain_redirect') ?>"><?= $csrf . $hidden . $field ?><input type="hidden" name="redirect" value="<?= $d['redirect_to_primary'] ? '0' : '1' ?>"><button class="navigation" type="submit"><?= e($d['redirect_to_primary'] ? t('Answer on its own') : t('Redirect to the primary')) ?></button></form>
<?php endif ?>
		<form class="inline" method="post" action="<?= $action('domain_remove') ?>" data-confirm="<?= e(t('Remove this host name? The site stops answering on it.')) ?>"><?= $csrf . $hidden . $field ?><button class="navigation" type="submit"><?= e(t('Remove')) ?></button></form>
<?php endif ?>
	</td>
</tr>
<?php if ($d['verified_at'] === null && $d['kind'] === 'domain'): ?>
<tr>
	<td colspan="4" class="small-text">
<?php if ($address['dnsOnly']): ?>
		<?= e(t('Point the host name to this installation in the DNS. Then show that it is yours with this TXT record:')) ?>
		<ul>
			<li><code>_talea.<?= e($d['host']) ?></code> = <code><?= e(Talea\Core\Domains::txtValue((string) $d['token'])) ?></code></li>
		</ul>
<?php else: ?>
		<?= e(t('Point the host name to this installation in the DNS. Then show that it is yours, in one of two ways:')) ?>
		<ul>
			<li><?= e(t('Let this address answer on the host name:')) ?> <code>http://<?= e($d['host']) ?>/.well-known/talea-domain/<?= e($d['token']) ?></code> <?= e(t('(it does as soon as the host name points here)')) ?></li>
			<li><?= e(t('Or add this TXT record:')) ?> <code>_talea.<?= e($d['host']) ?></code> = <code><?= e(Talea\Core\Domains::txtValue((string) $d['token'])) ?></code></li>
		</ul>
<?php endif ?>
	</td>
</tr>
<?php endif ?>
<?php endforeach ?>
</tbody>
</table></div>
<?php endif ?>
<form class="form" method="post" action="<?= $action('domain_add') ?>">
<?= $csrf . $hidden ?>
<div class="row">
	<label for="host"><?= e(t('Add a host name')) ?></label>
	<div><input class="textfield" type="text" id="host" name="host" maxlength="253" placeholder="www.example.com" required autocapitalize="none" spellcheck="false"><span class="help"><?= e(t('Only the name: no http://, no port, no path.')) ?></span></div>
</div>
<p class="buttons"><input class="btn" type="submit" value="<?= e(t('Add')) ?>"></p>
</form>
