<?php
/**
 * Settings → AI (administrators): the writing assistant's own key (settings/assistant.php), the instructions both it and every
 * Claude connection follow, and Claude's guardrails. Connecting Claude is on its own screen for everyone (Modules\Claude).
 *
 * @var Talea\Core\App $app
 * @var array<string, string> $values
 * @var array{at: string, by: string, via: string, before: string, after: string}|null $instructionsChange the change not checked yet (#149)
 * @var bool $assistant  the writing assistant (extension) is on
 */
?>
<h2 id="assistant"><?= e(t('Your own AI key')) ?></h2>
<?php if ($assistant): ?>
<?php require __DIR__ . '/assistant.php'; ?>
<?php else: ?>
<p><?= e(t('With a key from Anthropic, OpenAI, Google or Mistral, the writing assistant drafts sections, rewrites texts and helps with news. It is switched off.')) ?> <a href="<?= e($app->url('admin.php?module=extensions')) ?>"><?= e(t('Features')) ?></a></p>
<?php endif ?>
<h2><?= e(t('Instructions for the AI')) ?></h2>
<?php if ($instructionsChange !== null): // escaped first, so the comparison shows the exact text, angle brackets included ?>
<div class="notice notice-warning" id="instructions-change">
	<p><?= e(Talea\Core\ClaudeInstructions::who($instructionsChange)) ?> <?= e(t('Before → after:')) ?></p>
	<div class="compare"><?= Talea\Core\Diff::html(e($instructionsChange['before']), e($instructionsChange['after']))['html'] ?: '<p>–</p>' ?></div>
	<p><button class="btn" type="submit" formaction="<?= e($module->url('acknowledge_instructions')) ?>" name="instructions_seen" value="<?= e(Talea\Core\ClaudeInstructions::fingerprint($instructionsChange['after'])) ?>"><?= e(t('I have checked the instructions')) ?></button></p>
</div>
<?php endif ?>
<div class="row">
	<label for="claude_instructions"><?= e(t('Instructions for the AI')) ?></label>
	<div><textarea class="textfield wide" id="claude_instructions" name="claude_instructions" rows="6" maxlength="5000" placeholder="<?= e(t('e.g. We address customers informally. Say “renovation”, never “reconstruction”. Keep headings short. Always offer a free visit.')) ?>"><?= e($values['claude_instructions']) ?></textarea>
	<span class="help"><?= e(t('Brand voice, words to use or avoid, house rules. The writing assistant follows them in every text it writes; every Claude connection gets them when it connects, as its resource talea://instructions.')) ?></span></div>
</div>
<h2><?= e(t('Guardrails for the AI')) ?></h2>
<p class="help"><?= e(t('They hold for every Claude connection on top of its access. Claude is told why when it hits one, so it can tell you.')) ?> <a href="<?= e($app->url('admin.php?module=claude')) ?>"><?= e(t('Claude connection')) ?></a></p>
<div class="row">
	<label for="claude_change_limit"><?= e(t('Changes per connection and hour')) ?></label>
	<div><input class="textfield" type="number" id="claude_change_limit" name="claude_change_limit" min="0" max="10000" value="<?= e($values['claude_change_limit']) ?>">
	<span class="help"><?= e(t('0 = no limit. A long run of changes stops here until the next hour.')) ?></span></div>
</div>
<div class="row">
	<span class="caption"><?= e(t('Deleting')) ?></span>
	<div class="options"><label><input type="hidden" name="claude_destructive" value="0"><input type="checkbox" name="claude_destructive" value="1"<?= $values['claude_destructive'] === '1' ? ' checked' : '' ?>> <?= e(t('Claude may delete, trash and discard drafts')) ?></label>
	<span class="help"><?= e(t('Off: those tools are refused for every connection; you do them in the admin.')) ?></span></div>
</div>
<div class="row">
	<label for="claude_protected_pages"><?= e(t('Protected pages')) ?></label>
	<div><input class="textfield" type="text" id="claude_protected_pages" name="claude_protected_pages" value="<?= e($values['claude_protected_pages']) ?>" placeholder="12, 15" pattern="[0-9 ,;]*">
	<span class="help"><?= e(t('Page numbers (shown in the page editor’s address) that Claude must not change – neither their settings nor their build.')) ?></span></div>
</div>
