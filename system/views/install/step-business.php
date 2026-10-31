<?php
$check = '<span class="choice-check" aria-hidden="true"><svg viewBox="0 0 24 24"><path d="M5 12.5l4.5 4.5L19 7.5"></path></svg></span>';
foreach ($groups as $group):
	$members = array_filter($blueprints, fn (array $m): bool => $m['group'] === $group);
	if ($members === []) {
		continue;
	}
?>
<fieldset class="biz-group">
<legend class="side-label"><?= e(t(Talea\Core\Blueprint::GROUPS[$group])) ?></legend>
<div class="choices biz">
<?php foreach ($members as $key => $m): ?>
	<label class="choice"><input type="radio" name="blueprint" value="<?= e($key) ?>"<?= $data['blueprint'] === $key ? ' checked' : '' ?>><strong><?= e(t(Talea\Core\Blueprint::text($m['name']))) ?></strong><span class="biz-brings"><?php foreach ($m['presets'] as $preset): ?><span class="tag"><?= e(t(Talea\Builder\Presets::get($preset)['name'])) ?></span><?php endforeach ?></span><?= $check ?></label>
<?php endforeach ?>
</div>
</fieldset>
<?php endforeach ?>
<fieldset class="biz-group">
<legend class="side-label"><?= e(t('None of these')) ?></legend>
<div class="choices biz">
	<label class="choice"><input type="radio" name="blueprint" value=""<?= $data['blueprint'] === '' ? ' checked' : '' ?>><strong><?= e(t('Something else')) ?></strong><small><?= e(t('A general business website: Home, About us, Services and Contact.')) ?></small><?= $check ?></label>
	<label class="choice"><input type="radio" name="blueprint" value="export"<?= $data['blueprint'] === 'export' ? ' checked' : '' ?>><strong><?= e(t('Move a Talea site here')) ?></strong><small><?= e(t('An empty site; import its export right after installation.')) ?></small><?= $check ?></label>
</div>
<?= $error('blueprint') ?>
</fieldset>
