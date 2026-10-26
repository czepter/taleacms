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
        if ($match === 'regex' && @preg_match(self::pattern($from), '') === false) {
            return t('The regular expression is not valid.');
        }
        if ($type < 400 && $to === '') {
            return t('A redirect needs a target.');
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

            return preg_replace_callback('/\$([1-9])/', fn (array $x): string => $m[(int) $x[1]] ?? '', $to);
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

        return $match === 'starts' && str_ends_with($to, '/') ? $to . mb_substr($path, mb_strlen($from)) : $to;
    }

    private static function pattern(string $from): string
    {
        return "\x01" . $from . "\x01iu"; // a control character as the delimiter cannot collide with the pattern
    }
}
