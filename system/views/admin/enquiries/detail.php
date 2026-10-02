<?php
/**
 * Enquiry detail.
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\Enquiries $module
 * @var string $csrf
 * @var array<string, mixed> $p
 * @var list<array{0:string, 1:string, 2?:string}> $data  [label, value, attachment path]
 * @var array<int, string> $users
 */
use Kaleta\Admin\Modules\Enquiries;

?>
<p class="navigace-radek"><a class="navigace" href="<?= e($module->url()) ?>">← <?= e(t('All enquiries')) ?></a></p>
<div class="formular">
<dl class="poptavka">
	<dt><?= e(t('Received')) ?></dt><dd><?= e(format_date($p['datum'], true)) ?> · <?= e($p['formular']) ?><?php if ($p['stranka'] !== ''): ?> · <a href="<?= e($p['stranka']) ?>" target="_blank" rel="noopener"><?= e($p['stranka']) ?></a><?php endif ?></dd>
<?php if (($p['tema'] ?? '') !== ''): ?>
	<dt><?= e(t('Topic')) ?></dt><dd><?= $p['stranka'] !== '' ? '<a href="' . e($p['stranka']) . '" target="_blank" rel="noopener">' . e($p['tema']) . '</a>' : e($p['tema']) ?></dd>
<?php endif ?>
<?php if (($p['vstup'] ?? '') !== ''): ?>
	<dt><?= e(t('First page of the visit')) ?></dt><dd><?= e($p['vstup']) ?></dd>
<?php endif ?>
<?php if (($p['odkud'] ?? '') !== ''): ?>
	<dt><?= e(t('Came from')) ?></dt><dd><?= e($p['odkud']) ?></dd>
<?php endif ?>
<?php if (($p['kampan'] ?? '') !== ''): ?>
	<dt><?= e(t('Campaign')) ?></dt><dd><?= e(Kaleta\Front\Forms::campaignText($p['kampan'])) ?></dd>
<?php endif ?>
	<dt><?= e(t('Status')) ?></dt><dd><?= e(t(Enquiries::STATUSES[(int) $p['stav']])) ?></dd>
<?php foreach ($data as $i => $d): [$labelText, $value] = $d; ?>
	<dt><?= e($labelText) ?></dt><dd><?= $value === '' ? '<span class="napoveda">—</span>' : (isset($d[2]) ? '<a href="' . e($module->url('attachment', ['id' => (int) $p['idp'], 'pole' => $i])) . '">' . e($value) . '</a>' : nl2br(e($value))) ?></dd>
<?php endforeach ?>
</dl>
<form method="post" action="<?= e($module->url('note')) ?>">
	<?= $csrf ?><input type="hidden" name="idp" value="<?= (int) $p['idp'] ?>">
	<div class="radek"><label for="prirazeno"><?= e(t('Handled by')) ?></label><select id="prirazeno" name="prirazeno"><option value="0">—</option>
<?php foreach ($users as $userId => $displayName): ?>
		<option value="<?= (int) $userId ?>"<?= (int) ($p['prirazeno'] ?? 0) === (int) $userId ? ' selected' : '' ?>><?= e($displayName) ?></option>
<?php endforeach ?>
	</select></div>
	<div class="radek"><label for="poznamka"><?= e(t('Internal note')) ?></label><div><textarea class="textbox nizky" id="poznamka" name="poznamka" rows="3"><?= e((string) ($p['poznamka'] ?? '')) ?></textarea>
		<span class="napoveda"><?= e(t('Only administration users see it – e.g. what you offered the customer.')) ?></span></div></div>
	<p class="tlacitka"><button class="navigace" type="submit"><?= e(t('Save note')) ?></button></p>
</form>
<div class="tlacitka">
<?php if ($p['email'] !== ''): ?>
	<a class="tl" href="mailto:<?= e($p['email']) ?>?subject=<?= e(rawurlencode('Re: ' . $p['formular'])) ?>"><?= e(t('Reply by email')) ?></a>
<?php endif ?>
	<form class="vradku" method="post" action="<?= e($module->url('status')) ?>"><?= $csrf ?><input type="hidden" name="idp" value="<?= (int) $p['idp'] ?>"><input type="hidden" name="stav" value="<?= (int) $p['stav'] === 2 ? 1 : 2 ?>"><button class="tl<?= (int) $p['stav'] === 2 ? ' tl-vedlejsi' : '' ?>" type="submit"><?= e(t((int) $p['stav'] === 2 ? 'Reopen' : 'Označit jako vyřízenou')) ?></button></form>
	<form class="vradku" method="post" action="<?= e($module->url('delete')) ?>" data-potvrdit="<?= e(t('Really delete this enquiry?')) ?>"><?= $csrf ?><input type="hidden" name="idp" value="<?= (int) $p['idp'] ?>"><button class="navigace nebezpecne" type="submit"><?= e(t('Smazat')) ?></button></form>
</div>
</div>
