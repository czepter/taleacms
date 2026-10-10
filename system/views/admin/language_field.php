<?php
/**
 * Form row "Language version" - only when the site has other languages (the Language versions extension).
 *
 * @var Kaleta\Core\App $app
 * @var string $value  current value of the jazyk column ('' = default language)
 * @var string $hint
 * @var array<int, string> $originals  items in the default language from which the original of the translation can be chosen
 * @var int $translationOf
 */
use Kaleta\Core\Language;

$additional = Language::additional($app->settings());
if ($additional === []) {
    return;
}
?>
<div class="row">
	<label for="language"><?= e(t('Language version')) ?></label>
	<div><select id="language" name="language">
		<option value=""><?= e(Language::AVAILABLE[Language::defaults($app->settings())][0]) ?> (<?= e(t('default')) ?>)</option>
<?php foreach ($additional as $code): ?>
		<option value="<?= e($code) ?>"<?= $value === $code ? ' selected' : '' ?>><?= e(Language::AVAILABLE[$code][0]) ?> – /<?= e($code) ?>/</option>
<?php endforeach ?>
	</select>
<?php if (($hint ?? '') !== ''): ?>
	<span class="help"><?= e($hint) ?></span>
<?php endif ?>
	</div>
</div>
<?php if (($originals ?? []) !== []): ?>
<div class="row">
	<label for="translation_of"><?= e(t('Is a translation of')) ?></label>
	<div><select id="translation_of" name="translation_of">
		<option value="0"><?= e(t('– not a translation –')) ?></option>
<?php foreach ($originals as $originalId => $originalName): ?>
		<option value="<?= (int) $originalId ?>"<?= (int) ($translationOf ?? 0) === (int) $originalId ? ' selected' : '' ?>><?= e($originalName) ?></option>
<?php endforeach ?>
	</select>
	<span class="help"><?= e(t('Fill in for an item in another language version: the language switcher then leads straight to its counterpart and search engines get hreflang tags.')) ?></span></div>
</div>
<?php endif ?>
