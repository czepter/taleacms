<?php
/**
 * Claude settings (3.2, Modules\ClaudeSettings): how to connect, the instructions every connection gets, the guardrails,
 * and every connection of the site – moved here from Features so Claude has one home.
 *
 * @var Talea\Core\App $app
 * @var array<string, string> $values
 * @var list<array<string, mixed>> $connections
 */
$mcpUrl = $app->request->origin() . $app->url('mcp');
$accessNames = ['full' => t('Everything the account may'), 'drafts' => t('Drafts only'), 'read' => t('Read only')];
?>
<?php if (!in_array('claude', $enabledExtensions, true)): ?>
<p class="notice notice-error"><?= e(t('The Claude connection is switched off.')) ?> <a href="<?= e($app->url('admin.php?module=extensions')) ?>"><?= e(t('Features')) ?></a></p>
<?php endif ?>
<h2><?= e(t('How to connect')) ?></h2>
<ol class="guide">
	<li><?= e(t('In the Claude app open Settings → Connectors → Add custom connector.')) ?></li>
	<li><?= e(t('Enter this address:')) ?> <code class="totp-key" style="font-size:13px"><?= e($mcpUrl) ?></code></li>
	<li><?= e(t('Claude sends you here to sign in. You choose what it may do: everything your account may, only save drafts, or only read.')) ?></li>
</ol>
<p class="help"><?= e(t('In Claude Code, just run:')) ?> <code>claude mcp add --transport http talea <?= e($mcpUrl) ?></code>. <?= e(t('Connected applications and personal tokens for other tools are in')) ?> <a href="<?= e($app->url('admin.php?action=account#claude')) ?>"><?= e(t('My account')) ?></a>.
<?= e(t('The connector needs the site on HTTPS. In a subfolder, the Claude app finds the sign-in on its own; for a tool that does not, use a personal token.')) ?></p>
<div class="row">
	<label for="claude_instructions"><?= e(t('Instructions for Claude')) ?></label>
	<div><textarea class="textfield wide" id="claude_instructions" name="claude_instructions" rows="6" maxlength="5000" placeholder="<?= e(t('e.g. We address customers informally. Say “renovation”, never “reconstruction”. Keep headings short. Always offer a free visit.')) ?>"><?= e($values['claude_instructions']) ?></textarea>
	<span class="help"><?= e(t('Brand voice, words to use or avoid, house rules. Every Claude connection gets them when it connects, and they are its resource talea://instructions.')) ?></span></div>
</div>
<h3><?= e(t('Guardrails for Claude')) ?></h3>
<p class="help"><?= e(t('They hold for every Claude connection on top of its access. Claude is told why when it hits one, so it can tell you.')) ?></p>
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

<h2><?= e(t('Connections')) ?></h2>
<?php if ($connections === []): ?>
<p><?= e(t('Nobody has connected Claude yet.')) ?></p>
<?php else: ?>
<div class="tab-wrap"><table class="listing">
<thead><tr><th scope="col"><?= e(t('Connection')) ?></th><th scope="col"><?= e(t('User')) ?></th><th scope="col"><?= e(t('Access')) ?></th><th scope="col"><?= e(t('Last used')) ?></th><th scope="col"><?= e(t('Expires')) ?></th></tr></thead>
<tbody>
<?php foreach ($connections as $c): ?>
<tr><td><?= e((string) $c['name']) ?><?= $c['client_id'] === null ? ' <span class="badge">' . e(t('personal token')) . '</span>' : '' ?></td><td><?= e((string) $c['username']) ?></td><td><?= e($accessNames[(string) $c['access']] ?? (string) $c['access']) ?></td>
	<td><?= $c['used_at'] !== null ? e(format_date((string) $c['used_at'], true)) : '–' ?></td><td><?= $c['expires_at'] !== null ? e(format_date((string) $c['expires_at'])) : e(t('never')) ?></td></tr>
<?php endforeach ?>
</tbody></table></div>
<?php endif ?>
<p class="help"><?= e(t('Each person creates and revokes their own connections in')) ?> <a href="<?= e($app->url('admin.php?action=account#claude')) ?>"><?= e(t('My account')) ?></a>.</p>
