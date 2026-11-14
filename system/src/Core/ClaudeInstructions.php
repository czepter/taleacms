<?php

declare(strict_types=1);

namespace Talea\Core;

use Talea\Admin\ChangeLog;

/**
 * The instructions for Claude (claude_instructions) steer every later connection, and a connection may change them itself
 * (update_settings). So every change, from the admin or over MCP, is a change log row with the whole text before and after,
 * and Home tells the administrators until one of them has checked the newest text in Settings → AI. The setting
 * claude_instructions_seen holds a fingerprint of the text checked: a change made after the check shows again.
 *
 * What the notice watches is watched(): the owner's text and the instructions of every applied blueprint (a blueprint's own
 * `claude` text reaches every connection like the owner's), so applying or removing a blueprint that brings instructions is a
 * change like any other (#334).
 */
final class ClaudeInstructions
{
    /** The share of each text in the row's description (255 characters); the whole texts are in its detail. */
    private const int SHORT = 110;

    /**
     * Records a change; nothing when the text stayed the same. Only an administrator who typed the text in the admin ($typed)
     * has seen it; a restore (undoing a Claude session) still asks for a check. $blueprint names the blueprint whose instructions
     * changed (its text is $before / $after).
     */
    public static function changed(App $app, string $before, string $after, bool $typed = false, string $blueprint = ''): void
    {
        if ($before === $after) {
            return;
        }
        $short = fn (string $text): string => $text === '' ? '–' : '“' . mb_strimwidth(trim((string) preg_replace('/\s+/u', ' ', $text)), 0, self::SHORT, '…') . '”';
        // 'claude_settings' stays the module key of these rows (#149), the screen is Settings → AI now
        ChangeLog::write($app, 'claude_settings', 'instructions', ($blueprint !== '' ? mb_substr($blueprint, 0, 40) . ': ' : '') . $short($before) . ' → ' . $short($after),
            detail: (string) json_encode(['before' => $before, 'after' => $after] + ($blueprint !== '' ? ['blueprint' => $blueprint] : []), JSON_UNESCAPED_UNICODE));
        if ($typed && $app->auth()->connection() === null && $app->auth()->isAdmin()) {
            $app->settings()->set('claude_instructions_seen', self::fingerprint(self::watched($app)));
        }
    }

    /**
     * What every connection is told as instructions, independent of the admin language: the owner's text and each applied
     * blueprint's (by key). The notice watches its fingerprint.
     */
    public static function watched(App $app): string
    {
        return self::combine($app->settings()->get('claude_instructions'), Blueprint::claudeTexts($app->db()));
    }

    /**
     * The owner's text with the blueprints' instructions after it; the owner's text alone when no blueprint brings any (so a
     * fingerprint made before blueprints counted stays valid).
     *
     * @param array<string, string> $blueprints key => text
     */
    public static function combine(string $own, array $blueprints): string
    {
        if ($blueprints === []) {
            return $own;
        }
        $lines = [];
        foreach ($blueprints as $key => $text) {
            $lines[] = $key . ': ' . $text;
        }

        return $own . "\n\n" . implode("\n", $lines);
    }

    /**
     * The newest change while the current text is not checked yet, or null.
     *
     * @return array{at: string, by: string, via: string, before: string, after: string, blueprint: string, fingerprint: string}|null
     */
    public static function pending(App $app): ?array
    {
        $settings = $app->settings();
        $current = self::fingerprint(self::watched($app));
        if (hash_equals($current, $settings->get('claude_instructions_seen'))) {
            return null;
        }
        $row = $app->db()->one("SELECT created_at, user_name, via, detail FROM {change_log} WHERE module = 'claude_settings' AND action = 'instructions' ORDER BY log_id DESC LIMIT 1");
        if ($row === null) {
            // nothing recorded to check (a new site, a text from before #149, a row past the log's half year): this text counts
            // as checked, so the log is not searched again on every visit to Home
            $settings->set('claude_instructions_seen', $current);

            return null;
        }
        $texts = json_decode((string) $row['detail'], true);

        return ['at' => (string) $row['created_at'], 'by' => (string) $row['user_name'], 'via' => (string) $row['via'],
            'before' => (string) ($texts['before'] ?? ''), 'after' => (string) ($texts['after'] ?? ''), 'blueprint' => (string) ($texts['blueprint'] ?? ''), 'fingerprint' => $current];
    }

    /** "Changed by … on …" for Home and Settings → AI. @param array{at: string, by: string, via: string, blueprint?: string} $change */
    public static function who(array $change): string
    {
        $blueprint = (string) ($change['blueprint'] ?? '');
        if ($blueprint !== '') {
            return $change['via'] !== ''
                ? t('The instructions of the blueprint “%s” for the AI were changed by %s through the connection %s on %s.', $blueprint, $change['by'], $change['via'], format_date($change['at'], true))
                : t('The instructions of the blueprint “%s” for the AI were changed by %s on %s.', $blueprint, $change['by'], format_date($change['at'], true));
        }

        return $change['via'] !== ''
            ? t('The instructions for the AI were changed by %s through the connection %s on %s.', $change['by'], $change['via'], format_date($change['at'], true))
            : t('The instructions for the AI were changed by %s on %s.', $change['by'], format_date($change['at'], true));
    }

    /** An administrator checked the text with this fingerprint; false when the instructions changed again since (nothing is stored). */
    public static function acknowledge(App $app, string $fingerprint): bool
    {
        if (!hash_equals(self::fingerprint(self::watched($app)), $fingerprint)) {
            return false;
        }
        $app->settings()->set('claude_instructions_seen', $fingerprint);

        return true;
    }

    public static function fingerprint(string $text): string
    {
        return hash('sha256', $text);
    }
}
