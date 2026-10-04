<?php

declare(strict_types=1);

namespace Kaleta\Admin\Modules;

use Kaleta\Core\Response;

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
    public const string ICON = 'nastaveni';
    public const string HUB = 'claude';

    protected function tab(string $tab): string
    {
        return 'claude';
    }

    protected function view(string $template, string $heading, array $data = []): Response
    {
        // every connector and personal token of every user, newest use first (the secrets themselves are never stored)
        $data['connections'] = $this->db->all("SELECT t.nazev, t.klient, t.access, t.vytvoren, t.pouzit, t.expirace, IF(u.jmeno = '' OR u.jmeno IS NULL, u.user, u.jmeno) AS user
            FROM {api_tokeny} t JOIN {uzivatele} u ON u.idu = t.idu WHERE t.druh IN ('token', 'obnova') ORDER BY t.pouzit IS NULL, t.pouzit DESC, t.idt DESC LIMIT 200");

        return parent::view($template, $heading, $data);
    }
}
