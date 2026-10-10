<?php
/**
 * @var Talea\Admin\Modules\Members $module
 * @var Talea\Core\App $app
 * @var string $csrf
 * @var list<array<string, mixed>> $groups
 * @var list<array<string, mixed>> $members
 * @var array<int, list<string>> $memberGroups
 * @var string $signup
 * @var int $total
 */
?>
<p class="small-text"><?= e(t('Members sign in with a link sent to their e-mail address – there are no passwords. Restrict a page, a collection item or a news item to one or more groups in its own form (Options). Restricted content is never cached, indexed or searchable. Visitors sign in at %s.', $app->request->origin() . $app->url('member'))) ?></p>
<form class="form" method="post" action="<?= e($module->url('settings')) ?>">
<?= $csrf ?>
<fieldset>
<legend><?= e(t('Who can join')) ?></legend>
<div class="row"><span class="caption"><?= e(t('Sign-up')) ?></span><div class="options">
	<label><input type="radio" name="member_signup" value="invited"<?= $signup !== 'open' ? ' checked' : '' ?>> <?= e(t('By invitation only – you invite people below')) ?></label><br>
	<label><input type="radio" name="member_signup" value="open"<?= $signup === 'open' ? ' checked' : '' ?>> <?= e(t('Open – anyone can join with an e-mail address they confirm by the link (they belong to no group until you add them)')) ?></label>
</div></div>
</fieldset>
<p class="buttons"><input class="btn" type="submit" value="<?= e(t('Save')) ?>"></p>
</form>

<h2><?= e(t('Groups')) ?></h2>
<?php if ($groups === []): ?>
<p class="help"><?= e(t('No groups yet. Create one – for example “Clients” or “Club members” – and restrict pages to it.')) ?></p>
<?php else: ?>
<div class="tab-wrap"><table class="listing">
<thead><tr><th scope="col"><?= e(t('Group')) ?></th><th scope="col"><?= e(t('Members')) ?></th><th scope="col"><?= e(t('Restricted content')) ?></th><th scope="col"><?= e(t('Actions')) ?></th></tr></thead>
<tbody>
<?php foreach ($groups as $g): ?>
<tr>
	<td><form class="inline" method="post" action="<?= e($module->url('group_save')) ?>"><?= $csrf ?><input type="hidden" name="group_id" value="<?= e($g['public_id']) ?>"><input class="textfield" type="text" name="name" value="<?= e($g['name']) ?>" maxlength="80" required aria-label="<?= e(t('Group name')) ?>"> <button class="navigation" type="submit"><?= e(t('Rename')) ?></button></form></td>
	<td class="number"><?= (int) $g['members'] ?></td>
	<td class="number"><?= (int) $g['contents'] ?></td>
	<td class="actions"><form class="inline" method="post" action="<?= e($module->url('group_delete')) ?>" data-confirm="<?= e(t('Delete the group? Its members stay.')) ?>"><?= $csrf ?><input type="hidden" name="group_id" value="<?= e($g['public_id']) ?>"><button class="navigation danger" type="submit"><?= e(t('Delete')) ?></button></form></td>
</tr>
<?php endforeach ?>
</tbody></table></div>
<?php endif ?>
<form class="form" method="post" action="<?= e($module->url('group_save')) ?>">
<?= $csrf ?>
<div class="row"><label for="group-name"><?= e(t('New group')) ?></label><div><input class="textfield" type="text" id="group-name" name="name" maxlength="80" required> <input class="btn" type="submit" value="<?= e(t('Add group')) ?>"></div></div>
</form>

<h2><?= e(t('Invite a person')) ?></h2>
<form class="form" method="post" action="<?= e($module->url('invite')) ?>">
<?= $csrf ?>
<div class="row"><label for="invite-email"><?= e(t('E-mail address')) ?></label><input class="textfield wide" type="email" id="invite-email" name="email" maxlength="190" required></div>
<div class="row"><label for="invite-name"><?= e(t('Name')) ?></label><input class="textfield wide" type="text" id="invite-name" name="name" maxlength="100"></div>
<?php if ($groups !== []): ?>
<div class="row"><span class="caption"><?= e(t('Groups')) ?></span><div class="options">
<?php foreach ($groups as $g): ?>
	<label><input type="checkbox" name="groups[]" value="<?= e($g['public_id']) ?>"> <?= e($g['name']) ?></label><br>
<?php endforeach ?>
</div></div>
<?php endif ?>
<p class="buttons"><input class="btn" type="submit" value="<?= e(t('Send invitation')) ?>"> <span class="help"><?= e(t('The person gets an e-mail with a link that signs them in; it works once for a few days.')) ?></span></p>
</form>

<h2><?= e(t('Members')) ?> (<?= (int) $total ?>)</h2>
<?php if ($members === []): ?>
<?= $app->view->render('admin/empty', ['icon' => 'readers', 'heading' => t('No members yet.'), 'text' => t('Invite the first person above, or let visitors join themselves.')]) ?>
<?php else: ?>
<div class="tab-wrap"><table class="listing">
<thead><tr><th scope="col"><?= e(t('E-mail address')) ?></th><th scope="col"><?= e(t('Name')) ?></th><th scope="col"><?= e(t('Groups')) ?></th><th scope="col"><?= e(t('Last sign-in')) ?></th><th scope="col"><?= e(t('Actions')) ?></th></tr></thead>
<tbody>
<?php foreach ($members as $m): $mine = $memberGroups[(int) $m['member_id']] ?? []; ?>
<tr>
	<td><?= e($m['email']) ?><?= $m['confirmed_at'] === null ? ' <span class="badge">' . e(t('not confirmed')) . '</span>' : '' ?></td>
	<td colspan="3">
		<form class="inline" method="post" action="<?= e($module->url('save_member')) ?>"><?= $csrf ?><input type="hidden" name="member_id" value="<?= e($m['public_id']) ?>">
		<input class="textfield" type="text" name="name" value="<?= e($m['name']) ?>" maxlength="100" aria-label="<?= e(t('Name')) ?>">
<?php foreach ($groups as $g): ?>
		<label><input type="checkbox" name="groups[]" value="<?= e($g['public_id']) ?>"<?= in_array($g['public_id'], $mine, true) ? ' checked' : '' ?>> <?= e($g['name']) ?></label>
<?php endforeach ?>
		<button class="navigation" type="submit"><?= e(t('Save')) ?></button>
		<span class="help"><?= $m['last_login_at'] !== null ? e(format_date((string) $m['last_login_at'])) : '–' ?></span></form></td>
	<td class="actions"><form class="inline" method="post" action="<?= e($module->url('remove')) ?>" data-confirm="<?= e(t('Remove this member? They are signed out at once and lose access.')) ?>"><?= $csrf ?><input type="hidden" name="member_id" value="<?= e($m['public_id']) ?>"><button class="navigation danger" type="submit"><?= e(t('Remove')) ?></button></form></td>
</tr>
<?php endforeach ?>
</tbody></table></div>
<?php endif ?>
