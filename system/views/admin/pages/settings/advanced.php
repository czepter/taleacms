<?php
/**
 * The Advanced tab of the settings sidebar: robots, the main (canonical) address, the name in breadcrumbs and an
 * administrator's code in the head, then the page's structured data with a way to add a type in the builder.
 * The robots directives are seo/robots.php.
 *
 * @var Talea\Core\App $app
 * @var Talea\Admin\Modules\Pages $module
 * @var string $csrf
 * @var array<string, mixed> $page
 * @var string $formStart
 * @var bool $hasBuild
 * @var list<string> $structured the structured-data types in the page's build
 * @var string $version the version of the draft "Add a type" builds on
 */
$dir = Talea\Core\Language::dirAttribute(Talea\Core\Language::ofContent($app->settings(), (string) $page['language']));
?>
<?= $formStart ?>
<section class="website-settings-section" aria-labelledby="ws-robots">
	<h3 id="ws-robots"><?= e(t('Robots')) ?></h3>
	<label class="website-option"><input type="radio" name="noindex" value="0"<?= $page['noindex'] ? '' : ' checked' ?>> <?= e(t('Show in search results')) ?></label>
	<label class="website-option"><input type="radio" name="noindex" value="1"<?= $page['noindex'] ? ' checked' : '' ?>> <?= e(t('Hide from search engines (noindex)')) ?></label>
<?= $app->view->render('admin/seo/robots', ['robots' => (string) $page['robots'], 'labelClass' => 'website-option']) ?>
</section>
<section class="website-settings-section" aria-labelledby="ws-addresses">
	<h3 id="ws-addresses"><?= e(t('Addresses')) ?></h3>
	<div class="website-field"><label for="ws-canonical"><?= e(t('Main address (canonical)')) ?></label><input class="textfield" type="url" id="ws-canonical" name="canonical_url" value="<?= e((string) $page['canonical_url']) ?>" maxlength="500" placeholder="https://…"><span class="help"><?= e(t('Only when the same text lives at another address too.')) ?></span></div>
	<div class="website-field"><label for="ws-crumb"><?= e(t('Name in breadcrumbs')) ?></label><input class="textfield" type="text" id="ws-crumb" name="breadcrumb_title" value="<?= e((string) $page['breadcrumb_title']) ?>" maxlength="200" placeholder="<?= e($page['title']) ?>"<?= $dir ?>></div>
</section>
<?php if ($app->auth()->isAdmin()): ?>
<section class="website-settings-section" aria-labelledby="ws-head">
	<h3 id="ws-head"><?= e(t('Code in the head of this page')) ?></h3>
	<textarea class="textbox code" name="head_code" rows="4" spellcheck="false" aria-labelledby="ws-head" placeholder="&lt;script&gt;…&lt;/script&gt;"><?= e((string) ($page['head_code'] ?? '')) ?></textarea>
	<p class="help"><?= e(t('Only for this page, after the code for the whole site (Settings → Analytics) – e.g. the conversion tag of a landing page. Mind the cookie consent: code that tracks visitors belongs in the marketing code.')) ?></p>
</section>
<?php endif ?>
</form>
<section class="website-settings-section" aria-labelledby="ws-structured">
	<h3 id="ws-structured"><?= e(t('Structured data')) ?></h3>
<?php foreach ($structured as $type): ?>
	<div class="website-type"><?= e($type) ?></div>
<?php endforeach ?>
	<form class="website-add-type" method="post" action="<?= e($module->url('seo_add_type')) ?>"><?= $csrf ?><input type="hidden" name="id" value="<?= e($page['public_id']) ?>"><input type="hidden" name="version" value="<?= e($version) ?>">
		<select name="type" aria-label="<?= e(t('Type')) ?>"<?= $hasBuild ? '' : ' disabled' ?>>
<?php foreach (Talea\Builder\StructuredData::types() as $type): ?>
			<option><?= e($type) ?></option>
<?php endforeach ?>
		</select>
		<button class="navigation" type="submit"<?= $hasBuild ? '' : ' disabled' ?>><?= e(t('Add a type')) ?></button>
	</form>
<?php if (!$hasBuild): ?>
	<p class="help"><?= e(t('Open the page in the builder to add structured data.')) ?></p>
<?php endif ?>
</section>
