<?php
/**
 * What a Claude connection may do (2.2): the choice on the OAuth consent screen and when creating a personal token.
 * Never more than the user's role allows. On the consent screen of an application that does not return to Claude's own
 * apps the texts speak of "the application", never of Claude (3.3.4, N65).
 *
 * @var string $role the user's role, translated
 * @var string $selected full | drafts | read
 * @var bool|null $claude false = the application is not one of Claude's own apps (default: it is)
 */
$options = ($claude ?? true) ? [
    'full' => ['Everything your account may do', 'Build, edit and publish with the permissions of your role (%s). The menu and the look still wait in the draft look until they are published.'],
    'drafts' => ['Drafts only', 'Claude builds pages, writes news drafts, adds hidden collection items, proposes exceptions to the opening hours, sorts enquiries and prepares look changes – you review and publish them. Nothing on the live site changes without you.'],
    'read' => ['Read only', 'Claude reads pages, settings, the audit and the change log, and suggests changes. It changes nothing.'],
] : [
    'full' => ['Everything your account may do', 'Build, edit and publish with the permissions of your role (%s). The menu and the look still wait in the draft look until they are published.'],
    'drafts' => ['Drafts only', 'The application builds pages, writes news drafts, adds hidden collection items, proposes exceptions to the opening hours, sorts enquiries and prepares look changes – you review and publish them. Nothing on the live site changes without you.'],
    'read' => ['Read only', 'The application reads pages, settings, the audit and the change log. It changes nothing.'],
];
?>
<fieldset class="pristup-napojeni">
<legend><?= e(($claude ?? true) ? t('What may Claude do?') : t('What may the application do?')) ?></legend>
<div class="karty-volby">
<?php foreach ($options as $key => [$label, $help]): ?>
	<label class="karta-volba"><input type="radio" name="access" value="<?= e($key) ?>"<?= $selected === $key ? ' checked' : '' ?>><strong><?= e(t($label)) ?></strong><span><?= e(t($help, $role)) ?></span></label>
<?php endforeach ?>
</div>
</fieldset>
