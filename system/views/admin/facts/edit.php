<?php
/**
 * One business fact (2.10): its value, type, schema.org property, source, language versions, where it is used and – right
 * after a change – the sentences that still state the old value.
 *
 * @var Kaleta\Admin\Modules\Facts $module
 * @var array<string, mixed>|null $fact
 * @var list<string> $languages
 * @var array<string, string> $translations
 * @var list<array<string, string>> $history
 * @var string $oldValue
 * @var list<array<string, mixed>> $stillOld
 * @var list<array<string, mixed>> $used
 */
use Kaleta\Core\Facts;

$isNew = $fact === null;
?>
<p><a href="<?= e($module->url('')) ?>">← <?= e(t('All facts')) ?></a></p>
<?php if ($oldValue !== ''): ?>
<div class="notice <?= $stillOld === [] ? 'notice-ok' : 'notice-warning' ?>">
<p><?= e($stillOld === [] ? t('No text on the site states the old value “%s” any more.', $oldValue) : t('The old value “%s” is still written as plain text in %d places – replace it with the token so it changes by itself next time:', $oldValue, count($stillOld))) ?></p>
<?php if ($stillOld !== []): ?><ul><?php foreach ($stillOld as $o): ?><li><a href="<?= e($app->url($o['edit'])) ?>"><?= e($o['where']) ?></a>: <?= e($o['sentence']) ?></li><?php endforeach ?></ul><?php endif ?>
</div>
<?php endif ?>
<form class="form" method="post" action="<?= e($module->url('save')) ?>">
<?= $csrf ?>
<fieldset>
<legend><?= e($isNew ? t('New fact') : t('Fact')) ?></legend>
<div class="row"><label for="label"><?= e(t('Name')) ?></label><div><input class="textfield wide" id="label" name="label" required maxlength="150" value="<?= e((string) ($fact['label'] ?? '')) ?>" placeholder="<?= e(t('e.g. Founded')) ?>"></div></div>
<div class="row"><label for="key"><?= e(t('Key')) ?></label><div><input class="textfield" id="key" name="key" required pattern="[a-z][a-z0-9_]{1,39}" maxlength="40" value="<?= e((string) ($fact['key'] ?? '')) ?>"<?= $isNew ? '' : ' readonly' ?> placeholder="founded">
<span class="help"><?= e(t('Lowercase letters, digits and _. In content: {{fact.key}}. It cannot change later.')) ?></span></div></div>
<div class="row"><label for="type"><?= e(t('Type')) ?></label><div><select id="type" name="type">
<?php foreach (Facts::TYPES as $k => $label): ?><option value="<?= e($k) ?>"<?= ($fact['type'] ?? 'text') === $k ? ' selected' : '' ?>><?= e(t($label)) ?></option><?php endforeach ?>
</select><span class="help"><?= e(t('A number is shown with the thousands separator of the language, a date in its format, an amount as 1 500 CZK (enter 1500 CZK).')) ?></span></div></div>
<div class="row"><label for="value"><?= e(t('Value')) ?></label><div><input class="textfield wide" id="value" name="value" maxlength="500" value="<?= e((string) ($fact['value'] ?? '')) ?>"></div></div>
<?php foreach ($languages as $language): ?>
<div class="row"><label for="value_<?= e($language) ?>"><?= e(t('Value')) ?> (<?= e(strtoupper($language)) ?>)</label><div><input class="textfield wide" id="value_<?= e($language) ?>" name="value_<?= e($language) ?>" maxlength="500" value="<?= e($translations[$language] ?? '') ?>">
<span class="help"><?= e(t('Empty = the same as the default value.')) ?></span></div></div>
<?php endforeach ?>
<div class="row"><label for="schema"><?= e(t('For search engines')) ?></label><div><select id="schema" name="schema">
<?php foreach (Facts::SCHEMA_PROPS as $k => $label): ?><option value="<?= e($k) ?>"<?= ($fact['schema'] ?? '') === $k ? ' selected' : '' ?>><?= e($k === '' ? '—' : t($label) . ' (' . $k . ')') ?></option><?php endforeach ?>
</select><span class="help"><?= e(t('Adds the fact to the company in the structured data (schema.org) search engines and AI assistants read.')) ?></span></div></div>
<div class="row"><label for="source"><?= e(t('Source')) ?></label><div><input class="textfield wide" id="source" name="source" maxlength="255" value="<?= e((string) ($fact['source'] ?? '')) ?>">
<span class="help"><?= e(t('Where the number comes from – for you and your colleagues, not shown on the site.')) ?></span></div></div>
</fieldset>
<p class="buttons"><input class="btn" type="submit" value="<?= e(t('Save')) ?>">
<?php if (!$isNew): ?> <button class="navigation danger" type="submit" formaction="<?= e($module->url('delete')) ?>" data-confirm="<?= e(t('Delete the fact? Content that uses it will show nothing in its place.')) ?>"><?= e(t('Delete')) ?></button><?php endif ?></p>
</form>
<?php if (!$isNew): ?>
<h2><?= e(t('Where it is used')) ?></h2>
<?php if ($used === []): ?><p><?= e(t('Not used yet. Write {{fact.%s}} into a text, a button or a link.', (string) $fact['key'])) ?></p>
<?php else: ?><ul><?php foreach ($used as $o): ?><li><a href="<?= e($app->url($o['edit'])) ?>"><?= e($o['where']) ?></a>: <?= e($o['sentence']) ?></li><?php endforeach ?></ul><?php endif ?>
<?php if ($history !== []): ?>
<h2><?= e(t('Changes')) ?></h2>
<ul><?php foreach ($history as $h): ?><li><?= e(format_date($h['changed_at'], true)) ?>: <?= e($h['old_value']) ?> → <?= e($h['new_value']) ?><?= $h['language'] !== '' ? ' (' . e(strtoupper($h['language'])) . ')' : '' ?></li><?php endforeach ?></ul>
<?php endif ?>
<?php endif ?>
