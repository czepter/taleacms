<?php
/**
 * A page an add-on registered (3.0): its HTML as the add-on rendered it.
 *
 * @var Talea\Admin\Modules\Addons $module
 * @var string $html
 * @var string $addon
 */
?>
<p class="navigation-row"><a class="navigation" href="<?= e($module->url()) ?>">← <?= e(t('Add-ons')) ?></a> <span class="small-text"><?= e(t('This page belongs to the add-on %s.', $addon)) ?></span></p>
<div class="addon-page"><?= $html ?></div>
