<?php
/**
 * Claude connection (Modules\Claude, every signed-in user): how to connect Claude to the site with one's own account; for
 * administrators also the state of the connection (#154) and every connection of the site. The instructions and guardrails
 * are administrators' settings (Settings → AI).
 *
 * @var Talea\Core\App $app
 * @var bool $isAdmin
 * @var list<array<string, mixed>> $connections  every connection of the site (administrators only)
 */
$mcpUrl = $app->request->origin() . $app->url('mcp');
$accessNames = array_map(fn (string $label): string => t($label), Talea\Front\OAuth::ACCESS_LABELS);
?>
<?php if ($isAdmin): // the site-wide state, with steps that lead to screens for administrators ?>
<?= $app->view->render('admin/claude_panel', ['app' => $app]) ?>
<?php endif ?>
<h2><?= e(t('How to connect')) ?></h2>
<ol class="guide">
	<li><?= e(t('In the Claude app open Settings → Connectors → Add custom connector.')) ?></li>
	<li><?= e(t('Enter this address:')) ?> <code class="totp-key" style="font-size:13px"><?= e($mcpUrl) ?></code></li>
	<li><?= e(t('Claude sends you here to sign in. You choose what it may do: save drafts for you to publish (recommended), also publish, or only read.')) ?></li>
</ol>
<p class="help"><?= e(t('In Claude Code, just run:')) ?> <code>claude mcp add --transport http talea <?= e($mcpUrl) ?></code>. <?= e(t('Connected applications and personal tokens for other tools are in')) ?> <a href="<?= e($app->url('admin.php?action=account#claude')) ?>"><?= e(t('My account')) ?></a>.
<?= e(t('The connector needs the site on HTTPS. In a subfolder, the Claude app finds the sign-in on its own; for a tool that does not, use a personal token.')) ?></p>
<?php if ($isAdmin): ?>
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
<p class="help"><?= e(t('The instructions and guardrails every connection gets are in')) ?> <a href="<?= e($app->url('admin.php?module=settings&tab=ai')) ?>"><?= e(t('AI settings')) ?></a>.</p>
<?php endif ?>
<p class="help"><?= e(t('Each person creates and revokes their own connections in')) ?> <a href="<?= e($app->url('admin.php?action=account#claude')) ?>"><?= e(t('My account')) ?></a>.</p>
