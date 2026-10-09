<?php
/**
 * Collection definition: name, slug, item pages and fields.
 *
 * @var Kaleta\Core\App $app
 * @var Kaleta\Admin\Modules\Collections $module
 * @var string $csrf
 * @var array<string, mixed> $k
 * @var array<string, string> $otherCollections other collections for item links (2.10): address => name
 */
use Kaleta\Builder\CollectionSchema;
use Kaleta\Builder\Collections;

$field = array_merge($k['fields'], array_fill(0, 3, ['key' => '', 'label' => '', 'type' => 'text']));
$schema = CollectionSchema::of($k) ?? ['type' => '', 'fields' => [], 'currency' => ''];
// every property once, with the types that have it (the form shows only the rows of the chosen type)
$properties = [];
foreach (CollectionSchema::TYPES as $type => [, $props]) {
    foreach ($props as $property => $label) {
        $properties[$property] ??= [$label, []];
        $properties[$property][1][] = $type;
    }
}
?>
<form class="form" method="post" action="<?= e($module->url('save')) ?>" data-switch="schema[type]">
<?= $csrf ?>
<input type="hidden" name="collection_id" value="<?= (int) $k['collection_id'] ?>">
<div class="row"><label for="nazev"><?= e(t('Collection name')) ?></label><div><input class="textfield wide" id="nazev" name="name" value="<?= e($k['name']) ?>" maxlength="100" required placeholder="<?= e(t('e.g. Testimonials, Team, Products')) ?>"></div></div>
<div class="row"><label for="seo_link"><?= e(t('URL')) ?></label><div><input class="textfield" id="seo_link" name="slug" value="<?= e($k['slug']) ?>" maxlength="110"><span class="help"><?= e(t('From the name if left empty. Item pages will then be at /address/item-name.')) ?></span></div></div>
<div class="row"><span class="caption"><?= e(t('Item pages')) ?></span><div class="options"><label><input type="checkbox" name="detail" value="1"<?= $k['detail'] ? ' checked' : '' ?>> <?= e(t('each item has its own page (detail)')) ?></label>
<span class="help"><?= e(t('Design the detail page in the builder (Detail template). Without a detail page, items are just cards in the list.')) ?></span></div></div>
<div class="row"><label for="hidden_redirect"><?= e(t('Hidden items redirect to')) ?></label><div><input class="textfield" id="hidden_redirect" name="hidden_redirect" value="<?= e((string) ($k['hidden_redirect'] ?? '')) ?>" maxlength="255" placeholder="/<?= e($k['slug'] !== '' ? $k['slug'] : 'team') ?>">
<span class="help"><?= e(t('When an item is hidden or deleted – a person who left, a product no longer sold – its page leads here (301) instead of “page not found”. Empty = page not found.')) ?></span></div></div>
<fieldset>
<legend><?= e(t('Item fields')) ?></legend>
<p class="help"><?= e(t('Every item always has a name. Add the fields you need – in the builder you insert them with a {{key}} tag. An empty label removes the field; the key of a saved field never changes.')) ?></p>
<p class="help"><?= e(t('An item of another collection links two collections – a person to a branch, a reference to a service: choose the type and the linked collection. {{key}} shows the linked item\'s name, {{key_url}} its page. On the page of the linked item, a Collection list filtered by the field with the value {{seo}} lists everything linked to it.')) ?></p>
<div class="tab-wrap">
<table class="listing">
<thead><tr><th scope="col"><?= e(t('Label')) ?></th><th scope="col"><?= e(t('Type')) ?></th><th scope="col"><?= e(t('Linked collection or options')) ?></th><th scope="col"><?= e(t('Tag')) ?></th></tr></thead>
<tbody>
<?php foreach ($field as $i => $p): ?>
<tr>
	<td><input class="textfield" name="fields[<?= $i ?>][label]" value="<?= e($p['label']) ?>" maxlength="80" aria-label="<?= e(t('Label')) ?>"><input type="hidden" name="fields[<?= $i ?>][key]" value="<?= e($p['key']) ?>"></td>
	<td><select name="fields[<?= $i ?>][type]" aria-label="<?= e(t('Type')) ?>">
<?php foreach (Collections::FIELD_TYPES as $type => $name): ?>
		<option value="<?= e($type) ?>"<?= $p['type'] === $type ? ' selected' : '' ?>><?= e(t($name)) ?></option>
<?php endforeach ?>
	</select></td>
	<td><select name="fields[<?= $i ?>][collection]" aria-label="<?= e(t('Linked collection')) ?>"><option value="">–</option>
<?php foreach ($otherCollections as $slug => $name): ?>
		<option value="<?= e($slug) ?>"<?= ($p['collection'] ?? '') === $slug ? ' selected' : '' ?>><?= e($name) ?></option>
<?php endforeach ?>
	</select>
	<textarea class="textbox low" name="fields[<?= $i ?>][options]" rows="2" aria-label="<?= e(t('Options of a choice (one per line)')) ?>" placeholder="<?= e(t('Options of a choice (one per line)')) ?>"><?= e(implode("\n", (array) ($p['options'] ?? []))) ?></textarea></td>
	<td><?= $p['key'] !== '' ? '<code>{{' . e($p['key']) . '}}</code>' . match ($p['type']) {
        'item' => ' <code>{{' . e($p['key']) . '_url}}</code> <code>{{' . e($p['key']) . '_seo}}</code>',
        'datetime' => ' <code>{{' . e($p['key']) . '_iso}}</code>',
        'file' => ' <code>{{' . e($p['key']) . '_name}}</code>',
        default => '',
    } : '<span class="help">' . e(t('created from the label')) . '</span>' ?></td>
</tr>
<?php endforeach ?>
</tbody>
</table>
</div>
</fieldset>
<?php if ($k['fields'] !== []): ?>
<details class="advanced"<?= $schema['type'] !== '' ? ' open' : '' ?>>
<summary><?= e(t('Structured data for search engines')) ?></summary>
<p class="help"><?= e(t('Tell search engines what the items are. Item pages then carry the schema.org data; the name, address, description and image come from the item itself. Save new fields first – then you can pick them here.')) ?></p>
<div class="row"><label for="schema-type"><?= e(t('Items are')) ?></label><div><select id="schema-type" name="schema[type]">
	<option value=""><?= e(t('nothing specific (no structured data)')) ?></option>
<?php foreach (CollectionSchema::TYPES as $type => [$label]): ?>
	<option value="<?= e($type) ?>"<?= $schema['type'] === $type ? ' selected' : '' ?>><?= e(t($label)) ?> (<?= e($type) ?>)</option>
<?php endforeach ?>
</select></div></div>
<?php foreach ($properties as $property => [$label, $types]): ?>
<div class="row" data-for="<?= e(implode(' ', $types)) ?>"><label for="schema-<?= e($property) ?>"><?= e(t($label)) ?></label><div><select id="schema-<?= e($property) ?>" name="schema[fields][<?= e($property) ?>]">
	<option value=""><?= e(t('– none –')) ?></option>
<?php foreach ($k['fields'] as $f): ?>
	<option value="<?= e($f['key']) ?>"<?= ($schema['fields'][$property] ?? '') === $f['key'] ? ' selected' : '' ?>><?= e($f['label']) ?></option>
<?php endforeach ?>
</select></div></div>
<?php endforeach ?>
<div class="row" data-for="Service Product Event JobPosting"><label for="schema-currency"><?= e(t('Currency of the price')) ?></label><div><input class="textfield" id="schema-currency" name="schema[currency]" value="<?= e($schema['currency']) ?>" maxlength="3" size="5" placeholder="EUR">
	<span class="help"><?= e(t('A three-letter code (EUR, CZK, USD). Without it the price is not passed on.')) ?></span></div></div>
</details>
<?php endif ?>
<p class="buttons"><input class="btn" type="submit" value="<?= e(t('Save collection')) ?>"> <a class="navigation" href="<?= e($module->url()) ?>"><?= e(t('Back')) ?></a></p>
</form>
<?php if ($k['collection_id'] > 0): ?>
<div class="navigation-row actions-bottom"><form class="inline" method="post" action="<?= e($module->url('delete')) ?>" data-confirm="<?= e(t('Delete the collection with all its items? Lists on the site will disappear.')) ?>"><?= $csrf ?><input type="hidden" name="collection_id" value="<?= (int) $k['collection_id'] ?>"><button class="navigation danger" type="submit"><?= e(t('Delete collection')) ?></button></form></div>
<?php endif ?>
