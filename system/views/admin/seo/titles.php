<?php
/**
 * Titles and descriptions: a strip of kinds, the form of the open kind and a reset button beside it. The "Reads as" line is
 * made on the server from the saved templates and the latest row of the kind.
 *
 * @var Talea\Core\App $app
 * @var Talea\Admin\Modules\SeoTitles $module
 * @var string $csrf
 * @var string $kind
 * @var array<string, string> $values the saved values of the kind (and the sharing image)
 * @var list<string> $flags robots flags set site-wide
 * @var array<string, string> $limits robots limits set site-wide
 * @var string $sample
 */
use Talea\Admin\Modules\SeoTitles;
use Talea\Core\SeoTitle;

$variables = fn (string $id, bool $excerpt = false): string => '<div class="seo-vars">' . implode('', array_map(
    fn (string $v): string => '<button class="seo-var" type="button" data-insert="%' . e($v) . '%" data-insert-into="' . e($id) . '">%' . e($v) . '%</button>',
    $excerpt ? SeoTitle::VARIABLES : array_values(array_diff(SeoTitle::VARIABLES, ['excerpt'])),
)) . '</div>';
$template = function (string $key, string $label, bool $description = false) use ($values, $variables): void {
    ?>
	<div class="row"><label for="<?= e($key) ?>"><?= e(t($label)) ?></label><div>
		<input class="textfield wide" type="text" id="<?= e($key) ?>" name="<?= e($key) ?>" value="<?= e($values[$key]) ?>" maxlength="500"<?= $description ? ' placeholder="' . e(t('empty = the text of the page')) . '"' : '' ?>>
		<?= $variables($key, $description) ?>
<?php if ($description): ?>
		<span class="help"><?= e(t('%excerpt% is the text a page shows without a template: its intro, then its first text, cut at 160 characters. Leave the field empty to use it as it is; a template that gives back just the title keeps it too. A description written on the page always wins.')) ?></span>
<?php endif ?>
	</div></div>
<?php
};
?>
<p class="help"><?= e(t('The defaults every page, news item and collection item starts from. A page’s own title and description always win.')) ?></p>
<nav class="tabs" aria-label="<?= e(t('Sections')) ?>">
<?php foreach (SeoTitles::KINDS as $key => [$name]): ?>
	<a href="<?= e($module->url('', ['kind' => $key])) ?>"<?= $key === $kind ? ' class="active" aria-current="page"' : '' ?>><?= e(t($name)) ?></a>
<?php endforeach ?>
</nav>
<form class="form" method="post" action="<?= e($module->url('save')) ?>"><?= $csrf ?>
<input type="hidden" name="kind" value="<?= e($kind) ?>">
<?php if ($kind === 'defaults'): $own = !in_array($values['seo_separator'], SeoTitles::SEPARATORS, true); ?>
<fieldset><legend><?= e(t('Titles')) ?></legend>
	<div class="row"><span class="caption"><?= e(t('Separator')) ?></span><div>
		<div class="seo-seps" role="radiogroup" aria-label="<?= e(t('Separator')) ?>">
<?php foreach (SeoTitles::SEPARATORS as $sep): ?>
			<label><input type="radio" name="seo_separator" value="<?= e($sep) ?>"<?= $values['seo_separator'] === $sep ? ' checked' : '' ?>><?= e($sep) ?></label>
<?php endforeach ?>
			<input class="textfield" type="text" size="4" maxlength="3" name="seo_separator_own" value="<?= $own ? e($values['seo_separator']) : '' ?>" placeholder="<?= e(t('Own')) ?>" aria-label="<?= e(t('Own separator')) ?>">
		</div>
		<span class="help"><?= e(t('Stands between the parts of a title wherever a template says %sep%.')) ?></span>
	</div></div>
	<div class="row"><span class="caption"><?= e(t('Capital letters')) ?></span><div class="options"><label><input type="checkbox" name="seo_capitalize" value="1"<?= $values['seo_capitalize'] === '1' ? ' checked' : '' ?>> <?= e(t('Start every word of the page title with a capital')) ?></label><span class="help"><?= e(t('Only the %title% part changes; the site name and the tagline keep the way you wrote them.')) ?></span></div></div>
	<p class="seo-sample"><?= e(t('Reads as')) ?>: <b><?= e($sample) ?></b></p>
</fieldset>
<fieldset><legend><?= e(t('Robots')) ?></legend>
	<div class="row"><span class="caption"><?= e(t('For the whole site')) ?></span><div class="options seo-options">
<?php foreach (['nofollow' => 'Do not follow links (nofollow)', 'noarchive' => 'No cached copy (noarchive)', 'noimageindex' => 'Do not index images (noimageindex)', 'nosnippet' => 'No text excerpt (nosnippet)'] as $flag => $label): ?>
		<label><input type="checkbox" name="seo_robots[]" value="<?= e($flag) ?>"<?= in_array($flag, $flags, true) ? ' checked' : '' ?>> <?= e(t($label)) ?></label>
<?php endforeach ?>
		<span class="help"><?= e(t('A page can still differ under its own Advanced settings.')) ?></span>
	</div></div>
	<div class="row"><span class="caption"><?= e(t('Excerpt limits')) ?></span><div class="seo-limits">
		<label><?= e(t('Longest text excerpt')) ?><input class="textfield" type="number" min="-1" name="robots_max_snippet" value="<?= e($limits['max-snippet'] ?? '') ?>" placeholder="<?= e(t('no limit')) ?>"></label>
		<label><?= e(t('Longest video preview')) ?><input class="textfield" type="number" min="-1" name="robots_max_video" value="<?= e($limits['max-video-preview'] ?? '') ?>" placeholder="<?= e(t('no limit')) ?>"></label>
		<label><?= e(t('Image preview')) ?><select name="robots_image">
<?php foreach (['' => 'Not set', 'large' => 'Large', 'standard' => 'Standard', 'none' => 'None'] as $value => $label): ?>
			<option value="<?= e($value) ?>"<?= ($limits['max-image-preview'] ?? '') === $value ? ' selected' : '' ?>><?= e(t($label)) ?></option>
<?php endforeach ?>
		</select></label>
		<span class="help"><?= e(t('Leave a field empty for no limit; -1 means no limit too. Numbers are characters (text) and seconds (video).')) ?></span>
	</div></div>
	<div class="row"><span class="caption"><?= e(t('Empty lists')) ?></span><div class="options"><label><input type="checkbox" name="seo_hide_empty_terms" value="1"<?= $values['seo_hide_empty_terms'] === '1' ? ' checked' : '' ?>> <?= e(t('Hide categories and tags that have no published news')) ?></label></div></div>
</fieldset>
<fieldset><legend><?= e(t('Sharing')) ?></legend>
	<div class="row"><label for="share_image"><?= e(t('Sharing image')) ?></label><div><input class="textfield wide" type="text" id="share_image" name="share_image" value="<?= e($values['share_image']) ?>" maxlength="255" data-image><span class="help"><?= e(t('Used when a page has no image of its own. Ideally 1200×630 px.')) ?></span></div></div>
	<div class="row"><label for="seo_card"><?= e(t('Card size')) ?></label><div><select id="seo_card" name="seo_card">
		<option value="large"<?= $values['seo_card'] === 'large' ? ' selected' : '' ?>><?= e(t('Large image')) ?></option>
		<option value="small"<?= $values['seo_card'] === 'small' ? ' selected' : '' ?>><?= e(t('Small image')) ?></option>
	</select><span class="help"><?= e(t('The same for the whole site; a page shows it on its Sharing tab.')) ?></span></div></div>
</fieldset>
<?php else: ?>
<fieldset><legend><?= e(t(SeoTitles::KINDS[$kind][0])) ?></legend>
<?php $template('seo_title_' . $kind, 'Title') ?>
<?php if (isset($values['seo_description_' . $kind])): $template('seo_description_' . $kind, 'Description when there is none', true); endif ?>
	<p class="seo-sample"><?= e(t('Reads as')) ?>: <b><?= e($sample) ?></b></p>
</fieldset>
<?php endif ?>
<div class="buttons"><button class="btn" type="submit"><?= e(t('Save')) ?></button></div>
</form>
<form class="inline seo-reset" method="post" action="<?= e($module->url('reset')) ?>"><?= $csrf ?>
	<input type="hidden" name="kind" value="<?= e($kind) ?>">
	<button class="navigation" type="submit" data-confirm="<?= e(t('Reset this section to the defaults?')) ?>"><?= e(t('Reset to defaults')) ?></button>
</form>
