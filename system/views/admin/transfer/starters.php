<?php
/**
 * The starter pages of the installation in an import (Core\StarterPages): in a preview a choice per starter page an imported page would
 * take the address of, after the import what was replaced, kept or given a number.
 *
 * @var list<array{old: string, page_id: int, public_id: string, title: string, address: string, published: bool, action: string}>|null $choices  the preview's choices; null after the import
 * @var array{replaced: list<array<string, string>>, kept: list<array<string, string>>, renamed: list<array<string, string>>}|null $result  what the import did
 * @var bool $hidden  the imported pages arrive hidden (a published starter page then shows nothing until the imported page is published)
 */
$hidden ??= true;
?>
<?php if (!empty($choices)): ?>
<fieldset>
<legend><?= e(t('Starter pages of this site')) ?></legend>
<p class="help"><?= e(t('These old addresses land on starter pages of the installation. A ticked starter page goes to the trash for 30 days and the imported page takes its address; an unticked one stays and the imported page gets a number (/contact-2). An edited starter page and the home page are never replaced.')) ?></p>
<div class="options options-stacked">
<?php foreach ($choices as $c): ?>
<?php if (in_array($c['action'], ['replace', 'keep'], true)): ?>
	<label><input type="checkbox" name="replace_starter[]" value="<?= e($c['public_id']) ?>"<?= $c['action'] === 'replace' ? ' checked' : '' ?>> <?= e(t('Replace the starter page %s (%s) with the old page %s', $c['title'], $c['address'], $c['old'])) ?></label>
<?php if ($c['published'] && $hidden): ?>
	<p class="small-text"><?= e(t('%s is published: after the import its address shows nothing until you publish the imported page.', $c['address'])) ?></p>
<?php endif ?>
<?php elseif ($c['action'] === 'home'): ?>
	<p class="small-text"><?= e(t('The home page %s stays until you publish the imported home page and set it as the home page.', $c['title'])) ?></p>
<?php else: ?>
	<p class="small-text"><?= e(t('%s stays: it has been edited – the imported page gets a number.', $c['title'])) ?></p>
<?php endif ?>
<?php endforeach ?>
</div>
</fieldset>
<?php endif ?>
<?php if (!empty($result) && ($result['replaced'] !== [] || $result['kept'] !== [] || $result['renamed'] !== [])): ?>
<?php if ($result['replaced'] !== []): ?>
<details class="advanced"><summary><?= e(t('Starter pages replaced (in the trash for 30 days): %s', count($result['replaced']))) ?></summary><ul>
<?php foreach ($result['replaced'] as $r): ?><li><?= e($r['address'] . ' (' . $r['title'] . ') ← ' . $r['old']) ?></li><?php endforeach ?>
</ul></details>
<?php endif ?>
<?php if ($result['kept'] !== []): ?>
<details class="advanced"><summary><?= e(t('Starter pages kept: %s', count($result['kept']))) ?></summary><ul>
<?php foreach ($result['kept'] as $r): ?><li><?= e($r['address'] . ' (' . $r['title'] . ') – ' . $r['old']) ?></li><?php endforeach ?>
</ul></details>
<?php endif ?>
<?php if ($result['renamed'] !== []): ?>
<details class="advanced"><summary><?= e(t('Imported pages that got a number: %s', count($result['renamed']))) ?></summary><ul>
<?php foreach ($result['renamed'] as $r): ?><li><?= e($r['old'] . ' → ' . $r['new']) ?></li><?php endforeach ?>
</ul></details>
<?php endif ?>
<?php endif ?>
