<?php
/**
 * Collection item editor: the collection's fields in the main column, the item's settings in the sidebar of Website mode
 * (SEO · Item · Publishing · Sharing · Advanced; a collection without item pages keeps Item and Publishing). One form, one
 * Save in the sidebar's foot. The tabs are switched by image/admin.js (data-tabs); without the script every panel shows,
 * one below another. A version is restored through its own form outside the editor (the button names it with form="…").
 *
 * @var Talea\Core\App $app
 * @var Talea\Admin\Modules\Collections $module
 * @var string $csrf
 * @var array<string, mixed> $k
 * @var array<string, mixed> $p
 * @var string $submittedForm  the name of the form a visitor sent the item through, or empty
 * @var ?array<string, mixed> $seoCheck  the SEO score panel of a saved item (Core\SeoScore::panel)
 * @var array{serpTitle: string, serpDescription: string, serpAddress: string, dir: string} $serp  the search result preview
 * @var ?array{course: array<string, mixed>, taken: int, requested: int, waitlisted: int} $seats  the seats of a course with registration (Core\Registrations), null for any other item
 * @var string $host  the site's host, for the sharing preview
 * @var string $address  the item's address on the site, '' for a collection without item pages
 * @var list<array{idr: int, datum: string, kdo: ?string}> $versions  earlier versions of the item (1.9)
 * @var list<array<string, mixed>> $noticeLog  the audit trail of a notice (2.11, Core\Notices), newest first
 */
use Talea\Core\Language;

$languages = Language::additional($app->settings());
$contentDir = Language::dirAttribute(Language::ofContent($app->settings(), (string) $p['language'])); // an item of a right-to-left version (Arabic)
$detail = (bool) $k['detail'];
$icon = fn (string $paths): string => '<svg class="icon" viewBox="0 0 24 24" width="18" height="18" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $paths . '</svg>';
$tabs = array_filter([
    'seo' => $detail ? ['SEO', '<circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/>'] : null,
    'item' => ['Item', '<path d="M6 3h9l4 4v12a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2z"/><path d="M8 12h8M8 16h5"/>'],
    'publishing' => ['Publishing', '<rect x="3" y="5" width="18" height="16" rx="2"/><path d="M3 10h18M8 3v4M16 3v4"/>'],
    'sharing' => $detail ? ['Sharing', '<circle cx="18" cy="5" r="2.5"/><circle cx="6" cy="12" r="2.5"/><circle cx="18" cy="19" r="2.5"/><path d="m8.2 10.8 7.6-4.5M8.2 13.2l7.6 4.5"/>'] : null,
    'advanced' => $detail ? ['Advanced', '<path d="M4 6h10M18 6h2M4 12h4M12 12h8M4 18h12"/><circle cx="16" cy="6" r="2"/><circle cx="10" cy="12" r="2"/><circle cx="18" cy="18" r="2"/>'] : null,
]);
$score = $seoCheck['analysis']['score'] ?? null;
$band = Talea\Core\ContentCheck::band($score);
// characters against what search results show in full; admin.js keeps the bar current while typing
$meter = function (string $value, int $max): string {
    $length = mb_strlen($value);

    return '<span class="seo-meter' . ($length > $max ? ' warning' : '') . '" data-meter="' . $max . '"><i style="--fill: ' . min(100, (int) round($length / $max * 100)) . '%"></i><span>' . $length . ' / ' . $max . '</span></span>';
};
$shareImage = (string) $p['image'];
$shareTitle = (string) ($p['share_title'] ?? '') !== '' ? (string) $p['share_title'] : ($p['seo_title'] !== '' ? (string) $p['seo_title'] : (string) $p['name']);
$shareDescription = (string) ($p['share_description'] ?? '') !== '' ? (string) $p['share_description'] : (string) $p['description'];
$actions = '';
if ($seats !== null && $app->auth()->hasModule('enquiries')) {
    $actions .= '<a class="navigation" href="' . e($module->url('registrations', ['id' => $k['public_id'], 'item' => $p['public_id']])) . '">' . e(t('Registrations')) . ' · '
        . e($seats['course']['capacity'] > 0 ? t('%d of %d', $seats['taken'], $seats['course']['capacity']) : (string) $seats['taken']) . '</a>';
}
if ($detail && $p['item_id'] > 0 && $p['visible']) {
    $actions .= '<a class="navigation" href="' . e($app->url($address)) . '" target="_blank" rel="noopener">' . $icon('<path d="M14 4h6v6M20 4l-9 9M18 14v5a1 1 0 0 1-1 1H5a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1h5"/>') . e(t('View on the site')) . '</a>';
}
if ($actions !== '') {
    $app->view->slot('actions', $actions);
}
?>
<p class="item-meta">
	<a class="item-back" href="<?= e($module->url('items', ['id' => $k['public_id']])) ?>"><?= $icon('<path d="m15 18-6-6 6-6"/>') ?><?= e($k['name']) ?></a>
<?php if ($p['item_id'] > 0): ?>
	<span class="status <?= $p['visible'] ? 'status-live' : 'status-hidden' ?>"><?= e($p['visible'] ? t('Published') : t('hidden')) ?></span>
<?php if ($detail): ?>
	<span class="item-address"><?= e('/' . $address) ?></span>
	<?= Talea\Admin\Modules\ShortLinks::entryLinks($app, 'item', $p['public_id']) ?>
<?php endif ?>
<?php if (!empty($p['submitted_via'])): ?>
	<span class="status status-hidden"><?= e(t('Sent by a visitor through the form "%s"', (string) ($submittedForm ?? ''))) ?></span>
<?php endif ?>
<?php if (!empty($p['updated_at'])): ?>
	<span><?= e(t('edited %s', format_ago((string) $p['updated_at']))) ?></span>
<?php endif ?>
<?php endif ?>
</p>
<form class="form item-editor" method="post" action="<?= e($module->url('save_item')) ?>">
<?= $csrf ?>
<input type="hidden" name="collection_id" value="<?= e($k['public_id']) ?>">
<input type="hidden" name="item_id" value="<?= e($p['public_id']) ?>">

<div class="item-main">
	<div class="item-field">
		<label class="item-label" for="name"><?= e(t('Name')) ?></label>
		<input class="textfield wide item-name" id="name" name="name" value="<?= e($p['name']) ?>" maxlength="200" required<?= $contentDir ?>>
	</div>
<?php foreach ($k['fields'] as $field): $h = (string) ($p['data'][$field['key']] ?? ''); $id = 'field-' . $field['key']; $displayName = 'data[' . $field['key'] . ']';
    // the collection's form settings (Collections::sanitizeFields): width, description, placeholder, required – formatted
    // text and a gallery have no input of their own to mark required, the save asks for them
    $help = (string) ($field['help'] ?? '');
    $extra = (!empty($field['required']) && !in_array($field['type'], ['html', 'gallery'], true) ? ' required' : '') . ($help !== '' ? ' aria-describedby="' . e($id) . '-help"' : '');
    $hint = fn (string $default = ''): string => ($text = (string) ($field['placeholder'] ?? $default)) !== '' ? ' placeholder="' . e($text) . '"' : ''; ?>
	<div class="item-field item-field-<?= e((string) ($field['width'] ?? 'full')) ?>">
		<div class="item-label"><label for="<?= e($id) ?>"><?= e($field['label']) ?><?php if (!empty($field['required'])): ?> <span class="item-required"><span aria-hidden="true">*</span><span class="visually-hidden"><?= e(t('Required')) ?></span></span><?php endif ?></label><code class="item-key">{{<?= e($field['key']) ?>}}</code></div>
		<div><?= match ($field['type']) {
        'lines' => '<textarea class="textbox low" id="' . e($id) . '" name="' . e($displayName) . '" rows="4"' . $hint() . $extra . $contentDir . '>' . e($h) . '</textarea>',
        'html' => '<textarea class="textbox" id="' . e($id) . '" name="' . e($displayName) . '" rows="10" data-editor' . $contentDir . '>' . e($h) . '</textarea>',
        'image' => '<input class="textfield wide" id="' . e($id) . '" name="' . e($displayName) . '" value="' . e($h) . '" maxlength="500"' . $extra . ' data-image>',
        'link' => '<input class="textfield wide" id="' . e($id) . '" name="' . e($displayName) . '" value="' . e($h) . '" maxlength="500"' . $hint('https://… ' . t('or') . '/page') . $extra . '>',
        'number' => '<input class="textfield" id="' . e($id) . '" name="' . e($displayName) . '" value="' . e($h) . '" inputmode="decimal" size="12"' . $hint() . $extra . '>',
        'date' => '<input class="textfield" type="date" id="' . e($id) . '" name="' . e($displayName) . '" value="' . e($h) . '"' . $extra . '>',
        // a whole day is stored without a time; the input shows it at midnight, which saves back as the whole day (Collections::cleanDateTime)
        'datetime' => '<input class="textfield" type="datetime-local" id="' . e($id) . '" name="' . e($displayName) . '" value="' . e($h === '' ? '' : (strlen($h) === 10 ? $h . 'T00:00' : str_replace(' ', 'T', $h))) . '"' . $extra . '> <span class="help">' . e(t('00:00 = the whole day')) . '</span>',
        'file' => '<input class="textfield wide" id="' . e($id) . '" name="' . e($displayName) . '" value="' . e($h) . '" maxlength="500"' . $extra . ' data-file>',
        // one photo per line "path | description"; image/editor.js turns it into photo tiles (upload, drop, reorder, describe) –
        // a value written as formatted text before shows as lines
        'gallery' => '<textarea class="textbox low" id="' . e($id) . '" name="' . e($displayName) . '" rows="6" data-gallery>' . e((string) Talea\Builder\Collections::cleanGallery($h)) . '</textarea>'
            . ' <span class="help gallery-help">' . e(t('One photo per line: path | description')) . '</span>',
        'location' => '<input class="textfield" id="' . e($id) . '" name="' . e($displayName) . '" value="' . e($h) . '" maxlength="40"' . $hint('50.0875, 14.4214') . $extra . ' inputmode="decimal">',
        'radio' => '<select id="' . e($id) . '" name="' . e($displayName) . '"' . $extra . '><option value="">–</option>' . implode('', array_map(fn (string $option): string => '<option value="' . e($option) . '"' . ($option === $h ? ' selected' : '') . '>' . e(t($option)) . '</option>',
            (array) ($field['options'] ?? []))) . '</select>',
        'item' => '<select id="' . e($id) . '" name="' . e($displayName) . '"' . $extra . '><option value="">–</option>' . implode('', array_map(fn (string $slug, string $name): string => '<option value="' . e($slug) . '"' . ($slug === $h ? ' selected' : '') . '>' . e($name) . '</option>',
            array_keys($choices = Talea\Builder\Collections::choices($app->db(), (string) ($field['collection'] ?? ''))), $choices)) . '</select>',
        default => '<input class="textfield wide" id="' . e($id) . '" name="' . e($displayName) . '" value="' . e($h) . '" maxlength="500"' . $hint() . $extra . $contentDir . '>',
    } ?></div>
<?php if ($help !== ''): ?>
		<p class="help" id="<?= e($id) ?>-help"><?= e($help) ?></p>
<?php endif ?>
	</div>
<?php endforeach ?>
</div>

<aside class="website-settings item-settings" id="item-settings" data-tabs aria-label="<?= e(t('Item settings')) ?>">
	<div class="website-settings-head">
		<h2><?= e(t('Item settings')) ?></h2>
<?php if ($seoCheck !== null): ?>
		<span class="website-score website-score-<?= $band ?>" title="<?= e(t('SEO score')) ?>"><?= $score === null ? '–' : $score . ' / 100' ?></span>
<?php endif ?>
		<button class="menu-tool" type="button" data-settings-toggle aria-expanded="true" aria-controls="item-settings" title="<?= e(t('Hide the sidebar')) ?>" aria-label="<?= e(t('Hide the sidebar')) ?>"><?= $icon('<path d="m9 18 6-6-6-6"/>') ?></button>
	</div>
	<div class="website-settings-tabs" role="tablist" aria-label="<?= e(t('Settings')) ?>" style="--tabs: <?= count($tabs) ?>">
<?php foreach (array_keys($tabs) as $i => $key): ?>
		<button type="button" role="tab" id="item-tab-<?= $key ?>" aria-controls="item-panel-<?= $key ?>" aria-selected="<?= $i === 0 ? 'true' : 'false' ?>"<?= $i === 0 ? '' : ' tabindex="-1"' ?>><?= $icon($tabs[$key][1]) ?><?= e(t($tabs[$key][0])) ?></button>
<?php endforeach ?>
	</div>
	<div class="website-settings-body">
<?php if ($detail): ?>
		<div role="tabpanel" id="item-panel-seo" aria-labelledby="item-tab-seo">
<?php if ($seoCheck !== null): ?>
<?= $app->view->render('admin/seo/checks', ['app' => $app, 'marksUrl' => ''] + $seoCheck) ?>
<?php endif ?>
			<section class="website-settings-section" aria-labelledby="item-serp-heading">
				<h3 class="seo-heading" id="item-serp-heading"><span><?= e(t('Search result')) ?></span></h3>
<?= $app->view->render('admin/seo/serp', $serp) ?>
				<div class="website-field"><label for="seo_title"><?= e(t('Search engine title')) ?></label><input class="textfield" type="text" id="seo_title" name="seo_title" value="<?= e($p['seo_title']) ?>" maxlength="200" placeholder="<?= e(t('empty = the item name')) ?>"<?= $contentDir ?>><?= $meter((string) $p['seo_title'], 60) ?></div>
				<div class="website-field"><label for="description"><?= e(t('Search engine description')) ?></label><textarea class="textbox" id="description" name="description" rows="3" maxlength="300"<?= $contentDir ?>><?= e($p['description']) ?></textarea><?= $meter((string) $p['description'], 160) ?><span class="help"><?= e(t('One or two sentences for search results (up to 160 characters). Empty = the beginning of the first longer text field.')) ?></span></div>
			</section>
			<section class="website-settings-section" aria-label="<?= e(t('Focus keywords')) ?>">
				<div class="website-field"><label for="focus_keywords"><?= e(t('Focus keywords')) ?></label><textarea class="textbox" id="focus_keywords" name="focus_keywords" rows="3" data-chips<?= $contentDir ?>><?= e((string) ($p['focus_keywords'] ?? '')) ?></textarea><span class="help"><?= e(t('One per line, up to five. The page is checked against the first.')) ?></span></div>
				<div class="website-field"><input type="hidden" name="key_page" value="0"><label class="website-option"><input type="checkbox" name="key_page" value="1"<?= !empty($p['key_page']) ? ' checked' : '' ?>> <?= e(t('Key page')) ?></label><span class="help"><?= e(t('Marks one of the most important pages of the site.')) ?></span></div>
			</section>
		</div>
<?php endif ?>
		<div role="tabpanel" id="item-panel-item" aria-labelledby="item-tab-item">
			<section class="website-settings-section" aria-label="<?= e(t('Item')) ?>">
<?php if ($detail): ?>
				<div class="website-field"><label for="slug"><?= e(t('URL')) ?></label><div class="website-slug"><span>/<?= e($k['slug']) ?>/</span><input class="textfield" type="text" id="slug" name="slug" value="<?= e($p['slug']) ?>" maxlength="150"></div></div>
<?php else: ?>
				<input type="hidden" name="slug" value="<?= e($p['slug']) ?>">
<?php endif ?>
				<div class="website-field"><label for="sort_order"><?= e(t('Order')) ?></label><input class="textfield" type="number" id="sort_order" name="sort_order" value="<?= (int) $p['sort_order'] ?>" min="-9999" max="9999"><span class="help"><?= e(t('Smaller number = earlier in the list.')) ?></span></div>
<?php if ($languages !== []): ?>
				<div class="website-field"><label for="language"><?= e(t('Language')) ?></label><select id="language" name="language">
					<option value=""><?= e(Language::AVAILABLE[Language::defaults($app->settings())][0]) ?></option>
<?php foreach ($languages as $j): ?>
					<option value="<?= e($j) ?>"<?= $p['language'] === $j ? ' selected' : '' ?>><?= e(Language::AVAILABLE[$j][0]) ?></option>
<?php endforeach ?>
				</select></div>
<?php endif ?>
			</section>
			<section class="website-settings-section" aria-labelledby="item-collection">
				<h3 id="item-collection"><?= e($k['name']) ?></h3>
				<div class="website-actions">
					<a href="<?= e($module->url('items', ['id' => $k['public_id']])) ?>"><?= $icon('<path d="M4 6h16M4 12h16M4 18h10"/>') ?><?= e(t('All items')) ?></a>
<?php if ($app->auth()->isAdmin()): ?>
					<a href="<?= e($module->url('edit', ['id' => $k['public_id']])) ?>"><?= $icon('<path d="M4 20h4L19 9l-4-4L4 16z"/>') ?><?= e(t('Fields and settings')) ?></a>
<?php endif ?>
<?php if ($p['item_id'] > 0 && Talea\Builder\EmailSignature::isPeople($k)): ?>
					<a href="<?= e($module->url('signature', ['id' => $k['public_id'], 'item' => $p['public_id']])) ?>"><?= $icon('<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/>') ?><?= e(t('E-mail signature')) ?></a>
<?php endif ?>
				</div>
			</section>
		</div>
		<div role="tabpanel" id="item-panel-publishing" aria-labelledby="item-tab-publishing">
			<section class="website-settings-section" aria-label="<?= e(t('Visibility')) ?>">
				<div class="website-field"><span class="website-caption"><?= e(t('Visibility')) ?></span>
					<div class="website-choices" role="radiogroup" aria-label="<?= e(t('Visibility')) ?>">
						<label><input type="radio" name="visible" value="1"<?= $p['visible'] ? ' checked' : '' ?>><span><?= e(t('Published')) ?><small><?= e(t('Everyone can see the item.')) ?></small></span></label>
						<label><input type="radio" name="visible" value="0"<?= $p['visible'] ? '' : ' checked' ?>><span><?= e(t('Hidden')) ?><small><?= e(t('Only you and your team see the item.')) ?></small></span></label>
					</div>
				</div>
				<div class="website-field"><label for="publish_at"><?= e(t('Publish automatically at')) ?></label><input class="textfield" type="datetime-local" id="publish_at" name="publish_at" value="<?= e(($p['publish_at'] ?? null) ? date('Y-m-d\TH:i', strtotime($p['publish_at'])) : '') ?>"><span class="help"><?= e(t('Only for a hidden item.')) ?></span></div>
<?= $app->view->render('admin/members/gating', ['app' => $app, 'type' => 'item', 'row' => (int) ($p['item_id'] ?? 0)]) ?>
			</section>
			<section class="website-settings-section" aria-labelledby="item-dates">
				<h3 id="item-dates"><?= e(t('Dates')) ?></h3>
				<div class="website-field"><label for="valid_until"><?= e(t('Visible until')) ?></label><input class="textfield" type="date" id="valid_until" name="valid_until" value="<?= e((string) ($p['valid_until'] ?? '')) ?>"><span class="help"><?= e(t('After this day the item hides itself. Empty = always.')) ?></span></div>
				<div class="website-field"><label for="review_by"><?= e(t('Review by')) ?></label><input class="textfield" type="date" id="review_by" name="review_by" value="<?= e((string) ($p['review_by'] ?? '')) ?>"><span class="help"><?= e(t('On this day the site audit and the alert e-mail remind you to check it.')) ?></span></div>
			</section>
<?php if ($versions !== []): ?>
			<section class="website-settings-section" aria-labelledby="item-history">
				<h3 class="seo-heading" id="item-history"><span><?= e(t('Item history')) ?></span><span><?= count($versions) ?></span></h3>
				<ul class="revisions item-history">
<?php foreach ($versions as $v): ?>
					<li><span><?= e(format_date($v['created_at'], true)) ?><?php if ($v['user_name']): ?><small><?= e($v['user_name']) ?></small><?php endif ?></span><button class="navigation" type="submit" form="restore-<?= (int) $v['revision_id'] ?>"><?= e(t('Restore')) ?></button></li>
<?php endforeach ?>
				</ul>
			</section>
<?php endif ?>
		</div>
<?php if ($detail): ?>
		<div role="tabpanel" id="item-panel-sharing" aria-labelledby="item-tab-sharing">
			<section class="website-settings-section" aria-label="<?= e(t('Sharing')) ?>">
				<div class="website-share">
<?php if ($shareImage !== ''): ?>
					<img src="<?= e(preg_match('#^(https?:)?/#', $shareImage) ? $shareImage : $app->url($shareImage)) ?>" alt="">
<?php else: ?>
					<span class="website-share-empty" aria-hidden="true"></span>
<?php endif ?>
					<div><small><?= e($host) ?></small><strong<?= $contentDir ?>><?= e($shareTitle) ?></strong><?php if ($shareDescription !== ''): ?><span<?= $contentDir ?>><?= e($shareDescription) ?></span><?php endif ?></div>
				</div>
				<div class="website-field"><label for="image"><?= e(t('Sharing image')) ?></label><input class="textfield" type="text" id="image" name="image" value="<?= e($shareImage) ?>" maxlength="255" placeholder="<?= e(t('empty = the first image field')) ?>" data-image><span class="help"><?= e(t('Shown when the link is shared on Facebook, LinkedIn or Teams (ideally 1200 × 630 px).')) ?></span></div>
				<div class="website-field"><label for="share_title"><?= e(t('Sharing title')) ?></label><input class="textfield" type="text" id="share_title" name="share_title" value="<?= e((string) ($p['share_title'] ?? '')) ?>" maxlength="200"<?= $contentDir ?>></div>
				<div class="website-field"><label for="share_description"><?= e(t('Sharing description')) ?></label><textarea class="textbox" id="share_description" name="share_description" rows="3" maxlength="320"<?= $contentDir ?>><?= e((string) ($p['share_description'] ?? '')) ?></textarea><span class="help"><?= e(t('Empty = the title and description for search engines.')) ?></span></div>
			</section>
		</div>
		<div role="tabpanel" id="item-panel-advanced" aria-labelledby="item-tab-advanced">
			<section class="website-settings-section" aria-labelledby="item-robots">
				<h3 id="item-robots"><?= e(t('Robots')) ?></h3>
				<label class="website-option"><input type="radio" name="noindex" value="0"<?= $p['noindex'] ? '' : ' checked' ?>> <?= e(t('Show in search results')) ?></label>
				<label class="website-option"><input type="radio" name="noindex" value="1"<?= $p['noindex'] ? ' checked' : '' ?>> <?= e(t('Hide from search engines (noindex)')) ?></label>
				<p class="help"><?= e(t('The item page stays reachable, but it is left out of search engines, the sitemap, llms.txt and site search.')) ?></p>
<?= $app->view->render('admin/seo/robots', ['robots' => (string) ($p['robots'] ?? ''), 'labelClass' => 'website-option']) ?>
			</section>
			<section class="website-settings-section" aria-labelledby="item-addresses">
				<h3 id="item-addresses"><?= e(t('Addresses')) ?></h3>
				<div class="website-field"><label for="canonical_url"><?= e(t('Main address (canonical)')) ?></label><input class="textfield" type="url" id="canonical_url" name="canonical_url" value="<?= e((string) ($p['canonical_url'] ?? '')) ?>" maxlength="500" placeholder="https://…"><span class="help"><?= e(t('Only when the same text lives at another address too.')) ?></span></div>
				<div class="website-field"><label for="breadcrumb_title"><?= e(t('Name in breadcrumbs')) ?></label><input class="textfield" type="text" id="breadcrumb_title" name="breadcrumb_title" value="<?= e((string) ($p['breadcrumb_title'] ?? '')) ?>" maxlength="200" placeholder="<?= e($p['name']) ?>"<?= $contentDir ?>></div>
			</section>
		</div>
<?php endif ?>
	</div>
	<div class="website-settings-foot">
		<button class="btn" type="submit"><?= e(t('Save')) ?></button>
		<button class="navigation" type="submit" name="after_save" value="stay"><?= e(t('Save and continue')) ?></button>
	</div>
	<div class="website-settings-rail">
<?php if ($seoCheck !== null): ?>
		<button class="website-score website-score-<?= $band ?>" type="button" data-show-tab="item-tab-seo" title="<?= e(t('SEO score')) ?>"><?= $score ?? '–' ?></button>
<?php endif ?>
<?php foreach ($tabs as $key => [$label, $paths]): ?>
		<button class="menu-tool" type="button" data-show-tab="item-tab-<?= $key ?>" title="<?= e(t($label)) ?>" aria-label="<?= e(t($label)) ?>"><?= $icon($paths) ?></button>
<?php endforeach ?>
	</div>
</aside>
</form>
<?php foreach ($versions as $v): ?>
<form id="restore-<?= (int) $v['revision_id'] ?>" method="post" action="<?= e($module->url('restore_item_version')) ?>" data-confirm="<?= e(t('Restore this version of the item? The current version stays in the history.')) ?>" hidden><?= $csrf ?><input type="hidden" name="collection_id" value="<?= e($k['public_id']) ?>"><input type="hidden" name="item_id" value="<?= e($p['public_id']) ?>"><input type="hidden" name="revision_id" value="<?= (int) $v['revision_id'] ?>"></form>
<?php endforeach ?>
<?php if (($noticeLog ?? []) !== []): $actions = ['created' => t('Created'), 'changed' => t('Changed'), 'posted' => t('Posted'), 'taken_down' => t('Taken down')]; ?>
<details class="advanced item-notice-log" open>
<summary><?= e(t('Notice log (%s)', count($noticeLog))) ?></summary>
<p class="help"><?= e(t('Every creation and change of the notice and the day it was posted and taken down. The log is append-only – nothing in it can be edited or deleted.')) ?>
<?php if ($app->auth()->isAdmin()): ?> <a href="<?= e($module->url('notice_log', ['id' => $k['public_id']])) ?>"><?= e(t('Download the whole log as CSV')) ?></a><?php endif ?></p>
<div class="tab-wrap">
<table class="listing">
<thead><tr><th scope="col"><?= e(t('Date')) ?></th><th scope="col"><?= e(t('Action')) ?></th><th scope="col"><?= e(t('By')) ?></th><th scope="col"><?= e(t('Changes')) ?></th></tr></thead>
<tbody>
<?php foreach ($noticeLog as $l): ?>
<tr><td><?= e(format_date($l['at'], true)) ?></td><td><?= e($actions[$l['action']] ?? $l['action']) ?></td><td><?= e($l['by']) ?></td><td><?= e(Talea\Core\Notices::changesText($l['fields'])) ?></td></tr>
<?php endforeach ?>
</tbody>
</table>
</div>
</details>
<?php endif ?>
