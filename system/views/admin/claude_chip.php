<?php
/**
 * Whether Claude is connected (#154, Core\ClaudeConnection), under the logo on every screen for whoever may use Ask AI or the
 * AI settings, with the Claude connection on (not in the public demo): a dot plus words – connected (a call in the last
 * week), idle, requests wait (the scheduled run that picks them up stopped), not connected. On a phone only the dot shows;
 * the words stay for screen readers. It leads to the AI settings, or to Ask AI for whoever may not open them.
 *
 * @var Talea\Core\App $app
 * @var array<string, class-string<Talea\Admin\Module>> $modules
 */
use Talea\Core\ClaudeConnection;

if ((!isset($modules['claude']) && !isset($modules['requests'])) || !Talea\Core\Extensions::isEnabled($app->settings(), 'claude') || Talea\Core\Demo::active()) {
    return;
}
$claude = ClaudeConnection::status($app->db(), $app->settings());
[$kind, $label, $detail] = match (true) {
    !ClaudeConnection::reachable($claude) => ['off', t('Claude not connected'), $claude['waiting'] > 0 ? t('%d request(s) wait', $claude['waiting']) : ''],
    $claude['pickup'] === 'stalled' => ['hidden', t('Claude: requests wait'), t('the scheduled run stopped')],
    $claude['state'] === 'active' => ['live', t('Claude connected'), t('last call %s', format_ago($claude['last_call']))],
    default => ['draft', t('Claude idle'), $claude['last_call'] !== null ? t('last call %s', format_ago($claude['last_call'])) : t('no call yet')],
};
$text = $label . ($detail !== '' ? ' – ' . $detail : '');
?>
<a class="claude-chip status status-<?= $kind ?>" href="<?= e($app->url('admin.php?module=' . (isset($modules['claude']) ? 'claude' : 'requests'))) ?>" title="<?= e($text) ?>" data-claude-state="<?= e($claude['state']) ?>"><span class="claude-chip-text"><?= e($text) ?></span></a>
