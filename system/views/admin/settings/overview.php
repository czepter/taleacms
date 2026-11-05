<?php
/**
 * Settings overview: the groups of Admin\Kernel::SETTINGS_MENU as tiles (docs/specs/settings-ux.md section 4).
 *
 * @var Talea\Admin\Modules\Settings $module
 * @var array<string, class-string> $modules the modules the person may open
 */
use Talea\Admin\Modules\Settings;

$about = [
	'settings:general' => 'Name, language, time zone and contact', 'settings:address' => 'Live or paused, search engines, host names',
	'business:' => 'Address, hours and legal details', 'settings:seo' => 'Titles, descriptions and AI search', 'settings:cookies' => 'Privacy policy and cookie notice',
	'settings:messages' => 'Texts visitors get by e-mail', 'settings:enquiries' => 'Forms, spam protection and retention', 'settings:analytics' => 'Visits without cookies',
	'transfer:' => 'Move content in or out', 'settings:mail' => 'Sender and delivery of e-mails', 'newsletter_settings:' => 'Lists and sending service',
	'settings:webhooks' => 'Tell other services about changes', 'settings:reports' => 'Monthly summary for the owner', 'connectors:' => 'Link other services',
	'settings:backups' => 'Copies and where they go', 'redirects:' => 'Old addresses to new ones', 'changelog:' => 'Who changed what',
	'extensions:' => 'Switch features on and off', 'settings:ai' => 'Assistant and model key', 'users:' => 'People, roles and sign-in', 'status:' => 'Checks and versions',
	'sites:' => 'All sites of this installation', 'owners:' => 'Accounts and limits',
];
// the search (?search=): the screens whose name or description match, and single settings by the label of their field
$search = trim((string) $app->request->get('search'));
$found = $search === '' ? [] : array_values(array_filter(Talea\Admin\Kernel::settingsSearch($modules, $app->settings(), fn (string $query): string => $app->url('admin.php?' . $query)),
    fn (array $entry): bool => Talea\Admin\Kernel::textMatches($search, $entry['n'] . ' ' . $entry['s'])));
$shown = 0;
?>
<form class="settings-search" method="get" action="<?= e($app->url('admin.php?module=settings')) ?>" role="search">
	<input type="hidden" name="tab" value="overview">
	<label class="visually-hidden" for="settings-search"><?= e(t('Search settings')) ?></label>
	<input class="textfield" type="search" id="settings-search" name="search" value="<?= e($search) ?>" placeholder="<?= e(t('Search settings, e.g. time zone or sender')) ?>">
	<button class="btn" type="submit"><?= e(t('Search')) ?></button>
<?php if ($search !== ''): ?>
	<a class="navigation" href="<?= e($app->url('admin.php?module=settings&tab=overview')) ?>"><?= e(t('Show all')) ?></a>
<?php endif ?>
</form>
<?php if ($found !== []): ?>
<h2><?= e(t('Single settings')) ?></h2>
<ul class="settings-found">
<?php foreach ($found as $entry): ?>
	<li><a href="<?= e($entry['u']) ?>"><span><?= e($entry['n']) ?></span><small><?= e($entry['s']) ?></small></a></li>
<?php endforeach ?>
</ul>
<?php endif ?>
<?php foreach (Talea\Admin\Kernel::settingsMenu() as $group => $entries): ?>
<?php $links = array_filter($entries, fn (array $entry): bool => isset($modules[$entry[0]]) && ($entry[0] !== 'settings' || Settings::tabAvailable($entry[1], $app->settings(), $app->db()))
    && ($search === '' || Talea\Admin\Kernel::textMatches($search, t($group) . ' ' . t($entry[2]) . ' ' . (isset($about[$entry[0] . ':' . $entry[1]]) ? t($about[$entry[0] . ':' . $entry[1]]) : '')))); ?>
<?php if ($links === []) { continue; } $shown += count($links); ?>
<h2><?= e(t($group)) ?></h2>
<div class="tiles settings-tiles">
<?php foreach ($links as [$ident, $entryTab, $label]): ?>
	<a class="tiles-item" href="<?= e($app->url('admin.php?module=' . $ident . ($entryTab !== '' ? '&tab=' . $entryTab : ''))) ?>"><strong><?= e(t($label)) ?></strong><?php if (isset($about[$ident . ':' . $entryTab])): ?><span><?= e(t($about[$ident . ':' . $entryTab])) ?></span><?php endif ?></a>
<?php endforeach ?>
</div>
<?php endforeach ?>
<?php if ($search !== '' && $shown === 0 && $found === []): ?>
<p class="empty-state-text"><?= e(t('No setting matches “%s”.', $search)) ?></p>
<?php endif ?>
