<?php

declare(strict_types=1);

namespace Talea\Core;

/** Redirect rules beyond the exact address: how a rule matches a path and what it points to, and what a rule may say. */
final class RedirectRules
{
    public const array TYPES = [301, 302, 307, 410, 451];
    public const array MATCHES = ['exact', 'contains', 'starts', 'ends', 'regex'];

    /** The refusal message, null when the rule is fine. */
    public static function validate(string $from, string $to, string $match, int $type): ?string
    {
        $from = trim($from);
        $to = trim($to);
        if (!in_array($match, self::MATCHES, true)) {
            return t('Unknown match type.');
        }
        if (!in_array($type, self::TYPES, true)) {
            return t('Unknown redirect type.');
        }
        if ($from === '' || mb_strlen($from) > 255) {
            return t('The address or pattern must be 1 to 255 characters.');
        }
        if ($match === 'regex') {
            if (@preg_match(self::pattern($from), '') === false) {
                return t('The regular expression is not valid.');
            }
            // a rule that answers every address (or, for 410 and 451, the empty one) would swallow the whole site
            if (($type >= 400 && preg_match(self::pattern($from), '') === 1) || array_sum(array_map(fn (string $p): int => (int) preg_match(self::pattern($from), $p), ['x', 'a/b', 'zq-9'])) === 3) {
                return t('The pattern matches every address.');
            }
        } elseif (trim($from, '/') === '') {
            return t('The address or pattern must be 1 to 255 characters.');
        }
        if ($type < 400 && $to === '') {
            return t('A redirect needs a target.');
        }
        // a target that target() would never use: it could be saved and then silently never match
        if ($to !== '' && (preg_match('/[\x00-\x1f\x7f]/', $to) || (!preg_match('#^https?://#i', $to) && preg_match('#^(/[/\\\\]|\\\\|[a-z][a-z0-9+.-]*:)#i', $to)))) {
            return t('The target must be a path on this site or a full https://… address.');
        }
        if (trim($from, '/') === trim($to, '/') && $to !== '') {
            return t('The address and the target are the same.');
        }
        if ($to !== '' && self::target(['match_type' => $match, 'from_path' => $from, 'to_path' => $to], $to) !== null) {
            return t('The target would match the rule itself: a loop.');
        }

        return null;
    }

    /** Where the rule sends the path, null when it does not match (also when the pattern fails at run time). */
    public static function target(array $rule, string $path): ?string
    {
        $path = trim($path, '/');
        $from = (string) $rule['from_path'];
        $to = (string) $rule['to_path'];
        $match = $rule['match_type'] ?? 'exact';
        if ($match === 'regex') {
            if (@preg_match(self::pattern($from), $path, $m) !== 1) {
                return null; // no match, or a PCRE error (backtrack limit): never a failure
            }

            return self::safe($to, preg_replace_callback('/\$([1-9])/', fn (array $x): string => $m[(int) $x[1]] ?? '', $to));
        }
        $from = trim($from, '/') . (str_ends_with($from, '/') && $match === 'starts' ? '/' : '');
        $lower = mb_strtolower($path);
        $needle = mb_strtolower($from);
        $hit = match ($match) {
            'exact' => $lower === $needle,
            'contains' => str_contains($lower, $needle),
            'starts' => str_starts_with($lower, $needle),
            'ends' => str_ends_with($lower, $needle),
            default => false,
        };
        if (!$hit) {
            return null;
        }

        return self::safe($to, $match === 'starts' && str_ends_with($to, '/') ? $to . mb_substr($path, mb_strlen($from)) : $to);
    }

    /**
     * The expanded target, null when substituting the visitor's text made it something the template did not say: only a template that
     * starts with http:// or https:// may leave the site; otherwise the result must stay a path of this site (no //host, no \, no scheme).
     */
    private static function safe(string $template, ?string $result): ?string
    {
        if ($result === null || preg_match('/[\x00-\x1f\x7f]/', $result)) {
            return null;
        }
        if (preg_match('#^https?://#i', $template)) {
            return $result;
        }

        return preg_match('#^(\\s|/[/\\\\]|\\\\|[a-z][a-z0-9+.-]*:)#i', $result) ? null : $result;
    }

    private static function pattern(string $from): string
    {
        return "\x01" . $from . "\x01iu"; // a control character as the delimiter cannot collide with the pattern
    }
}
