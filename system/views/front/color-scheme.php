<?php
/**
 * Color scheme switcher for visitors: by device / light / dark. image/web.js stores the choice in the browser (localStorage
 * "tl-theme") and a script in the template head applies it before rendering, so the page does not flash. It shares
 * appearance and behaviour with the language switcher (class tl-languages-select, design system tokens).
 *
 * @var string $selected auto | dark – default color scheme of the site (Site appearance → Dark mode)
 */
$id = 'tl-theme-' . bin2hex(random_bytes(3));
$ikony = [
    'auto' => '<circle cx="12" cy="12" r="8.5"/><path d="M12 3.5v17a8.5 8.5 0 0 0 0-17z" fill="currentColor" stroke="none"/>',
    'light' => '<circle cx="12" cy="12" r="4"/><path d="M12 2.5v2M12 19.5v2M4.6 4.6 6 6M18 18l1.4 1.4M2.5 12h2M19.5 12h2M4.6 19.4 6 18M18 6l1.4-1.4"/>',
    'dark' => '<path d="M20 14.5A8.5 8.5 0 1 1 9.5 4a7 7 0 0 0 10.5 10.5z"/>',
];
$choices = ['auto' => t('Match device'), 'light' => t('Light'), 'dark' => t('Dark')];
?>
<nav class="tl-languages-select tl-theme" data-option="<?= e($selected) ?>" data-theme-default="<?= e($selected) ?>" aria-label="<?= e(t('Appearance')) ?>">
	<button type="button" class="tl-languages-btn tl-theme-btn" popovertarget="<?= $id ?>" style="anchor-name: --<?= $id ?>" aria-label="<?= e(t('Appearance')) ?>">
<?php foreach ($ikony as $key => $icon): ?>
		<svg class="tl-theme-icon tl-theme-icon--<?= $key ?>" viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><?= $icon ?></svg>
<?php endforeach ?>
	</button>
	<div id="<?= $id ?>" popover style="position-anchor: --<?= $id ?>">
<?php foreach ($choices as $key => $label): ?>
		<button type="button" data-theme-option="<?= $key ?>" aria-pressed="<?= $key === $selected ? 'true' : 'false' ?>"><svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><?= $ikony[$key] ?></svg><span><?= e($label) ?></span></button>
<?php endforeach ?>
	</div>
</nav>
