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
    public const string NAME = 'AI settings';
    public const string GROUP = 'AI';
    public const string ICON = 'settings';
    public const string HUB = 'claude';

    protected function tab(string $tab): string
    {
        return 'claude';
    }

    protected function view(string $template, string $heading, array $data = []): Response
    {
        // every personal token and every live connected application of every user, newest use first (the secrets themselves are never
        // stored); an application is one row per user and client, with its strongest live access (the same as in My account)
        $data['connections'] = $this->db->all("SELECT MAX(t.name) AS name, t.client_id, " . \Talea\Front\OAuth::strongestAccessSql('t.access') . " AS access, MIN(t.created_at) AS created_at, MAX(t.used_at) AS used_at,
                MAX(t.expires_at) AS expires_at, MAX(CASE WHEN u.name = '' OR u.name IS NULL THEN u.username ELSE u.name END) AS username
            FROM {api_tokens} t JOIN {users} u ON u.user_id = t.user_id WHERE t.kind IN ('token', 'refresh') AND (t.client_id IS NULL OR t.expires_at > ?)
            GROUP BY t.user_id, t.client_id, CASE WHEN t.client_id IS NULL THEN t.token_id ELSE 0 END
            ORDER BY MAX(t.used_at) IS NULL, MAX(t.used_at) DESC, MAX(t.token_id) DESC LIMIT 200", [date('Y-m-d H:i:s')]);

        return parent::view($template, $heading, $data);
    }
}
