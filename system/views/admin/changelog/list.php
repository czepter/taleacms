<?php
/**
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\ChangeLog $module
 * @var list<array<string, mixed>> $records
 * @var array<string, string> $users  public id => name
 * @var string $who  public id of the user the list is filtered by ('' = all)
 * @var string $by  people | claude | '' (everyone)
 * @var string $whereParts
 * @var string $search
 * @var list<string> $modules
 * @var int $pageNumber
 * @var int $pageCount
 * @var int $total
 */
// names of the admin modules (including those added later) and a few places outside modules
$names = array_map(fn (string $class): string => $class::NAME, array_combine(array_map(fn (string $class): string => $class::IDENT, Kaleta\Admin\Kernel::MODULES), Kaleta\Admin\Kernel::MODULES))
    + ['assistant' => 'Writing assistant (your own key)', 'mcp' => 'Claude (MCP)', 'claude' => 'Claude (MCP)', 'signed_in' => 'Sign in', 'account' => 'My account'];
$action = ['save' => 'save', 'delete' => 'deletion', 'delete_permanently' => 'deleted permanently', 'restore' => 'restored from trash', 'duplicate' => 'copy',
    'publish' => 'publication', 'bulk' => 'bulk action', 'upload' => 'upload', 'login' => 'sign-in', 'failed_attempt' => 'failed attempt',
    'backup' => 'backup', 'update' => 'system update', 'folder' => 'folder', 'automatic' => 'automatic menu',
    'save_variant' => 'variant saved', 'template' => 'back to default design', 'status' => 'status change', 'import' => 'import', 'build_text' => 'back to text',
    'claude_token' => 'Claude token created', 'email_change' => 'e-mail changed', 'password_change' => 'password changed', 'disconnect_app' => 'application disconnected', 'two_step_on' => 'two-step sign-in on', 'two_step_off' => 'two-step sign-in off', 'passkey_added' => 'passkey added', 'passkey_removed' => 'passkey removed', 'connect_app' => 'application connected', 'password_reset' => 'password reset', 'auto_block' => 'blocked automatically', 'auto_revoke' => 'connection revoked automatically', 'reactivate' => 'account reactivated', 'revoke_connection' => 'connection revoked',
    // Claude's (MCP) writes by tool
    'restore_build_version' => 'version restored', 'discard_draft' => 'draft discarded', 'create_collection' => 'collection created', 'update_collection' => 'collection changed', 'save_collection_item' => 'collection item saved', 'save_popup' => 'pop-up saved', 'build_from_html' => 'build changed', 'save_build' => 'build changed',
    'edit_build' => 'build changed', 'insert_section' => 'build changed', 'save_classes' => 'shared classes changed', 'upload_file' => 'file uploaded', 'update_settings' => 'settings changed', 'save_redirect' => 'redirects changed', 'trash_page' => 'page moved to trash', 'publish_build' => 'published',
    'update_design_system' => 'design system changed', 'create_page' => 'page created', 'update_page' => 'page changed', 'create_news' => 'news item created', 'update_news' => 'news item changed', 'create_category' => 'category created', 'save_menu' => 'menu changed', 'save_part_variant' => 'variant saved'];
?>
<p><a class="navigation" href="<?= e($module->url('sessions')) ?>"><?= e(t('Claude sessions')) ?></a> – <?= e(t('undo everything one Claude session changed')) ?></p>
<form method="get" action="<?= e($app->url('admin.php')) ?>" class="center small-text">
	<input type="hidden" name="module" value="changelog">
	<label><?= e(t('User:')) ?> <select name="username" data-submit-on-change><option value="0"><?= e(t('all')) ?></option>
<?php foreach ($users as $userId => $displayName): ?>
		<option value="<?= e((string) $userId) ?>"<?= $who === (string) $userId ? ' selected' : '' ?>><?= e($displayName) ?></option>
<?php endforeach ?>
	</select></label>
	<label><?= e(t('Made by:')) ?> <select name="by" data-submit-on-change><option value=""><?= e(t('people and Claude')) ?></option>
		<option value="people"<?= $by === 'people' ? ' selected' : '' ?>><?= e(t('people in the admin')) ?></option>
		<option value="claude"<?= $by === 'claude' ? ' selected' : '' ?>><?= e(t('Claude')) ?></option>
	</select></label>
	<label><?= e(t('Where:')) ?> <select name="area" data-submit-on-change><option value=""><?= e(t('everywhere')) ?></option>
<?php foreach ($modules as $m): ?>
		<option value="<?= e($m) ?>"<?= $whereParts === $m ? ' selected' : '' ?>><?= e(isset($names[$m]) ? t($names[$m]) : $m) ?></option>
<?php endforeach ?>
	</select></label>
	<label><?= e(t('Detail contains:')) ?> <input class="textfield" type="search" name="search" value="<?= e($search) ?>" size="18"></label>
	<input class="btn" type="submit" value="<?= e(t('Filter')) ?>"> (<?= e(t('Total:')) ?> <?= $total ?>)
</form>
<div class="tab-wrap">
<table class="listing">
<thead><tr><th scope="col"><?= e(t('When')) ?></th><th scope="col"><?= e(t('Who')) ?></th><th scope="col"><?= e(t('Where')) ?></th><th scope="col"><?= e(t('What')) ?></th><th scope="col"><?= e(t('Detail')) ?></th></tr></thead>
<tbody>
<?php foreach ($records as $z): ?>
<tr<?= $z['action'] === 'failed_attempt' ? ' class="unpublished"' : '' ?>>
	<td class="number"><?= e(format_date($z['created_at'], true)) ?></td>
	<td><?= e($z['user_name'] !== '' ? $z['user_name'] : '–') ?><?php if (($z['via'] ?? '') !== ''): ?> <span class="badge" title="<?= e(t('Made by Claude through the connection %s', $z['via'])) ?>"><?= e(t('Claude: %s', $z['via'])) ?></span><?php endif ?></td>
	<td><?= e(isset($names[$z['module']]) ? t($names[$z['module']]) : $z['module']) ?></td>
	<td><?= e(t($action[$z['action']] ?? $z['action'])) ?></td>
	<td><?= e($z['description']) ?><?php if (($z['reason'] ?? '') !== ''): ?><br><span class="small-text"><?= e(t('Why: %s', $z['reason'])) ?></span><?php endif ?></td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<?php if ($pageCount > 1): ?>
<p class="pagination">
<?php for ($s = max(1, $pageNumber - 5); $s <= min($pageCount, $pageNumber + 5); $s++): ?>
	<?= $s === $pageNumber ? '<strong>[' . $s . ']</strong>' : '<a href="' . e($module->url('', array_filter(['username' => $who ?: null, 'by' => $by, 'area' => $whereParts, 'search' => $search, 'page' => $s]))) . '">' . $s . '</a>' ?>
<?php endfor ?>
</p>
<?php endif ?>
<p class="small-text"><?= e(t('The log is kept for six months.')) ?></p>
