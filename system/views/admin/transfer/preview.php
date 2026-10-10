<?php
/**
 * WordPress import, step 2: preview – what the file contains, what will not be converted, and the import options. Nothing has been written to the database yet.
 *
 * @var Kaleta\Admin\Modules\Transfer $module
 * @var Kaleta\Core\App $app
 * @var string $csrf
 * @var array<string, mixed> $state  import state (Core\WpImport::newState)
 * @var list<string> $languages  language versions of the site, the first one is the default
 * @var list<array{category_id:int, name:string, language:string}> $categories  news categories
 * @var bool $redirectsEnabled
 */
$p = $state['overview'];
$options = $state['options'];
$statuses = ['publish' => 'published', 'future' => 'scheduled', 'draft' => 'drafts', 'pending' => 'pending review', 'private' => 'private', 'trash' => 'in trash', 'auto-draft' => 'auto-drafts', 'inherit' => 'revisions'];
$byStatus = function (array $counts) use ($statuses): string {
    $parts = [];
    foreach ($counts as $s => $count) {
        $parts[] = (int) $count . ' ' . t($statuses[$s] ?? 'other');
    }

    return implode(', ', $parts);
};
$converts = fn (array $counts): int => array_sum(array_intersect_key($counts, ['publish' => 1, 'future' => 1, 'draft' => 1, 'pending' => 1]));
?>
<?= $app->view->render('admin/transfer/steps', ['step' => 2]) ?>
<p><?= e(t('File %s – site “%s” (%s). Nothing has been imported yet; this is only an overview of what the file contains.', $state['file'], $state['web']['name'], $state['web']['url'])) ?></p>
<div class="tiles">
	<div class="tiles-item"><strong><?= (int) array_sum($p['articles']) ?></strong><span><?= e(t('Posts')) ?><?= $p['articles'] !== [] ? ': ' . e($byStatus($p['articles'])) : '' ?></span></div>
	<div class="tiles-item"><strong><?= (int) array_sum($p['pages']) ?></strong><span><?= e(t('Pages')) ?><?= $p['pages'] !== [] ? ': ' . e($byStatus($p['pages'])) : '' ?></span></div>
	<div class="tiles-item"><strong><?= (int) $p['categories'] ?></strong><span><?= e(t('Categories (those with posts are created)')) ?></span></div>
	<div class="tiles-item"><strong><?= (int) $p['tags'] ?></strong><span><?= e(t('Tags')) ?></span></div>
	<div class="tiles-item"><strong><?= (int) $p['attachments'] ?></strong><span><?= e(t('Files in the media library')) ?> · <?= e(t('images in texts: %s', (int) $p['images'])) ?></span></div>
	<div class="tiles-item"><strong><?= (int) $p['authors'] ?></strong><span><?= e(t('Authors')) ?></span></div>
</div>
<?php foreach ($p['types'] ?? [] as $type => $t): ?>
<?php $prefixes = $t['prefixes']; arsort($prefixes); $address = (string) (array_key_first($prefixes) ?? $type); ?>
<p><?= e(t('Custom post type “%s”: %s items become a collection with item pages at /%s/…, with the fields %s.', $type, (int) $t['count'], $address,
    $t['fields'] === [] ? t('none') : implode(', ', array_map(fn (string $key, array $votes): string => $key . ' (' . t(Kaleta\Builder\Collections::FIELD_TYPES[Kaleta\Core\WpTypes::fieldType($votes)] ?? 'text') . ')', array_keys($t['fields']), $t['fields'])))) ?>
<?php if ($t['left_out'] !== []): ?> <?= e(t('Left out (repeaters, galleries or relationships – Claude can move them by hand): %s.', implode(', ', array_keys($t['left_out'])))) ?><?php endif ?></p>
<?php endforeach ?>
<?php foreach ($p['seo'] ?? [] as $plugin => $n): ?>
<p><?= e(t('SEO plugin %s: %s custom titles, %s meta descriptions, %s noindex – they go into the SEO fields of the news items and pages. A title made only of the plugin’s variables is skipped; the site builds it itself.', $plugin, (int) $n['title'], (int) $n['description'], (int) $n['noindex'])) ?><?= (int) $n['canonical'] > 0 ? ' ' . e(t('Canonical URLs (%s) are not transferred.', (int) $n['canonical'])) : '' ?></p>
<?php endforeach ?>

<div class="notice notice-warning">
<p><strong><?= e(t('What will not be converted')) ?></strong></p>
<ul>
	<li><?= e(t('User accounts and passwords – the news items will belong to you. Comments are not transferred.')) ?></li>
	<li><?= e(t('Menus, widgets, appearance and plugin settings – you will rebuild the navigation on the new site.')) ?></li>
	<li><?= e(t('Redirects managed by SEO plugins (SmartCrawl, Yoast SEO, Rank Math) are not part of the export – they come over separately.')) ?></li>
	<li><?= e(t('Private posts, trash, revisions and auto-drafts. A password-protected post is imported as a draft.')) ?></li>
<?php if ($p['other'] !== []): ?>
	<li><?= e(t('Custom content types:')) ?> <?= e(implode(', ', array_map(fn (string $type, int $count): string => $type . ' (' . $count . ')', array_keys($p['other']), $p['other']))) ?></li>
<?php endif ?>
<?php if ($p['shortcodes'] !== []): ?>
	<li><?= e(t('Plug-in shortcodes (forms, page builders…) – the tag disappears, the text inside stays:')) ?> <?= e(implode(', ', array_map(fn (string $z, int $count): string => '[' . $z . '] ' . $count . '×', array_keys($p['shortcodes']), $p['shortcodes']))) ?></li>
<?php endif ?>
	<li><?= e(t('Images stay on the old site for now; after the import you can download them to your site with one button.')) ?></li>
</ul>
</div>

<form class="form" method="post" action="<?= e($module->url('run')) ?>">
<?= $csrf ?>
<input type="hidden" name="file" value="<?= e($state['file']) ?>">
<fieldset>
<legend><?= e(t('Import options')) ?></legend>
<?php if (count($languages) > 1): ?>
<div class="row"><label for="language"><?= e(t('Language version')) ?></label><div><select id="language" name="language">
<?php foreach ($languages as $i => $code): ?>
	<option value="<?= $i === 0 ? '' : e($code) ?>"<?= ($i === 0 ? '' : $code) === $options['language'] ? ' selected' : '' ?>><?= e(Kaleta\Core\Language::AVAILABLE[$code][0] ?? $code) ?><?= $i === 0 ? ' – ' . e(t('default site language')) : '' ?></option>
<?php endforeach ?>
</select><span class="help"><?= e(t('Which language version of the site the new categories and pages belong to.')) ?></span></div></div>
<?php endif ?>
<div class="row"><span class="caption"><?= e(t('What to import')) ?></span><div class="options">
	<label><input type="checkbox" name="drafts" value="1"<?= $options['drafts'] ? ' checked' : '' ?>> <?= e(t('drafts and posts pending review (%s)', (int) (($p['articles']['draft'] ?? 0) + ($p['articles']['pending'] ?? 0)))) ?></label>
	<label><input type="checkbox" name="pages" value="1"<?= $options['pages'] ? ' checked' : '' ?>> <?= e(t('pages (%s)', $converts($p['pages']))) ?></label>
	<label><input type="checkbox" name="builder" value="1"<?= ($options['builder'] ?? true) ? ' checked' : '' ?>> <?= e(t('pages straight into the builder – edit them visually; the original text stays as a backup')) ?></label>
	<label><input type="checkbox" name="redirects" value="1"<?= $options['redirects'] ? ' checked' : '' ?>> <?= e(t('redirects from old addresses to new ones')) ?></label>
<?php if (($p['types'] ?? []) !== []): ?>
	<label><input type="checkbox" name="collections" value="1"<?= ($options['collections'] ?? true) ? ' checked' : '' ?>> <?= e(t('custom post types as collections (%s)', implode(', ', array_keys($p['types'])))) ?></label>
<?php endif ?>
</div></div>
<?php if (!$redirectsEnabled): ?>
<p class="help"><?= e(t('The redirects will be saved but only take effect once you turn on the Redirects feature.')) ?></p>
<?php endif ?>
<div class="row"><label for="category"><?= e(t('Put posts without a category into')) ?></label><div><select id="category" name="category">
	<option value="0"><?= e(t('a new “Uncategorised” category')) ?></option>
<?php foreach ($categories as $r): ?>
	<option value="<?= e($r['public_id']) ?>"<?= (int) $r['category_id'] === (int) $options['category'] ? ' selected' : '' ?>><?= e($r['name']) ?><?= $r['language'] !== '' ? ' (' . e($r['language']) . ')' : '' ?></option>
<?php endforeach ?>
</select></div></div>
</fieldset>
<p class="help"><?= e(t('Imported news is not announced: no webhook or IndexNow. Before a larger import, create a database backup in Settings → Backups and updates.')) ?></p>
<p class="buttons"><input class="btn" type="submit" value="<?= e(t('Start import')) ?>"> <a class="navigation" href="<?= e($module->url()) ?>"><?= e(t('Back')) ?></a></p>
</form>
