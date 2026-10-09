<?php
/**
 * News item editor: text on the left, settings on the right (one below the other on a narrow screen).
 *
 * @var Kaleta\Admin\Modules\News $module
 * @var string $csrf
 * @var array<string, mixed> $newsItem
 * @var array<string, string> $errors
 * @var list<array<string, mixed>> $category
 * @var array<int, string> $authors
 * @var bool $canPublish
 * @var bool $assistant  the AI assistant is enabled and has a key
 * @var list<string> $translationLanguages  languages the news item can be translated into (only for a saved news item in the default language)
 * @var array<string, int> $translations  existing translations: language => news item number
 * @var array{saved_at:string, data:string}|null $draftOnServer  unsaved work stored on the server (from another device)
 * @var bool $siteLanguages  the site has other language versions
 * @var string $original  url of the news item this one is a translation of
 * @var string $tags  comma-separated tags
 * @var list<string> $allTags
 * @var list<array<string, mixed>> $versions
 * @var list<array<string, mixed>>|null $socialDrafts  social post drafts (2.13, Core\SocialDrafts); null = the news item is not published
 */
$dt = fn (?string $v): string => $v ? date('Y-m-d\TH:i', strtotime($v)) : '';
$error = fn (string $field): string => isset($errors[$field]) ? '<span class="error-field" role="alert">' . e(t($errors[$field])) . '</span>' : '';
?>
<p class="navigation-row"><a class="navigation" href="<?= e($module->url()) ?>"><?= e(t('Back to the news list')) ?></a></p>

<?php if (!empty($draftOnServer)): ?>
<script type="application/json" id="draft-server"><?= json_encode(['time' => strtotime($draftOnServer['saved_at']) * 1000, 'fields' => json_decode($draftOnServer['data'], true)], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
<?php endif ?>
<form class="form form-article" method="post" action="<?= e($module->url('save')) ?>" data-draft="novinka-<?= (int) $newsItem['news_id'] ?>" data-draft-url="<?= e($module->url('draft')) ?>"<?= $assistant ? ' data-assistant="' . e($module->url('assistant')) . '"' : '' ?>>
<?= $csrf ?>
<input type="hidden" name="news_id" value="<?= (int) $newsItem['news_id'] ?>">

<div class="article-main">
	<div class="row span-all">
		<label for="title"><?= e(t('Title')) ?></label>
		<input class="textfield wide title-field" type="text" id="title" name="title" value="<?= e($newsItem['title']) ?>" maxlength="255" required placeholder="<?= e(t('News item title')) ?>"><?= $error('title') ?>
	</div>
	<div class="row span-all">
		<label for="intro"><?= e(t('Lead paragraph')) ?></label>
		<textarea class="textbox" id="intro" name="intro" rows="5" data-editor="small"><?= e($newsItem['intro']) ?></textarea>
		<span class="help"><?= e(t('Shown in lists and at the start of the news item – do not repeat it in the text.')) ?></span>
	</div>
	<div class="row span-all">
		<label for="text"><?= e(t('Text')) ?></label>
		<textarea class="textbox tall" id="text" name="text" rows="20" data-editor><?= e($newsItem['text']) ?></textarea>
		<span class="help"><?= e(t('To embed a video, put its address (YouTube, Vimeo) on a line of its own. It loads for the visitor only after a click.')) ?></span>
	</div>
</div>

<aside class="article-settings">
<fieldset>
<legend><?= e(t('Publishing')) ?></legend>
<div class="row">
	<label for="stav"><?= e(t('Status')) ?></label>
	<div><select id="stav" name="status">
		<option value="draft"<?= !$newsItem['visible'] ? ' selected' : '' ?>><?= e(t('Draft')) ?></option>
<?php if ($canPublish): ?>
		<option value="published"<?= $newsItem['visible'] ? ' selected' : '' ?>><?= e(t('Published')) ?></option>
<?php endif ?>
	</select>
<?php if (!$canPublish): ?>
	<span class="help"><?= e(t('An editor or site administrator publishes the news item.')) ?></span>
<?php endif ?>
	</div>
</div>
<div class="row">
	<label for="datum"><?= e(t('Publish date')) ?></label>
	<div><input class="textfield" type="datetime-local" id="datum" name="published_at" value="<?= e($dt($newsItem['published_at'])) ?>" required>
	<span class="help"><?= e(t('A future date = the news item is published automatically at that time.')) ?></span></div>
</div>
<div class="row">
	<label for="valid_until"><?= e(t('True until')) ?></label>
	<div><input class="textfield" type="date" id="valid_until" name="valid_until" value="<?= e((string) ($newsItem['valid_until'] ?? '')) ?>">
	<span class="help"><?= e(t('After this day the news item hides itself. Empty = always.')) ?></span></div>
</div>
<div class="row">
	<label for="review_by"><?= e(t('Review by')) ?></label>
	<div><input class="textfield" type="date" id="review_by" name="review_by" value="<?= e((string) ($newsItem['review_by'] ?? '')) ?>">
	<span class="help"><?= e(t('On this day the site audit and the alert e-mail remind you to check it.')) ?></span></div>
</div>
<?php if ($newsItem['visible']): ?>
<div class="row"><span class="caption"></span><div class="options"><label><input type="checkbox" name="mark_updated" value="1"> <?= e(t('Mark as updated (with today\'s date)')) ?></label></div></div>
<?php endif ?>
<?php $readOnly = $newsItem['visible'] && !$canPublish; /* a published news item is edited only by an editor – the author sees it but cannot save it */ ?>
<?php if ($readOnly): ?>
<p class="help"><?= e(t('This news item is published – only an editor or administrator can save changes to it. Ask them to edit it.')) ?></p>
<?php endif ?>
<p class="buttons save-bar">
	<button class="btn" type="submit" name="after_save" value="list"<?= $readOnly ? ' disabled' : '' ?>><?= e(t('Save')) ?></button>
	<button class="btn" type="submit" name="after_save" value="stay"<?= $readOnly ? ' disabled' : '' ?>><?= e(t('Save and continue')) ?></button>
<?php if ($newsItem['news_id']): ?>
	<a class="navigation" href="<?= e($module->app()->url('news/' . $newsItem['slug'] . '?preview=1')) ?>" target="_blank" rel="noopener"><?= e(t('Preview')) ?></a>
<?php endif ?>
</p>
</fieldset>

<fieldset>
<legend><?= e(t('Classification')) ?></legend>
<?php if (count($category) < 2): ?>
<input type="hidden" name="category_id" value="<?= (int) ($category[0]['category_id'] ?? $newsItem['category_id']) ?>">
<?php else: ?>
<div class="row">
	<label for="tema"><?= e(t('Categories')) ?></label>
	<div><select id="tema" name="category_id" required>
<?php foreach ($category as $k): ?>
		<option value="<?= (int) $k['category_id'] ?>"<?= (int) $newsItem['category_id'] === (int) $k['category_id'] ? ' selected' : '' ?>><?= e($k['name']) ?></option>
<?php endforeach ?>
	</select><?= $error('category_id') ?></div>
</div>
<?php endif ?>
<?php if (count($authors) < 2): ?>
<input type="hidden" name="author_id" value="<?= (int) (array_key_first($authors) ?? $newsItem['author_id']) ?>">
<?php else: ?>
<div class="row">
	<label for="autor"><?= e(t('Author')) ?></label>
	<div><select id="autor" name="author_id">
<?php foreach ($authors as $userId => $displayName): ?>
		<option value="<?= (int) $userId ?>"<?= (int) $newsItem['author_id'] === (int) $userId ? ' selected' : '' ?>><?= e($displayName) ?></option>
<?php endforeach ?>
	</select><?= $error('author_id') ?></div>
</div>
<?php endif ?>
<div class="row">
	<label for="tags"><?= e(t('Tags')) ?></label>
	<div><input class="textfield wide" type="text" id="tags" name="tags" value="<?= e($tags) ?>" maxlength="600" list="tags-list" autocomplete="off" data-tags>
	<datalist id="tags-list"><?php foreach ($allTags as $s): ?><option value="<?= e($s) ?>"><?php endforeach ?></datalist>
	<span class="help"><?= e(t('Comma-separated. Visitors can use a tag to see related news.')) ?></span></div>
</div>
</fieldset>

<fieldset>
<legend><?= e(t('Featured image')) ?></legend>
<div class="row span-all">
	<input class="textfield wide" type="text" id="obrazek" name="image" value="<?= e($newsItem['image']) ?>" maxlength="255" placeholder="<?= e(t('choose from media or paste a URL')) ?>" aria-label="<?= e(t('Featured image')) ?>" data-image>
	<span class="help"><?= e(t('Used in listings and when shared on social networks.')) ?></span>
</div>
<div class="row span-all">
	<label for="obrazek_popis"><?= e(t('Image caption')) ?></label>
	<input class="textfield wide" type="text" id="obrazek_popis" name="image_caption" value="<?= e($newsItem['image_caption']) ?>" maxlength="300">
</div>
<div class="row span-all">
	<label for="obrazek_autor"><?= e(t('Image credit')) ?></label>
	<div><input class="textfield wide" type="text" id="obrazek_autor" name="image_author" value="<?= e($newsItem['image_author']) ?>" maxlength="120">
	<span class="help"><?= e(t('Empty field = caption and credit from the Media library.')) ?></span></div>
</div>
</fieldset>

<?php if ($siteLanguages): ?>
<details class="advanced"<?= $original !== '' || $translations !== [] ? ' open' : '' ?>>
<summary><?= e(t('Translation')) ?></summary>
<?php if ($translationLanguages !== []): ?>
<div class="row span-all">
	<span class="caption"><?= e(t('Language versions')) ?></span>
	<div class="options">
<?php foreach ($translationLanguages as $languageCode): $languageName = Kaleta\Core\Language::AVAILABLE[$languageCode][0]; ?>
<?php if (isset($translations[$languageCode])): ?>
		<a class="navigation" href="<?= e($module->url('edit', ['id' => $translations[$languageCode]])) ?>"><?= e($languageName) ?>: <?= e(t('open translation')) ?></a>
<?php elseif ($assistant): ?>
		<button class="navigation" type="submit" name="translate_to" value="<?= e($languageCode) ?>" formaction="<?= e($module->url('translate')) ?>" formnovalidate data-confirm="<?= e(t('Translate the saved version with the assistant? A draft is created for you to read before publishing. Translation can take up to a minute.')) ?>"><?= e(t('Translate with the assistant')) ?>: <?= e($languageName) ?></button>
<?php else: ?>
		<span class="help inline"><?= e($languageName) ?>: <?= e(t('no translation yet')) ?></span>
<?php endif ?>
<?php endforeach ?>
	</div>
</div>
<?php endif ?>
<div class="row span-all">
	<label for="preklad_z"><?= e(t('Original in the default language')) ?></label>
	<input class="textfield wide" type="text" id="preklad_z" name="translation_of" value="<?= e($original) ?>" maxlength="255" placeholder="<?= e(t('address or number of the original news item')) ?>">
	<span class="help"><?= e(t('Fill in only for a news item in another language version (the category sets the language).')) ?></span>
</div>
</details>
<?php endif ?>

<fieldset class="check" data-check>
<legend><?= e(t('Accessibility check')) ?></legend>
<div data-check-result aria-live="polite"><p class="help"><?= e(t('The check runs while you write (needs JavaScript).')) ?></p></div>
</fieldset>
<?php if ($newsItem['news_id']): ?>
<?= $app->view->render('admin/content_check', ['results' => $contentCheck]) ?>
<?php endif ?>

<details class="advanced"<?= $newsItem['seo_title'] !== '' || $newsItem['seo_description'] !== '' || (string) $newsItem['faq'] !== '' ? ' open' : '' ?>>
<summary><?= e(t('SEO and more settings')) ?></summary>
<div class="row">
	<label for="seo_link"><?= e(t('URL')) ?></label>
	<div><input class="textfield wide" type="text" id="seo_link" name="slug" value="<?= e($newsItem['slug']) ?>" maxlength="150" placeholder="<?= e(t('created from the headline')) ?>">
	<span class="help"><?= e(t('The part of the address after %s. If you change it after publishing, the old address redirects automatically.', substr($app->url('news/'), strlen($app->request->basePath())))) ?></span></div>
</div>
<div class="row">
	<label for="seo_title"><?= e(t('Search engine title')) ?></label>
	<div><input class="textfield wide" type="text" id="seo_title" name="seo_title" value="<?= e($newsItem['seo_title']) ?>" maxlength="255" placeholder="<?= e(t('empty = news item title')) ?>"></div>
</div>
<div class="row">
	<label for="seo_description"><?= e(t('Search engine description')) ?></label>
	<div><input class="textfield wide" type="text" id="seo_description" name="seo_description" value="<?= e($newsItem['seo_description']) ?>" maxlength="320" placeholder="<?= e(t('empty = beginning of the lead')) ?>"></div>
</div>
<div class="row">
	<label for="t_slova"><?= e(t('Keywords')) ?></label>
	<div><input class="textfield wide" type="text" id="t_slova" name="keywords" value="<?= e($newsItem['keywords']) ?>" maxlength="500">
	<span class="help"><?= e(t('Comma-separated; they help the site search.')) ?></span></div>
</div>
<div class="row">
	<label for="faq"><?= e(t('Questions and answers')) ?></label>
	<div><textarea class="textbox low" id="faq" name="faq" rows="5"><?= e((string) $newsItem['faq']) ?></textarea>
	<span class="help"><?= e(t('Question on one line, the answer below it, an empty line between pairs. Shown below the text and in structured data (FAQ).')) ?></span></div>
</div>
<div class="row">
	<span class="caption"><?= e(t('Options')) ?></span>
	<div class="options"><label><input type="checkbox" name="noindex" value="1"<?= $newsItem['noindex'] ? ' checked' : '' ?>> <?= e(t('Hide from search engines (noindex)')) ?></label></div>
</div>
</details>
<?php if ($versions !== []): ?>
<details class="advanced">
<summary><?= e(t('Version history (%s)', count($versions))) ?></summary>
<ul class="revisions">
<?php foreach ($versions as $version): ?>
	<li><a href="<?= e($module->url('versions', ['id' => $newsItem['news_id'], 'revision' => $version['revision_id']])) ?>" title="<?= e($version['title']) ?>"><?= e(format_date($version['created_at'], true)) ?></a> <span class="help inline"><?= e($version['user_name'] ?? '') ?></span> · <a href="<?= e($module->url('compare', ['id' => $newsItem['news_id'], 'revision' => $version['revision_id']])) ?>"><?= e(t('what changed')) ?></a></li>
<?php endforeach ?>
</ul>
<p class="help"><?= e(t('Click to load an older version into the editor. The last 20 versions are kept.')) ?></p>
</details>
<?php endif ?>
</aside>
</form>
<?php if ($socialDrafts !== null): // social post drafts (2.13): one card per network, each its own form – outside the editor form ?>
<section class="social-posts" id="social-posts" aria-labelledby="social-posts-heading">
<h2 id="social-posts-heading"><?= e(t('Social posts')) ?></h2>
<?php if ($socialDrafts === []): ?>
<p class="help"><?= e(t('No network is chosen. Pick the networks under Settings → General → More options and the drafts appear here.')) ?></p>
<?php else: ?>
<p class="help"><?= e(t('Prepared when the news item was published, with a tracked link – the statistics show the visits from each network. Edit the text, copy it and post it yourself; the site never posts anywhere.')) ?></p>
<?php if ($assistant): ?>
<form method="post" action="<?= e($module->url('social_suggest')) ?>" class="inline">
<?= $csrf ?>
<input type="hidden" name="news_id" value="<?= (int) $newsItem['news_id'] ?>">
<button class="navigation" type="submit" data-confirm="<?= e(t('Rewrite all the drafts with the assistant? Your edits to them are replaced. Hashtags and the link are added back.')) ?>"><?= e(t('Suggest with the assistant')) ?></button>
</form>
<?php endif ?>
<div class="social-grid">
<?php foreach ($socialDrafts as $d): $id = (int) $d['id']; ?>
<form method="post" action="<?= e($module->url('social_save')) ?>" class="social-post<?= $d['posted_at'] ? ' social-post--done' : '' ?>">
<?= $csrf ?>
<input type="hidden" name="id" value="<?= $id ?>">
<h3><?= e($d['network_name']) ?><?= $d['posted_at'] ? ' <span class="badge badge-published">' . e(t('posted %s', format_date($d['posted_at'], true))) . '</span>' : '' ?></h3>
<textarea class="textbox" id="social-text-<?= $id ?>" name="text" rows="8" maxlength="<?= Kaleta\Core\SocialDrafts::MAX_TEXT ?>" aria-label="<?= e(t('Post for %s', $d['network_name'])) ?>"><?= e($d['text']) ?></textarea>
<?php if ($d['network'] === 'x'): ?>
<p class="help"><?= e(t('At most %d characters; a link counts as %d.', Kaleta\Core\SocialDrafts::X_LIMIT, Kaleta\Core\SocialDrafts::X_LINK_LENGTH)) ?></p>
<?php endif ?>
<?php if ($d['image'] !== ''): ?>
<p class="small-text"><?= e(t('Image:')) ?> <a href="<?= e($d['image']) ?>" target="_blank" rel="noopener"><?= e(mb_strimwidth($d['image'], 0, 70, '…')) ?></a></p>
<?php endif ?>
<?php if ($d['network'] === 'instagram'): ?>
<p class="small-text"><?= e(t('Link for the bio:')) ?> <code id="social-link-<?= $id ?>"><?= e($d['link']) ?></code> <button class="navigation" type="button" data-copy="#social-link-<?= $id ?>"><?= e(t('Copy')) ?></button></p>
<?php endif ?>
<p class="buttons">
	<button class="btn" type="button" data-copy="#social-text-<?= $id ?>"><?= e(t('Copy')) ?></button>
	<button class="navigation" type="submit"><?= e(t('Save')) ?></button>
	<button class="navigation" type="submit" formaction="<?= e($module->url('social_posted')) ?>" name="posted" value="<?= $d['posted_at'] ? 0 : 1 ?>" formnovalidate><?= e(t($d['posted_at'] ? 'Not posted yet' : 'Mark as posted')) ?></button>
</p>
</form>
<?php endforeach ?>
</div>
<?php endif ?>
</section>
<?php endif ?>
<script src="<?= e($module->app()->url('image/helper.js')) ?>?v=<?= e(KALETA_VERSION) ?>" defer></script>
