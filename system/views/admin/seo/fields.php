<?php
/**
 * The "Search engines" fields of a news item or collection item form (Core\SeoFields): focus keywords, key page, robots
 * directives, the main address, the name in breadcrumbs and the sharing texts. key_page=0 and the empty robots[] marker go
 * before their checkboxes, so unticking clears them; limits set elsewhere (max-snippet …) are carried along.
 *
 * @var array<string, mixed> $row the row (missing keys count as empty)
 * @var string $dir dir attribute of the content language
 */
$robots = array_filter(explode(',', (string) ($row['robots'] ?? '')));
$flags = [
    'nofollow' => 'Do not follow links (nofollow)', 'noarchive' => 'No cached copy (noarchive)',
    'noimageindex' => 'Do not index the images (noimageindex)', 'nosnippet' => 'No text excerpt (nosnippet)',
];
?>
<div class="row"><label for="focus_keywords"><?= e(t('Focus keywords')) ?></label><div><textarea class="textbox low" id="focus_keywords" name="focus_keywords" rows="3" data-chips<?= $dir ?>><?= e((string) ($row['focus_keywords'] ?? '')) ?></textarea>
	<span class="help"><?= e(t('One per line, up to five. The page is checked against the first.')) ?></span></div></div>
<div class="row"><span class="caption"><?= e(t('Key page')) ?></span><div class="options"><input type="hidden" name="key_page" value="0"><label><input type="checkbox" name="key_page" value="1"<?= !empty($row['key_page']) ? ' checked' : '' ?>> <?= e(t('Key page')) ?></label>
	<span class="help"><?= e(t('Other pages are checked for a link to a key page.')) ?></span></div></div>
<div class="row"><span class="caption"><?= e(t('Robots')) ?></span><div class="options"><input type="hidden" name="robots[]" value="">
<?php foreach (array_diff($robots, array_keys($flags)) as $limit): ?>
	<input type="hidden" name="robots[]" value="<?= e($limit) ?>">
<?php endforeach ?>
<?php foreach ($flags as $flag => $label): ?>
	<label><input type="checkbox" name="robots[]" value="<?= $flag ?>"<?= in_array($flag, $robots, true) ? ' checked' : '' ?>> <?= e(t($label)) ?></label><br>
<?php endforeach ?>
</div></div>
<div class="row"><label for="canonical_url"><?= e(t('Main address (canonical)')) ?></label><div><input class="textfield wide" type="url" id="canonical_url" name="canonical_url" value="<?= e((string) ($row['canonical_url'] ?? '')) ?>" maxlength="500" placeholder="https://…">
	<span class="help"><?= e(t('Only when the same text lives at another address too.')) ?></span></div></div>
<div class="row"><label for="breadcrumb_title"><?= e(t('Name in breadcrumbs')) ?></label><div><input class="textfield wide" type="text" id="breadcrumb_title" name="breadcrumb_title" value="<?= e((string) ($row['breadcrumb_title'] ?? '')) ?>" maxlength="200"<?= $dir ?>></div></div>
<div class="row"><label for="share_title"><?= e(t('Sharing title')) ?></label><div><input class="textfield wide" type="text" id="share_title" name="share_title" value="<?= e((string) ($row['share_title'] ?? '')) ?>" maxlength="200"<?= $dir ?>></div></div>
<div class="row"><label for="share_description"><?= e(t('Sharing description')) ?></label><div><textarea class="textbox low" id="share_description" name="share_description" rows="2" maxlength="320"<?= $dir ?>><?= e((string) ($row['share_description'] ?? '')) ?></textarea>
	<span class="help"><?= e(t('Empty = the title and description for search engines.')) ?></span></div></div>
