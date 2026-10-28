<?php

declare(strict_types=1);

namespace Talea\Core;

use Talea\Admin\ChangeLog;

/**
 * The instructions for Claude (claude_instructions) steer every later connection, and a connection may change them itself
 * (update_settings). So every change, from the admin or over MCP, is a change log row with the whole text before and after,
 * and Home tells the administrators until one of them has checked the newest text in Settings → AI. The setting
 * claude_instructions_seen holds a fingerprint of the text checked: a change made after the check shows again.
 */
final class ClaudeInstructions
{
    /** The share of each text in the row's description (255 characters); the whole texts are in its detail. */
    private const int SHORT = 110;

    /**
     * Records a change; nothing when the text stayed the same. Only an administrator who typed the text in the admin ($typed)
     * has seen it; a restore (undoing a Claude session) still asks for a check.
     */
    public static function changed(App $app, string $before, string $after, bool $typed = false): void
    {
        if ($before === $after) {
            return;
        }
        $short = fn (string $text): string => $text === '' ? '–' : '“' . mb_strimwidth(trim((string) preg_replace('/\s+/u', ' ', $text)), 0, self::SHORT, '…') . '”';
        // 'claude_settings' stays the module key of these rows (#149), the screen is Settings → AI now
        ChangeLog::write($app, 'claude_settings', 'instructions', $short($before) . ' → ' . $short($after),
            detail: (string) json_encode(['before' => $before, 'after' => $after], JSON_UNESCAPED_UNICODE));
        if ($typed && $app->auth()->connection() === null && $app->auth()->isAdmin()) {
            $app->settings()->set('claude_instructions_seen', self::fingerprint($after));
        }
    }

    /**
     * The newest change while the current text is not checked yet, or null.
     *
     * @return array{at: string, by: string, via: string, before: string, after: string}|null
     */
    public static function pending(App $app): ?array
    {
        $settings = $app->settings();
        $current = self::fingerprint($settings->get('claude_instructions'));
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
            'before' => (string) ($texts['before'] ?? ''), 'after' => (string) ($texts['after'] ?? '')];
    }

    /** "Changed by … on …" for Home and Settings → AI. @param array{at: string, by: string, via: string} $change */
    public static function who(array $change): string
    {
        return $change['via'] !== ''
            ? t('The instructions for the AI were changed by %s through the connection %s on %s.', $change['by'], $change['via'], format_date($change['at'], true))
            : t('The instructions for the AI were changed by %s on %s.', $change['by'], format_date($change['at'], true));
    }

    /** An administrator checked the text with this fingerprint; false when the instructions changed again since (nothing is stored). */
    public static function acknowledge(Settings $settings, string $fingerprint): bool
    {
        if (!hash_equals(self::fingerprint($settings->get('claude_instructions')), $fingerprint)) {
            return false;
        }
        $settings->set('claude_instructions_seen', $fingerprint);

        return true;
    }

    public static function fingerprint(string $text): string
    {
        return hash('sha256', $text);
    }
}
