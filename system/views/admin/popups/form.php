<?php
/**
 * Popup settings: type, trigger, frequency and display rules.
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\Popups $module
 * @var string $csrf
 * @var array<string, mixed> $p
 * @var list<array<string, mixed>> $pages
 * @var list<array<string, mixed>> $collection
 * @var array<string, string> $languages
 */
use Kaleta\Builder\Popups;

$rules = $p['rules'];
$selection = function (string $displayName, array $options, string $value, bool $toTranslate = true): string {
    $html = '<select id="' . e($displayName) . '" name="' . e($displayName) . '">';
    foreach ($options as $k => $n) {
        $html .= '<option value="' . e((string) $k) . '"' . ((string) $k === $value ? ' selected' : '') . '>' . e($toTranslate ? t(is_array($n) ? $n[0] : $n) : (is_array($n) ? $n[0] : $n)) . '</option>';
    }

    return $html . '</select>';
};
?>
<div class="navigace-radek"><a class="tl" href="<?= e($module->url('builder', ['id' => $p['popup_id']])) ?>"><?= e(t('Edit the content in the builder')) ?></a>
	<form class="vradku" method="post" action="<?= e($module->url('toggle')) ?>"><?= $csrf ?><input type="hidden" name="popup_id" value="<?= (int) $p['popup_id'] ?>"><input type="hidden" name="z" value="edit"><button class="navigace" type="submit"><?= e($p['active'] ? t('Turn off') : t('Turn on')) ?></button></form>
	<?php if ($p['active']): ?><span class="stitek stitek-vydano"><?= e(t('zapnuté')) ?></span><?php elseif ($p['build'] === null): ?><span class="stitek stitek-koncept"><?= e(t('nepublikované')) ?></span><?php else: ?><span class="stitek"><?= e(t('vypnuté')) ?></span><?php endif ?>
	<span class="napoveda"><?= e(t('Views')) ?>: <?= (int) $p['impressions'] ?> · <?= e(t('Closes')) ?>: <?= (int) $p['closes'] ?> · <?= e(t('Conversions')) ?>: <?= (int) $p['conversions'] ?></span></div>
<form class="formular" method="post" action="<?= e($module->url('save')) ?>">
<?= $csrf ?>
<input type="hidden" name="popup_id" value="<?= (int) $p['popup_id'] ?>">
<div class="radek"><label for="nazev"><?= e(t('Pop-up name')) ?></label><div><input class="textpole siroke" id="nazev" name="name" value="<?= e($p['name']) ?>" maxlength="100" required></div></div>
<div class="radek"><label for="adresa"><?= e(t('Adresa')) ?></label><div><input class="textpole" id="adresa" name="slug" value="<?= e($p['slug']) ?>" maxlength="60" pattern="[a-z0-9][a-z0-9\-]*"><span class="napoveda"><?= e(t('A link or button to #popup-%s opens the pop-up at any time – even when it does not show on its own.', $p['slug'])) ?></span></div></div>
<div class="radek"><label for="typ"><?= e(t('Typ')) ?></label><div><?= $selection('type', Popups::TYPES, $p['type']) ?></div></div>
<fieldset>
<legend><?= e(t('When the pop-up shows')) ?></legend>
<div class="radek"><label for="spoustec"><?= e(t('Trigger')) ?></label><div><?= $selection('trigger_type', Popups::TRIGGERS, $p['trigger_type']) ?></div></div>
<div class="radek"><label for="hodnota"><?= e(t('Trigger value')) ?></label><div><input class="textpole" size="5" type="number" id="hodnota" name="value" min="0" max="3600" value="<?= (int) $p['value'] ?>"><span class="napoveda"><?= e(t('Seconds for time and idle, percent of the page for scrolling, number of pages for the visit.')) ?></span></div></div>
<div class="radek"><label for="cetnost"><?= e(t('Frequency')) ?></label><div><?= $selection('frequency', Popups::FREQUENCIES, $p['frequency']) ?> <span data-aktivni-kdyz="cetnost=dni"> <label for="dni" class="vradku"><?= e(t('number of days')) ?></label> <input class="textpole" size="5" type="number" id="dni" name="days" min="1" max="365" value="<?= (int) $p['days'] ?>"></span><span class="napoveda"><?= e(t('The visitor’s browser remembers it (sessionStorage and localStorage), not cookies.')) ?></span></div></div>
</fieldset>
<fieldset>
<legend><?= e(t('Where the pop-up shows')) ?></legend>
<div class="radek"><span class="popisek"><?= e(t('Places')) ?></span><div class="volby">
<label><input type="radio" name="kde" value="vse"<?= $rules['kde'] === 'vse' ? ' checked' : '' ?>> <?= e(t('on the whole site')) ?></label>
<label><input type="radio" name="kde" value="vybrane"<?= $rules['kde'] === 'vybrane' ? ' checked' : '' ?>> <?= e(t('only on selected pages, in collections or in news')) ?></label>
</div></div>
<div data-aktivni-kdyz="kde=vybrane">
<div class="radek"><span class="popisek"><?= e(t('Pages')) ?></span><div class="volby volby-seznam">
<?php foreach ($pages as $s): ?>
<label><input type="checkbox" name="pages[]" value="<?= (int) $s['page_id'] ?>"<?= in_array((int) $s['page_id'], $rules['pages'], true) ? ' checked' : '' ?>> <?= e(($s['language'] !== '' ? strtoupper($s['language']) . ' · ' : '') . $s['title']) ?></label>
<?php endforeach ?>
</div></div>
<?php if ($collection !== []): ?>
<div class="radek"><span class="popisek"><?= e(t('Collection item pages')) ?></span><div class="volby">
<?php foreach ($collection as $k): ?>
<label><input type="checkbox" name="kolekce[]" value="<?= e($k['slug']) ?>"<?= in_array($k['slug'], $rules['kolekce'], true) ? ' checked' : '' ?>> <?= e($k['name']) ?> (/<?= e($k['slug']) ?>/…)</label>
<?php endforeach ?>
</div></div>
<?php endif ?>
<div class="radek"><span class="popisek"><?= e(t('Novinky')) ?></span><div class="volby"><label><input type="checkbox" name="novinky" value="1"<?= $rules['novinky'] ? ' checked' : '' ?>> <?= e(t('the news list, categories and news items')) ?></label></div></div>
</div>
<?php if ($languages !== []): ?>
<div class="radek"><label for="jazyk"><?= e(t('Language version')) ?></label><div><?= $selection('language', ['' => t('všechny')] + $languages, $rules['language'], false) ?></div></div>
<?php endif ?>
<div class="radek"><label for="od"><?= e(t('Period')) ?></label><div><input class="textpole" type="date" id="od" name="od" value="<?= e($rules['od']) ?>" aria-label="<?= e(t('from')) ?>"> – <input class="textpole" type="date" id="do" name="do" value="<?= e($rules['do']) ?>" aria-label="<?= e(t('to')) ?>"><span class="napoveda"><?= e(t('Empty = no limit. Outside the period the pop-up is not added to the page at all.')) ?></span></div></div>
<div class="radek"><label for="zarizeni"><?= e(t('Zařízení')) ?></label><div><?= $selection('device', Popups::DEVICES, $rules['device']) ?></div></div>
<div class="radek"><label for="utm"><?= e(t('Only from a campaign')) ?></label><div><input class="textpole" id="utm" name="utm" value="<?= e($rules['utm']) ?>" maxlength="80"><span class="napoveda"><?= e(t('Text in the utm_* parameters of the address the visitor arrived with (e.g. spring or newsletter). Empty = everyone.')) ?></span></div></div>
<div class="radek"><label for="odkud"><?= e(t('Only from a referrer')) ?></label><div><input class="textpole" id="odkud" name="referrer" value="<?= e($rules['referrer']) ?>" maxlength="80"><span class="napoveda"><?= e(t('Part of the address of the site the visitor came from (e.g. facebook.com). Empty = from anywhere.')) ?></span></div></div>
</fieldset>
<div class="radek">
	<label for="valid_until"><?= e(t('True until')) ?></label>
	<div><input class="textpole" type="date" id="valid_until" name="valid_until" value="<?= e((string) ($p['valid_until'] ?? '')) ?>">
	<span class="napoveda"><?= e(t('After this day the pop-up switches itself off. Empty = always.')) ?></span></div>
</div>
<div class="radek">
	<label for="review_by"><?= e(t('Review by')) ?></label>
	<div><input class="textpole" type="date" id="review_by" name="review_by" value="<?= e((string) ($p['review_by'] ?? '')) ?>">
	<span class="napoveda"><?= e(t('On this day the site audit and the alert e-mail remind you to check it.')) ?></span></div>
</div>
<div class="radek"><label for="poradi"><?= e(t('Pořadí')) ?></label><div><input class="textpole" size="5" type="number" id="poradi" name="sort_order" value="<?= (int) $p['sort_order'] ?>"><span class="napoveda"><?= e(t('When several pop-ups would show, the lower number goes first. No pop-up opens over an open one.')) ?></span></div></div>
<p class="tlacitka"><input class="tl" type="submit" value="<?= e(t('Save settings')) ?>"></p>
</form>
<div class="navigace-radek akce-dole">
<a class="navigace" href="<?= e($module->url()) ?>"><?= e(t('All pop-ups')) ?></a>
<form class="vradku" method="post" action="<?= e($module->url('reset')) ?>" data-potvrdit="<?= e(t('Reset the counters of views, closes and conversions?')) ?>"><?= $csrf ?><input type="hidden" name="popup_id" value="<?= (int) $p['popup_id'] ?>"><button class="navigace" type="submit"><?= e(t('Reset the counters')) ?></button></form>
<form class="vradku" method="post" action="<?= e($module->url('delete')) ?>" data-potvrdit="<?= e(t('Delete the pop-up? It disappears from the site at once.')) ?>"><?= $csrf ?><input type="hidden" name="popup_id" value="<?= (int) $p['popup_id'] ?>"><button class="navigace nebezpecne" type="submit"><?= e(t('Delete the pop-up')) ?></button></form>
</div>
