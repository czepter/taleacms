<?php
/**
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\ChangeLog $module
 * @var list<array<string, mixed>> $records
 * @var array<int, string> $users
 * @var int $who
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
    + ['asistent' => 'Writing assistant (your own key)', 'mcp' => 'Claude (MCP)', 'claude' => 'Claude (MCP)', 'prihlaseni' => 'Přihlášení', 'ucet' => 'My account'];
$action = ['uloz' => 'uložení', 'smaz' => 'smazání', 'smaz_natrvalo' => 'deleted permanently', 'obnov' => 'restored from trash', 'duplikuj' => 'kopie',
    'vydat' => 'vydání', 'hromadne' => 'bulk action', 'nahraj' => 'nahrání', 'login' => 'přihlášení', 'neuspech' => 'failed attempt',
    'zalohuj' => 'záloha', 'aktualizuj' => 'system update', 'slozka' => 'složka', 'automaticky' => 'automatic menu',
    'uloz_variantu' => 'variant saved', 'sablona' => 'back to default design', 'status' => 'status change', 'import' => 'import', 'stavba_text' => 'back to text',
    'claude_token' => 'Claude token created', 'vytvořen token pro Claude' => 'Claude token created', 'auto_block' => 'blocked automatically', 'auto_revoke' => 'connection revoked automatically', 'reactivate' => 'account reactivated', 'revoke_connection' => 'connection revoked',
    // Claude's (MCP) writes by tool
    'obnov_verzi' => 'version restored', 'zahod_koncept' => 'draft discarded', 'vytvor_kolekci' => 'collection created', 'uprav_kolekci' => 'collection changed', 'uloz_polozku_kolekce' => 'collection item saved', 'uloz_popup' => 'pop-up saved', 'stavba_z_html' => 'build changed', 'stavba_uloz' => 'build changed',
    'stavba_uprav' => 'build changed', 'vloz_sekci' => 'build changed', 'uloz_tridy' => 'shared classes changed', 'nahraj_soubor' => 'file uploaded', 'uprav_nastaveni' => 'settings changed', 'uloz_presmerovani' => 'redirects changed', 'smaz_stranku' => 'page moved to trash', 'publikuj_stavbu' => 'publikování',
    'uprav_design_system' => 'design system changed', 'vytvor_stranku' => 'page created', 'uprav_stranku' => 'page changed', 'vytvor_novinku' => 'news item created', 'uprav_novinku' => 'news item changed', 'vytvor_kategorii' => 'category created', 'uloz_menu' => 'menu changed'];
// actions logged since 1.4 have English names (the Czech ones above are in older records)
foreach (['update' => 'aktualizuj', 'automatic' => 'automaticky', 'duplicate' => 'duplikuj', 'bulk' => 'hromadne', 'upload' => 'nahraj', 'restore' => 'obnov', 'template' => 'sablona',
    'folder' => 'slozka', 'delete' => 'smaz', 'delete_permanently' => 'smaz_natrvalo', 'status' => 'status', 'build_text' => 'stavba_text', 'save' => 'uloz', 'save_variant' => 'uloz_variantu', 'backup' => 'zalohuj'] as $new => $old) {
    $action[$new] ??= $action[$old];
}
?>
<p><a class="navigace" href="<?= e($module->url('sessions')) ?>"><?= e(t('Claude sessions')) ?></a> – <?= e(t('undo everything one Claude session changed')) ?></p>
<form method="get" action="<?= e($app->url('admin.php')) ?>" class="stred smltxt">
	<input type="hidden" name="module" value="changelog">
	<label><?= e(t('User:')) ?> <select name="username" data-odeslat-pri-zmene><option value="0"><?= e(t('všichni')) ?></option>
<?php foreach ($users as $userId => $displayName): ?>
		<option value="<?= (int) $userId ?>"<?= $who === (int) $userId ? ' selected' : '' ?>><?= e($displayName) ?></option>
<?php endforeach ?>
	</select></label>
	<label><?= e(t('Made by:')) ?> <select name="by" data-odeslat-pri-zmene><option value=""><?= e(t('people and Claude')) ?></option>
		<option value="people"<?= $by === 'people' ? ' selected' : '' ?>><?= e(t('people in the admin')) ?></option>
		<option value="claude"<?= $by === 'claude' ? ' selected' : '' ?>><?= e(t('Claude')) ?></option>
	</select></label>
	<label><?= e(t('Where:')) ?> <select name="area" data-odeslat-pri-zmene><option value=""><?= e(t('everywhere')) ?></option>
<?php foreach ($modules as $m): ?>
		<option value="<?= e($m) ?>"<?= $whereParts === $m ? ' selected' : '' ?>><?= e(isset($names[$m]) ? t($names[$m]) : $m) ?></option>
<?php endforeach ?>
	</select></label>
	<label><?= e(t('Detail contains:')) ?> <input class="textpole" type="search" name="search" value="<?= e($search) ?>" size="18"></label>
	<input class="tl" type="submit" value="<?= e(t('Filtrovat')) ?>"> (<?= e(t('Total:')) ?> <?= $total ?>)
</form>
<div class="tab-obal">
<table class="vypis">
<thead><tr><th scope="col"><?= e(t('When')) ?></th><th scope="col"><?= e(t('Who')) ?></th><th scope="col"><?= e(t('Where')) ?></th><th scope="col"><?= e(t('What')) ?></th><th scope="col"><?= e(t('Podrobnost')) ?></th></tr></thead>
<tbody>
<?php foreach ($records as $z): ?>
<tr<?= $z['action'] === 'neuspech' ? ' class="nevydany"' : '' ?>>
	<td class="cislo"><?= e(format_date($z['created_at'], true)) ?></td>
	<td><?= e($z['user_name'] !== '' ? $z['user_name'] : '–') ?><?php if (($z['via'] ?? '') !== ''): ?> <span class="stitek" title="<?= e(t('Made by Claude through the connection %s', $z['via'])) ?>"><?= e(t('Claude: %s', $z['via'])) ?></span><?php endif ?></td>
	<td><?= e(isset($names[$z['module']]) ? t($names[$z['module']]) : $z['module']) ?></td>
	<td><?= e(t($action[$z['action']] ?? $z['action'])) ?></td>
	<td><?= e($z['description']) ?><?php if (($z['reason'] ?? '') !== ''): ?><br><span class="smltxt"><?= e(t('Why: %s', $z['reason'])) ?></span><?php endif ?></td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<?php if ($pageCount > 1): ?>
<p class="strankovani">
<?php for ($s = max(1, $pageNumber - 5); $s <= min($pageCount, $pageNumber + 5); $s++): ?>
	<?= $s === $pageNumber ? '<strong>[' . $s . ']</strong>' : '<a href="' . e($module->url('', array_filter(['username' => $who ?: null, 'by' => $by, 'area' => $whereParts, 'search' => $search, 'page' => $s]))) . '">' . $s . '</a>' ?>
<?php endfor ?>
</p>
<?php endif ?>
<p class="smltxt"><?= e(t('The log is kept for six months.')) ?></p>
