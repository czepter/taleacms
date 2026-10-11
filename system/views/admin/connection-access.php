<?php
/**
 * What a Claude connection may do (2.2): the choice on the OAuth consent screen and when creating a personal token.
 * Never more than the user's role allows.
 *
 * @var string $role the user's role, translated
 * @var string $selected full | drafts | read
 * @var string|null $recommended the level marked as recommended (the consent screen: drafts)
 */
$labels = Talea\Front\OAuth::ACCESS_LABELS;
$options = [
    'drafts' => [$labels['drafts'], 'Claude builds pages, writes news drafts, adds hidden collection items, proposes exceptions to the opening hours, sorts enquiries and prepares look changes – you review and publish them. Nothing on the live site changes without you.'],
    'full' => [$labels['full'], 'Everything your role (%s) allows, publishing included – Claude publishes when you ask it to. The menu and the look still wait in the draft look until they are published.'],
    'read' => [$labels['read'], 'Claude reads pages, settings, the audit and the change log, and suggests changes. It changes nothing.'],
];
?>
<fieldset class="access-connection">
<legend><?= e(t('What may Claude do?')) ?></legend>
<div class="cards-options">
<?php foreach ($options as $key => [$label, $help]): ?>
	<label class="card-option"><input type="radio" name="access" value="<?= e($key) ?>"<?= $selected === $key ? ' checked' : '' ?>><strong><?= e(t($label)) ?><?= ($recommended ?? null) === $key ? ' <span class="badge badge-published">' . e(t('recommended')) . '</span>' : '' ?></strong><span><?= e(t($help, $role)) ?></span></label>
<?php endforeach ?>
</div>
</fieldset>
