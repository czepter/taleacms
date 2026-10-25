<?php
/**
 * "Ask Claude" on the dashboard (3.1, Core\AskClaude): one box whose text becomes a request for Claude (Requests), with
 * attachments; example requests that fill the box (admin.js); whether a scheduled run picks requests up; the person's
 * latest requests. "Copy for the Claude app" copies the text with the site's address and opens Claude in a new tab.
 *
 * @var Talea\Core\App $app
 * @var array{connected: bool, status: array{state: string, pickup: ?string, stalled_since: ?string}, examples: array<string, string>, recent: list<array{id: int, title: string, status: string, updated_at: string}>, routine: ?array{cadence: string, day: int, time: string, next_due: ?string}, prompt: string, admin: bool, from?: string} $ask  from: where the box is ('dashboard' = Home)
 */
use Talea\Core\Requests;

$requestsUrl = fn (string $action = '', array $params = []): string => $app->url('admin.php?module=requests' . ($action !== '' ? '&action=' . $action : '') . ($params !== [] ? '&' . http_build_query($params) : ''));
?>
<section class="ask-claude" aria-labelledby="ask-claude-heading">
	<h2 id="ask-claude-heading"><?= e(t('Ask AI')) ?></h2>
	<p class="small-text"><?= e(t('Write what you need done on the site, as you would to a colleague. Claude does it as drafts and answers in Requests; a person reviews and publishes.')) ?></p>
	<form method="post" action="<?= e($requestsUrl('save')) ?>" enctype="multipart/form-data">
		<?= $app->session->csrfField() ?>
		<input type="hidden" name="quick" value="1">
		<input type="hidden" name="from" value="<?= e($ask['from'] ?? 'dashboard') ?>">
		<label class="guide-hidden" for="ask-claude-text"><?= e(t('What should Claude do?')) ?></label>
		<textarea class="textbox" id="ask-claude-text" name="text" rows="3" required maxlength="<?= Requests::MAX_TEXT ?>" placeholder="<?= e(t('e.g. Add the new opening hours to the contact page')) ?>"></textarea>
		<div class="ask-claude-buttons">
			<input class="btn" type="submit" value="<?= e(Talea\Core\ClaudeConnection::blocked($ask['status']) ? t('Save the request') : t('Send to Claude')) ?>">
			<label class="ask-claude-files"><span><?= e(t('Attach files')) ?></span> <input type="file" name="attachments[]" multiple></label>
<?php if ($ask['connected']): ?>
			<a class="navigation" href="https://claude.ai/new" target="_blank" rel="noopener" data-ask-claude-copy data-prompt="<?= e($ask['prompt']) ?>" data-copied="<?= e(t('Copied – paste it into Claude')) ?>"><?= e(t('Copy for the Claude app')) ?></a>
<?php endif ?>
		</div>
	</form>
<?= $app->view->render('admin/claude_notice', ['app' => $app, 'claude' => $ask['status'], 'routine' => $ask['routine'], 'admin' => $ask['admin']]) ?>
<?php if ($ask['examples'] !== []): ?>
	<p class="small-text ask-claude-try"><?= e(t('For example – click one, change it and send:')) ?></p>
	<ul class="ask-claude-examples">
<?php foreach ($ask['examples'] as $key => $text): ?>
		<li><button type="button" class="ask-claude-example" data-ask-claude-example="<?= e($key) ?>"><?= e(t($text)) ?></button></li>
<?php endforeach ?>
	</ul>
<?php endif ?>
<?php if ($ask['recent'] !== []): ?>
	<h3><?= e(t('Your requests')) ?></h3>
	<ul class="ask-claude-my">
<?php foreach ($ask['recent'] as $r): ?>
		<li><a href="<?= e($requestsUrl('detail', ['id' => $r['public_id']])) ?>"><?= e($r['title']) ?></a>
			<span class="badge<?= match ($r['status']) { 'done' => ' badge-published', 'new' => ' badge-draft', 'declined' => ' badge-error', default => '' } ?>"><?= e(t(Requests::STATUSES[$r['status']] ?? $r['status'])) ?></span>
			<span class="small-text"><?= e(format_date($r['updated_at'], true)) ?></span></li>
<?php endforeach ?>
	</ul>
	<p class="small-text"><a href="<?= e($requestsUrl()) ?>"><?= e(t('All requests')) ?></a></p>
<?php endif ?>
</section>
