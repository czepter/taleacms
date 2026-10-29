<?php
/**
 * Collection definition, laid out like the item editor: the item form itself in the main column – one tile per field on the
 * grid of six columns, as wide as the field will be – and the settings sidebar of Website mode: Field (the chosen tile's
 * label, type, width, description, placeholder, required, tag and position), Collection (name, address, item pages, menu,
 * redirect) and Search engines (structured data). A tile is a tab of the Field panels, so image/admin.js (data-tabs)
 * shows one at a time; without the script every field's settings show one below another. admin.js also mirrors the typing
 * on the tile and reorders by dragging; Earlier / Later save and reorder without it (move, Collections::actionSave).
 *
 * @var Talea\Core\App $app
 * @var Talea\Admin\Modules\Collections $module
 * @var string $csrf
 * @var array<string, mixed> $k
 * @var array<string, string> $otherCollections other collections for item links (2.10): address => name
 */
use Talea\Builder\CollectionSchema;
use Talea\Builder\Collections;

$icon = fn (string $paths, int $size = 18): string => '<svg class="icon" viewBox="0 0 24 24" width="' . $size . '" height="' . $size . '" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $paths . '</svg>';
// the saved fields, then an empty one: giving it a label adds a field
$fields = array_merge($k['fields'], [['key' => '', 'label' => '', 'type' => 'text']]);
$new = count($fields) - 1;
$widths = ['full' => 'Full width', 'half' => 'Half', 'third' => 'Third'];
// what a tile shows in its input: the placeholder, else what the field holds
$sample = fn (array $f): string => (string) ($f['placeholder'] ?? '') !== '' ? (string) $f['placeholder'] : match ($f['type']) {
    'radio' => implode(' · ', array_map(t(...), (array) ($f['options'] ?? []))),
    'image', 'file', 'gallery' => t('Choose from Media'),
    'date', 'datetime' => t('date'),
    'html' => t('formatted text'),
    default => '',
};
$schema = CollectionSchema::of($k) ?? ['type' => '', 'fields' => [], 'currency' => ''];
// every property once, with the types that have it (the form shows only the rows of the chosen type)
$properties = [];
foreach (CollectionSchema::TYPES as $type => [, $props]) {
    foreach ($props as $property => $label) {
        $properties[$property] ??= [$label, []];
        $properties[$property][1][] = $type;
    }
}
$tabs = ['field' => ['Field', '<rect x="3" y="6" width="18" height="12" rx="2"/><path d="M7 12h6"/>'], 'collection' => ['Collection', '<rect x="3" y="4" width="7" height="7" rx="1"/><rect x="14" y="4" width="7" height="7" rx="1"/><rect x="3" y="13" width="7" height="7" rx="1"/><rect x="14" y="13" width="7" height="7" rx="1"/>']]
    + ($k['fields'] !== [] ? ['search' => ['Search engines', '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>']] : []);
if ($k['collection_id'] > 0) {
    $app->view->slot('actions', '<a class="navigation" href="' . e($module->url('items', ['id' => $k['public_id']])) . '">' . $icon('<path d="M4 6h16M4 12h16M4 18h10"/>', 16) . e(t('Open the items')) . '</a>');
}
?>
<p class="item-meta">
	<a class="item-back" href="<?= e($module->url()) ?>"><?= $icon('<path d="m15 18-6-6 6-6"/>') ?><?= e(t('All collections')) ?></a>
<?php if ($k['slug'] !== ''): ?>
	<span class="item-address">/<?= e($k['slug']) ?>/…</span>
<?php endif ?>
</p>
<form class="form item-editor collection-editor" method="post" action="<?= e($module->url('save')) ?>" data-switch="schema[type]">
<?= $csrf ?>
<input type="hidden" name="collection_id" value="<?= e($k['public_id']) ?>">

<div class="item-main" data-tabs data-field-tiles>
	<div class="field-tile field-tile-name">
		<span class="field-tile-row"><?= $icon('<rect x="6" y="11" width="12" height="9" rx="1"/><path d="M9 11V8a3 3 0 0 1 6 0v3"/>', 16) ?><span class="field-tile-label"><?= e(t('Name')) ?></span></span>
		<span class="field-tile-input"></span>
		<span class="field-tile-help"><?= e(t('Every item always has a name. Add the fields you need – in the builder you insert them with a {{key}} tag. An empty label removes the field; the key of a saved field never changes.')) ?></span>
	</div>
	<div class="field-grid" role="tablist" aria-label="<?= e(t('Item fields')) ?>">
<?php foreach ($fields as $i => $f): $width = (string) ($f['width'] ?? 'full'); ?>
<?php if ($i === $new): ?>
		<button type="button" class="field-tile field-tile-add" role="tab" id="field-tab-<?= $i ?>" aria-controls="field-panel-<?= $i ?>" aria-selected="<?= $i === 0 ? 'true' : 'false' ?>"<?= $i === 0 ? '' : ' tabindex="-1"' ?>><?= $icon('<path d="M12 5v14M5 12h14"/>') ?><?= e(t('Add a field')) ?></button>
<?php else: ?>
		<button type="button" class="field-tile field-tile-<?= e($width) ?>" role="tab" id="field-tab-<?= $i ?>" aria-controls="field-panel-<?= $i ?>" aria-selected="<?= $i === 0 ? 'true' : 'false' ?>"<?= $i === 0 ? '' : ' tabindex="-1"' ?> draggable="true">
			<span class="field-tile-row"><?= $icon('<circle cx="9" cy="6" r="1"/><circle cx="15" cy="6" r="1"/><circle cx="9" cy="12" r="1"/><circle cx="15" cy="12" r="1"/><circle cx="9" cy="18" r="1"/><circle cx="15" cy="18" r="1"/>', 16) ?><span class="field-tile-label"><span data-tile="label"><?= e($f['label']) ?></span><span class="item-required" data-tile="required"<?= empty($f['required']) ? ' hidden' : '' ?>> *</span></span><code class="field-tile-key">{{<?= e($f['key']) ?>}}</code></span>
			<span class="field-tile-input<?= in_array($f['type'], ['lines', 'html', 'gallery', 'parameters', 'variants'], true) ? ' tall' : '' ?>" data-tile="input" data-sample="<?= e($sample(['placeholder' => ''] + $f)) ?>"><?= e($sample($f)) ?></span>
			<span class="field-tile-help" data-tile="help"<?= ($f['help'] ?? '') === '' ? ' hidden' : '' ?>><?= e((string) ($f['help'] ?? '')) ?></span>
			<span class="field-tile-meta"><span><?= e(t(Collections::FIELD_TYPES[$f['type']] ?? $f['type'])) ?></span><span data-tile="width"><?= e(t($widths[$width] ?? 'Full width')) ?></span></span>
		</button>
<?php endif ?>
<?php endforeach ?>
	</div>
<?php if ($k['fields'] !== []): ?>
	<p class="help field-grid-help"><?= e(t('Drag a field to change the order. Its width, description and placeholder are set beside.')) ?></p>
<?php endif ?>
</div>

<aside class="website-settings item-settings" id="collection-settings" data-tabs aria-label="<?= e(t('Collection settings')) ?>">
	<div class="website-settings-head"><h2><?= e(t('Collection settings')) ?></h2></div>
	<div class="website-settings-tabs" role="tablist" aria-label="<?= e(t('Settings')) ?>" style="--tabs: <?= count($tabs) ?>">
<?php foreach (array_keys($tabs) as $key): $open = $key === ($k['collection_id'] > 0 ? 'field' : 'collection'); // a new collection starts with its name ?>
		<button type="button" role="tab" id="collection-tab-<?= $key ?>" aria-controls="collection-panel-<?= $key ?>" aria-selected="<?= $open ? 'true' : 'false' ?>"<?= $open ? '' : ' tabindex="-1"' ?>><?= $icon($tabs[$key][1]) ?><?= e(t($tabs[$key][0])) ?></button>
<?php endforeach ?>
	</div>
	<div class="website-settings-body">
		<div role="tabpanel" id="collection-panel-field" aria-labelledby="collection-tab-field">
<?php foreach ($fields as $i => $f): $width = (string) ($f['width'] ?? 'full'); $p = 'fields[' . $i . ']'; ?>
			<div class="field-panel" role="tabpanel" id="field-panel-<?= $i ?>" aria-labelledby="field-tab-<?= $i ?>" data-field-panel>
				<section class="website-settings-section" aria-labelledby="field-heading-<?= $i ?>">
					<h3 id="field-heading-<?= $i ?>"><?= e($i === $new ? t('New field') : $f['label']) ?></h3>
					<input type="hidden" name="<?= $p ?>[key]" value="<?= e($f['key']) ?>">
					<input type="hidden" name="<?= $p ?>[position]" value="<?= $i ?>" data-field-position>
					<div class="website-field"><label for="field-<?= $i ?>-label"><?= e(t('Label')) ?></label><input class="textfield" type="text" id="field-<?= $i ?>-label" name="<?= $p ?>[label]" value="<?= e($f['label']) ?>" maxlength="80" data-field-prop="label"></div>
					<div class="website-field"><label for="field-<?= $i ?>-type"><?= e(t('Type')) ?></label><select id="field-<?= $i ?>-type" name="<?= $p ?>[type]">
<?php foreach (Collections::FIELD_TYPES as $type => $name): ?>
						<option value="<?= e($type) ?>"<?= $f['type'] === $type ? ' selected' : '' ?>><?= e(t($name)) ?></option>
<?php endforeach ?>
					</select></div>
					<div class="website-field field-options"><label for="field-<?= $i ?>-options"><?= e(t('Options of a choice (one per line)')) ?></label><textarea class="textbox" id="field-<?= $i ?>-options" name="<?= $p ?>[options]" rows="4"><?= e(implode("\n", (array) ($f['options'] ?? []))) ?></textarea></div>
					<div class="website-field field-linked"><label for="field-<?= $i ?>-collection"><?= e(t('Linked collection')) ?></label><select id="field-<?= $i ?>-collection" name="<?= $p ?>[collection]"><option value="">–</option>
<?php foreach ($otherCollections as $slug => $name): ?>
						<option value="<?= e($slug) ?>"<?= ($f['collection'] ?? '') === $slug ? ' selected' : '' ?>><?= e($name) ?></option>
<?php endforeach ?>
					</select><span class="help"><?= e(t('An item of another collection links two collections – a person to a branch, a reference to a service: choose the type and the linked collection. {{key}} shows the linked item\'s name, {{key_url}} its page. On the page of the linked item, a Collection list filtered by the field with the value {{seo}} lists everything linked to it.')) ?></span></div>
					<fieldset class="website-field field-width"><legend class="website-caption"><?= e(t('Width in the form')) ?></legend>
						<span class="field-width-choices">
<?php foreach ($widths as $value => $label): ?>
							<label><input type="radio" name="<?= $p ?>[width]" value="<?= $value ?>"<?= $width === $value ? ' checked' : '' ?> data-field-prop="width"><?= e(t($label)) ?></label>
<?php endforeach ?>
						</span>
						<span class="help"><?= e(t('Fields side by side fill a row; on a phone they stack.')) ?></span>
					</fieldset>
				</section>
				<section class="website-settings-section" aria-labelledby="field-guide-<?= $i ?>">
					<h3 id="field-guide-<?= $i ?>"><?= e(t('Help for whoever fills it in')) ?></h3>
					<div class="website-field"><label for="field-<?= $i ?>-help"><?= e(t('Description')) ?></label><textarea class="textbox" id="field-<?= $i ?>-help" name="<?= $p ?>[help]" rows="3" maxlength="300" data-field-prop="help"><?= e((string) ($f['help'] ?? '')) ?></textarea><span class="help"><?= e(t('Shown under the field.')) ?></span></div>
					<div class="website-field field-placeholder"><label for="field-<?= $i ?>-placeholder"><?= e(t('Placeholder')) ?></label><input class="textfield" type="text" id="field-<?= $i ?>-placeholder" name="<?= $p ?>[placeholder]" value="<?= e((string) ($f['placeholder'] ?? '')) ?>" maxlength="120" data-field-prop="placeholder"><span class="help"><?= e(t('Grey text in the empty field; it is never saved.')) ?></span></div>
					<label class="website-option"><input type="checkbox" name="<?= $p ?>[required]" value="1"<?= !empty($f['required']) ? ' checked' : '' ?> data-field-prop="required"> <?= e(t('Required – an item cannot be saved without it')) ?></label>
				</section>
<?php if ($i !== $new): ?>
				<section class="website-settings-section" aria-labelledby="field-tag-<?= $i ?>">
					<h3 id="field-tag-<?= $i ?>"><?= e(t('Tag')) ?></h3>
					<p class="field-tags"><code>{{<?= e($f['key']) ?>}}</code><?= match ($f['type']) {
                        'item' => ' <code>{{' . e($f['key']) . '_url}}</code> <code>{{' . e($f['key']) . '_seo}}</code>',
                        'datetime' => ' <code>{{' . e($f['key']) . '_iso}}</code>',
                        'number' => ' <code>{{' . e($f['key']) . '_raw}}</code>',
                        'file' => ' <code>{{' . e($f['key']) . '_name}}</code>',
                        'gallery' => ' <code>{{' . e($f['key']) . '_count}}</code>',
                        default => '',
                    } ?></p>
					<div class="field-moves">
						<button class="navigation" type="submit" name="move" value="<?= $i ?>:-1" data-field-move="-1"><?= $icon('<path d="m6 15 6-6 6 6"/>', 16) ?><?= e(t('Earlier')) ?></button>
						<button class="navigation" type="submit" name="move" value="<?= $i ?>:1" data-field-move="1"><?= $icon('<path d="m6 9 6 6 6-6"/>', 16) ?><?= e(t('Later')) ?></button>
					</div>
					<label class="website-option"><input type="checkbox" name="<?= $p ?>[remove]" value="1"> <?= e(t('Remove this field when saving')) ?></label>
				</section>
<?php else: ?>
				<section class="website-settings-section"><p class="help"><?= e(t('created from the label')) ?>: <code>{{…}}</code></p></section>
<?php endif ?>
			</div>
<?php endforeach ?>
		</div>
		<div role="tabpanel" id="collection-panel-collection" aria-labelledby="collection-tab-collection">
			<section class="website-settings-section" aria-label="<?= e(t('Collection')) ?>">
				<div class="website-field"><label for="name"><?= e(t('Collection name')) ?></label><input class="textfield" type="text" id="name" name="name" value="<?= e($k['name']) ?>" maxlength="100" required placeholder="<?= e(t('e.g. Testimonials, Team, Products')) ?>"></div>
				<div class="website-field"><label for="slug"><?= e(t('URL')) ?></label><div class="website-slug"><span>/</span><input class="textfield" type="text" id="slug" name="slug" value="<?= e($k['slug']) ?>" maxlength="110"></div><span class="help"><?= e(t('From the name if left empty. Item pages will then be at /address/item-name.')) ?></span></div>
				<div class="website-field"><label class="website-option"><input type="checkbox" name="detail" value="1"<?= $k['detail'] ? ' checked' : '' ?>> <?= e(t('each item has its own page (detail)')) ?></label><span class="help"><?= e(t('Design the detail page in the builder (Detail template). Without a detail page, items are just cards in the list.')) ?></span></div>
				<div class="website-field"><label class="website-option"><input type="checkbox" name="admin_menu" value="1"<?= !empty($k['admin_menu']) ? ' checked' : '' ?>> <?= e(t('Show in the admin menu')) ?></label><span class="help"><?= e(t('The collection gets its own link in the main menu, under the heading Collections, so its items are one click away from every screen. At most %d collections are listed there, by name.', Talea\Admin\Modules\Collections::MENU_LIMIT)) ?></span></div>
				<div class="website-field"><label for="hidden_redirect"><?= e(t('Hidden items redirect to')) ?></label><input class="textfield" type="text" id="hidden_redirect" name="hidden_redirect" value="<?= e((string) ($k['hidden_redirect'] ?? '')) ?>" maxlength="255" placeholder="/<?= e($k['slug'] !== '' ? $k['slug'] : 'team') ?>"><span class="help"><?= e(t('When an item is hidden or deleted – a person who left, a product no longer sold – its page leads here (301) instead of “page not found”. Empty = page not found.')) ?></span></div>
			</section>
<?php if ($k['collection_id'] > 0): ?>
			<section class="website-settings-section"><button class="navigation danger" type="submit" form="collection-delete"><?= e(t('Delete collection')) ?></button></section>
<?php endif ?>
		</div>
<?php if ($k['fields'] !== []): ?>
		<div role="tabpanel" id="collection-panel-search" aria-labelledby="collection-tab-search">
			<section class="website-settings-section" aria-label="<?= e(t('Structured data for search engines')) ?>">
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
			</section>
		</div>
<?php endif ?>
	</div>
	<div class="website-settings-foot"><button class="btn" type="submit"><?= e(t('Save collection')) ?></button></div>
</aside>
</form>
<?php if ($k['collection_id'] > 0): ?>
<form id="collection-delete" method="post" action="<?= e($module->url('delete')) ?>" data-confirm="<?= e(t('Delete the collection with all its items? Lists on the site will disappear.')) ?>" hidden><?= $csrf ?><input type="hidden" name="collection_id" value="<?= e($k['public_id']) ?>"></form>
<?php endif ?>
