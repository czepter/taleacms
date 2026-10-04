<?php
/**
 * What a Claude connection may do (2.2): the choice on the OAuth consent screen and when creating a personal token.
 * Never more than the user's role allows.
 *
 * @var string $role the user's role, translated
 * @var string $selected full | drafts | read
 */
$options = [
    'full' => ['Everything your account may do', 'Build, edit and publish with the permissions of your role (%s). The menu and the look still wait in the draft look until they are published.'],
    'drafts' => ['Drafts only', 'Claude builds pages, writes news drafts, adds hidden collection items, proposes exceptions to the opening hours, sorts enquiries and prepares look changes – you review and publish them. Nothing on the live site changes without you.'],
    'read' => ['Read only', 'Claude reads pages, settings, the audit and the change log, and suggests changes. It changes nothing.'],
];
?>
<fieldset class="pristup-napojeni">
<legend><?= e(t('What may Claude do?')) ?></legend>
<div class="karty-volby">
<?php foreach ($options as $key => [$label, $help]): ?>
	<label class="karta-volba"><input type="radio" name="access" value="<?= e($key) ?>"<?= $selected === $key ? ' checked' : '' ?>><strong><?= e(t($label)) ?></strong><span><?= e(t($help, $role)) ?></span></label>
<?php endforeach ?>
</div>
</fieldset>
