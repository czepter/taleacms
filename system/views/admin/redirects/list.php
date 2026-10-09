<?php
/**
 * @var Kaleta\Admin\Modules\Redirects $module
 * @var string $csrf
 * @var list<array<string, mixed>> $records
 * @var list<array<string, mixed>> $notFound  urls that ended with a 404 error in the last 60 days
 * @var array<string, array{to: string, score: int}> $suggestions  the page each missing address most likely meant (Core\RedirectMatcher)
 * @var bool $autoOn  the daily job creates the sure redirects by itself
 * @var int $threshold  the score a candidate needs for that
 * @var string $fromUrl  pre-filled old url
 * @var ?array<string, mixed> $edit  the record being edited
 * @var int $total
 * @var int $pageNumber
 * @var int $pageCount
 * @var string $search
 */
$u = $edit;
$path = fn (string $a): string => preg_match('#^https?://#i', $a) ? $a : '/' . $a;
?>
<form class="formular" method="post" action="<?= e($module->url('save')) ?>" id="upravit">
<?= $csrf ?>
<input type="hidden" name="idp" value="<?= (int) ($u['idp'] ?? 0) ?>">
<div class="radek"><label for="z_adresy"><?= e(t('Old address')) ?></label><div><input class="textpole siroke" type="text" id="z_adresy" name="from_path" value="<?= e($u !== null ? '/' . $u['from_path'] : ($fromUrl !== '' ? '/' . ltrim($fromUrl, '/') : '')) ?>" maxlength="255" required placeholder="<?= e(t('/old-page.html')) ?>"><span class="napoveda"><?= e(t('A path on this site that no longer exists.')) ?></span></div></div>
<div class="radek"><label for="na_adresu"><?= e(t('Redirect to')) ?></label><div><input class="textpole siroke" type="text" id="na_adresu" name="to_path" value="<?= e($u !== null ? $path($u['to_path']) : '') ?>" maxlength="255" required placeholder="<?= e(t('/new-address or https://…')) ?>"></div></div>
<div class="radek"><label for="typ"><?= e(t('Typ')) ?></label><select id="typ" name="type">
	<option value="301"><?= e(t('permanent (301) – the page has moved')) ?></option>
	<option value="302"<?= (int) ($u['type'] ?? 301) === 302 ? ' selected' : '' ?>><?= e(t('temporary (302) – a promotion or seasonal offer')) ?></option>
</select></div>
<p class="tlacitka"><input class="tl" type="submit" value="<?= e(t($u !== null ? 'Save changes' : 'Add redirect')) ?>"><?= $u !== null ? ' <a class="navigace" href="' . e($module->url()) . '">' . e(t('Cancel')) . '</a>' : '' ?></p>
</form>
<form method="get" action="<?= e($app->url('admin.php')) ?>" class="stred smltxt"><input type="hidden" name="module" value="redirects">
	<label><?= e(t('Address contains:')) ?> <input class="textpole" type="search" name="search" value="<?= e($search) ?>" size="24"></label> <input class="tl" type="submit" value="<?= e(t('Filtrovat')) ?>"> (<?= e(t('Total:')) ?> <?= $total ?>)</form>
<p class="smltxt"><?= e(t('A redirect is only used when nothing exists at the old address. It is created automatically when the address of a page, news item or category changes.')) ?></p>
<?php if ($records === []): ?>
<?= $app->view->render('admin/empty', ['icon' => 'presmerovani', 'heading' => t('No redirects yet.'), 'text' => t('Nothing to do – when you change the address of a page or news item, a redirect is created automatically.')]) ?>
<?php endif ?>
<?php if ($records !== []): ?>
<div class="tab-obal">
<table class="vypis">
<thead><tr><th scope="col"><?= e(t('Old address')) ?></th><th scope="col"><?= e(t('Target')) ?></th><th scope="col"><?= e(t('Použito')) ?></th><th scope="col"><?= e(t('Vytvořeno')) ?></th><th scope="col"><?= e(t('Actions')) ?></th></tr></thead>
<tbody>
<?php foreach ($records as $z): ?>
<tr>
	<td>/<?= e($z['from_path']) ?></td>
	<td><?= e($path($z['to_path'])) ?><?= (int) ($z['type'] ?? 301) === 302 ? ' <span class="stitek">302</span>' : '' ?><?= $z['auto_score'] !== null ? ' <span class="stitek" title="' . e(t('Created by the site itself from an address visitors could not find. Delete it to undo.')) . '">' . e(t('automatic, score %d', (int) $z['auto_score'])) . '</span>' : '' ?></td>
	<td class="cislo"><?= (int) $z['pocet'] ?>×</td>
	<td class="cislo"><?= e(format_date($z['created_at'])) ?></td>
	<td class="akce"><a href="<?= e($module->url('', ['edit' => (int) $z['idp']])) ?>#upravit"><?= e(t('Edit')) ?></a> ·
		<form class="vradku" method="post" action="<?= e($module->url('delete')) ?>" data-potvrdit="<?= e(t('Delete the redirect? The old address will then end with a 404 error.')) ?>"><?= $csrf ?><input type="hidden" name="idp" value="<?= (int) $z['idp'] ?>"><input type="hidden" name="title" value="<?= e('/' . $z['from_path']) ?>"><button class="navigace nebezpecne" type="submit"><?= e(t('Smazat')) ?></button></form></td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
<?php if ($pageCount > 1): ?>
<p class="strankovani">
<?php for ($s = 1; $s <= $pageCount; $s++): ?>
	<?= $s === $pageNumber ? '<strong>[' . $s . ']</strong>' : '<a href="' . e($module->url('', array_filter(['search' => $search, 'page' => $s]))) . '">' . $s . '</a>' ?>
<?php endfor ?>
</p>
<?php endif ?>
<?php endif ?>
<h2 id="nenalezeno"><?= e(t('Addresses visitors could not find (404)')) ?></h2>
<form class="formular" method="post" action="<?= e($module->url('settings')) ?>" id="automaticky">
<?= $csrf ?>
<div class="radek"><label for="redirect_auto"><?= e(t('By themselves')) ?></label><div><label><input type="checkbox" id="redirect_auto" name="redirect_auto" value="1"<?= $autoOn ? ' checked' : '' ?>> <?= e(t('Create redirects for missing addresses by themselves')) ?></label>
	<span class="napoveda"><?= e(t('Once a day the site redirects an address visitors could not find to the page it is sure they meant (the same address written differently, moved to another section or language). Such a redirect is marked automatic; deleting it undoes it. Less certain candidates stay below as suggestions.')) ?></span></div></div>
<div class="radek"><label for="redirect_auto_threshold"><?= e(t('Needed score')) ?></label><div><input class="textpole" type="number" id="redirect_auto_threshold" name="redirect_auto_threshold" value="<?= $threshold ?>" min="50" max="100" size="4"> <span class="napoveda"><?= e(t('0–100; 100 = the same address written differently, 95 = another section or language, 90 = the same last part of the address, lower = a similar address.')) ?></span></div></div>
<p class="tlacitka"><input class="tl" type="submit" value="<?= e(t('Save settings')) ?>"></p>
</form>
<?php if ($notFound === []): ?>
<p class="smltxt"><?= e(t('Nothing to do – no address ended with “page not found” repeatedly in the last 60 days.')) ?></p>
<?php else: ?>
<p><?= e(t('Visitors came to these addresses and found nothing – usually an old link from another site, a search engine or a typo. For each one choose:')) ?></p>
<ul class="smltxt">
	<li><?= e(t('Redirect – the old address leads to the page that replaced it (the form above is filled in, add the target).')) ?></li>
	<li><?= e(t('Create redirect – one click accepts the page the site thinks the visitor meant (the score says how sure it is).')) ?></li>
	<li><?= e(t('Ignore – nothing replaces it, or it is a bot probing for other systems. It will not come back.')) ?></li>
</ul>
<div class="tab-obal"><table class="vypis">
<thead><tr><th scope="col"><?= e(t('Adresa')) ?></th><th scope="col"><?= e(t('Hits')) ?></th><th scope="col"><?= e(t('Last')) ?></th><th scope="col"><?= e(t('Probably meant')) ?></th><th scope="col"><?= e(t('Actions')) ?></th></tr></thead>
<tbody>
<?php foreach ($notFound as $n): $tip = $suggestions[$n['path']] ?? null; ?>
<tr><td>/<?= e($n['path']) ?></td><td class="cislo"><?= (int) $n['pocet'] ?>×</td><td class="cislo"><?= e(format_date($n['last_seen_at'])) ?></td>
	<td><?php if ($tip !== null): ?>/<?= e($tip['to']) ?> <span class="stitek"><?= e(t('score %d', $tip['score'])) ?></span><?php else: ?><span class="smltxt"><?= e(t('no similar page')) ?></span><?php endif ?></td>
	<td class="akce"><?php if ($tip !== null): ?><form class="vradku" method="post" action="<?= e($module->url('save')) ?>"><?= $csrf ?><input type="hidden" name="from_path" value="<?= e('/' . $n['path']) ?>"><input type="hidden" name="to_path" value="<?= e('/' . $tip['to']) ?>"><input type="hidden" name="type" value="301"><button class="navigace" type="submit"><?= e(t('Create redirect')) ?></button></form> · <?php endif ?><a href="<?= e($module->url('', ['z' => $n['path']])) ?>#upravit"><?= e(t('Redirect')) ?></a> ·
		<form class="vradku" method="post" action="<?= e($module->url('ignore')) ?>"><?= $csrf ?><input type="hidden" name="path" value="<?= e($n['path']) ?>"><button class="navigace" type="submit"><?= e(t('Ignore')) ?></button></form></td></tr>
<?php endforeach ?>
</tbody></table></div>
<form method="post" action="<?= e($module->url('ignore_all')) ?>" data-potvrdit="<?= e(t('Ignore all these addresses? The warning on the start screen goes away until a new address appears.')) ?>"><?= $csrf ?><p><button class="navigace" type="submit"><?= e(t('Ignore all')) ?></button></p></form>
<?php endif ?>
