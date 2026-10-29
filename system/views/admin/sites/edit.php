<?php
/**
 * @var Talea\Admin\Modules\Sites $module
 * @var string $csrf
 * @var array<string, mixed> $site  the row with `domains`, `ready`
 * @var list<array<string, mixed>> $others  the other sites that are set up (targets of an archived site)
 * @var int $current
 * @var string $host  the host name of this request
 */
$id = (string) $site['public_id'];
$hidden = '<input type="hidden" name="id" value="' . e($id) . '">';
?>
<p class="navigation-row"><a class="navigation" href="<?= e($module->url()) ?>"><?= e(t('Back to sites')) ?></a></p>
<?php if (!$site['ready']): ?>
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

<h2><?= e(t('Host names')) ?></h2>
<p class="small-text"><?= e(t('The site answers to these host names. Point each one to this installation in the DNS. The primary one is where people are sent when they open the site from here.')) ?></p>
<?php if ($site['domains'] === []): ?>
<p class="small-text"><?= e(t('No host name yet.')) ?></p>
<?php else: ?>
<div class="tab-wrap"><table class="listing">
<thead><tr><th scope="col"><?= e(t('Host name')) ?></th><th scope="col"><?= e(t('Role')) ?></th><th scope="col"><span class="visually-hidden"><?= e(t('Actions')) ?></span></th></tr></thead>
<tbody>
<?php foreach ($site['domains'] as $d): $field = '<input type="hidden" name="host" value="' . e($d['host']) . '">'; ?>
<tr>
	<td class="cell-main"><span class="cell-title"><?= bdi($d['host']) ?></span><?= $d['host'] === $host ? '<span class="cell-sub">' . e(t('You are using this host name')) . '</span>' : '' ?></td>
	<td><?= $d['is_primary'] ? '<span class="badge badge-published">' . e(t('Primary')) . '</span>' : ($d['redirect_to_primary'] ? '<span class="badge">' . e(t('Redirects to the primary')) . '</span>' : '<span class="badge">' . e(t('Alias')) . '</span>') ?></td>
	<td class="cell-actions">
<?php if (!$d['is_primary']): ?>
		<form class="inline" method="post" action="<?= e($module->url('domain_primary')) ?>"><?= $csrf . $hidden . $field ?><button class="navigation" type="submit"><?= e(t('Make primary')) ?></button></form>
		<form class="inline" method="post" action="<?= e($module->url('domain_redirect')) ?>"><?= $csrf . $hidden . $field ?><input type="hidden" name="redirect" value="<?= $d['redirect_to_primary'] ? '0' : '1' ?>"><button class="navigation" type="submit"><?= e($d['redirect_to_primary'] ? t('Answer on its own') : t('Redirect to the primary')) ?></button></form>
<?php endif ?>
		<form class="inline" method="post" action="<?= e($module->url('domain_remove')) ?>" data-confirm="<?= e(t('Remove this host name? The site stops answering on it.')) ?>"><?= $csrf . $hidden . $field ?><button class="navigation" type="submit"><?= e(t('Remove')) ?></button></form>
	</td>
</tr>
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

<?php if ((int) $site['site_id'] !== 1 && (int) $site['site_id'] !== $current): ?>
<h2><?= e(t('Delete this site')) ?></h2>
<form class="inline" method="post" action="<?= e($module->url('trash')) ?>" data-confirm="<?= e(t('Move this site to the trash? It is deleted for good after 30 days.')) ?>"><?= $csrf . $hidden ?><button class="btn danger" type="submit"><?= e(t('Move to trash')) ?></button></form>
<?php endif ?>
