<?php
/**
 * @var Talea\Admin\Modules\Sites $module
 * @var string $csrf
 * @var array<string, mixed> $site  the row with `domains`, `ready`
 * @var list<array<string, mixed>> $others  the other sites that are set up (targets of an archived site)
 * @var int $current
 * @var string $host  the host name of this request
 * @var bool $dnsOnly  a host name of this owner is verified by the DNS record alone
 * @var array{parents: list<array<string, mixed>>, parent: ?array<string, mixed>, children: list<array<string, mixed>>, hasDraft: bool} $family  look family (Core\LookFamily)
 */
$id = (string) $site['public_id'];
$hidden = '<input type="hidden" name="id" value="' . e($id) . '">';
?>
<p class="navigation-row"><a class="navigation" href="<?= e($module->url()) ?>"><?= e(t('Back to sites')) ?></a></p>
<?php if (!$site['ready'] && $site['import_error'] !== ''): ?>
<p class="notice notice-error" role="alert"><?= e(t('The import stopped: %s', $site['import_error'])) ?> <?= e(t('Move this site to the trash and import the archive again.')) ?></p>
<?php elseif (!$site['ready'] && $site['import_file'] !== ''): ?>
<p class="notice notice-pending"><?= e(t('The archive is being imported by the scheduled tasks (%d %%). The site is ready when it is done.', (int) (Talea\Core\SiteImports::progress($site)['percent'] ?? 0))) ?></p>
<?php elseif (!$site['ready']): ?>
<p class="notice notice-pending"><?= e(t('This site is being set up by the scheduled tasks. It can be edited as soon as it is ready.')) ?></p>
<?php endif ?>
<form class="form" method="post" action="<?= e($module->url('save')) ?>">
<?= $csrf . $hidden ?>
<div class="row">
	<label for="name"><?= e(t('Site name')) ?></label>
	<input class="textfield" type="text" id="name" name="name" value="<?= e($site['name']) ?>" maxlength="120" required>
</div>
<fieldset>
<legend><?= e(t('State')) ?></legend>
<div class="cards-options cards-options-text">
<?php foreach (['live' => ['Live', 'Visitors see the site.'], 'paused' => ['Paused', 'Visitors see a notice; signed-in people and preview links still open it.'], 'archived' => ['Archived', 'Read-only: forms and bookings are closed, or visitors are sent to another site.']] as $value => [$label, $help]): ?>
	<label class="card-option"><input type="radio" name="status" value="<?= $value ?>"<?= $site['status'] === $value ? ' checked' : '' ?>><strong><?= e(t($label)) ?></strong><span><?= e(t($help)) ?></span></label>
<?php endforeach ?>
</div>
</fieldset>
<div class="row">
	<label for="redirect_to"><?= e(t('Archived: send visitors to')) ?></label>
	<select id="redirect_to" name="redirect_to">
		<option value=""><?= e(t('Nowhere – show the site read-only')) ?></option>
<?php foreach ($others as $o): ?>
		<option value="<?= e($o['public_id']) ?>"<?= (int) $site['redirect_to_site_id'] === (int) $o['site_id'] ? ' selected' : '' ?>><?= bdi($o['name'] !== '' ? $o['name'] : $o['slug']) ?></option>
<?php endforeach ?>
	</select>
</div>
<fieldset>
<legend><?= e(t('Card on the other sites')) ?></legend>
<p class="help"><?= e(t('The Projects element on the owner’s other sites lists live sites that are switched on here: name, summary and image, linking to the primary host name.')) ?></p>
<div class="row">
	<label for="show_in_hub"><?= e(t('Show on the other sites')) ?></label>
	<div><input type="checkbox" id="show_in_hub" name="show_in_hub" value="1"<?= $site['show_in_hub'] ? ' checked' : '' ?>></div>
</div>
<div class="row">
	<label for="card_summary"><?= e(t('Summary')) ?></label>
	<input class="textfield wide" type="text" id="card_summary" name="card_summary" value="<?= e($site['card_summary']) ?>" maxlength="300">
</div>
<div class="row">
	<label for="card_image"><?= e(t('Image')) ?></label>
	<input class="textfield wide" type="text" id="card_image" name="card_image" value="<?= e($site['card_image']) ?>" maxlength="255" data-image>
</div>
</fieldset>
<p class="buttons"><input class="btn" type="submit" value="<?= e(t('Save')) ?>"></p>
</form>

<?php $people = $site['import_people'] !== null ? json_decode((string) $site['import_people'], true) : null; if (is_array($people) && $people !== []): ?>
<h2><?= e(t('People in the archive')) ?></h2>
<p class="small-text"><?= e(t('Accounts are not imported and the news belong to the person who imported them. These are the authors and booking staff the archive names – invite them in Users.')) ?></p>
<ul>
<?php foreach ($people as $person): ?>
	<li><?= bdi((string) ($person['name'] ?? '')) ?><?= ($person['email'] ?? '') !== '' ? ' &lt;' . e((string) $person['email']) . '&gt;' : '' ?></li>
<?php endforeach ?>
</ul>
<?php endif ?>
<h2><?= e(t('Look family')) ?></h2>
<?php if ($family['children'] !== []): ?>
<p class="small-text"><?= e(t('This site hands its look down to:')) ?> <?= implode(', ', array_map(fn (array $c): string => bdi($c['name'] !== '' ? $c['name'] : $c['slug']), $family['children'])) ?>. <?= e(t('Publishing updates %d sites.', count($family['children']))) ?></p>
<?php elseif ($family['parent'] !== null): ?>
<p class="small-text"><?= e(t('This site takes its design system, classes, header and footer from %s. Change them there.', $family['parent']['name'] !== '' ? $family['parent']['name'] : $family['parent']['slug'])) ?></p>
<form class="inline" method="post" action="<?= e($module->url('look_parent')) ?>"><?= $csrf . $hidden ?><input type="hidden" name="parent" value=""><button class="navigation" type="submit"><?= e(t('Leave the look family')) ?></button></form>
<?php endif ?>
<?php if ($family['children'] === [] && $site['ready'] && $family['parents'] !== []): ?>
<form class="form" method="post" action="<?= e($module->url('look_parent')) ?>"<?= $family['hasDraft'] ? ' data-confirm="' . e(t('This site has an unpublished look. Joining a look family throws it away. Continue?')) . '"' : '' ?>>
<?= $csrf . $hidden ?><?= $family['hasDraft'] ? '<input type="hidden" name="discard_draft" value="1">' : '' ?>
<div class="row">
	<label for="parent"><?= e(t('Join the look family of')) ?></label>
	<div><select id="parent" name="parent">
<?php foreach ($family['parents'] as $p): ?>
		<option value="<?= e($p['public_id']) ?>"<?= $family['parent'] !== null && (int) $family['parent']['site_id'] === (int) $p['site_id'] ? ' selected' : '' ?>><?= bdi($p['name'] !== '' ? $p['name'] : $p['slug']) ?></option>
<?php endforeach ?>
	</select><span class="help"><?= e(t('The design system, classes, header and footer of that site are copied into this one now and whenever it publishes them. Menus, pages and the rest stay this site\'s own.')) ?></span></div>
</div>
<p class="buttons"><input class="btn" type="submit" value="<?= e(t('Join')) ?>"></p>
</form>
<?php endif ?>

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
		<form class="inline" method="post" action="<?= e($module->url('domain_check')) ?>"><?= $csrf . $hidden . $field ?><button class="navigation" type="submit"><?= e(t('Check now')) ?></button></form>
<?php endif ?>
<?php if (!$d['is_primary']): ?>
		<form class="inline" method="post" action="<?= e($module->url('domain_primary')) ?>"><?= $csrf . $hidden . $field ?><button class="navigation" type="submit"><?= e(t('Make primary')) ?></button></form>
		<form class="inline" method="post" action="<?= e($module->url('domain_redirect')) ?>"><?= $csrf . $hidden . $field ?><input type="hidden" name="redirect" value="<?= $d['redirect_to_primary'] ? '0' : '1' ?>"><button class="navigation" type="submit"><?= e($d['redirect_to_primary'] ? t('Answer on its own') : t('Redirect to the primary')) ?></button></form>
<?php endif ?>
		<form class="inline" method="post" action="<?= e($module->url('domain_remove')) ?>" data-confirm="<?= e(t('Remove this host name? The site stops answering on it.')) ?>"><?= $csrf . $hidden . $field ?><button class="navigation" type="submit"><?= e(t('Remove')) ?></button></form>
<?php endif ?>
	</td>
</tr>
<?php if ($d['verified_at'] === null && $d['kind'] === 'domain'): ?>
<tr>
	<td colspan="4" class="small-text">
<?php if ($dnsOnly): ?>
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
<form class="form" method="post" action="<?= e($module->url('domain_add')) ?>">
<?= $csrf . $hidden ?>
<div class="row">
	<label for="host"><?= e(t('Add a host name')) ?></label>
	<div><input class="textfield" type="text" id="host" name="host" maxlength="253" placeholder="www.example.com" required autocapitalize="none" spellcheck="false"><span class="help"><?= e(t('Only the name: no http://, no port, no path.')) ?></span></div>
</div>
<p class="buttons"><input class="btn" type="submit" value="<?= e(t('Add')) ?>"></p>
</form>

<?php if ((int) $site['site_id'] !== 1): ?>
<h2><?= e(t('Empty this site')) ?></h2>
<?php if ((int) $site['site_id'] === $current): ?>
<p class="small-text"><?= e(t('Deletes the pages, news, collections, forms, enquiries, bookings, statistics and import files of this site. The site, its host names, users, media, components and facts stay. An export of the site is made first.')) ?></p>
<form class="form" method="post" action="<?= e($module->url('empty')) ?>" data-confirm="<?= e(t('Empty this site? An export is made first, the content is deleted.')) ?>"><?= $csrf . $hidden ?>
<div class="row">
	<label for="confirm_name"><?= e(t('Type the name of the site to confirm')) ?></label>
	<div><input class="textfield" type="text" id="confirm_name" name="confirm_name" required autocomplete="off" placeholder="<?= e($site['name'] !== '' ? $site['name'] : $site['slug']) ?>"></div>
</div>
<div class="row">
	<label for="reset_settings"><?= e(t('Reset the settings')) ?></label>
	<div><input type="checkbox" id="reset_settings" name="reset_settings" value="1"><span class="help"><?= e(t('Also sets this site’s settings back to the defaults.')) ?></span></div>
</div>
<p class="buttons"><button class="btn danger" type="submit"><?= e(t('Empty this site')) ?></button></p>
</form>
<?php else: ?>
<p class="small-text"><?= e(t('To empty this site, open it first (Open this site in the list) and use this page there.')) ?></p>
<?php endif ?>
<?php endif ?>

<?php if ((int) $site['site_id'] !== 1 && (int) $site['site_id'] !== $current): ?>
<h2><?= e(t('Delete this site')) ?></h2>
<form class="inline" method="post" action="<?= e($module->url('trash')) ?>" data-confirm="<?= e(t('Move this site to the trash? It is deleted for good after 30 days.')) ?>"><?= $csrf . $hidden ?><button class="btn danger" type="submit"><?= e(t('Move to trash')) ?></button></form>
<?php endif ?>
