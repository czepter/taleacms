<?php

declare(strict_types=1);

namespace Kaleta\Core;

/**
 * Guardrails for Claude (2.15): limits the site owner sets on what any Claude connection may do, on top of the access
 * a connection has (read, drafts, full). They hold for every tool call, so an agent working through a long list of
 * requests cannot run away with the site.
 *
 *  - At most claude_change_limit changes per connection in an hour (0 = no limit).
 *  - claude_destructive = 0: no destructive tool at all (deleting, trashing, discarding drafts, restoring old versions) –
 *    the user does those in the admin.
 *  - claude_protected_pages: pages Claude must not change – neither their settings nor their build, draft or published.
 *
 * Every refusal says why, so Claude can tell the user instead of trying another way.
 */
final class Guardrails
{
    /** Tools whose `id` is a page – unless another build target (a site part, a pop-up, a component, a collection) is named. */
    private const array PAGE_TOOLS = ['uprav_stranku', 'smaz_stranku', 'stavba_uloz', 'stavba_uprav', 'stavba_z_html', 'vloz_sekci', 'publikuj_stavbu',
        'obnov_verzi', 'zahod_koncept'];

    /** The other build targets: with one of them the `id` does not name a page. */
    private const array OTHER_TARGETS = ['cast', 'popup', 'komponenta', 'kolekce'];

    /** The pages protected in the setting ("12, 15 18" – any separators). @return list<int> */
    public static function protectedPages(Settings $settings): array
    {
        preg_match_all('/\d+/', $settings->get('claude_protected_pages'), $m);

        return array_values(array_unique(array_filter(array_map('intval', $m[0]))));
    }

    /**
     * The page a tool call would change, by the Czech tool name and Czech arguments (after Mcp\Translator), or null.
     *
     * @param array<string, mixed> $arguments
     */
    public static function targetPage(string $tool, array $arguments): ?int
    {
        if (!in_array($tool, self::PAGE_TOOLS, true) || array_intersect_key($arguments, array_flip(self::OTHER_TARGETS)) !== []) {
            return null;
        }
        $id = $arguments['id'] ?? null;

        return is_numeric($id) && (int) $id > 0 ? (int) $id : null;
    }

    /**
     * Why the call is refused, or null when the guardrails allow it. $access is the tool's kind from Mcp\Catalog
     * (read | draft | write | destructive); $connection the name of the Claude connection.
     *
     * @param array<string, mixed> $arguments Czech arguments
     */
    public static function refusal(App $app, string $tool, string $access, array $arguments, string $connection): ?string
    {
        if ($access === 'read') {
            return null;
        }
        $settings = $app->settings();
        if ($access === 'destructive' && !$settings->bool('claude_destructive')) {
            return 'The site owner switched off deleting and discarding for Claude (Claude settings → Guardrails for Claude). Tell the user what you wanted to remove – they can do it in the admin.';
        }
        $page = self::targetPage($tool, $arguments);
        if ($page !== null && in_array($page, self::protectedPages($settings), true)) {
            return 'Page ' . $page . ' is protected from changes by Claude (Claude settings → Guardrails for Claude). Suggest the change to the user instead.';
        }
        $limit = $settings->int('claude_change_limit');
        if ($limit > 0 && (int) $app->db()->value("SELECT COUNT(*) FROM {protokol} WHERE modul = 'claude' AND via = ? AND cas > NOW() - INTERVAL 1 HOUR", [$connection]) >= $limit) {
            return 'This connection reached the limit of ' . $limit . ' changes an hour that the site owner set (Claude settings → Guardrails for Claude). Stop here and tell the user what is done and what is left.';
        }

        return null;
    }

    /** The reason Claude gave for a change: one line of plain text, at most 255 characters ('' = none). */
    public static function reason(mixed $reason): string
    {
        return is_string($reason) ? mb_substr(trim((string) preg_replace('/\s+/u', ' ', strip_tags($reason))), 0, 255) : '';
    }
}
