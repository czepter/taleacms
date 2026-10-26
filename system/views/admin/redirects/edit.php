<?php
/**
 * One redirect on its own page: a new one (prefilled with `?from=` from the 404 list or the audit) or an existing one.
 *
 * @var Talea\Core\App $app
 * @var Talea\Admin\Modules\Redirects $module
 * @var string $csrf
 * @var ?array<string, mixed> $edit the redirect (null = a new one)
 * @var string $fromUrl pre-filled old address
 * @var ?array{match_type: string, from_path: string, to_path: string, type: int, active: bool} $typed what a refused save sent, shown as typed
 */
$u = $edit;
$typed ??= null;
$match = $typed['match_type'] ?? $u['match_type'] ?? 'exact';
$from = $typed !== null ? $typed['from_path'] : ($u !== null ? ($match === 'regex' ? $u['from_path'] : '/' . $u['from_path']) : ($fromUrl !== '' ? '/' . ltrim($fromUrl, '/') : ''));
$matches = ['exact' => 'Exactly this address', 'starts' => 'Starts with', 'ends' => 'Ends with', 'contains' => 'Contains', 'regex' => 'Regular expression'];
$types = [301 => 'Permanent (301)', 302 => 'Temporary (302)', 307 => 'Temporary, keeps the request method (307)', 410 => 'Gone (410)', 451 => 'Unavailable for legal reasons (451)'];
$to = $typed !== null ? $typed['to_path'] : ($u === null ? '' : (preg_match('#^https?://#i', $u['to_path']) ? $u['to_path'] : '/' . $u['to_path']));
$type = (int) ($typed['type'] ?? $u['type'] ?? 301);
$active = $typed !== null ? $typed['active'] : (int) ($u['active'] ?? 1) === 1;
?>
<p class="navigation-row"><a class="navigation" href="<?= e($module->url()) ?>">← <?= e(t('Redirects')) ?></a></p>
<form class="form" method="post" action="<?= e($module->url('save')) ?>">
<?= $csrf ?><input type="hidden" name="redirect_id" value="<?= e($u['public_id'] ?? '') ?>">
<fieldset><legend><?= e(t($u !== null ? 'Edit redirect' : 'New redirect')) ?></legend>
<div class="row"><label for="match"><?= e(t('Match')) ?></label><div><select id="match" name="match">
<?php foreach ($matches as $key => $label): ?>
	<option value="<?= e($key) ?>"<?= $match === $key ? ' selected' : '' ?>><?= e(t($label)) ?></option>
<?php endforeach ?>
</select><span class="help"><?= e(t('An exact address wins over the other kinds. With a regular expression, $1 in the target is the first captured part. Starts with: a target ending in a slash keeps the rest of the address.')) . ' ' . e(t('The kinds other than an exact address are checked only when nothing exists at the address, so they cannot hide a live page.')) ?></span></div></div>
<div class="row"><label for="from_path"><?= e(t('Old address')) ?></label><div>
<?php if ($u === null): ?><textarea class="textfield wide" id="from_path" name="from_path" rows="3" required placeholder="<?= e(t('/old-page.html')) ?>"><?= e($from) ?></textarea><span class="help"><?= e(t('One address per line; each becomes its own redirect.')) ?></span>
<?php else: ?><input class="textfield wide" type="text" id="from_path" name="from_path" value="<?= e($from) ?>" maxlength="255" required placeholder="<?= e(t('/old-page.html')) ?>"><span class="help"><?= e(t('A path on this site that no longer exists.')) ?></span><?php endif ?></div></div>
<div class="row"><label for="to_path"><?= e(t('Redirect to')) ?></label><div><input class="textfield wide" type="text" id="to_path" name="to_path" value="<?= e($to) ?>" maxlength="255" placeholder="<?= e(t('/new-address or https://…')) ?>"<?= $u === null && $from !== '' ? ' autofocus' : '' ?>><span class="help"><?= e(t('Not needed for Gone (410) and Unavailable for legal reasons (451).')) ?></span></div></div>
<div class="row"><label for="type"><?= e(t('Type')) ?></label><div><select id="type" name="type">
<?php foreach ($types as $code => $label): ?>
	<option value="<?= $code ?>"<?= $type === $code ? ' selected' : '' ?>><?= e(t($label)) ?></option>
<?php endforeach ?>
</select><span class="help"><?= e(t('Permanent tells search engines that the page has moved for good. Temporary suits a promotion or a seasonal offer.')) ?></span></div></div>
<div class="row"><label for="active"><?= e(t('Active')) ?></label><div><input type="hidden" name="active" value="0"><label><input type="checkbox" id="active" name="active" value="1"<?= $active ? ' checked' : '' ?>> <?= e(t('The rule is in use')) ?></label></div></div>
</fieldset>
<p class="buttons"><input class="btn" type="submit" value="<?= e(t('Save')) ?>"> <a class="navigation" href="<?= e($module->url()) ?>"><?= e(t('Cancel')) ?></a>
<?php if ($u !== null): ?><button class="navigation danger buttons-end" type="submit" form="redirect-delete" data-confirm="<?= e(t('Delete the redirect? The old address will then end with a 404 error.')) ?>"><?= e(t('Delete')) ?></button><?php endif ?></p>
</form>
<?php if ($u !== null): ?>
<form id="redirect-delete" method="post" action="<?= e($module->url('delete')) ?>"><?= $csrf ?><input type="hidden" name="redirect_id" value="<?= e($u['public_id']) ?>"><input type="hidden" name="title" value="<?= e('/' . $u['from_path']) ?>"></form>
<?php endif ?>
