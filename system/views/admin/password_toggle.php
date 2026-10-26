<?php
/**
 * The eye button inside a password field of the sign-in pages (image/password.js shows or hides what was typed).
 *
 * @var string $for  id of the password input
 */
$icon = require __DIR__ . '/icons.php';
?>
<button class="password-toggle" type="button" data-show-password="<?= e($for) ?>" aria-controls="<?= e($for) ?>" aria-pressed="false" aria-label="<?= e(t('Show password')) ?>" title="<?= e(t('Show password')) ?>"><span class="password-hidden"><?= $icon('eye') ?></span><span class="password-shown"><?= $icon('eye-off') ?></span></button>
