<?php
/**
 * The sidebar of My account: back home, the title and its screens, built like the sidebar of the settings.
 *
 * @var Talea\Core\App $app
 * @var array<string, string> $tabs  tab => label (Admin\Account::TABS that are available)
 * @var string $tab  the open screen
 */
?>
	<a class="menu-back" href="<?= e($app->url('admin.php')) ?>"><svg class="icon" viewBox="0 0 24 24" width="16" height="16" fill="none" stroke="currentColor" stroke-width="2.2" stroke-linecap="round" aria-hidden="true"><path d="m15 6-6 6 6 6"/></svg><?= e(t('Home')) ?></a>
	<p class="menu-title"><?= e(t('My account')) ?></p>
	<ul class="menu menu-settings" id="menu">
		<li>
			<ul class="menu-sub">
<?php foreach ($tabs as $id => $label): ?>
				<li<?= $id === $tab ? ' class="active"' : '' ?>><a href="<?= e($app->url('admin.php?action=account&tab=' . $id)) ?>"<?= $id === $tab ? ' aria-current="page"' : '' ?>><?= e(t($label)) ?></a></li>
<?php endforeach ?>
			</ul>
		</li>
	</ul>
