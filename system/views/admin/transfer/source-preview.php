<?php
/**
 * Import from another system (3.0, Import\Batch), step 2: what the export contains, what will not be converted, and the
 * mapping – what becomes a news item, a page, a category or a tag, whose the posts will be. Nothing has been written yet.
 *
 * @var Kaleta\Admin\Modules\Transfer $module
 * @var Kaleta\Core\App $app
 * @var string $csrf
 * @var array<string, mixed> $state  import state (Import\Batch::newState)
 * @var class-string<Kaleta\Import\Source> $source
 * @var list<string> $languages  language versions of the site, the first one is the default
 * @var list<array{category_id:int, name:string, language:string}> $categories  news categories
 * @var list<array{user_id:int, name:string, username:string}> $users  users the authors can be mapped to
 * @var bool $redirectsEnabled
 */
$p = $state['overview'];
$m = $state['mapping'];
$statuses = ['published' => 'published', 'draft' => 'drafts', 'scheduled' => 'scheduled'];
$byStatus = function (array $counts) use ($statuses): string {
    $parts = [];
    foreach ($counts as $s => $count) {
        $parts[] = (int) $count . ' ' . t($statuses[$s] ?? $s);
    }

    return implode(', ', $parts);
};
$choices = fn (string $name, array $options, string $current): string => '<select id="' . $name . '" name="' . $name . '">' . implode('', array_map(
    fn (string $value, string $label): string => '<option value="' . e($value) . '"' . ($value === $current ? ' selected' : '') . '>' . e(t($label)) . '</option>',
    array_keys($options), $options)) . '</select>';
?>
<?= $app->view->render('admin/transfer/steps', ['step' => 2]) ?>
<p><?= e(t('File %s – %s export of “%s”. Nothing has been imported yet; this is only an overview of what the file contains.', $state['file'], $source::name(), $state['web']['name'] !== '' ? $state['web']['name'] : $state['web']['url'])) ?></p>
<div class="tiles">
	<div class="tiles-item"><strong><?= (int) array_sum($p['articles']) ?></strong><span><?= e(t('Posts')) ?><?= $p['articles'] !== [] ? ': ' . e($byStatus($p['articles'])) : '' ?></span></div>
	<div class="tiles-item"><strong><?= (int) array_sum($p['pages']) ?></strong><span><?= e(t('Pages')) ?><?= $p['pages'] !== [] ? ': ' . e($byStatus($p['pages'])) : '' ?></span></div>
	<div class="tiles-item"><strong><?= (int) $p['categories'] ?></strong><span><?= e(t('Categories (those with posts are created)')) ?></span></div>
	<div class="tiles-item"><strong><?= (int) $p['tags'] ?></strong><span><?= e(t('Tags')) ?></span></div>
	<div class="tiles-item"><strong><?= (int) $p['images'] ?></strong><span><?= e(t('Images in texts')) ?></span></div>
	<div class="tiles-item"><strong><?= (int) $p['authors'] ?></strong><span><?= e(t('Authors')) ?></span></div>
</div>
<?php foreach (['post' => 'The first posts', 'page' => 'The first pages'] as $kind => $label): ?>
<?php if ($p['titles'][$kind] !== []): ?>
<p><strong><?= e(t($label)) ?>:</strong> <?= e(implode(' · ', $p['titles'][$kind])) ?></p>
<?php endif ?>
<?php endforeach ?>

<div class="notice notice-warning">
<p><strong><?= e(t('What will not be converted')) ?></strong></p>
<ul>
	<li><?= e(t('User accounts and passwords – choose below whose the posts will be.')) ?></li>
<?php foreach ($p['notes'] as $note): ?>
	<li><?= e(t($note)) ?></li>
<?php endforeach ?>
<?php if ($p['blocks'] !== []): ?>
	<li><?= e(t('Content blocks the import cannot convert – they are left out, the text around them stays:')) ?> <?= e(implode(', ', array_map(fn (string $block, int $count): string => $block . ' ' . $count . '×', array_keys($p['blocks']), $p['blocks']))) ?></li>
<?php endif ?>
<?php foreach ($p['warnings'] as $code => $count): ?>
	<li><?= e(Kaleta\Import\Preview::describe((string) $code, (int) $count)) ?></li>
<?php endforeach ?>
	<li><?= e(t('Images stay on the old site for now; after the import you can download them to your site with one button.')) ?></li>
</ul>
</div>

<form class="form" method="post" action="<?= e($module->url('source_run')) ?>">
<?= $csrf ?>
<input type="hidden" name="file" value="<?= e($state['file']) ?>">
<fieldset>
<legend><?= e(t('What becomes what')) ?></legend>
<?php if ($state['web']['url'] === ''): ?>
<div class="row"><label for="site_url"><?= e(t('Address of the old site')) ?></label><div><input class="textfield wide" type="url" id="site_url" name="site_url" value="<?= e($m['site_url']) ?>" placeholder="https://www.example.com" maxlength="300">
	<span class="help"><?= e(t('The export does not carry it. Images are downloaded from this address and the redirects from the old addresses count on it.')) ?></span></div></div>
<?php endif ?>
<div class="row"><label for="posts"><?= e(t('Posts (%s)', (int) array_sum($p['articles']))) ?></label><div><?= $choices('posts', ['news' => 'news items', 'skip' => 'do not import'], $m['posts']) ?></div></div>
<div class="row"><label for="pages"><?= e(t('Pages (%s)', (int) array_sum($p['pages']))) ?></label><div><?= $choices('pages', ['page' => 'pages', 'news' => 'news items', 'skip' => 'do not import'], $m['pages']) ?></div></div>
<div class="row"><label for="categories"><?= e(t('Categories')) ?></label><div><?= $choices('categories', ['category' => 'news categories (the first one of a post)', 'tag' => 'tags', 'skip' => 'do not import'], $m['categories']) ?></div></div>
<div class="row"><label for="tags"><?= e(t('Tags')) ?></label><div><?= $choices('tags', ['tag' => 'tags', 'skip' => 'do not import'], $m['tags']) ?></div></div>
<div class="row"><label for="default_category"><?= e(t('Put posts without a category into')) ?></label><div><select id="default_category" name="default_category">
	<option value="0"><?= e(t('a new “Uncategorised” category')) ?></option>
<?php foreach ($categories as $r): ?>
	<option value="<?= (int) $r['category_id'] ?>"<?= (int) $r['category_id'] === (int) $m['default_category'] ? ' selected' : '' ?>><?= e($r['name']) ?><?= $r['language'] !== '' ? ' (' . e($r['language']) . ')' : '' ?></option>
<?php endforeach ?>
</select></div></div>
<?php if (count($languages) > 1): ?>
<div class="row"><label for="language"><?= e(t('Language version')) ?></label><div><select id="language" name="language">
<?php foreach ($languages as $i => $code): ?>
	<option value="<?= $i === 0 ? '' : e($code) ?>"<?= ($i === 0 ? '' : $code) === $m['language'] ? ' selected' : '' ?>><?= e(Kaleta\Core\Language::AVAILABLE[$code][0] ?? $code) ?><?= $i === 0 ? ' – ' . e(t('default site language')) : '' ?></option>
<?php endforeach ?>
</select><span class="help"><?= e(t('Which language version of the site the new categories and pages belong to.')) ?></span></div></div>
<?php endif ?>
<div class="row"><span class="caption"><?= e(t('Options')) ?></span><div class="options">
	<label><input type="checkbox" name="drafts" value="1"<?= $m['drafts'] ? ' checked' : '' ?>> <?= e(t('drafts too (%s) – as hidden news items and pages', (int) (($p['articles']['draft'] ?? 0) + ($p['pages']['draft'] ?? 0)))) ?></label>
	<label><input type="checkbox" name="builder" value="1"<?= $m['builder'] ? ' checked' : '' ?>> <?= e(t('pages straight into the builder – edit them visually; the original text stays as a backup')) ?></label>
	<label><input type="checkbox" name="redirects" value="1"<?= $m['redirects'] ? ' checked' : '' ?>> <?= e(t('redirects from old addresses to new ones')) ?></label>
</div></div>
<?php if (!$redirectsEnabled): ?>
<p class="help"><?= e(t('The redirects will be saved but only take effect once you turn on the Redirects feature.')) ?></p>
<?php endif ?>
</fieldset>
<?php if ($state['dictionary']['authors'] !== []): ?>
<fieldset>
<legend><?= e(t('Authors')) ?></legend>
<p class="help"><?= e(t('No accounts are created. Each author’s posts belong to the user you choose; by default to you.')) ?></p>
<?php foreach ($state['dictionary']['authors'] as $key => $name): $field = 'author_' . substr(sha1((string) $key), 0, 12); ?>
<div class="row"><label for="<?= e($field) ?>"><?= e($name) ?></label><div><select id="<?= e($field) ?>" name="<?= e($field) ?>">
	<option value="0"><?= e(t('me (the importing user)')) ?></option>
<?php foreach ($users as $u): ?>
	<option value="<?= (int) $u['user_id'] ?>"<?= (int) $u['user_id'] === (int) ($m['authors'][$key] ?? 0) ? ' selected' : '' ?>><?= e($u['name'] !== '' ? $u['name'] : $u['username']) ?></option>
<?php endforeach ?>
</select></div></div>
<?php endforeach ?>
</fieldset>
<?php endif ?>
<p class="help"><?= e(t('Imported news is not announced: no webhook or IndexNow. Before a larger import, create a database backup in Settings → Backups and updates.')) ?></p>
<p class="buttons"><input class="btn" type="submit" value="<?= e(t('Start import')) ?>"> <a class="navigation" href="<?= e($module->url()) ?>"><?= e(t('Back')) ?></a></p>
</form>
