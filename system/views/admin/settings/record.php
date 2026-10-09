<?php
/**
 * Record of processing (2.14, Core\Privacy): assembled from the configuration, printable from the browser, with a Markdown
 * copy to paste into the owner's documentation. A template to review – not legal advice.
 *
 * @var Kaleta\Admin\Modules\Settings $module
 * @var list<array{heading: string, lines: list<string>}> $sections
 */
?>
<p class="navigation-row"><a class="navigation" href="<?= e($module->url('', ['tab' => 'cookies'])) ?>">← <?= e(t('Privacy and cookies')) ?></a></p>
<p class="help"><?= e(t('Generated on %s from the site’s configuration. A template to review and complete – not legal advice.', date('j. n. Y'))) ?> <?= e(t('Add what the site does not know about (paper files, accounting, other systems) and keep the record with your documentation; print it from the browser.')) ?></p>
<div class="form record-processing">
<?php foreach ($sections as $section): ?>
<h2><?= e($section['heading']) ?></h2>
<ul>
<?php foreach ($section['lines'] as $line): ?>
	<li><?= e($line) ?></li>
<?php endforeach ?>
</ul>
<?php endforeach ?>
</div>
<details class="advanced">
<summary><?= e(t('As Markdown (to copy)')) ?></summary>
<textarea class="textbox code" rows="20" readonly><?= e(Kaleta\Core\Privacy::markdown($sections, t('Record of processing'))) ?></textarea>
</details>
