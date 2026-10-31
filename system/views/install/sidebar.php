<?php
/**
 * The side column: logo, the steps (a finished one is a button back to it), the installation language and the version.
 *
 * @var list<string> $steps
 * @var int $current  position of the current step; count($steps) = all done
 * @var bool $interactive  the steps and the language are buttons of the wizard form
 * @var array<string, string> $errors  (the wizard only)
 * @var string $language
 * @var string $register
 * @var array<string, string> $languages
 */
$stepName = static fn (string $key): string => match ($key) {
	'website' => t('Website'), 'business' => t('Your business'), 'look' => t('Look'), 'features' => t('Features'), 'account' => t('Your account'),
};
?>
<div class="side-inner">
<div class="brand"><?php $height = 28; $markOnly = false; require TALEA_SYSTEM . '/views/admin/logo.php'; ?></div>
<nav class="steps-nav" aria-label="<?= e(t('Installation steps')) ?>">
<p class="side-label"><?= e(t('Installation')) ?></p>
<ol class="steps">
<?php foreach ($steps as $i => $key): $done = $i < $current; $now = $i === $current; $failedNow = $now && !empty($errors);
	$no = '<span class="step-no">' . ($done ? '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M5 12.5l4.5 4.5L19 7.5"></path></svg>' : $i + 1) . '</span>'; ?>
<li class="<?= $done ? 'done' : ($failedNow ? 'failed' : ($now ? 'now' : 'later')) ?>">
<?php if ($done && $interactive): ?>
	<button class="step-link" type="submit" name="go" value="<?= e($key) ?>" formnovalidate><?= $no ?><?= e($stepName($key)) ?></button>
<?php else: ?>
	<span class="step-link"<?= $now ? ' aria-current="step"' : '' ?>><?= $no ?><?= e($stepName($key)) ?></span>
<?php endif ?>
</li>
<?php endforeach ?>
</ol>
</nav>
<?php if ($interactive): ?>
<nav aria-label="<?= e(t('Language')) ?>">
<p class="side-label"><?= e(t('Language')) ?></p>
<div class="segmented">
<?php foreach ($languages as $code => $languageName): ?>
	<button type="submit" name="switch" value="<?= e($code . ($code === 'de' ? ':' . $register : '')) ?>" formnovalidate lang="<?= e($code) ?>"<?= $code === $language ? ' aria-current="true"' : '' ?>><?= e($languageName) ?></button>
<?php endforeach ?>
</div>
</nav>
<?php if ($language === 'de'): ?>
<nav aria-label="<?= e(t('Form of address')) ?>">
<div class="segmented">
	<button type="submit" name="switch" value="de:formal" formnovalidate<?= $register === 'formal' ? ' aria-current="true"' : '' ?>><?= e(t('Formal (Sie)')) ?></button>
	<button type="submit" name="switch" value="de:informal" formnovalidate<?= $register !== 'formal' ? ' aria-current="true"' : '' ?>><?= e(t('Informal (du)')) ?></button>
</div>
</nav>
<?php endif ?>
<?php endif ?>
<p class="side-version">Talea <?= e(TALEA_VERSION) ?></p>
</div>
