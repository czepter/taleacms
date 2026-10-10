<?php
/**
 * Popup settings: type, trigger, frequency and display rules.
 *
 * @var Talea\Core\App $app
 * @var Talea\Admin\Modules\Popups $module
 * @var string $csrf
 * @var array<string, mixed> $p
 * @var list<array<string, mixed>> $pages
 * @var list<array<string, mixed>> $collection
 * @var array<string, string> $languages
 */
use Talea\Builder\Popups;

$rules = $p['rules'];
$selection = function (string $displayName, array $options, string $value, bool $toTranslate = true): string {
    $html = '<select id="' . e($displayName) . '" name="' . e($displayName) . '">';
    foreach ($options as $k => $n) {
        $html .= '<option value="' . e((string) $k) . '"' . ((string) $k === $value ? ' selected' : '') . '>' . e($toTranslate ? t(is_array($n) ? $n[0] : $n) : (is_array($n) ? $n[0] : $n)) . '</option>';
    }

    return $html . '</select>';
};
?>
<div class="navigation-row"><a class="btn" href="<?= e($module->url('builder', ['id' => $p['public_id']])) ?>"><?= e(t('Edit the content in the builder')) ?></a>
	<form class="inline" method="post" action="<?= e($module->url('toggle')) ?>"><?= $csrf ?><input type="hidden" name="popup_id" value="<?= e($p['public_id']) ?>"><input type="hidden" name="back_to" value="edit"><button class="navigation" type="submit"><?= e($p['active'] ? t('Turn off') : t('Turn on')) ?></button></form>
	<?php if ($p['active']): ?><span class="badge badge-published"><?= e(t('on')) ?></span><?php elseif ($p['build'] === null): ?><span class="badge badge-draft"><?= e(t('unpublished')) ?></span><?php else: ?><span class="badge"><?= e(t('off')) ?></span><?php endif ?>
	<span class="help"><?= e(t('Views')) ?>: <?= (int) $p['impressions'] ?> · <?= e(t('Closes')) ?>: <?= (int) $p['closes'] ?> · <?= e(t('Conversions')) ?>: <?= (int) $p['conversions'] ?></span></div>
<form class="form" method="post" action="<?= e($module->url('save')) ?>">
<?= $csrf ?>
<input type="hidden" name="popup_id" value="<?= e($p['public_id']) ?>">
<div class="row"><label for="name"><?= e(t('Pop-up name')) ?></label><div><input class="textfield wide" id="name" name="name" value="<?= e($p['name']) ?>" maxlength="100" required></div></div>
<div class="row"><label for="slug"><?= e(t('URL')) ?></label><div><input class="textfield" id="slug" name="slug" value="<?= e($p['slug']) ?>" maxlength="60" pattern="[a-z0-9][a-z0-9\-]*"><span class="help"><?= e(t('A link or button to #popup-%s opens the pop-up at any time – even when it does not show on its own.', $p['slug'])) ?></span></div></div>
<div class="row"><label for="typ"><?= e(t('Type')) ?></label><div><?= $selection('type', Popups::TYPES, $p['type']) ?></div></div>
<fieldset>
<legend><?= e(t('When the pop-up shows')) ?></legend>
<div class="row"><label for="trigger_type"><?= e(t('Trigger')) ?></label><div><?= $selection('trigger_type', Popups::TRIGGERS, $p['trigger_type']) ?></div></div>
<div class="row"><label for="value"><?= e(t('Trigger value')) ?></label><div><input class="textfield" size="5" type="number" id="value" name="value" min="0" max="3600" value="<?= (int) $p['value'] ?>"><span class="help"><?= e(t('Seconds for time and idle, percent of the page for scrolling, number of pages for the visit.')) ?></span></div></div>
<div class="row"><label for="frequency"><?= e(t('Frequency')) ?></label><div><?= $selection('frequency', Popups::FREQUENCIES, $p['frequency']) ?> <span data-active-when="frequency=days"> <label for="days" class="inline"><?= e(t('number of days')) ?></label> <input class="textfield" size="5" type="number" id="days" name="days" min="1" max="365" value="<?= (int) $p['days'] ?>"></span><span class="help"><?= e(t('The visitor’s browser remembers it (sessionStorage and localStorage), not cookies.')) ?></span></div></div>
</fieldset>
<fieldset>
<legend><?= e(t('Where the pop-up shows')) ?></legend>
<div class="row"><span class="caption"><?= e(t('Places')) ?></span><div class="options">
<label><input type="radio" name="where" value="all"<?= $rules['where'] === 'all' ? ' checked' : '' ?>> <?= e(t('on the whole site')) ?></label>
<label><input type="radio" name="where" value="selected"<?= $rules['where'] === 'selected' ? ' checked' : '' ?>> <?= e(t('only on selected pages, in collections or in news')) ?></label>
</div></div>
<div data-active-when="where=selected">
<div class="row"><span class="caption"><?= e(t('Pages')) ?></span><div class="options options-list">
<?php foreach ($pages as $s): ?>
<label><input type="checkbox" name="pages[]" value="<?= e($s['public_id']) ?>"<?= in_array((int) $s['page_id'], $rules['pages'], true) ? ' checked' : '' ?>> <?= e(($s['language'] !== '' ? strtoupper($s['language']) . ' · ' : '') . $s['title']) ?></label>
<?php endforeach ?>
</div></div>
<?php if ($collection !== []): ?>
<div class="row"><span class="caption"><?= e(t('Collection item pages')) ?></span><div class="options">
<?php foreach ($collection as $k): ?>
<label><input type="checkbox" name="collections[]" value="<?= e($k['slug']) ?>"<?= in_array($k['slug'], $rules['collections'], true) ? ' checked' : '' ?>> <?= e($k['name']) ?> (/<?= e($k['slug']) ?>/…)</label>
<?php endforeach ?>
</div></div>
<?php endif ?>
<div class="row"><span class="caption"><?= e(t('News')) ?></span><div class="options"><label><input type="checkbox" name="news" value="1"<?= $rules['news'] ? ' checked' : '' ?>> <?= e(t('the news list, categories and news items')) ?></label></div></div>
</div>
<?php if ($languages !== []): ?>
<div class="row"><label for="language"><?= e(t('Language version')) ?></label><div><?= $selection('language', ['' => t('all')] + $languages, $rules['language'], false) ?></div></div>
<?php endif ?>
<div class="row"><label for="from"><?= e(t('Period')) ?></label><div><input class="textfield" type="date" id="from" name="from" value="<?= e($rules['from']) ?>" aria-label="<?= e(t('from')) ?>"> – <input class="textfield" type="date" id="to" name="to" value="<?= e($rules['to']) ?>" aria-label="<?= e(t('to')) ?>"><span class="help"><?= e(t('Empty = no limit. Outside the period the pop-up is not added to the page at all.')) ?></span></div></div>
<div class="row"><label for="device"><?= e(t('Devices')) ?></label><div><?= $selection('device', Popups::DEVICES, $rules['device']) ?></div></div>
<div class="row"><label for="utm"><?= e(t('Only from a campaign')) ?></label><div><input class="textfield" id="utm" name="campaign" value="<?= e($rules['campaign']) ?>" maxlength="80"><span class="help"><?= e(t('Text in the utm_* parameters of the address the visitor arrived with (e.g. spring or newsletter). Empty = everyone.')) ?></span></div></div>
<div class="row"><label for="referrer"><?= e(t('Only from a referrer')) ?></label><div><input class="textfield" id="referrer" name="referrer" value="<?= e($rules['referrer']) ?>" maxlength="80"><span class="help"><?= e(t('Part of the address of the site the visitor came from (e.g. facebook.com). Empty = from anywhere.')) ?></span></div></div>
</fieldset>
<div class="row">
	<label for="valid_until"><?= e(t('True until')) ?></label>
	<div><input class="textfield" type="date" id="valid_until" name="valid_until" value="<?= e((string) ($p['valid_until'] ?? '')) ?>">
	<span class="help"><?= e(t('After this day the pop-up switches itself off. Empty = always.')) ?></span></div>
</div>
<div class="row">
	<label for="review_by"><?= e(t('Review by')) ?></label>
	<div><input class="textfield" type="date" id="review_by" name="review_by" value="<?= e((string) ($p['review_by'] ?? '')) ?>">
	<span class="help"><?= e(t('On this day the site audit and the alert e-mail remind you to check it.')) ?></span></div>
</div>
<div class="row"><label for="sort_order"><?= e(t('Order')) ?></label><div><input class="textfield" size="5" type="number" id="sort_order" name="sort_order" value="<?= (int) $p['sort_order'] ?>"><span class="help"><?= e(t('When several pop-ups would show, the lower number goes first. No pop-up opens over an open one.')) ?></span></div></div>
<p class="buttons"><input class="btn" type="submit" value="<?= e(t('Save settings')) ?>"></p>
</form>
<div class="navigation-row actions-bottom">
<a class="navigation" href="<?= e($module->url()) ?>"><?= e(t('All pop-ups')) ?></a>
<form class="inline" method="post" action="<?= e($module->url('reset')) ?>" data-confirm="<?= e(t('Reset the counters of views, closes and conversions?')) ?>"><?= $csrf ?><input type="hidden" name="popup_id" value="<?= e($p['public_id']) ?>"><button class="navigation" type="submit"><?= e(t('Reset the counters')) ?></button></form>
<form class="inline" method="post" action="<?= e($module->url('delete')) ?>" data-confirm="<?= e(t('Delete the pop-up? It disappears from the site at once.')) ?>"><?= $csrf ?><input type="hidden" name="popup_id" value="<?= e($p['public_id']) ?>"><button class="navigation danger" type="submit"><?= e(t('Delete the pop-up')) ?></button></form>
</div>
