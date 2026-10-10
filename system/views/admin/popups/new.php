<?php
/**
 * A new popup from a ready-made template.
 *
 * @var Talea\Core\App $app
 * @var Talea\Admin\Modules\Popups $module
 * @var string $csrf
 */
use Talea\Builder\Popups;

?>
<form class="form" method="post" action="<?= e($module->url('create')) ?>">
<?= $csrf ?>
<div class="row"><label for="name"><?= e(t('Pop-up name')) ?></label><div><input class="textfield wide" id="name" name="name" maxlength="100" placeholder="<?= e(t('e.g. Newsletter on the blog')) ?>"><span class="help"><?= e(t('Only for you in the admin and for screen readers. The address #popup-… is made from the name.')) ?></span></div></div>
<fieldset>
<legend><?= e(t('Start from')) ?></legend>
<div class="options">
<?php $first = true; foreach (Popups::LIBRARY as $key => [$name, $description, $type]): ?>
<label><input type="radio" name="template" value="<?= e($key) ?>"<?= $first ? ' checked' : '' ?>> <strong><?= e(t($name)) ?></strong> (<?= e(mb_strtolower(t(Popups::TYPES[$type][0]))) ?>) – <?= e(t($description)) ?></label>
<?php $first = false; endforeach ?>
</div>
</fieldset>
<p class="help"><?= e(t('The pop-up is created turned off and opens in the builder. It shows on the site once you publish it and turn it on.')) ?></p>
<p class="buttons"><input class="btn" type="submit" value="<?= e(t('Create and open in the builder')) ?>"> <a class="navigation" href="<?= e($module->url()) ?>"><?= e(t('Back')) ?></a></p>
</form>
