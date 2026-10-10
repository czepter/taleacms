<?php
/**
 * The wizard's plan to review (#31): the blueprint, the look and the pages. Nothing is made until "Create the drafts".
 *
 * @var Talea\Admin\Modules\Wizard $module
 * @var string $csrf
 * @var array<string, string> $answers
 * @var array{blueprint: string, look: string, pages: list<array{title: string, brief: string}>, generated: bool} $plan
 * @var array<string, array<string, mixed>> $blueprints
 * @var array<string, array<string, mixed>> $looks
 * @var bool $ready the plan comes with pages (an AI key is set)
 * @var string $provider
 */
use Talea\Core\Blueprint;

?>
<p class="notice"><?= e(t('This is the plan. Change anything you like, then create the drafts: the look waits in the draft look, the pages are hidden, nothing is published.')) ?></p>
<form class="form" method="post" action="<?= e($module->url('apply')) ?>"><?= $csrf ?>
<?php foreach ($answers as $key => $value): ?>
<input type="hidden" name="<?= e($key) ?>" value="<?= e($key === 'tone' ? (array_search($value, Talea\Core\SiteWizard::TONES, true) ?: 'friendly') : $value) ?>">
<?php endforeach ?>
<h2><?= e(t('Blueprint')) ?></h2>
<div class="row"><label for="p-blueprint"><?= e(t('Kind of business')) ?></label><div><select id="p-blueprint" name="plan_blueprint">
	<option value=""><?= e(t('None')) ?></option>
<?php foreach ($blueprints as $key => $m): ?>
	<option value="<?= e($key) ?>"<?= $key === $plan['blueprint'] ? ' selected' : '' ?>><?= e(Blueprint::text($m['name'])) ?></option>
<?php endforeach ?>
</select><span class="help"><?= e(t('Adds the collections, facts and checks such a business needs.')) ?></span></div></div>
<h2><?= e(t('Look')) ?></h2>
<div class="row"><label for="p-look"><?= e(t('Look')) ?></label><div><select id="p-look" name="plan_look">
<?php foreach ($looks as $key => $look): ?>
	<option value="<?= e($key) ?>"<?= $key === $plan['look'] ? ' selected' : '' ?>><?= e($look['name'] . ' – ' . $look['description']) ?></option>
<?php endforeach ?>
</select><span class="help"><?= e(t('Applied as a draft look: you preview the whole site and publish it yourself.')) ?></span></div></div>
<h2><?= e(t('Pages')) ?></h2>
<?php if (!$ready): ?>
<p class="notice notice-warning"><?= e(t('No AI key is set, so no text is written and no pages are made. Add a key under Features (Writing assistant) to get them.')) ?></p>
<?php else: ?>
<p class="help"><?= e(t('The assistant writes each page when you create the drafts, from the brief. This sends the brief and your answers to %s.', $provider)) ?></p>
<?php foreach ($plan['pages'] as $i => $page): ?>
<fieldset>
	<label><input type="checkbox" name="pages[<?= $i ?>][use]" value="1" checked> <?= e(t('Create this page')) ?></label>
	<div class="row"><label for="p-title-<?= $i ?>"><?= e(t('Title')) ?></label><div><input class="textfield wide" id="p-title-<?= $i ?>" name="pages[<?= $i ?>][title]" maxlength="80" value="<?= e($page['title']) ?>"></div></div>
	<div class="row"><label for="p-brief-<?= $i ?>"><?= e(t('What it should say')) ?></label><div><textarea class="textfield wide" id="p-brief-<?= $i ?>" name="pages[<?= $i ?>][brief]" rows="2" maxlength="500"><?= e($page['brief']) ?></textarea></div></div>
</fieldset>
<?php endforeach ?>
<?php endif ?>
<p class="navigation-row"><button class="btn" type="submit"><?= e(t('Create the drafts')) ?></button> <a class="navigation" href="<?= e($module->url()) ?>"><?= e(t('Back')) ?></a></p>
</form>
