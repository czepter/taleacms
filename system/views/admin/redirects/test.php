<?php
/**
 * What one rule does with a sample path (read-only).
 *
 * @var Talea\Core\App $app
 * @var Talea\Admin\Modules\Redirects $module
 * @var array<string, mixed> $rule
 * @var string $path   the sample path
 * @var ?string $result the target the rule gives, '' = the rule answers without a target (410, 451), null = does not match
 */
?>
<p class="navigation-row"><a class="navigation" href="<?= e($module->url()) ?>">← <?= e(t('Redirects')) ?></a></p>
<form class="form" method="get" action="<?= e($app->url('admin.php')) ?>">
<input type="hidden" name="module" value="redirects"><input type="hidden" name="action" value="test"><input type="hidden" name="id" value="<?= e($rule['public_id']) ?>">
<fieldset><legend><?= e(t('Test redirect')) ?> · <code><?= e($rule['from_path']) ?></code></legend>
<div class="row"><label for="path"><?= e(t('Sample address')) ?></label><div><input class="textfield wide" type="text" id="path" name="path" value="<?= e($path) ?>" maxlength="255" placeholder="<?= e(t('/old-page.html')) ?>" autofocus></div></div>
<?php if ($path !== ''): ?>
<div class="row"><span class="caption"><?= e(t('Result')) ?></span><div><?php if ($result === null): ?><?= e(t('The rule does not match this address.')) ?><?php elseif ($result === '' && (int) $rule['type'] >= 400): ?><?= e(t('The rule matches: the answer is %d with no target.', (int) $rule['type'])) ?><?php else: ?><?= e(t('The rule matches: %d to', (int) $rule['type'])) ?> <code><?= e(preg_match('#^https?://#i', $result) ? $result : '/' . ltrim($result, '/')) ?></code><?php endif ?></div></div>
<?php endif ?>
</fieldset>
<p class="buttons"><input class="btn" type="submit" value="<?= e(t('Test')) ?>"></p>
</form>
