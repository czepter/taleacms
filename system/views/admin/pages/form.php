<?php
/**
 * @var Talea\Admin\Modules\Pages $module
 * @var string $csrf
 * @var array<string, mixed> $page
 * @var array<string, string> $errors
 * @var bool $home  this is the home page of the site
 * @var ?bool $inMenu  the page is in the built menu (null = the menu is built automatically from v_menu)
 * @var bool $customMenu  the site has a built main menu
 * @var list<array{page_id:int, title:string, slug:string}> $parents  possible parent pages
 * @var list<array{revision_id:int, created_at:string, title:string, user_id:?string}> $versions  older versions of the text
 */
$segment = basename((string) $page['slug']);
$prefix = '';
foreach ($parents as $r) {
    if ((int) $r['page_id'] === (int) ($page['parent_id'] ?? 0)) {
        $prefix = $r['slug'] . '/';
    }
}
$error = fn (string $field): string => isset($errors[$field]) ? '<span class="error-field" role="alert">' . e(t($errors[$field])) . '</span>' : '';
?>
<p class="navigation-row"><a class="navigation" href="<?= e($module->url()) ?>"><?= e(t('Back to overview')) ?></a>
<?php if ($page['page_id']): ?>
	<a class="navigation" href="<?= e($app->url(($page['language'] ?? '') !== '' ? $page['language'] . '/' . ($home ? '' : $page['slug']) : ($home ? '' : $page['slug'])) . ($page['visible'] ? '' : '?build=draft')) ?>" target="_blank" rel="noopener"><?= e(t($page['visible'] ? 'View on site' : 'Preview hidden page')) ?></a>
<?php endif ?></p>
<?php if (($page['build_draft'] ?? null) !== null): ?>
<p class="notice notice-warning"><?= e(t(($page['build'] ?? null) !== null ? 'The builder has work-in-progress changes that are not on the site yet.' : 'You are building this page in the builder. The site still shows the text below – once you publish in the builder, the build replaces it.')) ?>
	<a href="<?= e($module->url('builder', ['id' => $page['public_id']])) ?>"><?= e(t('Open the builder')) ?></a></p>
<?php endif ?>
<form class="form" method="post" action="<?= e($module->url('save')) ?>" data-draft="page-<?= e($page['public_id'] ?: 'new') ?>">
<?= $csrf ?>
<input type="hidden" name="page_id" value="<?= e($page['public_id']) ?>">
<div class="row span-all">
	<label for="title"><?= e(t('Page title')) ?></label>
	<input class="textfield wide title-field" type="text" id="title" name="title" value="<?= e($page['title']) ?>" maxlength="200" required><?= $error('title') ?>
</div>
<?php if (!$page['page_id']): ?>
<div class="row">
	<label for="template"><?= e(t('Start from a template')) ?></label>
	<div><select id="template" name="template">
		<option value=""><?= e(t('blank page (text)')) ?></option>
<?php foreach (Talea\Builder\Library::PAGE_TEMPLATES as $key => [$name]): ?>
		<option value="<?= e($key) ?>"><?= e(t($name)) ?></option>
<?php endforeach ?>
	</select><span class="help"><?= e(t('A template builds the page from ready-made sections with sample texts and opens it in the builder.')) ?></span></div>
</div>
<?php endif ?>
<?php if (($page['build'] ?? null) !== null): ?>
<div class="row span-all">
	<p class="notice"><?= e(t('This page\'s content is built in the builder.')) ?> <a class="btn" href="<?= e($module->url('builder', ['id' => $page['public_id']])) ?>"><?= e(t('Open the builder')) ?></a></p>
	<input type="hidden" name="text" value="<?= e($page['text']) ?>">
</div>
<?php else: ?>
<div class="row span-all">
	<label for="text"><?= e(t('Content')) ?></label>
	<textarea class="textbox tall" id="text" name="text" rows="18" data-editor><?= e($page['text']) ?></textarea>
<?php if ($page['page_id']): ?>
	<span class="help"><?= e(t('Want to build the page from sections, columns and buttons?')) ?> <a href="<?= e($module->url('builder', ['id' => $page['public_id']])) ?>"><?= e(t('Open in the builder')) ?></a></span>
<?php endif ?>
</div>
<?php endif ?>
<div class="row">
	<label for="parent_id"><?= e(t('Parent page')) ?></label>
	<div><select id="parent_id" name="parent_id">
		<option value="0"><?= e(t('— none (top level) —')) ?></option>
<?php foreach ($parents as $r): ?>
		<option value="<?= e($r['public_id']) ?>"<?= $r['public_id'] === $parentPublicId ? ' selected' : '' ?>><?= e(str_repeat('– ', substr_count($r['slug'], '/')) . $r['title']) ?></option>
<?php endforeach ?>
	</select><span class="help"><?= e(t('A subpage has an address under its parent (/services/kitchens) and appears in its breadcrumbs.')) ?></span></div>
</div>
<div class="row">
	<label for="slug"><?= e(t('URL')) ?></label>
	<div><span class="help-inline">/<?= e($prefix) ?></span><input class="textfield" type="text" id="slug" name="slug" value="<?= e($segment) ?>" maxlength="110" placeholder="<?= e(t('generated from the title, e.g. o-nas')) ?>"><?= $error('slug') ?></div>
</div>
<details class="advanced"<?= $page['description'] !== '' || $page['seo_title'] !== '' || $page['image'] !== '' || $page['noindex'] || !empty($page['password_hash']) || isset($errors['page_password']) || array_filter($contentCheck ?? [], fn (array $r): bool => !$r['ok']) !== [] ? ' open' : '' ?>>
<summary><?= e(t('Search engines and sharing')) ?></summary>
<div class="row">
	<label for="seo_title"><?= e(t('Search engine title')) ?></label>
	<input class="textfield wide" type="text" id="seo_title" name="seo_title" value="<?= e($page['seo_title']) ?>" maxlength="200" placeholder="<?= e(t('empty = page name')) ?>">
</div>
<div class="row">
	<label for="description"><?= e(t('Search engine description')) ?></label>
	<div><input class="textfield wide" type="text" id="description" name="description" value="<?= e($page['description']) ?>" maxlength="300">
	<span class="help"><?= e(t('One or two sentences on what visitors will find on the page (up to 160 characters).')) ?></span></div>
</div>
<div class="row">
	<label for="image"><?= e(t('Sharing image')) ?></label>
	<div><input class="textfield wide" type="text" id="image" name="image" value="<?= e($page['image']) ?>" maxlength="255" placeholder="<?= e(t('empty = default image from Settings')) ?>" data-image>
	<span class="help"><?= e(t('Shown when the link is shared on Facebook, LinkedIn or Teams (ideally 1200 × 630 px).')) ?></span></div>
</div>
<div class="row">
	<span class="caption"><?= e(t('Options')) ?></span>
	<div class="options"><label><input type="checkbox" name="noindex" value="1"<?= $page['noindex'] ? ' checked' : '' ?>> <?= e(t('Hide from search engines (noindex)')) ?></label></div>
</div>
<div class="row">
	<label for="page_password"><?= e(t('Page password')) ?></label>
	<div><input class="textfield" type="password" id="page_password" name="page_password" autocomplete="new-password" minlength="<?= Talea\Core\PageLock::MIN_LENGTH ?>" placeholder="<?= e(!empty($page['password_hash']) ? t('protected – type a new password to change it') : t('none – the page is public')) ?>">
	<?php if (!empty($page['password_hash'])): ?><label><input type="checkbox" name="remove_password" value="1"> <?= e(t('Remove the password')) ?></label><?php endif ?>
	<?= $error('page_password') ?>
	<span class="help"><?= e(t('Visitors see the page only after entering the password – e.g. a price list for partners. It is not an account: whoever knows the password reads the page. A protected page is never in search engines, the sitemap or the site search.')) ?></span></div>
</div>
<?php if ($app->auth()->isAdmin()): ?>
<div class="row">
	<label for="head_code"><?= e(t('Code in the head of this page')) ?></label>
	<div><textarea class="textfield wide code" id="head_code" name="head_code" rows="4" spellcheck="false" placeholder="&lt;script&gt;…&lt;/script&gt;"><?= e((string) ($page['head_code'] ?? '')) ?></textarea>
	<span class="help"><?= e(t('Only for this page, after the code for the whole site (Settings → Analytics) – e.g. the conversion tag of a landing page. Mind the cookie consent: code that tracks visitors belongs in the marketing code.')) ?></span></div>
</div>
<?php endif ?>
<?php if ($page['page_id']): ?>
<?= $app->view->render('admin/content_check', ['results' => $contentCheck]) ?>
<?php endif ?>
</details>
<?= $app->view->render('admin/language_field', ['app' => $app, 'value' => (string) ($page['language'] ?? ''), 'translationOf' => $app->db()->publicId('pages', (int) ($page['translation_of'] ?? 0)), 'originals' => $app->db()->pairs("SELECT public_id, title FROM {pages} WHERE language = '' AND deleted_at IS NULL ORDER BY title"), 'hint' => '']) ?>
<div class="row">
	<span class="caption"><?= e(t('Display')) ?></span>
	<div class="options">
		<label><input type="checkbox" name="visible" value="1"<?= $page['visible'] ? ' checked' : '' ?>> <?= e(t('Publish page')) ?></label><?= $home ? ' <span class="badge">' . e(t('site home page')) . '</span>' : '' ?><?= $error('visible') ?><br>
		<span class="help" data-active-when="visible="><label for="publish_at"><?= e(t('Publish the hidden page automatically at:')) ?></label> <input class="textfield" type="datetime-local" id="publish_at" name="publish_at" value="<?= e(($page['publish_at'] ?? null) ? date('Y-m-d\TH:i', strtotime($page['publish_at'])) : '') ?>"></span><br>
		<label><input type="checkbox" name="in_menu" value="1"<?= ($inMenu ?? (bool) $page['in_menu']) ? ' checked' : '' ?>> <?= e(t('Show in the site\'s main navigation')) ?></label>
<?php if ($customMenu): ?>
		<span class="help"><?= e(t('The site has a custom menu – the page is added to its end. Change the order and submenus in Appearance → Menu.')) ?></span>
<?php endif ?>
	</div>
</div>
<div class="row">
	<label for="valid_until"><?= e(t('True until')) ?></label>
	<div><input class="textfield" type="date" id="valid_until" name="valid_until" value="<?= e((string) ($page['valid_until'] ?? '')) ?>">
	<span class="help"><?= e(t('After this day the page hides itself. Empty = always.')) ?></span></div>
</div>
<div class="row">
	<label for="review_by"><?= e(t('Review by')) ?></label>
	<div><input class="textfield" type="date" id="review_by" name="review_by" value="<?= e((string) ($page['review_by'] ?? '')) ?>">
	<span class="help"><?= e(t('On this day the site audit and the alert e-mail remind you to check it.')) ?></span></div>
</div>
<div class="row">
	<label for="sort_order"><?= e(t('Order in navigation')) ?></label>
	<div><input class="textfield" type="number" id="sort_order" name="sort_order" value="<?= (int) $page['sort_order'] ?>" min="0" max="65535">
	<span class="help"><?= e(t('Lower number = earlier in the page list and in the automatic menu.')) ?></span></div>
</div>
<p class="buttons"><button class="btn" type="submit"><?= e(t('Save')) ?></button><?php if (($page['build'] ?? null) === null): ?> <button class="navigation" type="submit" name="after_save" value="builder"><?= e(t('Save and open in the builder')) ?></button><?php endif ?></p>
</form>
<?php if ($versions !== []): ?>
<details class="advanced">
<summary><?= e(t('Text history (%s)', count($versions))) ?></summary>
<ul class="revisions">
<?php foreach ($versions as $v): ?>
	<li><?= e(format_date($v['created_at'], true)) ?><?= $v['user_id'] ? ' · ' . e($v['user_id']) : '' ?> · <?= e($v['title']) ?>
		<form class="inline" method="post" action="<?= e($module->url('restore_version')) ?>" data-confirm="<?= e(t('Restore this version of the text? The current version stays in the history.')) ?>"><?= $csrf ?><input type="hidden" name="revision_id" value="<?= (int) $v['revision_id'] ?>"><button class="navigation" type="submit"><?= e(t('Restore')) ?></button></form></li>
<?php endforeach ?>
</ul>
</details>
<?php endif ?>
<?php if ($page['page_id']): ?>
<div class="navigation-row actions-bottom">
<a class="navigation" href="<?= e($module->url('export', ['id' => $page['public_id']])) ?>"><?= e(t('Download as JSON')) ?></a>
<form class="inline" method="post" action="<?= e($module->url('duplicate')) ?>"><?= $csrf ?><input type="hidden" name="page_id" value="<?= e($page['public_id']) ?>"><input type="hidden" name="title" value="<?= e($page['title']) ?>"><button class="navigation" type="submit"><?= e(t('Duplicate page')) ?></button></form>
<?php if (($page['build'] ?? null) !== null): ?>
<form class="inline" method="post" action="<?= e($module->url('build_text')) ?>" data-confirm="<?= e(t('Return the page to plain text? The build stays in versions and you can go back to it.')) ?>"><?= $csrf ?><input type="hidden" name="page_id" value="<?= e($page['public_id']) ?>"><button class="navigation danger" type="submit"><?= e(t('Return page to text')) ?></button></form>
<?php endif ?>
</div>
<?php endif ?>
