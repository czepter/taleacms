<?php
/**
 * The "Writing assistant" screen (Modules\AssistantSettings): provider, key and the model from the provider's own list.
 *
 * @var list<array{id: string, name: string}> $models  the provider's models, newest first (Core\Assistant::loadModels)
 * @var int $modelsTime  when the list was loaded
 * @var string $modelsError  why the list could not be loaded
 */
use Talea\Core\Assistant;
?>
<input type="hidden" name="ai_provider_previous" value="<?= e($values['ai_provider']) ?>">
<div class="row">
	<label for="ai_provider"><?= e(t('Provider')) ?></label>
	<div><select id="ai_provider" name="ai_provider">
<?php foreach (Assistant::PROVIDERS as $key => [$name]): ?>
		<option value="<?= e($key) ?>"<?= $values['ai_provider'] === $key ? ' selected' : '' ?>><?= e(t($name)) ?></option>
<?php endforeach ?>
	</select>
	<span class="help"><?= e(t('Create a key with the provider:')) ?>
<?php foreach (Assistant::PROVIDERS as [$name, , $console]): ?>
		<a href="<?= e($console) ?>" target="_blank" rel="noopener"><?= e(t($name)) ?></a>
<?php endforeach ?>
		· <?= e(t('You pay only for actual use; one suggestion costs a fraction of a cent. The key is stored only on your site.')) ?></span></div>
</div>
<div class="row">
	<label for="ai_key"><?= e(t('API key')) ?></label>
	<div><input class="textfield wide" type="password" id="ai_key" name="ai_key" value="" autocomplete="off" placeholder="<?= $values['ai_key'] !== '' ? e(t('saved key ending in %s – enter a new one only to change it', $values['ai_key'])) : '' ?>">
<?php if ($values['ai_key'] !== ''): ?>
	<label><input type="checkbox" name="ai_key_delete" value="1"> <?= e(t('Remove saved key')) ?></label>
<?php endif ?>
	</div>
</div>
<div class="row">
	<label for="ai_model"><?= e(t('Model')) ?></label>
	<div>
<?php if ($models !== []): ?>
	<div class="navigation-row"><select id="ai_model" name="ai_model">
<?php foreach ($models as $i => $model): $selected = $values['ai_model'] === $model['id'] || ($i === 0 && !in_array($values['ai_model'], array_column($models, 'id'), true)); ?>
		<option value="<?= e($model['id']) ?>"<?= $selected ? ' selected' : '' ?>><?= e($model['name'] === $model['id'] ? $model['id'] : $model['name'] . ' · ' . $model['id']) ?></option>
<?php endforeach ?>
	</select>
	<button class="navigation" type="submit" name="reload_models" value="1"><?= e(t('Reload the list')) ?></button></div>
	<span class="help"><?= e(t('The list comes straight from %s with your key, newest first – new models appear here without an update of Talea. Loaded %s.', t(Assistant::PROVIDERS[$values['ai_provider']][0] ?? ''), format_date(date('Y-m-d H:i:s', $modelsTime), true))) ?></span>
<?php else: ?>
	<select id="ai_model" disabled><option><?= e(t('Save a key first – then the list loads')) ?></option></select>
<?php if ($modelsError !== ''): ?>
	<span class="error-field"><?= e(t($modelsError)) ?></span>
<?php endif ?>
<?php endif ?>
	</div>
</div>
<p class="notice"><?= e(t('The assistant only suggests – a person decides on every change. When used, the text is sent to the chosen provider; nothing is sent anywhere without clicking an assistant button.')) ?></p>
