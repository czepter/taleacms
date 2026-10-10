<?php

declare(strict_types=1);

namespace Talea\Admin\Modules;

use Talea\Core\Response;

/**
 * Claude settings (3.2): how to connect, the instructions and guardrails every connection gets (formerly in Extensions –
 * the settings keys stay), and every Claude connection of the site. The home of the Claude hub (Admin\Hubs) with Ask
 * Claude, the scheduled runs, the notebook and the sessions.
 */
final class ClaudeSettings extends Settings
{
    public const string IDENT = 'claude_settings';
    public const string NAME = 'Claude settings';
    public const string GROUP = 'Claude';
    public const string ICON = 'settings';
    public const string HUB = 'claude';

    protected function tab(string $tab): string
    {
        return 'claude';
    }

    protected function view(string $template, string $heading, array $data = []): Response
    {
        // every connector and personal token of every user, newest use first (the secrets themselves are never stored)
        $data['connections'] = $this->db->all("SELECT t.name, t.client_id, t.access, t.created_at, t.used_at, t.expires_at, CASE WHEN u.name = '' OR u.name IS NULL THEN u.username ELSE u.name END AS username
            FROM {api_tokens} t JOIN {users} u ON u.user_id = t.user_id WHERE t.kind IN ('token', 'refresh') ORDER BY t.used_at IS NULL, t.used_at DESC, t.token_id DESC LIMIT 200");

        return parent::view($template, $heading, $data);
    }
}
