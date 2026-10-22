<?php
/**
 * One redirect on its own page: a new one (prefilled with `?from=` from the 404 list or the audit) or an existing one.
 *
 * @var Talea\Core\App $app
 * @var Talea\Admin\Modules\Redirects $module
 * @var string $csrf
 * @var ?array<string, mixed> $edit the redirect (null = a new one)
 * @var string $fromUrl pre-filled old address
 */
$u = $edit;
$from = $u !== null ? '/' . $u['from_path'] : ($fromUrl !== '' ? '/' . ltrim($fromUrl, '/') : '');
$to = $u === null ? '' : (preg_match('#^https?://#i', $u['to_path']) ? $u['to_path'] : '/' . $u['to_path']);
?>
<p class="navigation-row"><a class="navigation" href="<?= e($module->url()) ?>">← <?= e(t('Redirects')) ?></a></p>
<form class="form" method="post" action="<?= e($module->url('save')) ?>">
<?= $csrf ?><input type="hidden" name="redirect_id" value="<?= e($u['public_id'] ?? '') ?>">
<fieldset><legend><?= e(t($u !== null ? 'Edit redirect' : 'New redirect')) ?></legend>
<div class="row"><label for="from_path"><?= e(t('Old address')) ?></label><div><input class="textfield wide" type="text" id="from_path" name="from_path" value="<?= e($from) ?>" maxlength="255" required placeholder="<?= e(t('/old-page.html')) ?>"><span class="help"><?= e(t('A path on this site that no longer exists.')) ?></span></div></div>
<div class="row"><label for="to_path"><?= e(t('Redirect to')) ?></label><div><input class="textfield wide" type="text" id="to_path" name="to_path" value="<?= e($to) ?>" maxlength="255" required placeholder="<?= e(t('/new-address or https://…')) ?>"<?= $u === null && $from !== '' ? ' autofocus' : '' ?>></div></div>
<div class="row"><label for="type"><?= e(t('Type')) ?></label><div><select id="type" name="type">
	<option value="301"><?= e(t('Permanent')) ?></option>
	<option value="302"<?= (int) ($u['type'] ?? 301) === 302 ? ' selected' : '' ?>><?= e(t('Temporary')) ?></option>
</select><span class="help"><?= e(t('Permanent tells search engines that the page has moved for good. Temporary suits a promotion or a seasonal offer.')) ?></span></div></div>
</fieldset>
<p class="buttons"><input class="btn" type="submit" value="<?= e(t('Save')) ?>"> <a class="navigation" href="<?= e($module->url()) ?>"><?= e(t('Cancel')) ?></a>
<?php if ($u !== null): ?><button class="navigation danger buttons-end" type="submit" form="redirect-delete" data-confirm="<?= e(t('Delete the redirect? The old address will then end with a 404 error.')) ?>"><?= e(t('Delete')) ?></button><?php endif ?></p>
</form>
<?php if ($u !== null): ?>
<form id="redirect-delete" method="post" action="<?= e($module->url('delete')) ?>"><?= $csrf ?><input type="hidden" name="redirect_id" value="<?= e($u['public_id']) ?>"><input type="hidden" name="title" value="<?= e('/' . $u['from_path']) ?>"></form>
<?php endif ?>
