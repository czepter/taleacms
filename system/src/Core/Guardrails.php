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
 *    the user does those in the admin. Since 3.3.2 also the deleting, overwriting or sending calls of write tools
 *    (DESTRUCTIVE_CALLS).
 *  - claude_protected_pages: pages Claude must not change – neither their settings nor their build, draft or published.
 *
 * Every refusal says why, so Claude can tell the user instead of trying another way.
 */
final class Guardrails
{
    /** Tools whose `id` is a page – unless another build target (a site part, a pop-up, a component, a collection) is named. */
    private const array PAGE_TOOLS = ['uprav_stranku', 'smaz_stranku', 'stavba_uloz', 'stavba_uprav', 'stavba_z_html', 'vloz_sekci', 'publikuj_stavbu',
        'obnov_verzi', 'zahod_koncept'];

    /**
     * Calls of write tools that delete, overwrite or send (3.3.2, N32): with claude_destructive = 0 they are refused like
     * a destructive tool. tool (Czech name, or the English one when both sides are the same) => the parameter that makes
     * the call destructive ('' = the tool always overwrites). The tools read these parameters with !empty().
     */
    private const array DESTRUCTIVE_CALLS = ['uloz_presmerovani' => 'smazat', 'uloz_variantu' => 'smazat', 'restore_item_version' => '', 'request_testimonial' => 'send',
        // they e-mail the customer, like decline_booking (3.4.2, N34-2)
        'confirm_booking' => '', 'propose_booking_times' => ''];

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
        // 3.3.2 (N31): another target counts only when the tools would use it – the same non-empty test as
        // Tools::loadBuildTarget ("popup": 0 still edits the page); update_page and trash_page have no other target
        if (!in_array($tool, self::PAGE_TOOLS, true) || (!in_array($tool, ['uprav_stranku', 'smaz_stranku'], true) && self::otherTarget($arguments))) {
            return null;
        }
        $id = $arguments['id'] ?? null;

        return is_numeric($id) && (int) $id > 0 ? (int) $id : null;
    }

    /** @param array<string, mixed> $arguments */
    private static function otherTarget(array $arguments): bool
    {
        foreach (self::OTHER_TARGETS as $key) {
            $value = $arguments[$key] ?? null;
            if (in_array($key, ['popup', 'komponenta'], true) ? is_scalar($value) && (int) $value > 0 : $value !== null && $value !== '') {
                return true;
            }
        }

        return false;
    }

    /** Batch tools: the argument with the rows – each row is one change against claude_change_limit (3.7). */
    public const array BATCH_ROWS = ['save_redirects' => 'redirects', 'save_collection_items' => 'items'];

    /**
     * How many changes a call makes for the hourly limit: the rows of a batch tool, otherwise one.
     *
     * @param array<string, mixed> $arguments
     */
    public static function weight(string $tool, array $arguments): int
    {
        $key = self::BATCH_ROWS[$tool] ?? null;

        return $key !== null && is_array($arguments[$key] ?? null) ? max(1, count($arguments[$key])) : 1;
    }

    /** The changes of a connection in the last hour: one per change-log row, a batch row counts its rows ("<n> rows"). */
    private static function changesInLastHour(App $app, string $connection): int
    {
        $batch = "'" . implode("','", array_keys(self::BATCH_ROWS)) . "'";

        return (int) $app->db()->value("SELECT COALESCE(SUM(CASE WHEN akce IN ($batch) AND popis REGEXP '^[0-9]+ rows' THEN CAST(SUBSTRING_INDEX(popis, ' ', 1) AS UNSIGNED) ELSE 1 END), 0)"
            . " FROM {protokol} WHERE modul = 'claude' AND via = ? AND cas > NOW() - INTERVAL 1 HOUR", [$connection]);
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
        if (isset(self::DESTRUCTIVE_CALLS[$tool]) && (self::DESTRUCTIVE_CALLS[$tool] === '' || !empty($arguments[self::DESTRUCTIVE_CALLS[$tool]]))) {
            $access = 'destructive';
        }
        if ($access === 'destructive' && !$settings->bool('claude_destructive')) {
            return 'The site owner switched off deleting and discarding for Claude (Claude settings → Guardrails for Claude). Tell the user what you wanted to remove – they can do it in the admin.';
        }
        $page = self::targetPage($tool, $arguments);
        if ($page !== null && in_array($page, self::protectedPages($settings), true)) {
            return 'Page ' . $page . ' is protected from changes by Claude (Claude settings → Guardrails for Claude). Suggest the change to the user instead.';
        }
        $limit = $settings->int('claude_change_limit');
        if ($limit > 0) {
            $used = self::changesInLastHour($app, $connection);
            if ($used >= $limit) {
                return 'This connection reached the limit of ' . $limit . ' changes an hour that the site owner set (Claude settings → Guardrails for Claude). Stop here and tell the user what is done and what is left.';
            }
            $weight = self::weight($tool, $arguments);
            if ($used + $weight > $limit) {
                return 'This call would make ' . $weight . ' changes, but the connection has ' . ($limit - $used) . ' left of the limit of ' . $limit
                    . ' changes an hour that the site owner set (Claude settings → Guardrails for Claude). Send at most ' . ($limit - $used) . ' rows now, or stop and tell the user what is left.';
            }
        }

        return null;
    }

    /** The reason Claude gave for a change: one line of plain text, at most 255 characters ('' = none). */
    public static function reason(mixed $reason): string
    {
        return is_string($reason) ? mb_substr(trim((string) preg_replace('/\s+/u', ' ', strip_tags($reason))), 0, 255) : '';
    }
}
