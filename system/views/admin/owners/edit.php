<?php
/**
 * @var Talea\Admin\Modules\Owners $module
 * @var string $csrf
 * @var array<string, mixed> $owner
 * @var list<array<string, mixed>> $sites  the owner's sites (not in the trash) with their `domains`
 * @var int $users  the owner's own users (the operators' rows not counted)
 */
$id = (string) $owner['public_id'];
$hidden = '<input type="hidden" name="id" value="' . e($id) . '">';
$ready = $owner['ready_at'] !== null;
$first = $sites[0] ?? null;
?>
<p class="navigation-row"><a class="navigation" href="<?= e($module->url()) ?>"><?= e(t('Back to owners')) ?></a></p>
<?php if (!$ready): ?>
<p class="notice notice-pending"><?= e(t('This owner is being set up by the scheduled tasks. Host names, invitation and opening are possible as soon as it is ready.')) ?></p>
<?php endif ?>
<form class="form" method="post" action="<?= e($module->url('save')) ?>">
<?= $csrf . $hidden ?>
<div class="row">
	<label for="name"><?= e(t('Name of the owner')) ?></label>
	<input class="textfield" type="text" id="name" name="name" value="<?= e($owner['name']) ?>" maxlength="120" required>
</div>
<div class="row">
	<label for="admin_email"><?= e(t('E-mail of the first administrator')) ?></label>
	<input class="textfield" type="email" id="admin_email" name="admin_email" value="<?= e($owner['admin_email']) ?>" maxlength="190">
</div>
<p class="buttons"><input class="btn" type="submit" value="<?= e(t('Save')) ?>"></p>
</form>

<h2><?= e(t('State')) ?></h2>
<form class="form" method="post" action="<?= e($module->url('status')) ?>">
<?= $csrf . $hidden ?>
<div class="cards-options cards-options-text">
<?php foreach (['live' => ['Live', 'All sites of the owner answer.'], 'paused' => ['Paused', 'Every site of the owner answers 503, the administration too. Nothing is deleted.'], 'archived' => ['Archived', 'Every host name of the owner answers 404. Nothing is deleted.']] as $value => [$label, $help]): ?>
	<label class="card-option"><input type="radio" name="status" value="<?= $value ?>"<?= $owner['status'] === $value ? ' checked' : '' ?>><strong><?= e(t($label)) ?></strong><span><?= e(t($help)) ?></span></label>
<?php endforeach ?>
</div>
<p class="buttons"><input class="btn" type="submit" value="<?= e(t('Save')) ?>"></p>
</form>

<?php if ($ready): ?>
<h2><?= e(t('Sites')) ?></h2>
<div class="tab-wrap"><table class="listing">
<thead><tr><th scope="col"><?= e(t('Site')) ?></th><th scope="col"><?= e(t('Primary host name')) ?></th><th scope="col"><span class="visually-hidden"><?= e(t('Actions')) ?></span></th></tr></thead>
<tbody>
<?php foreach ($sites as $s): $host = $s['domains'][0]['host'] ?? ''; ?>
<tr>
	<td class="cell-main"><span class="cell-title"><?= bdi($s['name'] !== '' ? $s['name'] : $s['slug']) ?></span></td>
	<td class="cell-weak"><?= $host !== '' ? bdi($host) : e(t('none yet')) ?></td>
	<td class="cell-actions"><?php if ($s['ready'] && $host !== '' && $owner['status'] === 'live'): ?><form class="inline" method="post" action="<?= e($module->url('handoff')) ?>"><?= $csrf . $hidden ?><input type="hidden" name="site" value="<?= e($s['public_id']) ?>"><button class="navigation" type="submit"><?= e(t('Open this site')) ?></button></form><?php endif ?></td>
</tr>
<?php endforeach ?>
</tbody>
</table></div>
<p class="small-text"><?= e(t('You sign in there as “Operator” with the rights of an administrator. The owner’s own administrators cannot change or delete that account.')) ?></p>

<?php $pending = []; foreach ($sites as $s) { foreach ($s['domains'] as $d) { if ($d['kind'] === 'domain' && $d['verified_at'] === null) { $pending[] = $d; } } } ?>
<?php if ($pending !== []): ?>
<h2><?= e(t('Host names waiting for verification')) ?></h2>
<p class="small-text"><?= e(t('The owner proves a host name with a DNS record. If it cannot, you can vouch for it here without a check.')) ?></p>
<div class="tab-wrap"><table class="listing">
<tbody>
<?php foreach ($pending as $d): ?>
<tr>
	<td class="cell-main"><span class="cell-title"><?= bdi($d['host']) ?></span></td>
	<td class="cell-actions"><form class="inline" method="post" action="<?= e($module->url('domain_verify')) ?>" data-confirm="<?= e(t('Mark this host name as verified without a check?')) ?>"><?= $csrf . $hidden ?><input type="hidden" name="host" value="<?= e($d['host']) ?>"><button class="navigation" type="submit"><?= e(t('Mark as verified')) ?></button></form></td>
</tr>
<?php endforeach ?>
</tbody>
</table></div>
<?php endif ?>

<?php if ($first !== null && $first['domains'] === []): ?>
<form class="form" method="post" action="<?= e($module->url('domain_add')) ?>">
<?= $csrf . $hidden ?>
<div class="row">
	<label for="host"><?= e(t('Add a host name')) ?></label>
	<div><input class="textfield" type="text" id="host" name="host" maxlength="253" placeholder="www.example.com" required autocapitalize="none" spellcheck="false"><span class="help"><?= e(t('The first host name of the owner’s first site. Further host names and sites are managed by the owner.')) ?></span></div>
</div>
<p class="buttons"><input class="btn" type="submit" value="<?= e(t('Add')) ?>"></p>
</form>
<?php endif ?>

<h2><?= e(t('Invitation')) ?></h2>
<p class="small-text"><?= e($users > 0 ? t('The owner has %d user(s).', $users) : t('The owner has no user yet.')) ?></p>
<form class="inline" method="post" action="<?= e($module->url('invite')) ?>"><?= $csrf . $hidden ?><button class="btn" type="submit"><?= e(t('Send the invitation')) ?></button></form>
<?php endif ?>
