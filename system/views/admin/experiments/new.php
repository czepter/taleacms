<?php
/**
 * A new experiment: first the page, then what to test, version B and the goal.
 *
 * @var Talea\Core\App $app
 * @var Talea\Admin\Modules\Experiments $module
 * @var string $csrf
 * @var list<array<string, mixed>> $pages
 * @var ?array<string, mixed> $page
 * @var list<array{id: string, label: string, depth: int}> $choices
 */
use Talea\Builder\Experiments;

?>
<?php if ($page === null): ?>
<form class="form" method="get" action="<?= e($module->url()) ?>">
<input type="hidden" name="module" value="experiments"><input type="hidden" name="action" value="new">
<div class="row"><label for="page"><?= e(t('Page')) ?></label><div>
<select class="textfield wide" id="page" name="page" required>
<?php foreach ($pages as $p): ?>
<option value="<?= e($p['public_id']) ?>"><?= e($p['title']) ?></option>
<?php endforeach ?>
</select>
<span class="help"><?= e(t('The page visitors see now (version A). It has to be published and made in the builder.')) ?></span></div></div>
<p class="buttons"><input class="btn" type="submit" value="<?= e(t('Continue')) ?>"> <a class="navigation" href="<?= e($module->url()) ?>"><?= e(t('Back')) ?></a></p>
</form>
<?php else: ?>
<form class="form" method="post" action="<?= e($module->url('create')) ?>">
<?= $csrf ?>
<input type="hidden" name="page" value="<?= e($page['public_id']) ?>">
<p><strong><?= e($page['title']) ?></strong></p>
<div class="row"><label for="name"><?= e(t('Name')) ?></label><div><input class="textfield wide" id="name" name="name" maxlength="100" required placeholder="<?= e(t('e.g. Shorter headline on the home page')) ?>"></div></div>
<fieldset>
<legend><?= e(t('What to test')) ?></legend>
<div class="options">
<label><input type="radio" name="kind" value="element" checked> <strong><?= e(t(Experiments::KINDS['element'])) ?></strong> – <?= e(t('version B starts as a copy that you edit in the builder (as a component).')) ?></label>
<label><input type="radio" name="kind" value="page"> <strong><?= e(t(Experiments::KINDS['page'])) ?></strong> – <?= e(t('the content of another page is version B at this page’s address.')) ?></label>
</div>
</fieldset>
<div class="row"><label for="element"><?= e(t('Element or section')) ?></label><div>
<select class="textfield wide" id="element" name="element">
<?php foreach ($choices as $c): ?>
<option value="<?= e($c['id']) ?>"><?= e(str_repeat('– ', $c['depth']) . $c['label']) ?></option>
<?php endforeach ?>
</select>
<span class="help"><?= e(t('Only for “a section or an element”. Choose a section for a block, a heading or a button for a single piece of text.')) ?></span></div></div>
<div class="row"><label for="variant_page"><?= e(t('Version B page')) ?></label><div>
<select class="textfield wide" id="variant_page" name="variant_page">
<option value=""></option>
<?php foreach (array_filter($pages, fn (array $o): bool => $o['public_id'] !== $page['public_id']) as $p): ?>
<option value="<?= e($p['public_id']) ?>"><?= e($p['title']) ?></option>
<?php endforeach ?>
</select>
<span class="help"><?= e(t('Only for “a whole page”.')) ?></span></div></div>
<div class="row"><label for="goal"><?= e(t('Goal')) ?></label><div>
<select class="textfield wide" id="goal" name="goal">
<?php foreach (Experiments::GOALS as $key => [$name, $help]): ?>
<option value="<?= e($key) ?>"><?= e(t($name)) ?></option>
<?php endforeach ?>
</select>
<span class="help"><?= e(t('A form sent and a booking made are counted when the visitor submits the form on this page.')) ?></span></div></div>
<div class="row"><label for="goal_target"><?= e(t('Address of the link or button')) ?></label><div><input class="textfield wide" id="goal_target" name="goal_target" maxlength="255" placeholder="/contact"><span class="help"><?= e(t('Only for the goal “a link or button is clicked”: the address as it is in the link, e.g. /contact or tel:+441234567890.')) ?></span></div></div>
<div class="row"><label for="goal_page"><?= e(t('Page to reach')) ?></label><div>
<select class="textfield wide" id="goal_page" name="goal_page">
<option value=""></option>
<?php foreach ($pages as $p): ?>
<option value="<?= e($p['public_id']) ?>"><?= e($p['title']) ?></option>
<?php endforeach ?>
</select>
<span class="help"><?= e(t(Experiments::GOALS['page'][1])) ?></span></div></div>
<div class="row"><label><input type="checkbox" name="auto_promote" value="1"> <?= e(t('Promote the winner automatically when the guardrails below are met')) ?></label></div>
<p class="help"><?= e(t('Guardrails: at least %d views of each version, at least %d days, at least %d%% probability, and B at least %d%% better.', Experiments::MIN_VIEWS, Experiments::MIN_DAYS, (int) round(Experiments::MIN_PROBABILITY * 100), (int) round(Experiments::MIN_UPLIFT * 100))) ?></p>
<p class="buttons"><input class="btn" type="submit" value="<?= e(t('Create the experiment')) ?>"> <a class="navigation" href="<?= e($module->url()) ?>"><?= e(t('Back')) ?></a></p>
</form>
<?php endif ?>
