<?php
/**
 * Collection item form – fields according to the collection definition.
 *
 * @var Talea\Core\App $app
 * @var Talea\Admin\Modules\Collections $module
 * @var string $csrf
 * @var array<string, mixed> $k
 * @var array<string, mixed> $p
 * @var list<array{idr: int, datum: string, kdo: ?string}> $versions  earlier versions of the item (1.9)
 * @var list<array<string, mixed>> $noticeLog  the audit trail of a notice (2.11, Core\Notices), newest first
 */
use Talea\Core\Language;

$languages = Language::additional($app->settings());
?>
<form class="form" method="post" action="<?= e($module->url('save_item')) ?>">
<?= $csrf ?>
<input type="hidden" name="collection_id" value="<?= e($k['public_id']) ?>">
<input type="hidden" name="item_id" value="<?= e($p['public_id']) ?>">
<div class="row"><label for="name"><?= e(t('Name')) ?></label><div><input class="textfield wide" id="name" name="name" value="<?= e($p['name']) ?>" maxlength="200" required></div></div>
<?php foreach ($k['fields'] as $field): $h = (string) ($p['data'][$field['key']] ?? ''); $id = 'field-' . $field['key']; $displayName = 'data[' . $field['key'] . ']'; ?>
<div class="row<?= $field['type'] === 'html' ? ' span-all' : '' ?>">
	<label for="<?= e($id) ?>"><?= e($field['label']) ?></label>
	<div><?= match ($field['type']) {
        'lines' => '<textarea class="textbox low" id="' . e($id) . '" name="' . e($displayName) . '" rows="4">' . e($h) . '</textarea>',
        'html' => '<textarea class="textbox" id="' . e($id) . '" name="' . e($displayName) . '" rows="10" data-editor>' . e($h) . '</textarea>',
        'image' => '<input class="textfield wide" id="' . e($id) . '" name="' . e($displayName) . '" value="' . e($h) . '" maxlength="500" data-image>',
        'link' => '<input class="textfield wide" id="' . e($id) . '" name="' . e($displayName) . '" value="' . e($h) . '" maxlength="500" placeholder="https://… ' . e(t('or')) . '/page">',
        'number' => '<input class="textfield" id="' . e($id) . '" name="' . e($displayName) . '" value="' . e($h) . '" inputmode="decimal" size="12">',
        'date' => '<input class="textfield" type="date" id="' . e($id) . '" name="' . e($displayName) . '" value="' . e($h) . '">',
        // a whole day is stored without a time; the input shows it at midnight, which saves back as the whole day (Collections::cleanDateTime)
        'datetime' => '<input class="textfield" type="datetime-local" id="' . e($id) . '" name="' . e($displayName) . '" value="' . e($h === '' ? '' : (strlen($h) === 10 ? $h . 'T00:00' : str_replace(' ', 'T', $h))) . '"> <span class="help">' . e(t('00:00 = the whole day')) . '</span>',
        'file' => '<input class="textfield wide" id="' . e($id) . '" name="' . e($displayName) . '" value="' . e($h) . '" maxlength="500" data-file>',
        'location' => '<input class="textfield" id="' . e($id) . '" name="' . e($displayName) . '" value="' . e($h) . '" maxlength="40" placeholder="50.0875, 14.4214" inputmode="decimal">',
        'radio' => '<select id="' . e($id) . '" name="' . e($displayName) . '"><option value="">–</option>' . implode('', array_map(fn (string $option): string => '<option value="' . e($option) . '"' . ($option === $h ? ' selected' : '') . '>' . e(t($option)) . '</option>',
            (array) ($field['options'] ?? []))) . '</select>',
        'item' => '<select id="' . e($id) . '" name="' . e($displayName) . '"><option value="">–</option>' . implode('', array_map(fn (string $slug, string $name): string => '<option value="' . e($slug) . '"' . ($slug === $h ? ' selected' : '') . '>' . e($name) . '</option>',
            array_keys($choices = Talea\Builder\Collections::choices($app->db(), (string) ($field['collection'] ?? ''))), $choices)) . '</select>',
        default => '<input class="textfield wide" id="' . e($id) . '" name="' . e($displayName) . '" value="' . e($h) . '" maxlength="500">',
    } ?> <code class="help">{{<?= e($field['key']) ?>}}</code></div>
</div>
<?php endforeach ?>
<?php if ($k['detail']): ?>
<details class="advanced"<?= $p['description'] !== '' || $p['seo_title'] !== '' || $p['image'] !== '' || $p['noindex'] ? ' open' : '' ?>>
<summary><?= e(t('Search engines and sharing')) ?></summary>
<div class="row"><label for="seo_title"><?= e(t('Search engine title')) ?></label><div><input class="textfield wide" id="seo_title" name="seo_title" value="<?= e($p['seo_title']) ?>" maxlength="200" placeholder="<?= e(t('empty = the item name')) ?>"></div></div>
<div class="row"><label for="description"><?= e(t('Search engine description')) ?></label><div><input class="textfield wide" id="description" name="description" value="<?= e($p['description']) ?>" maxlength="300">
	<span class="help"><?= e(t('One or two sentences for search results (up to 160 characters). Empty = the beginning of the first longer text field.')) ?></span></div></div>
<div class="row"><label for="image"><?= e(t('Sharing image')) ?></label><div><input class="textfield wide" id="image" name="image" value="<?= e($p['image']) ?>" maxlength="255" placeholder="<?= e(t('empty = the first image field')) ?>" data-image>
	<span class="help"><?= e(t('Shown when the link is shared on Facebook, LinkedIn or Teams (ideally 1200 × 630 px).')) ?></span></div></div>
<div class="row"><span class="caption"><?= e(t('Options')) ?></span><div class="options"><label><input type="checkbox" name="noindex" value="1"<?= $p['noindex'] ? ' checked' : '' ?>> <?= e(t('Hide from search engines (noindex)')) ?></label>
	<span class="help"><?= e(t('The item page stays reachable, but it is left out of search engines, the sitemap, llms.txt and site search.')) ?></span></div></div>
</details>
<?php endif ?>
<details class="advanced"<?= ($p['publish_at'] ?? null) !== null || ($p['valid_until'] ?? null) !== null || ($p['review_by'] ?? null) !== null ? ' open' : '' ?>>
<summary><?= e(t('Address, order and visibility')) ?></summary>
<?php if ($k['detail']): ?>
<div class="row"><label for="slug"><?= e(t('URL')) ?></label><div><input class="textfield" id="slug" name="slug" value="<?= e($p['slug']) ?>" maxlength="150"><span class="help">/<?= e($k['slug']) ?>/…</span></div></div>
<?php else: ?>
<input type="hidden" name="slug" value="<?= e($p['slug']) ?>">
<?php endif ?>
<div class="row"><label for="sort_order"><?= e(t('Order')) ?></label><div><input class="textfield" type="number" id="sort_order" name="sort_order" value="<?= (int) $p['sort_order'] ?>" min="-9999" max="9999"><span class="help"><?= e(t('Smaller number = earlier in the list.')) ?></span></div></div>
<div class="row"><span class="caption"><?= e(t('Display')) ?></span><div class="options"><label><input type="checkbox" name="visible" value="1"<?= $p['visible'] ? ' checked' : '' ?>> <?= e(t('published on the site')) ?></label><br>
	<span class="help" data-active-when="visible="><label for="publish_at"><?= e(t('Publish the hidden item automatically at:')) ?></label> <input class="textfield" type="datetime-local" id="publish_at" name="publish_at" value="<?= e(($p['publish_at'] ?? null) ? date('Y-m-d\TH:i', strtotime($p['publish_at'])) : '') ?>"></span></div></div>
<div class="row">
	<label for="valid_until"><?= e(t('True until')) ?></label>
	<div><input class="textfield" type="date" id="valid_until" name="valid_until" value="<?= e((string) ($p['valid_until'] ?? '')) ?>">
	<span class="help"><?= e(t('After this day the item hides itself. Empty = always.')) ?></span></div>
</div>
<div class="row">
	<label for="review_by"><?= e(t('Review by')) ?></label>
	<div><input class="textfield" type="date" id="review_by" name="review_by" value="<?= e((string) ($p['review_by'] ?? '')) ?>">
	<span class="help"><?= e(t('On this day the site audit and the alert e-mail remind you to check it.')) ?></span></div>
</div>
<?php if ($languages !== []): ?>
<div class="row"><label for="language"><?= e(t('Language')) ?></label><div><select id="language" name="language">
	<option value=""><?= e(Language::AVAILABLE[Language::defaults($app->settings())][0]) ?></option>
<?php foreach ($languages as $j): ?>
	<option value="<?= e($j) ?>"<?= $p['language'] === $j ? ' selected' : '' ?>><?= e(Language::AVAILABLE[$j][0]) ?></option>
<?php endforeach ?>
</select></div></div>
<?php endif ?>
</details>
<p class="buttons"><input class="btn" type="submit" value="<?= e(t('Save item')) ?>"> <a class="navigation" href="<?= e($module->url('items', ['id' => $k['public_id']])) ?>"><?= e(t('Back')) ?></a><?php if ($p['item_id'] > 0 && Talea\Builder\EmailSignature::isPeople($k)): ?>
	<a class="navigation" href="<?= e($module->url('signature', ['id' => $k['public_id'], 'item' => $p['public_id']])) ?>"><?= e(t('E-mail signature')) ?></a><?php endif ?></p>
</form>
<?php if (($versions ?? []) !== []): ?>
<details class="advanced">
<summary><?= e(t('Item history (%s)', count($versions))) ?></summary>
<ul class="revisions">
<?php foreach ($versions as $v): ?>
	<li><?= e(format_date($v['created_at'], true)) ?><?= $v['user_name'] ? ' · ' . e($v['user_name']) : '' ?>
		<form class="inline" method="post" action="<?= e($module->url('restore_item_version')) ?>" data-confirm="<?= e(t('Restore this version of the item? The current version stays in the history.')) ?>"><?= $csrf ?><input type="hidden" name="collection_id" value="<?= e($k['public_id']) ?>"><input type="hidden" name="item_id" value="<?= e($p['public_id']) ?>"><input type="hidden" name="revision_id" value="<?= (int) $v['revision_id'] ?>"><button class="navigation" type="submit"><?= e(t('Restore')) ?></button></form></li>
<?php endforeach ?>
</ul>
</details>
<?php endif ?>
<?php if (($noticeLog ?? []) !== []): $actions = ['created' => t('Created'), 'changed' => t('Changed'), 'posted' => t('Posted'), 'taken_down' => t('Taken down')]; ?>
<details class="advanced" open>
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
