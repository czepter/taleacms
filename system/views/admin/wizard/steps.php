<?php
/**
 * The onboarding's three questions (#46): what the site is about, what it should be able to do, how to start. Plain GET forms that
 * carry the answers; nothing is stored until a look is used.
 *
 * @var Talea\Admin\Modules\Wizard $module
 * @var int $step 1–3
 * @var array{topic: string, features: list<string>} $answers
 * @var bool $ready (step 3) the owner has an AI key
 */
use Talea\Builder\Looks;
use Talea\Core\Onboarding;

$next = [1 => 'features', 2 => 'start'][$step] ?? '';
$back = [2 => $module->url('', $answers), 3 => $module->url('features', $answers)][$step] ?? '';
?>
<div class="onboarding">
<p class="onboarding-progress"><progress max="3" value="<?= $step ?>"></progress> <?= e(t('Step %d of %d', $step, 3)) ?></p>
<form method="get" action="<?= e($app->url('admin.php')) ?>">
<input type="hidden" name="module" value="wizard"><input type="hidden" name="action" value="<?= e($next) ?>">
<?php if ($step === 1): ?>
<?php foreach ($answers['features'] as $feature): ?>
<input type="hidden" name="features[]" value="<?= e($feature) ?>">
<?php endforeach ?>
<h2><label for="o-topic"><?= e(t('What is your site about?')) ?></label></h2>
<p class="help"><?= e(t('So we can show the looks that fit it best first. You can change everything later.')) ?></p>
<input class="textfield wide" id="o-topic" name="topic" list="o-topics" maxlength="100" autocomplete="off" placeholder="<?= e(t('Search topics or use your own words')) ?>"
	value="<?= e(isset(Looks::TOPICS[$answers['topic']]) ? t(Looks::TOPICS[$answers['topic']]) : $answers['topic']) ?>">
<datalist id="o-topics">
<?php foreach (Looks::TOPICS as $name): ?>
	<option value="<?= e(t($name)) ?>">
<?php endforeach ?>
</datalist>
<?php elseif ($step === 2): ?>
<input type="hidden" name="topic" value="<?= e($answers['topic']) ?>">
<fieldset>
<legend><h2><?= e(t('What should your site be able to do?')) ?></h2></legend>
<p class="help"><?= e(t('Choose all that apply: the matching features are switched on and their pages prepared as drafts.')) ?></p>
<div class="onboarding-features">
<?php foreach (Onboarding::FEATURES as $key => $feature): ?>
	<label><input type="checkbox" name="features[]" value="<?= e($key) ?>"<?= in_array($key, $answers['features'], true) ? ' checked' : '' ?>> <?= e(t($feature[0])) ?></label>
<?php endforeach ?>
</div>
</fieldset>
<?php else: ?>
<h2><?= e(t('How do you want to start?')) ?></h2>
<div class="onboarding-choices">
	<a class="onboarding-choice" href="<?= e($module->url('looks', $answers)) ?>"><strong><?= e(t('Choose a look')) ?></strong>
		<small><?= e(t('Pick a finished look, try its colours and fonts on your own site, then start from it.')) ?></small></a>
<?php if ($ready): ?>
	<a class="onboarding-choice" href="<?= e($module->url('ai', $answers)) ?>"><strong><?= e(t('Let AI draft it')) ?></strong>
		<small><?= e(t('The assistant plans the pages and writes first texts with your own AI key – you review the plan first.')) ?></small></a>
<?php endif ?>
</div>
<?php if (!$ready): ?>
<p class="help"><?= e(t('With your own AI key (Features → Writing assistant) the assistant can also draft the pages and their texts.')) ?></p>
<?php endif ?>
<?php endif ?>
<p class="navigation-row">
<?php if ($next !== ''): ?>
	<button class="btn" type="submit"><?= e(t('Continue')) ?></button>
<?php endif ?>
<?php if ($back !== ''): ?>
	<a class="navigation" href="<?= e($back) ?>"><?= e(t('Back')) ?></a>
<?php endif ?>
</p>
</form>
</div>
