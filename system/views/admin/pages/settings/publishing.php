<?php
/**
 * The Publishing tab of the settings sidebar: visibility, the page password, member groups and the dates, then the page's
 * actions (duplicate, download, versions, trash) through their existing POSTs. A text page lists its text history here.
 *
 * @var Talea\Core\App $app
 * @var Talea\Admin\Modules\Pages $module
 * @var string $csrf
 * @var array<string, mixed> $page
 * @var string $formStart
 * @var bool $home
 * @var bool $hasBuild
 * @var bool $canPublish
 * @var list<array{revision_id:int, created_at:string, title:string, user_id:?string}> $versions the text history of a text page
 */
$icon = fn (string $paths): string => '<svg class="icon" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $paths . '</svg>';
?>
<?= $formStart ?>
<?php if (!$canPublish): ?>
<p class="notice"><?= e(t('Only an editor or administrator edits, publishes and deletes published pages. You can prepare a new hidden page.')) ?></p>
<?php endif ?>
<section class="website-settings-section" aria-label="<?= e(t('Visibility')) ?>">
	<div class="website-field"><span class="website-caption"><?= e(t('Visibility')) ?></span>
		<div class="website-choices" role="radiogroup" aria-label="<?= e(t('Visibility')) ?>">
			<label><input type="radio" name="visible" value="1"<?= $page['visible'] ? ' checked' : '' ?>><span><?= e(t('Published')) ?><small><?= e(t('Everyone can see the page.')) ?></small></span></label>
			<label><input type="radio" name="visible" value="0"<?= $page['visible'] ? '' : ' checked' ?><?= $home ? ' disabled' : '' ?>><span><?= e(t('Hidden')) ?><small><?= e($home ? t('The home page cannot be hidden. First choose another home page in Settings → General.') : t('Only you and your team see it.')) ?></small></span></label>
		</div>
	</div>
	<div class="website-field"><label for="ws-password"><?= e(t('Page password')) ?></label><input class="textfield" type="password" id="ws-password" name="page_password" autocomplete="new-password" minlength="<?= Talea\Core\PageLock::MIN_LENGTH ?>" placeholder="<?= e(!empty($page['password_hash']) ? t('protected – type a new password to change it') : t('none – the page is public')) ?>">
<?php if (!empty($page['password_hash'])): ?>
		<label class="website-option"><input type="checkbox" name="remove_password" value="1"> <?= e(t('Remove the password')) ?></label>
<?php endif ?>
		<span class="help"><?= e(t('Whoever knows the password reads the page. A protected page is never in search engines.')) ?></span></div>
<?= $app->view->render('admin/members/gating', ['app' => $app, 'type' => 'page', 'row' => (int) $page['page_id']]) ?>
</section>
<section class="website-settings-section" aria-labelledby="ws-dates">
	<h3 id="ws-dates"><?= e(t('Dates')) ?></h3>
	<div class="website-field"><label for="ws-publish-at"><?= e(t('Publish automatically at')) ?></label><input class="textfield" type="datetime-local" id="ws-publish-at" name="publish_at" value="<?= e($page['publish_at'] ? date('Y-m-d\TH:i', strtotime((string) $page['publish_at'])) : '') ?>"><span class="help"><?= e(t('Only for a hidden page.')) ?></span></div>
	<div class="website-pair">
		<div class="website-field"><label for="ws-valid-until"><?= e(t('Visible until')) ?></label><input class="textfield" type="date" id="ws-valid-until" name="valid_until" value="<?= e((string) $page['valid_until']) ?>"></div>
		<div class="website-field"><label for="ws-review-by"><?= e(t('Review by')) ?></label><input class="textfield" type="date" id="ws-review-by" name="review_by" value="<?= e((string) $page['review_by']) ?>"></div>
	</div>
	<p class="help"><?= e(t('After “visible until” the page hides itself; on “review by” the site audit reminds you to check it.')) ?></p>
</section>
</form>
<section class="website-settings-section" aria-labelledby="ws-actions">
	<h3 id="ws-actions"><?= e(t('Page')) ?></h3>
	<div class="website-actions">
		<form method="post" action="<?= e($module->url('duplicate')) ?>"><?= $csrf ?><input type="hidden" name="page_id" value="<?= e($page['public_id']) ?>"><button type="submit"><?= $icon('<rect x="8" y="8" width="12" height="12" rx="2"/><path d="M16 8V6a2 2 0 0 0-2-2H6a2 2 0 0 0-2 2v8a2 2 0 0 0 2 2h2"/>') ?><?= e(t('Duplicate page')) ?></button></form>
		<a href="<?= e($module->url('export', ['id' => $page['public_id']])) ?>"><?= $icon('<path d="M12 4v11M7 10l5 5 5-5M5 20h14"/>') ?><?= e(t('Download as JSON')) ?></a>
<?php if ($hasBuild): ?>
		<a href="<?= e($module->url('builder', ['id' => $page['public_id']])) ?>"><?= $icon('<path d="M3 12a9 9 0 1 0 3-6.7L3 8"/><path d="M3 3v5h5"/>') ?><?= e(t('Versions')) ?></a>
<?php endif ?>
<?php if (!$home): ?>
		<form method="post" action="<?= e($module->url('delete')) ?>" data-confirm="<?= e(t('Move the page to the trash? It disappears from the site; you can restore it for 30 days.')) ?>"><?= $csrf ?><input type="hidden" name="page_id" value="<?= e($page['public_id']) ?>"><button class="danger" type="submit"><?= $icon('<path d="M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13"/>') ?><?= e(t('Move to trash')) ?></button></form>
<?php endif ?>
	</div>
</section>
<?php if ($versions !== []): ?>
<section class="website-settings-section" aria-labelledby="ws-versions">
	<h3 id="ws-versions"><?= e(t('Text history (%s)', count($versions))) ?></h3>
	<ul class="revisions">
<?php foreach ($versions as $v): ?>
		<li><?= e(format_date($v['created_at'], true)) ?><?= $v['user_id'] ? ' · ' . e($v['user_id']) : '' ?> · <?= e($v['title']) ?> · <a href="<?= e($module->url('compare', ['id' => $page['public_id'], 'revision' => (int) $v['revision_id']])) ?>"><?= e(t('what changed')) ?></a>
			<form class="inline" method="post" action="<?= e($module->url('restore_version')) ?>" data-confirm="<?= e(t('Restore this version of the text? The current version stays in the history.')) ?>"><?= $csrf ?><input type="hidden" name="revision_id" value="<?= (int) $v['revision_id'] ?>"><button class="navigation" type="submit"><?= e(t('Restore')) ?></button></form></li>
<?php endforeach ?>
	</ul>
</section>
<?php endif ?>
