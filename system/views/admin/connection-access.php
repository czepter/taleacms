<?php
/**
 * What a Claude connection may do (2.2): the choice on the OAuth consent screen and when creating a personal token.
 * Never more than the user's role allows.
 *
 * @var string $role the user's role, translated
 * @var string $selected full | drafts | read
 * @var string|null $recommended the level marked as recommended (the consent screen: drafts)
 * @var string $toolSet all | essentials | migration | fleet – the tools the connection lists (Mcp\Catalog::TOOL_SETS)
 * @var list<string> $offered the sets this site offers (Mcp\Catalog::offered; default essentials and all)
 */
$labels = Talea\Front\OAuth::ACCESS_LABELS;
$options = [
    'drafts' => [$labels['drafts'], 'Claude builds pages, writes news drafts, adds hidden collection items, proposes exceptions to the opening hours, sorts enquiries and prepares look changes – you review and publish them. Nothing on the live site changes without you.'],
    'full' => [$labels['full'], 'Everything your role (%s) allows, publishing included – Claude publishes when you ask it to. The menu and the look still wait in the draft look until they are published.'],
    'read' => [$labels['read'], 'Claude reads pages, settings, the audit and the change log, and suggests changes. It changes nothing.'],
];
$toolSets = [
    'essentials' => ['Essentials', 'About a quarter of the tools are listed – pages, the builder, collections, media, the look and the audit. Claude finds every other tool it may use (news, redirects, settings, bookings, newsletters…) when it needs it, with the same limits, change log and undo. Much less to load in every conversation.'],
    'migration' => ['Migration', 'For moving a site here: the import, old pages, rebuilding, redirects, media and the migration report are listed first. Claude finds every other tool when it needs it, with the same limits.'],
    'fleet' => ['Fleet console', 'For the console of your Talea sites: the sites, their health and events are listed first.'],
    'all' => ['All tools', 'Every tool this connection may use is listed up front. More to load in every conversation.'],
];
$toolSets = array_intersect_key($toolSets, array_flip($offered ?? ['essentials', 'all']));
?>
<fieldset class="access-connection">
<legend><?= e(t('What may Claude do?')) ?></legend>
<div class="cards-options">
<?php foreach ($options as $key => [$label, $help]): ?>
	<label class="card-option"><input type="radio" name="access" value="<?= e($key) ?>"<?= $selected === $key ? ' checked' : '' ?>><strong><?= e(t($label)) ?><?= ($recommended ?? null) === $key ? ' <span class="badge badge-published">' . e(t('recommended')) . '</span>' : '' ?></strong><span><?= e(t($help, $role)) ?></span></label>
<?php endforeach ?>
</div>
</fieldset>
<fieldset class="access-connection">
<legend><?= e(t('Which tools should Claude list?')) ?></legend>
<div class="cards-options">
<?php foreach ($toolSets as $key => [$label, $help]): ?>
	<label class="card-option"><input type="radio" name="tool_set" value="<?= e($key) ?>"<?= ($toolSet ?? 'all') === $key ? ' checked' : '' ?>><strong><?= e(t($label)) ?></strong><span><?= e(t($help)) ?></span></label>
<?php endforeach ?>
</div>
<p class="help"><?= e(t('A tool set only decides which tools Claude sees first – what it may do still depends on your role and the access chosen above.')) ?></p>
</fieldset>
