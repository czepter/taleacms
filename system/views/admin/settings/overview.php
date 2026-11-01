<?php
/**
 * Settings overview: the groups of Admin\Kernel::SETTINGS_MENU as tiles (docs/specs/settings-ux.md section 4).
 *
 * @var Talea\Admin\Modules\Settings $module
 * @var array<string, class-string> $modules the modules the person may open
 */
use Talea\Admin\Modules\Settings;
?>
<?php foreach (Talea\Admin\Kernel::settingsMenu() as $group => $entries): ?>
<?php $links = array_filter($entries, fn (array $entry): bool => isset($modules[$entry[0]]) && ($entry[0] !== 'settings' || Settings::tabAvailable($entry[1], $app->settings(), $app->db()))); ?>
<?php if ($links === []) { continue; } ?>
<h2><?= e(t($group)) ?></h2>
<div class="tiles settings-tiles">
<?php foreach ($links as [$ident, $entryTab, $label]): ?>
	<a class="tiles-item" href="<?= e($app->url('admin.php?module=' . $ident . ($entryTab !== '' ? '&tab=' . $entryTab : ''))) ?>"><strong><?= e(t($label)) ?></strong></a>
<?php endforeach ?>
</div>
<?php endforeach ?>
