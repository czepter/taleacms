<?php
/**
 * The SEO tab of the settings sidebar: the search title and description, the focus keywords and the key page, then the
 * content check of the saved version. key_page=0 goes before its checkbox, so unticking clears it.
 *
 * @var Talea\Core\App $app
 * @var array<string, mixed> $page
 * @var string $formStart
 * @var list<array{check: string, ok: bool, message: string}> $contentCheck
 */
$dir = Talea\Core\Language::dirAttribute(Talea\Core\Language::ofContent($app->settings(), (string) $page['language']));
?>
<?= $formStart ?>
<section class="website-settings-section" aria-label="<?= e(t('SEO')) ?>">
	<div class="website-field"><label for="ws-seo-title"><?= e(t('Search engine title')) ?></label><input class="textfield" type="text" id="ws-seo-title" name="seo_title" value="<?= e((string) $page['seo_title']) ?>" maxlength="200" placeholder="<?= e(t('empty = page name')) ?>"<?= $dir ?>></div>
	<div class="website-field"><label for="ws-description"><?= e(t('Search engine description')) ?></label><textarea class="textbox" id="ws-description" name="description" rows="3" maxlength="300"<?= $dir ?>><?= e((string) $page['description']) ?></textarea><span class="help"><?= e(t('One or two sentences on what visitors will find on the page (up to 160 characters).')) ?></span></div>
	<div class="website-field"><label for="ws-keywords"><?= e(t('Focus keywords')) ?></label><textarea class="textbox" id="ws-keywords" name="focus_keywords" rows="3"<?= $dir ?>><?= e((string) $page['focus_keywords']) ?></textarea><span class="help"><?= e(t('One per line, up to five. The page is checked against the first.')) ?></span></div>
	<div class="website-field"><input type="hidden" name="key_page" value="0"><label class="website-option"><input type="checkbox" name="key_page" value="1"<?= $page['key_page'] ? ' checked' : '' ?>> <?= e(t('Key page')) ?></label><span class="help"><?= e(t('Other pages are checked for a link to a key page.')) ?></span></div>
</section>
</form>
<section class="website-settings-section">
<?= $app->view->render('admin/content_check', ['results' => $contentCheck]) ?>
</section>
