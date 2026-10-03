<?php
/**
 * A page an add-on registered (3.0): its HTML as the add-on rendered it.
 *
 * @var Kaleta\Admin\Modules\Addons $module
 * @var string $html
 * @var string $addon
 */
?>
<p class="navigace-radek"><a class="navigace" href="<?= e($module->url()) ?>">← <?= e(t('Add-ons')) ?></a> <span class="smltxt"><?= e(t('This page belongs to the add-on %s.', $addon)) ?></span></p>
<div class="addon-stranka"><?= $html ?></div>
